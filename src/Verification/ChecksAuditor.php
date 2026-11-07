<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\StagingException;
use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\StagedFile;
use Celyes\RalphPhp\Git\StagedPatch;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * Reads a verified task's staged diff for changes that make the project's own
 * verification weaker than it was (PROJECT.md section G, item 5).
 *
 * Called once per attempt, in the task's own worktree, after the checks have
 * passed and after staging has decided what the commit would contain, and
 * before the commit is made. That position is the requirement rather than a
 * convenience:
 *
 *  - **After the checks**, because a diff that fails them is already refused
 *    and a second reason would add nothing.
 *  - **After staging**, because what matters is what the *commit* would carry.
 *    A test file an agent deleted and the stager excluded is not in the commit
 *    and has weakened nothing.
 *  - **Before the commit**, because the only useful answer is one given while
 *    not committing is still an option.
 *
 * **Why it exists at all.** The checks passed against the suite the diff leaves
 * behind. Delete the test that would have failed and the suite is green and
 * honest; the frozen set of item 3 stops a worker choosing *which* commands run
 * and does nothing about what those commands then find. This is the other half.
 *
 * **The reading is conventions over a patch, and that is stated plainly in
 * {@see ChecksAudit::hints()} as well as here**, because the item asks for the
 * limit to be documented and a limit documented only in source is documented to
 * nobody. There is no parser here, no second run of the old suite against the
 * new code, and no coverage measurement. It matches the shapes test code has in
 * a diff's added and removed lines, which misses a weakening written another way
 * and occasionally reports a change that was asked for. The bias toward
 * reporting is deliberate: a finding costs a person a patch to read, and a miss
 * costs the guarantee the whole item exists for.
 *
 * **Existing verification, specifically.** The item says *weaken existing*
 * verification, and the rules keep to it. A removal is counted wherever it
 * happens, since removals only ever happen to content that was already there;
 * an addition counts toward the same total, so moving tests between files nets
 * to nothing. A disabling marker, though, is only counted in a file that
 * existed before the diff — a new test file carrying an environment guard has
 * made the suite larger, not weaker, and reporting it would make the finding
 * mean nothing.
 *
 * Nothing here acts. It reads, it writes a copy of the patch for a person, and
 * it returns a {@see ChecksAudit}; whether a task stops is the pass's decision.
 */
final class ChecksAuditor
{
    /**
     * Filenames that hold tests, as conventions rather than as a rule.
     *
     * PHP first, since that is the package's stated target, and a short list of
     * the other languages' conventions because the `checks` setting runs
     * whatever the project configures and a PHP package can hold a JavaScript
     * build beside it.
     *
     * @var list<non-empty-string>
     */
    private const array TEST_FILENAMES = [
        '/(?:^|\/)[^\/]*Test\.php$/',
        '/(?:^|\/)[^\/]*TestCase\.php$/',
        '/(?:^|\/)test_[^\/]+\.py$/',
        '/(?:^|\/)[^\/]*_test\.(?:go|py|rb|ts|js)$/',
        '/(?:^|\/)[^\/]*\.(?:test|spec)\.(?:ts|tsx|js|jsx|mjs|cjs)$/',
    ];

    /**
     * Directory names whose contents are treated as part of the suite.
     *
     * Broader than {@see self::TEST_FILENAMES} on purpose: a fixture, a helper
     * or a snapshot under `tests/` is not a test file and deleting one can
     * still stop tests passing for a reason nobody intended. Compared in lower
     * case, since `Tests/` and `tests/` are the same convention.
     *
     * @var list<string>
     */
    private const array TEST_DIRECTORIES = ['tests', 'test', 'spec', 'specs', 'features', '__tests__'];

