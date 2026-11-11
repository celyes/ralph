<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Example;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskList;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Fixture\FixtureWorker;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\Composer;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\Paths;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * That the shipped example project is a project, and that the documented way
 * into it works (PROJECT.md section J, item 10).
 *
 * `examples/text-tools/` is the only part of this repository a reader is
 * invited to copy and run, and it is the part nothing else in the suite
 * touches: its PHP is not autoloaded here, its tests are not in this suite,
 * and its `.ralph/` files are hand-written rather than generated. So all four
 * ways it can rot are silent — a test of the example package that fails, a
 * `config.json` the validator no longer accepts, a checklist the reader reads
 * differently, and a setting renamed in `Defaults` but not in the example.
 *
 * What is asserted here is therefore the example as a reader meets it:
 *
 *  - the package's own suite passes, run the way its `phpunit.xml.dist` says;
 *  - the real {@see ConfigurationLoader} accepts its configuration, and every
 *    path in it exists;
 *  - the real {@see ChecklistReader} finds exactly the tasks the example
 *    documents, each with its acceptance criteria attached;
 *  - `ralph init` in a copy of it keeps all three shipped files, and `ralph
 *    status` then reports the work;
 *  - and a run really reaches a commit and a ticked checkbox, with the
 *    example's own `composer install` and `composer test` doing the work in the
 *    task's worktree.
 *
 * No agent CLI and no credentials (section J, item 8): the agent is the
 * deterministic fixture worker, pointed at by the `codex` entry the copy's
 * configuration is rewritten with.
 */
#[CoversNothing]
final class ExampleProjectTest extends TestCase
{
    /**
     * The example's directory, relative to the package root.
     */
    private const string EXAMPLE = 'examples/text-tools';

    /**
     * The tasks the example ships, in the order the file states them.
     *
     * Written out rather than counted, because the example's README and
     * `CONTEXT.md` describe these three in this order: a task reworded in the
     * checklist and nowhere else is the drift this catches.
     *
     * @var list<string>
     */
    private const array TASKS = [
        'Add a maximum length to the slug generator',
        'Make Excerpt cut on a word boundary instead of mid-word',
        'Add a ReadingTime class that estimates how long a text takes to read',
    ];

    /**
     * Seconds a run of the example is given.
     *
     * It installs PHPUnit into a worktree and then runs it, so the default
     * minute is not enough on a cold Composer cache.
     */
    private const int RUN_TIMEOUT_SECONDS = 600;

    /**
     * Directories to delete when the test that made them is done.
     *
     * @var list<string>
     */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            TemporaryDirectory::remove($directory);
        }

        $this->temporary = [];
    }

    #[Test]
    public function the_example_packages_own_test_suite_passes(): void
    {
        $phpunit = Paths::inPackage('vendor/bin/phpunit');

        if (!is_file($phpunit)) {
            self::markTestSkipped('PHPUnit is not installed as a binary, so the example suite cannot be run.');
        }

        $project = $this->copyOfTheExample();

        // The example declares PHPUnit as a development dependency and loads
        // `vendor/autoload.php`, which is Composer's business and needs a
        // network. Both are stood in for here: this suite's own PHPUnit binary
        // runs the example's configuration, and the autoloader it bootstraps is
        // written from the example's *own* PSR-4 declarations — so a namespace
        // or directory renamed in one place and not the other fails here rather
        // than only for a reader who ran `composer install`.
        $this->writeStandInAutoloader($project);

        $result = Cli::runCommand([$phpunit, '--configuration', 'phpunit.xml.dist', '--colors=never'], $project);

        self::assertSame(0, $result->exitCode, 'The example package\'s suite does not pass: ' . $result->describe());
        self::assertStringContainsString('OK (', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_examples_configuration_is_one_the_loader_accepts(): void
    {
        $configuration = (new ConfigurationLoader())->load(Paths::inPackage(self::EXAMPLE));

        self::assertFileExists($configuration->tasksFile());
        self::assertFileExists($configuration->contextFile());

        // The example is run with whichever agent the reader has, so both
        // built-in entries have to be there and the default has to be one of
        // them.
        self::assertSame(['codex', 'claude'], $configuration->agentNames());
        self::assertContains($configuration->defaultAgent(), $configuration->agentNames());

        // The two commands the example's README promises, and which the run
        // below really executes.
        $checks = $configuration->checks();
        self::assertCount(1, $checks);
        self::assertSame(['composer', 'test'], $checks[0]->command());

        $prepare = $configuration->workspace()->prepare();
        self::assertCount(1, $prepare);
        self::assertSame(['composer', 'install', '--no-interaction'], $prepare[0]->command());
        self::assertSame([], $configuration->workspace()->copy(), 'The example needs no file from the reader\'s checkout.');

        // One iteration per task it ships: an example that stopped at its
        // iteration limit halfway through its own checklist would read as a
        // failure.
        self::assertSame(count(self::TASKS), $configuration->limits()->iterations());

        // Nothing is pushed by an example run: the reader has no remote for a
        // directory they copied a minute ago.
        self::assertFalse($configuration->git()->push()->isEnabled());

        // Every key the file sets is a key the current configuration has. An
        // unknown one is already rejected by the loader; this is the other
        // direction — a setting that gained a nested key stays visible in the
        // example that is meant to show them all.
        self::assertSame(
            array_keys(Defaults::toArray()),
            array_keys($this->exampleConfigurationDocument()),
        );
    }

    #[Test]
    public function the_examples_checklist_states_the_tasks_it_documents(): void
    {
        $checklist = (new ChecklistReader())->readFile(Paths::inPackage(self::EXAMPLE . '/.ralph/tasks.md'));
        $tasks = TaskList::fromChecklist($checklist);

        self::assertSame(count(self::TASKS), $tasks->pendingCount());
        self::assertSame(0, $tasks->doneCount(), 'The example ships its work undone.');
        self::assertSame(self::TASKS, array_map(
            static fn (Task $task): string => $task->description(),
            $tasks->all(),
        ));

        $identifiers = [];

        foreach ($tasks->all() as $task) {
            self::assertSame(
                Completion::Pending,
                $tasks->completionOf($task->id()),
                $task->description() . ' is not pending.',
            );

            // Every example task says how it is verified, which is the advice
            // the README gives about writing one. A task that lost its criteria
            // would still run, and would be a worse example than none.
            self::assertTrue($task->item()->hasDetails(), $task->description() . ' has no acceptance criteria.');

            $identifiers[] = $task->id()->value();
        }

        self::assertSame($identifiers, array_values(array_unique($identifiers)));
    }

    #[Test]
    public function initialising_a_copy_of_the_example_keeps_the_files_it_ships(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not available, so the documented setup cannot be followed.');
        }

        $project = $this->repositoryFromTheExample();

        $init = Cli::run(['init'], $project);
        self::assertSame(ExitCode::SUCCESS, $init->exitCode, $init->describe());

        // The first release acceptance criterion, against the example: the
        // reader's three files survive an `init` that is run *after* them.
        foreach (['.ralph/config.json', '.ralph/tasks.md', '.ralph/CONTEXT.md'] as $relative) {
            self::assertStringEqualsFile(
                $project . '/' . $relative,
                (string) file_get_contents(Paths::inPackage(self::EXAMPLE . '/' . $relative)),
                $relative . ' was not left as the example ships it.',
            );
        }

        foreach (['runtime', 'runtime/logs', 'runtime/memory', 'runtime/worktrees'] as $relative) {
            self::assertDirectoryExists($project . '/.ralph/' . $relative);
        }

        // The example tracks its own checklist, so that the checkbox a run
        // ticks shows up in `git diff`. Its ignore file negates the rule, and a
        // negated rule is left negated — if that ever stopped being true, the
        // example would quietly stop demonstrating what it says it does.
        $ignore = (string) file_get_contents($project . '/.gitignore');
        self::assertStringContainsString('!/.ralph/tasks.md', $ignore);
        // `check-ignore` answers 1 for a path that is not ignored, which is
        // the answer this example needs: its checklist is a file the reader
        // commits.
        $ignored = Git::tryRun($project, 'check-ignore', '--no-index', '.ralph/tasks.md');
        self::assertSame(
            1,
            $ignored->exitCode,
            'The example\'s checklist is ignored, so a run\'s tick would not be visible: ' . $ignored->describe(),
        );

        $status = Cli::run(['status', '--json'], $project);
        self::assertSame(ExitCode::SUCCESS, $status->exitCode, $status->describe());

        $document = JsonDocument::parse($status->standardOutput);
        self::assertSame(count(self::TASKS), $document->integer('progress.total'));
        self::assertSame(count(self::TASKS), $document->integer('progress.remaining'));
        self::assertSame(self::TASKS, array_map(
            static fn (JsonDocument $task): string => $task->string('description'),
            $document->objects('tasks'),
        ));
    }

    /**
     * The whole point of the example, run: one task from the checklist to a
     * commit and a ticked box, verified by the example's own suite in the
     * task's own worktree.
     *
     * The agent is the fixture worker rather than Codex or Claude Code, and
     * what it writes is the smallest change that makes the example's checklist
     * item true — not a reference answer to it. What is being tested is the
     * example's configuration: that `composer install` makes a bare worktree a
     * project PHPUnit runs in, and that `composer test` there is a check Ralph
     * PHP can gate a commit on. Real agents on these tasks are section J,
     * item 11.
     */
    #[Test]
    #[Group('example-run')]
    public function a_run_implements_the_first_example_task_and_ticks_it_off(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not available, so a run cannot be made.');
        }

        if (Composer::binary() === null) {
            self::markTestSkipped('Composer is not installed, so the example\'s workspace cannot be prepared.');
        }

        $project = $this->repositoryFromTheExample();
        $tools = TemporaryDirectory::create('ralph-example-tools');
        $this->temporary[] = $tools;

        $worker = FixtureWorker::install($tools, [
            FixtureStep::success($this->slugLengthImplementation())
                ->saying('Added an optional maximum length to Slugger and covered it.'),
        ]);

        // Two changes to the shipped configuration, and no more: the agent is
        // the fixture worker, and the run is allowed one task rather than
        // three. The checks, the workspace preparation, the branch prefix and
        // the attempt limit are the example's own. One task, because the
        // fixture implements this one, and a fixture repeating that work
        // against the next item would tick off a task nobody did.
        $this->configureOneTaskWith($project, $worker->path());

        $run = Cli::run(['run'], $project, timeout: self::RUN_TIMEOUT_SECONDS);

        // The iteration limit, reached with two tasks still pending: the
        // documented ending for a run that did its work and was told to stop.
        self::assertSame(ExitCode::LIMIT, $run->exitCode, $run->describe());
        self::assertCount(1, $worker->attempts(), 'The agent was not invoked exactly once: ' . $run->describe());

        $status = JsonDocument::parse(Cli::run(['status', '--json'], $project)->standardOutput);
        $first = $status->objects('tasks')[0];

        self::assertSame('completed', $first->string('state'), $run->describe());
        self::assertSame(Completion::RunnerVerified->value, $first->string('completion'), $run->describe());
        self::assertTrue($first->boolean('checked'), 'The finished task was not ticked off: ' . $run->describe());

        $commit = $first->string('commit');
        $branch = $first->string('branch');

        self::assertStringStartsWith('ralph/', $branch);

        // The commit is in the repository the reader copied, on the task's own
        // branch, and it carries the example's change rather than an empty
        // tree.
        self::assertSame(
            $commit,
            trim(Git::run($project, 'rev-parse', $branch)->standardOutput),
            'The task branch does not point at the recorded commit.',
        );

        $files = Git::run($project, 'show', '--name-only', '--format=', $commit)->standardOutputLines();
        self::assertContains('src/Slugger.php', $files, $run->describe());

        // The reader's own checkout holds the tick and nothing else: no
        // worktree, no vendor directory, no runtime data in the commit.
        self::assertNotContains('.ralph/tasks.md', $files, 'The run committed the controlling checkout\'s checklist.');
        self::assertStringContainsString(
            '- [x] ' . self::TASKS[0],
            (string) file_get_contents($project . '/.ralph/tasks.md'),
        );

        // And the verification really ran the example's suite, so the evidence
        // names the check the configuration asks for.
        self::assertSame('tests', $first->string('verification.checks.0.name'), $run->describe());
        self::assertTrue($first->boolean('verification.passed'), $run->describe());
    }

    /**
     * A throwaway copy of the example, as `cp -r` leaves it.
     *
     * @throws RuntimeException when the copy cannot be made
     */
    private function copyOfTheExample(): string
    {
        $directory = TemporaryDirectory::create('ralph-example');
        $this->temporary[] = $directory;

        $project = $directory . '/text-tools';

        self::copyTree(Paths::inPackage(self::EXAMPLE), $project);

        return $project;
    }

    /**
     * That copy, as the example's README says to make it: a Git repository with
     * the example in its first commit.
     */
    private function repositoryFromTheExample(): string
    {
        $project = $this->copyOfTheExample();

        Git::initialise($project);
        Git::setIdentity($project);
        // The suite runs on Linux and on WSL, where a developer may have
        // autocrlf on; the example's files are written with LF endings and the
        // assertions here compare them byte for byte.
        Git::run($project, 'config', 'core.autocrlf', 'false');
        Git::run($project, 'add', '--all');
        Git::commit($project, 'Import the example package');

        return $project;
    }

    /**
     * Rewrite the copy's agent executable and iteration limit, and commit the
     * change.
     *
     * @throws JsonException when the example's configuration is not readable as
     *                       JSON, which the loader test reports first
     */
    private function configureOneTaskWith(string $project, string $executable): void
    {
        $document = $this->exampleConfigurationDocument();

        /** @var array<string, array<string, mixed>> $agents */
        $agents = $document['agents'];
        $agents['codex']['executable'] = $executable;
        $document['agents'] = $agents;

        /** @var array<string, mixed> $limits */
        $limits = $document['limits'];
        $limits['iterations'] = 1;
        $document['limits'] = $limits;

        file_put_contents(
            $project . '/.ralph/config.json',
            json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        Git::run($project, 'add', '.ralph/config.json');
        Git::commit($project, 'Point the example at the fixture worker');
    }

    /**
     * The example's configuration as a decoded document.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when it is not readable as JSON
     */
    private function exampleConfigurationDocument(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(Paths::inPackage(self::EXAMPLE . '/.ralph/config.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * What the fixture worker writes when it is given the first example task.
     *
     * A maximum length on `Slugger`, cut at a separator boundary, and one test
     * file covering it. The existing `tests/SluggerTest.php` is left alone, so
     * the check also proves the default behaviour is unchanged — which is what
     * the checklist item asks for.
     *
     * @return array<string, string>
     */
    private function slugLengthImplementation(): array
    {
        return [
            'src/Slugger.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace RalphPhpExample\TextTools;

                use InvalidArgumentException;

                final class Slugger
                {
                    /** @var list<string> */
                    private const array SEPARATORS = ['-', '_'];

                    public function __construct(
                        private readonly string $separator = '-',
                        private readonly ?int $maxLength = null,
                    ) {
                        if (!in_array($separator, self::SEPARATORS, true)) {
                            throw new InvalidArgumentException(
                                sprintf('The slug separator must be "-" or "_", got "%s".', $separator),
                            );
                        }

                        if ($maxLength !== null && $maxLength < 1) {
                            throw new InvalidArgumentException(
                                sprintf('A slug may not be limited to %d characters.', $maxLength),
                            );
                        }
                    }

                    public function slugify(string $title): string
                    {
                        $lowercase = mb_strtolower($title, 'UTF-8');
                        $separated = preg_replace('/[^\p{L}\p{N}]+/u', $this->separator, $lowercase) ?? '';
                        $slug = trim($separated, $this->separator);

                        if ($this->maxLength === null || mb_strlen($slug, 'UTF-8') <= $this->maxLength) {
                            return $slug;
                        }

                        $cut = mb_substr($slug, 0, $this->maxLength, 'UTF-8');
                        $boundary = mb_strrpos($cut, $this->separator, 0, 'UTF-8');

                        return $boundary === false || $boundary === 0 ? $cut : mb_substr($cut, 0, $boundary, 'UTF-8');
                    }
                }

                PHP,
            'tests/SluggerMaximumLengthTest.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace RalphPhpExample\TextTools\Tests;

                use InvalidArgumentException;
                use PHPUnit\Framework\Attributes\Test;
                use PHPUnit\Framework\TestCase;
                use RalphPhpExample\TextTools\Slugger;

                final class SluggerMaximumLengthTest extends TestCase
                {
                    #[Test]
                    public function it_cuts_a_long_slug_at_a_separator(): void
                    {
                        self::assertSame('a-tiny-php', (new Slugger('-', 12))->slugify('A Tiny PHP Package'));
                    }

                    #[Test]
                    public function it_cuts_a_single_long_word_at_the_limit(): void
                    {
                        self::assertSame('internation', (new Slugger('-', 11))->slugify('Internationalisation'));
                    }

                    #[Test]
                    public function it_leaves_a_short_slug_alone(): void
                    {
                        self::assertSame('short-one', (new Slugger('-', 40))->slugify('Short one'));
                    }

                    #[Test]
                    public function it_rejects_a_limit_below_one_character(): void
                    {
                        $this->expectException(InvalidArgumentException::class);

                        new Slugger('-', 0);
                    }
                }

                PHP,
        ];
    }

    /**
     * Write a `vendor/autoload.php` into a copy, from the example's own PSR-4
     * declarations.
     *
     * Composer's own autoloader would need a network and a minute; what the
     * example's suite needs from it is the two prefixes its `composer.json`
     * declares.
     *
     * @throws JsonException when the example's manifest is not readable as JSON
     */
    private function writeStandInAutoloader(string $project): void
    {
        $manifest = json_decode(
            (string) file_get_contents($project . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest);

        /** @var array<string, mixed> $manifest */
        $prefixes = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $mapping = $manifest[$section] ?? null;
            self::assertIsArray($mapping, 'The example declares no ' . $section . ' section.');

            $declared = $mapping['psr-4'] ?? null;
            self::assertIsArray($declared, 'The example declares no ' . $section . '.psr-4 mapping.');

            /** @var array<string, string> $declared */
            foreach ($declared as $prefix => $directory) {
                $prefixes[$prefix] = $directory;
            }
        }

        // Longest prefix first, so that the test namespace is not swallowed by
        // the source one it sits under.
        uksort($prefixes, static fn (string $one, string $other): int => strlen($other) <=> strlen($one));

        $directory = $project . '/vendor';

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }

        file_put_contents($directory . '/autoload.php', sprintf(
            <<<'PHP'
            <?php

            // Written by %s: a stand-in for Composer's autoloader.
            $prefixes = %s;

            spl_autoload_register(static function (string $class) use ($prefixes): void {
                foreach ($prefixes as $prefix => $directory) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }

                    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                    $file = __DIR__ . '/../' . trim($directory, '/') . '/' . $relative . '.php';

                    if (is_file($file)) {
                        require $file;
                    }

                    return;
                }
            });

            PHP,
            self::class,
            var_export($prefixes, true),
        ));
    }

    /**
     * Copy a directory tree, as the reader's `cp -r` does.
     *
     * @throws RuntimeException when a directory or file cannot be copied
     */
    private static function copyTree(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0o777, true) && !is_dir($destination)) {
            throw new RuntimeException('Unable to create ' . $destination . '.');
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($from)) {
                self::copyTree($from, $to);

                continue;
            }

            if (!copy($from, $to)) {
                throw new RuntimeException('Unable to copy ' . $from . ' to ' . $to . '.');
            }
        }
    }
}