    /**
     * Where a test case is declared.
     *
     * A PHPUnit method, a `#[Test]` attribute, an `@test` annotation in a
     * comment, a Pest or Jest `it(...)`, a Python `def test_…`, a Go
     * `func TestX`. Known imprecision: a case written as `#[Test]` *and* named
     * `testSomething` counts twice, so a diff that migrates a file from
     * annotations to attributes can read as having removed cases. It is
     * symmetrical in every other diff, and a false finding here costs a patch
     * to read.
     *
     * @var non-empty-string
     */
    private const string TEST_CASE = '/\bfunction\s+test[A-Za-z0-9_]*\s*\(|#\[\s*Test\s*[\]\(]|(?:\*|\/\/)\s*@test\b'
        . '|\bdef\s+test[A-Za-z0-9_]*\s*\(|\bfunc\s+Test[A-Z][A-Za-z0-9_]*\s*\(|\b(?:it|test)\s*\(\s*[\'"]/';

    /**
     * Where an assertion is made.
     *
     * `assert…(` covers PHPUnit's methods whether they are called on `$this` or
     * statically, since `::` and `>` are both word boundaries. `expect(` is
     * Pest and Jest; `->should…(` is Mockery and Prophecy, where the
     * expectation *is* the assertion.
     *
     * @var non-empty-string
     */
    private const string ASSERTION = '/\bassert[A-Za-z_]*\s*\(|\bexpect\s*\(|->should[A-Za-z]*\s*\(/';

    /**
     * Markers that stop a test from reporting what it found.
     *
     * @var non-empty-string
     */
    private const string DISABLED = '/\bmarkTestSkipped\s*\(|\bmarkTestIncomplete\s*\('
        . '|\bexpectNotToPerformAssertions\s*\(|->skip\s*\(|\bx(?:it|describe)\s*\('
        . '|\b(?:it|test|describe)\.(?:skip|todo)\s*\(|@pytest\.mark\.skip|@unittest\.skip'
        . '|\bt\.Skip(?:Now)?\s*\(|#\[\s*(?:Ignore|Skip)\b/';

    /**
     * Files whose contents decide what a check looks at, by lower-cased
     * basename.
     *
     * Tool configuration only. The rule for these is about *narrowing* rather
     * than about having been edited, because a project adding a second test
     * suite is strengthening its verification and a rule that reported it would
     * mean nothing.
     *
     * A manifest holding a script is deliberately not here —
     * {@see self::CHECK_MANIFESTS} — because the two need opposite defaults.
     *
     * @var list<string>
     */
    private const array CHECK_CONFIGURATION = [
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpunit.dist.xml',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan.dist.neon',
        'psalm.xml',
        'psalm.xml.dist',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'rector.php',
        'infection.json',
        'infection.json5',
        'behat.yml',
        'behat.yml.dist',
        'codeception.yml',
        'pest.php',
    ];

    /**
     * Files that hold the scripts a check may run, by lower-cased basename.
     *
     * Separate from {@see self::CHECK_CONFIGURATION} because of what an
     * ordinary task does to one. `composer.json` is where `composer test` — this
     * package's own default check — is defined, and it is also the file most
     * often edited for a reason that has nothing to do with verification:
     * adding a dependency to it removes the line above where the new one goes,
     * so a rule about lost lines would block half the tasks a loop is for.
     *
     * So only the script a frozen check actually invokes is looked at, in
     * either direction: `"test": "phpunit --testsuite=fast"` is the finding,
     * and a new entry under `require-dev` is not.
     *
     * @var list<string>
     */
    private const array CHECK_MANIFESTS = ['composer.json', 'package.json', 'makefile'];

    /**
     * Programs whose first non-flag argument is the name of a script they run.
     *
     * @var list<string>
     */
    private const array SCRIPT_RUNNERS = ['composer', 'npm', 'yarn', 'pnpm', 'bun', 'make'];

    /**
     * Added lines that narrow what a check covers.
     *
     * Each one is a way of saying "look at less", or "mind less about what you
     * find": a PHPUnit `<exclude>`, a PHPStan excluded path or baseline, an
     * ignore annotation, a strictness switch turned off, a shell `|| true` that
     * discards a command's status.
     *
     * @var non-empty-string
     */
    private const string NARROWING = '/<exclude|excludePaths|excludeAnalyse|ignoreErrors|baselineFile'
        . '|errorFormat|reportUnmatched\w*|(?:stopOn|failOn|beStrictAbout)[A-Za-z]*\s*=\s*"false"'
        . '|@(?:phpstan|psalm|php-cs-fixer)-ignore|\|\|\s*true|--no-check|--dont-report/';

    /**
     * Evidence lines kept per finding.
     *
     * The whole patch is preserved beside the finding, so this is what a
     * terminal line and a stored blocker can hold rather than what is
     * available.
     */
    private const int QUOTED_LINES = 5;

    public function __construct(
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * Read a staged diff against the checks the run froze.
     *
     * @param StagedChanges $staged what staging decided the commit would
     *        contain, which is what this is a reading of — not the workspace
     * @param string|null $preserveTo where to keep the patch if there is
     *        anything to review, usually a path from
     *        {@see \Celyes\RalphPhp\Log\LogDirectory::stagedDiff()}
     * @param OutputSink|null $sink where the reading is noted as it is made;
     *        never closed here, since the caller that opened it owns it
     *
     * @throws StagingException when the patch cannot be read, which is a commit
     *         whose contents could not be examined rather than a commit with
     *         nothing wrong with it
     */
    public function audit(
        VerificationSnapshot $checks,
        TaskWorktree $worktree,
        StagedChanges $staged,
        ?string $preserveTo = null,
        ?OutputSink $sink = null,
    ): ChecksAudit {
        $workspace = $worktree->path();

        if ($staged->isEmpty()) {
            // Nothing is being committed, so nothing can have been weakened by
            // it. The no-change outcome is section G, item 6's decision and is
            // not this one's to pre-empt.
            return ChecksAudit::clean($workspace);
        }

        $patch = $this->read($worktree);
        $tests = $this->testFiles($staged);

        $findings = [
            ...$this->deletedTests($tests),
            ...$this->fewerCases($tests, $patch),
            ...$this->fewerAssertions($tests, $patch),
            ...$this->disabledCases($tests, $patch),
            ...$this->narrowedConfiguration($checks, $staged, $patch),
            ...$this->changedScripts($checks, $staged, $patch),
            ...$this->changedCommands($checks, $staged),
        ];

        $audit = ChecksAudit::of(
            $workspace,
            $findings,
            array_map(static fn (StagedFile $file): string => $file->path(), $tests),
        );

        if ($audit->isClean()) {
            $sink?->note($audit->describe());

            return $audit;
        }

        $audit = $preserveTo === null ? $audit : $this->preserve($audit, $patch, $preserveTo, $sink);

        $sink?->note($audit->summary());

        foreach ($audit->details() as $detail) {
            $sink?->note('  ' . $detail);
        }

        return $audit;
    }

    /**
     * The staged patch, or a refusal.
     *
     * @throws StagingException when Git cannot answer, which is treated the way
     *         {@see \Celyes\RalphPhp\Git\WorkspaceStager} treats an unreadable
     *         index: a commit this package could not examine is not one it
     *         makes. The work is kept, so nothing is lost by stopping.
     */
    private function read(TaskWorktree $worktree): StagedPatch
    {
        try {
            $git = Repository::at($worktree->path(), $this->executable, $this->processes);
        } catch (InvalidArgumentException $exception) {
            throw new StagingException(
                sprintf(
                    'the task workspace %s could not be opened to read its staged diff: %s',
                    $worktree->path(),
                    $exception->getMessage(),
                ),
                ['Nothing was committed, and the implementation is still in the workspace.'],
            );
        }

        $result = $git->query(...StagedPatch::COMMAND);

        if (!$result->succeeded()) {
            throw new StagingException(
                sprintf(
                    'the staged diff of the task workspace %s could not be read: %s',
                    $worktree->path(),
                    $result->failureReason(),
                ),
                [
                    'Ralph PHP reads the diff it is about to commit to see whether it weakens the '
                        . 'project\'s own checks, and will not commit a diff it could not examine.',
                    sprintf('Run "git diff --cached" in %s to see what Git reports.', $worktree->path()),
                    'Nothing was committed, and the implementation is still in the workspace.',
                ],
            );
        }

        return StagedPatch::parse($result->standardOutput());
    }

    /**
     * The staged files that are part of the project's suite.
     *
     * @return list<StagedFile>
     */
    private function testFiles(StagedChanges $staged): array
    {
        $tests = [];

        foreach ($staged->files() as $file) {
            foreach ($file->paths() as $path) {
                if (self::isTestPath($path)) {
                    $tests[] = $file;

                    continue 2;
                }
            }
        }

        return $tests;
    }

    /**
     * A test file the commit removes from the suite.
     *
     * A rename is only a finding when it lands somewhere tests are not looked
     * for: moved within the suite, the same cases are still collected under a
     * new name.
     *
     * @param list<StagedFile> $tests
     *
     * @return list<Weakening>
     */
    private function deletedTests(array $tests): array
    {
        $findings = [];

        foreach ($tests as $file) {
            $status = substr($file->status(), 0, 1);

            if ($status === 'D') {
                $findings[] = Weakening::testsDeleted($file->path());

                continue;
            }

            $from = $file->originalPath();

            if ($status === 'R' && $from !== null && self::isTestPath($from) && !self::isTestPath($file->path())) {
                $findings[] = Weakening::testsDeleted($from, $file->path());
            }
        }

        return $findings;
    }

    /**
     * Fewer test cases after the diff than before.
     *
     * @param list<StagedFile> $tests
     *
     * @return list<Weakening>
     */
    private function fewerCases(array $tests, StagedPatch $patch): array
    {
        [$added, $removed, $paths, $quoted] = $this->count($tests, $patch, self::TEST_CASE);

        return $removed > $added
            ? [Weakening::casesRemoved($removed, $added, $paths, $quoted)]
            : [];
    }

    /**
     * Fewer assertions in the suite after the diff than before.
     *
     * @param list<StagedFile> $tests
     *
     * @return list<Weakening>
     */
    private function fewerAssertions(array $tests, StagedPatch $patch): array
    {
        [$added, $removed, $paths, $quoted] = $this->count($tests, $patch, self::ASSERTION);

        return $removed > $added
            ? [Weakening::assertionsRemoved($removed, $added, $paths, $quoted)]
            : [];
    }

    /**
     * Markers added to existing test files that stop a test reporting.
     *
     * Counted over files the diff did not create, for the reason the class note
     * gives: a new test file's environment guard has not weakened anything that
     * existed. Removals are counted wherever they are, so a diff that lifts a
     * skip is credited for it.
     *
     * @param list<StagedFile> $tests
     *
     * @return list<Weakening>
     */
    private function disabledCases(array $tests, StagedPatch $patch): array
    {
        $added = 0;
        $removed = 0;
        $paths = [];
        $quoted = [];

        foreach ($tests as $file) {
            $patched = $patch->forPath($file->path());

            if ($patched === null) {
                continue;
            }

            $removed += $patched->removals(self::DISABLED);

            if (substr($file->status(), 0, 1) === 'A') {
                continue;
            }

            $count = $patched->additions(self::DISABLED);

            if ($count === 0) {
                continue;
            }

            $added += $count;
            $paths[] = $file->path();
            $quoted = [...$quoted, ...$this->quotes($patched->added(), self::DISABLED)];
        }

        return $added > $removed
            ? [Weakening::casesDisabled($added - $removed, $paths, array_slice($quoted, 0, self::QUOTED_LINES))]
            : [];
    }

    /**
     * A file configuring one of the frozen checks that lost lines or gained a
     * narrowing directive.
     *
     * @return list<Weakening>
     */
    private function narrowedConfiguration(
        VerificationSnapshot $checks,
        StagedChanges $staged,
        StagedPatch $patch,
    ): array {
        $findings = [];

        foreach ($staged->files() as $file) {
            $basename = strtolower(basename($file->path()));

            if (!in_array($basename, self::CHECK_CONFIGURATION, true)) {
                continue;
            }

            $patched = $patch->forPath($file->path());

            if ($patched === null) {
                continue;
            }

            $narrowing = $this->quotes($patched->added(), self::NARROWING);
            $losses = $patched->removed();

            if ($narrowing === [] && $losses === []) {
                continue;
            }

            // The narrowing directives are the better evidence when there are
            // any: a removed line says what the file no longer states, and an
            // added `<exclude>` says what the check no longer looks at.
            $evidence = $narrowing === []
                ? array_map(static fn (string $line): string => Weakening::quote($line), $losses)
                : $narrowing;

            $findings[] = Weakening::configurationNarrowed(
                $file->path(),
                $this->checkFor($checks, $basename),
                array_slice($evidence, 0, self::QUOTED_LINES),
            );
        }

        return $findings;
    }

    /**
     * A manifest whose declaration of a script a frozen check runs was changed.
     *
     * Either direction counts: a script that gained a flag and a script that
     * was not there before both change what "the checks passed" means. A
     * manifest with none of the run's scripts in its diff is passed over
     * entirely, which is what keeps an added dependency from being a finding.
     *
     * @return list<Weakening>
     */
    private function changedScripts(
        VerificationSnapshot $checks,
        StagedChanges $staged,
        StagedPatch $patch,
    ): array {
        $scripts = self::scriptsIn($checks);

        if ($scripts === []) {
            return [];
        }

        $findings = [];

        foreach ($staged->files() as $file) {
            if (!in_array(strtolower(basename($file->path())), self::CHECK_MANIFESTS, true)) {
                continue;
            }

            $patched = $patch->forPath($file->path());

            if ($patched === null) {
                continue;
            }

            foreach ($scripts as $script => $check) {
                $pattern = self::declarationOf($script);
                $quoted = [
                    ...$this->quotes($patched->removed(), $pattern),
                    ...$this->quotes($patched->added(), $pattern),
                ];

                if ($quoted === []) {
                    continue;
                }

                $findings[] = Weakening::scriptChanged(
                    $file->path(),
                    $script,
                    $check,
                    array_slice($quoted, 0, self::QUOTED_LINES),
                );
            }
        }

        return $findings;
    }

    /**
     * A file one of the frozen checks runs, changed by the diff.
     *
     * Exact paths only. A check given a directory to look in — `phpunit
     * tests/Unit` — names where the work is, and a change under it is the task
     * doing its job; a check given a program names what decides the verdict.
     *
     * @return list<Weakening>
     */
    private function changedCommands(VerificationSnapshot $checks, StagedChanges $staged): array
    {
        $findings = [];
        $paths = $staged->paths();

        foreach ($checks->commands() as $check) {
            foreach (self::repositoryPathsIn($check) as $path) {
                if (in_array($path, $paths, true)) {
                    $findings[] = Weakening::commandChanged($path, $check->name());
                }
            }
        }

        return $findings;
    }

    /**
     * Added and removed occurrences of a pattern across the staged test files,
     * with the files and the removed lines that make up the reading.
     *
     * @param list<StagedFile> $tests
     * @param non-empty-string $pattern
     *
     * @return array{int, int, list<string>, list<string>}
     */
    private function count(array $tests, StagedPatch $patch, string $pattern): array
    {
        $added = 0;
        $removed = 0;
        $paths = [];
        $quoted = [];

        foreach ($tests as $file) {
            $patched = $patch->forPath($file->path());

            if ($patched === null) {
                continue;
            }

            $gained = $patched->additions($pattern);
            $lost = $patched->removals($pattern);

            if ($gained === 0 && $lost === 0) {
                continue;
            }

            $added += $gained;
            $removed += $lost;
            $paths[] = $file->path();
            $quoted = [...$quoted, ...$this->quotes($patched->removed(), $pattern)];
        }

        return [$added, $removed, $paths, array_slice($quoted, 0, self::QUOTED_LINES)];
    }

    /**
     * The lines matching a pattern, quoted for a message.
     *
     * @param list<string> $lines
     * @param non-empty-string $pattern
     *
     * @return list<string>
     */
    private function quotes(array $lines, string $pattern): array
    {
        $quoted = [];

        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                $quoted[] = Weakening::quote($line);
            }
        }

        return $quoted;
    }

    /**
     * Which frozen check a configuration file belongs to, as a name to put in a
     * message.
     *
     * Matched on the tool's own name appearing in a check's name or command, so
     * a project whose check is `composer test` and whose configuration is
     * `phpunit.xml` gets the honest answer rather than a guess — null, which
     * {@see Weakening::configurationNarrowed()} reads as the project's checks
     * in general.
     */
    private function checkFor(VerificationSnapshot $checks, string $basename): ?string
    {
        $tool = trim(explode('.', ltrim($basename, '.'))[0]);

        if ($tool === '') {
            return null;
        }

        foreach ($checks->commands() as $check) {
            $haystack = strtolower($check->name() . ' ' . implode(' ', $check->command()));

            if (str_contains($haystack, $tool)) {
                return $check->name();
            }
        }

        return null;
    }

    /**
     * The scripts the frozen checks run, each mapped to the check that runs it.
     *
     * `composer test`, `composer run test`, `npm run test`, `make test`: the
     * first argument that is not the program and not a flag or a subcommand.
     * Anything else — `phpunit`, `./bin/check` — runs no script and contributes
     * nothing here.
     *
     * @return array<non-empty-string, string>
     */
    private static function scriptsIn(VerificationSnapshot $checks): array
    {
        $scripts = [];

        foreach ($checks->commands() as $check) {
            $command = $check->command();
            $program = strtolower(basename($command[0] ?? ''));

            if (!in_array($program, self::SCRIPT_RUNNERS, true)) {
                continue;
            }

            foreach (array_slice($command, 1) as $argument) {
                if (str_starts_with($argument, '-') || in_array($argument, ['run', 'run-script', 'exec'], true)) {
                    continue;
                }

                if ($argument !== '') {
                    $scripts[$argument] = $check->name();
                }

                break;
            }
        }

        return $scripts;
    }

    /**
     * Where a script of this name is declared, as a pattern.
     *
     * Two spellings, which covers the manifests that matter: `"test":` in JSON
     * and `test:` as a Make target. The name is quoted, because a script may
     * legitimately be called `test-all` or `check.unit` and a bare
     * concatenation would make those characters pattern syntax.
     *
     * @return non-empty-string
     */
    private static function declarationOf(string $script): string
    {
        return '/(?:"|^|\s)' . preg_quote($script, '/') . '(?:"\s*:|\s*:)/';
    }

    /**
     * The repository-relative paths a check's command names.
     *
     * An element is one when it has a directory separator in it and does not
     * leave the repository: `./bin/check` and `tools/verify.php` do, `composer`
     * and `/usr/bin/phpunit` do not. An absolute path is outside the diff by
     * definition, and a name with no separator is a program found on the search
     * path or a script name the tool resolves itself.
     *
     * @return list<string>
     */
    private static function repositoryPathsIn(VerificationCommand $check): array
    {
        $paths = [];

        foreach ($check->command() as $argument) {
            $path = str_starts_with($argument, './') ? substr($argument, 2) : $argument;

            if ($path === '' || !str_contains($path, '/')) {
                continue;
            }

            if (str_starts_with($path, '/') || str_starts_with($path, '../') || str_contains($path, '/../')) {
                continue;
            }

            $paths[] = rtrim($path, '/');
        }

        return array_values(array_unique($paths));
    }

    /**
     * Whether a path is part of the project's suite.
     */
    private static function isTestPath(string $path): bool
    {
        foreach (self::TEST_FILENAMES as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        $segments = explode('/', $path);
        array_pop($segments);

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::TEST_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the patch where a person can read it.
     *
     * A write that fails is noted and does not change the verdict. The finding
     * is the thing that matters, the staged index in the preserved workspace is
     * the same diff, and turning "your diff weakens the tests" into "the diff
     * file could not be written" would hide the reason the pass stopped behind
     * a filesystem problem.
     */
    private function preserve(
        ChecksAudit $audit,
        StagedPatch $patch,
        string $path,
        ?OutputSink $sink,
    ): ChecksAudit {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $sink?->note(sprintf('the staged diff could not be kept: %s cannot be created', $directory));

            return $audit;
        }

        if (@file_put_contents($path, $patch->text()) === false) {
            $sink?->note(sprintf('the staged diff could not be kept at %s', $path));

            return $audit;
        }

        $sink?->note(sprintf('staged diff kept at %s (%d bytes)', $path, $patch->bytes()));

        return $audit->keptAt($path);
    }
}
