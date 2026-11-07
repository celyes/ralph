<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Verification;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\StagingException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspacePreparer;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use Celyes\RalphPhp\Verification\ChecksAudit;
use Celyes\RalphPhp\Verification\ChecksAuditor;
use Celyes\RalphPhp\Verification\Weakening;
use Celyes\RalphPhp\Verification\WeakeningKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a verified task's diff for changes that leave the project's own
 * verification weaker than they found it (PROJECT.md section G, item 5).
 *
 * Every case stages real files in a real linked worktree and audits what
 * {@see WorkspaceStager} decided the commit would contain, because the question
 * the item asks is about a commit and not about a workspace: a test file an
 * agent deleted and the stager excluded has weakened nothing, and only a real
 * staging step can tell the two apart.
 *
 * The cases come in pairs wherever a rule has an edge, and the second of each
 * pair is the one that matters. A rule that blocks every deleted test file is
 * easy; a rule that blocks a deleted test file and *not* a renamed one, not a
 * case moved between two files, and not a new test with an environment guard
 * in it, is a rule a loop can run under.
 */
#[CoversClass(ChecksAuditor::class)]
#[CoversClass(ChecksAudit::class)]
#[CoversClass(Weakening::class)]
#[CoversClass(WeakeningKind::class)]
final class ChecksAuditorTest extends TestCase
{
    /**
     * A test file as the project has it committed: two cases, four assertions.
     */
    private const string SUITE = <<<'PHP'
        <?php

        final class GreetingTest
        {
            public function testItGreets(): void
            {
                self::assertSame('hello', greet());
                self::assertNotEmpty(greet());
            }

            public function testItFarewells(): void
            {
                self::assertSame('bye', farewell());
                self::assertNotEmpty(farewell());
            }
        }

        PHP;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this reads diffs staged in real worktrees.');
        }

        $this->project = TemporaryProject::create();
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('src/Greeting.php', "<?php\n\nfunction greet(): string { return 'hello'; }\n");
        $this->project->write('tests/GreetingTest.php', self::SUITE);
        $this->project->write('phpunit.xml.dist', $this->phpunitConfiguration());
        $this->project->write('composer.json', $this->composerManifest());
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    protected function tearDown(): void
    {
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
    }

    #[Test]
    public function an_ordinary_diff_that_adds_work_and_a_test_for_it_is_clean(): void
    {
        $workspace = $this->givenAWorkspace();

        $this->inWorkspace($workspace, 'src/Farewell.php', "<?php\n\nfunction farewell(): string { return 'bye'; }\n");
        $this->inWorkspace($workspace, 'tests/FarewellTest.php', <<<'PHP'
            <?php

            final class FarewellTest
            {
                public function testItFarewells(): void
                {
                    self::assertSame('bye', farewell());
                }
            }

            PHP);

        $audit = $this->audit($workspace);

        self::assertTrue($audit->isClean());
        self::assertFalse($audit->isBlocking());
        self::assertSame(['tests/FarewellTest.php'], $audit->examined());
        self::assertSame([], $audit->hints());
        self::assertStringContainsString('nothing weakened', $audit->describe());

        // Nothing to review means nothing written: a run that kept a patch for
        // every task would fill the log directory with diffs nobody asked for.
        self::assertNull($audit->diffPath());
        self::assertFileDoesNotExist($this->diffPath());
    }

    #[Test]
    public function deleting_a_test_file_blocks_and_the_diff_is_kept(): void
    {
        $workspace = $this->givenAWorkspace();

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\nfunction greet(): string { return 'HELLO'; }\n");
        unlink($workspace->path() . '/tests/GreetingTest.php');

        $sink = new RecordingSink();
        $audit = $this->audit($workspace, sink: $sink);

        self::assertTrue($audit->isBlocking());
        self::assertTrue($audit->has(WeakeningKind::TestsDeleted));

        $finding = $audit->first(WeakeningKind::TestsDeleted);
        self::assertNotNull($finding);
        self::assertSame(['tests/GreetingTest.php'], $finding->paths());
        self::assertStringContainsString('is deleted by this diff', $finding->summary());

        // The patch is kept where it was asked for, and it is the patch rather
        // than a description of it: a reviewer should be able to read the
        // deletion itself.
        self::assertSame($this->diffPath(), $audit->diffPath());
        self::assertFileExists($this->diffPath());

        $kept = (string) file_get_contents($this->diffPath());
        self::assertStringContainsString('diff --git a/tests/GreetingTest.php', $kept);
        self::assertStringContainsString('-    public function testItGreets(): void', $kept);

        // And the limit of the reading is stated to the person who hit it, not
        // only in a docblock.
        self::assertStringContainsString('not comprehensive', implode(' ', $audit->hints()));
        self::assertStringContainsString($this->diffPath(), implode(' ', $audit->hints()));
        self::assertStringContainsString('weakens', implode("\n", $sink->notes));
    }

    #[Test]
    public function a_test_file_the_stager_left_out_of_the_commit_weakens_nothing(): void
    {
        // The reading is of the commit, not of the workspace. A path the
        // project declares as local is never committed, so deleting it in a
        // throwaway worktree cannot have changed what the project verifies.
        $this->project->write('tests/LocalOnlyTest.php', self::SUITE);
        $configuration = $this->configuration([
            'workspace' => ['prepare' => [], 'copy' => ['tests/LocalOnlyTest.php']],
        ]);

        $workspace = $this->givenAWorkspace($configuration);
        WorkspacePreparer::forProject($configuration)->prepare($workspace);

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\nfunction greet(): string { return 'HELLO'; }\n");
        unlink($workspace->path() . '/tests/LocalOnlyTest.php');

        $audit = $this->audit($workspace, $configuration);

        self::assertTrue($audit->isClean());
        self::assertSame([], $audit->examined());
    }

    #[Test]
    public function a_test_renamed_within_the_suite_is_clean_and_one_renamed_out_of_it_is_not(): void
    {
        $moved = $this->givenAWorkspace();
        $this->inWorkspace($moved, 'tests/Unit/GreetingTest.php', self::SUITE);
        unlink($moved->path() . '/tests/GreetingTest.php');

        self::assertTrue($this->audit($moved)->isClean());

        $out = $this->givenAWorkspace(
            generation: TaskGeneration::first(TaskId::fromDescription('Moved out of the suite')),
        );
        $this->inWorkspace($out, 'docs/greeting-example.php', self::SUITE);
        unlink($out->path() . '/tests/GreetingTest.php');

        $audit = $this->audit($out, generation: $out->generation());
        $finding = $audit->first(WeakeningKind::TestsDeleted);

        self::assertNotNull($finding);
        self::assertSame(['tests/GreetingTest.php', 'docs/greeting-example.php'], $finding->paths());
        self::assertStringContainsString('where tests are not looked for', $finding->summary());
    }

    #[Test]
    public function removing_a_test_case_blocks_and_moving_one_between_files_does_not(): void
    {
        $removed = $this->givenAWorkspace();
        $this->inWorkspace($removed, 'tests/GreetingTest.php', <<<'PHP'
            <?php

            final class GreetingTest
            {
                public function testItGreets(): void
                {
                    self::assertSame('hello', greet());
                    self::assertNotEmpty(greet());
                }
            }

            PHP);

        $audit = $this->audit($removed);
        $finding = $audit->first(WeakeningKind::CasesRemoved);

        self::assertNotNull($finding);
        self::assertSame(['tests/GreetingTest.php'], $finding->paths());
        self::assertStringContainsString('removes 1 test case declaration and adds 0', $finding->summary());
        self::assertSame(['public function testItFarewells(): void'], $finding->quoted());

        // The same case in a new file is a refactor, and the count is taken
        // across the whole diff so that it nets to nothing.
        $moved = $this->givenAWorkspace(
            generation: TaskGeneration::first(TaskId::fromDescription('Split the suite')),
        );
        $this->inWorkspace($moved, 'tests/GreetingTest.php', <<<'PHP'
            <?php

            final class GreetingTest
            {
                public function testItGreets(): void
                {
                    self::assertSame('hello', greet());
                    self::assertNotEmpty(greet());
                }
            }

            PHP);
        $this->inWorkspace($moved, 'tests/FarewellTest.php', <<<'PHP'
            <?php

            final class FarewellTest
            {
                public function testItFarewells(): void
                {
                    self::assertSame('bye', farewell());
                    self::assertNotEmpty(farewell());
                }
            }

            PHP);

        self::assertTrue($this->audit($moved, generation: $moved->generation())->isClean());
    }

    #[Test]
    public function emptying_a_test_of_its_assertions_blocks_even_though_the_case_remains(): void
    {
        // The weakening that keeps the suite's shape and removes its meaning:
        // same two cases, same green run, nothing proved.
        $workspace = $this->givenAWorkspace();
        $this->inWorkspace($workspace, 'tests/GreetingTest.php', <<<'PHP'
            <?php

            final class GreetingTest
            {
                public function testItGreets(): void
                {
                    greet();
                }

                public function testItFarewells(): void
                {
                    self::assertSame('bye', farewell());
                    self::assertNotEmpty(farewell());
                }
            }

            PHP);

        $audit = $this->audit($workspace);
        $finding = $audit->first(WeakeningKind::AssertionsRemoved);

        self::assertNotNull($finding);
        self::assertNull($audit->first(WeakeningKind::CasesRemoved));
        self::assertStringContainsString('removes 2 assertions from test files and adds 0', $finding->summary());
        self::assertContains("self::assertSame('hello', greet());", $finding->quoted());
    }

    #[Test]
    public function a_skip_added_to_an_existing_test_blocks_and_one_in_a_new_test_does_not(): void
    {
        $skipped = $this->givenAWorkspace();
        $this->inWorkspace($skipped, 'tests/GreetingTest.php', str_replace(
            "        self::assertSame('hello', greet());",
            "        self::markTestSkipped('flaky');\n        self::assertSame('hello', greet());",
            self::SUITE,
        ));

        $audit = $this->audit($skipped);
        $finding = $audit->first(WeakeningKind::CasesDisabled);

        self::assertNotNull($finding);
        self::assertStringContainsString('adds 1 marker that stops a test from reporting', $finding->summary());
        self::assertSame(["self::markTestSkipped('flaky');"], $finding->quoted());

        // A new test file guarding itself against a missing program has made
        // the suite larger, not weaker — the item is about *existing*
        // verification, and this package's own suite is full of such guards.
        $added = $this->givenAWorkspace(
            generation: TaskGeneration::first(TaskId::fromDescription('Add a Git-dependent test')),
        );
        $this->inWorkspace($added, 'tests/GitTest.php', <<<'PHP'
            <?php

            final class GitTest
            {
                public function testItReadsARepository(): void
                {
                    if (!gitIsAvailable()) {
                        self::markTestSkipped('Git is not installed.');
                    }

                    self::assertTrue(readRepository());
                }
            }

            PHP);

        self::assertTrue($this->audit($added, generation: $added->generation())->isClean());
    }

    #[Test]
    public function excluding_a_directory_from_phpunit_blocks_and_adding_a_suite_does_not(): void
    {
        $narrowed = $this->givenAWorkspace();
        $this->inWorkspace($narrowed, 'phpunit.xml.dist', str_replace(
            '        <directory>tests</directory>',
            "        <directory>tests</directory>\n        <exclude>tests/Slow</exclude>",
            $this->phpunitConfiguration(),
        ));

        $audit = $this->audit($narrowed, checks: $this->checks([
            new VerificationCommand('phpunit', ['vendor/bin/phpunit'], 60),
        ]));
        $finding = $audit->first(WeakeningKind::ConfigurationNarrowed);

        self::assertNotNull($finding);
        self::assertSame(['phpunit.xml.dist'], $finding->paths());
        self::assertStringContainsString('configures the check "phpunit"', $finding->summary());
        self::assertSame(['<exclude>tests/Slow</exclude>'], $finding->quoted());

        // A second suite is more verification, not less, and a rule that
        // reported it would mean nothing.
        $widened = $this->givenAWorkspace(
            generation: TaskGeneration::first(TaskId::fromDescription('Add an integration suite')),
        );
        $this->inWorkspace($widened, 'phpunit.xml.dist', str_replace(
            "    </testsuite>\n",
            implode("\n", [
                '    </testsuite>',
                '    <testsuite name="integration">',
                '        <directory>integration</directory>',
                '    </testsuite>',
                '',
            ]),
            $this->phpunitConfiguration(),
        ));

        self::assertTrue($this->audit($widened, generation: $widened->generation())->isClean());
    }

    #[Test]
    public function rewriting_the_script_a_check_runs_blocks_and_adding_a_dependency_does_not(): void
    {
        // `composer test` is the default check and `composer.json` is the file
        // an ordinary task most often has a reason to touch, so the two sides
        // of this case are what makes the rule usable at all.
        $rewritten = $this->givenAWorkspace();
        $this->inWorkspace($rewritten, 'composer.json', str_replace(
            '"test": "phpunit"',
            '"test": "phpunit --testsuite=fast || true"',
            $this->composerManifest(),
        ));

        $audit = $this->audit($rewritten);
        $finding = $audit->first(WeakeningKind::CommandChanged);

        self::assertNotNull($finding);
        self::assertSame(['composer.json'], $finding->paths());
        self::assertStringContainsString(
            'defines the "test" script that the check "tests" runs',
            $finding->summary(),
        );
        self::assertContains('"test": "phpunit --testsuite=fast || true"', $finding->quoted());

        // The case this rule exists to get right. Adding a dependency to a JSON
        // manifest *removes* the line above the new one, to put a comma on it,
        // so a rule about lost lines would block half the tasks a loop is for.
        $dependency = $this->givenAWorkspace(
            generation: TaskGeneration::first(TaskId::fromDescription('Add a dependency')),
        );
        $this->inWorkspace($dependency, 'composer.json', str_replace(
            '        "php": "^8.3"',
            "        \"php\": \"^8.3\",\n        \"psr/log\": \"^3.0\"",
            $this->composerManifest(),
        ));

        $clean = $this->audit($dependency, generation: $dependency->generation());

        self::assertTrue($clean->isClean(), implode(' | ', $clean->details()));
    }

    #[Test]
    public function a_manifest_is_passed_over_when_no_frozen_check_runs_a_script(): void
    {
        // A project whose checks name their tools directly has nothing in its
        // manifest that decides a verdict, and a finding there would be about a
        // file this run never reads.
        $workspace = $this->givenAWorkspace();
        $this->inWorkspace($workspace, 'composer.json', str_replace(
            '"test": "phpunit"',
            '"test": "phpunit --testsuite=fast"',
            $this->composerManifest(),
        ));

        $audit = $this->audit($workspace, checks: $this->checks([
            new VerificationCommand('phpunit', ['vendor/bin/phpunit'], 60),
        ]));

        self::assertTrue($audit->isClean(), implode(' | ', $audit->details()));
    }

    #[Test]
    public function changing_the_program_a_check_runs_blocks(): void
    {
        // The complete bypass: whatever `bin/check` does after the diff is what
        // "the checks passed" then means.
        $this->project->write('bin/check', "#!/bin/sh\nexec phpunit \"$@\"\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'Add the check script');

        $workspace = $this->givenAWorkspace();
        $this->inWorkspace($workspace, 'bin/check', "#!/bin/sh\nexit 0\n");

        $audit = $this->audit($workspace, checks: $this->checks([
            new VerificationCommand('suite', ['sh', './bin/check'], 60),
        ]));
        $finding = $audit->first(WeakeningKind::CommandChanged);

        self::assertNotNull($finding);
        self::assertSame(['bin/check'], $finding->paths());
        self::assertStringContainsString('the check "suite" runs bin/check', $finding->summary());

        // A directory a check is pointed at is where the work is, not what
        // decides the verdict, so changing a file under it is the task doing
        // its job.
        self::assertTrue(
            $this->audit($workspace, checks: $this->checks([
                new VerificationCommand('suite', ['phpunit', 'tests'], 60),
            ]))->isClean(),
        );
    }

    #[Test]
    public function several_findings_are_reported_together_as_evidence(): void
    {
        $workspace = $this->givenAWorkspace();
        unlink($workspace->path() . '/tests/GreetingTest.php');
        $this->inWorkspace($workspace, 'composer.json', str_replace(
            '"test": "phpunit"',
            '"test": "phpunit --no-check"',
            $this->composerManifest(),
        ));

        $audit = $this->audit($workspace);

        self::assertSame(4, $audit->count());
        self::assertTrue($audit->has(WeakeningKind::TestsDeleted));
        self::assertTrue($audit->has(WeakeningKind::CasesRemoved));
        self::assertTrue($audit->has(WeakeningKind::AssertionsRemoved));
        self::assertTrue($audit->has(WeakeningKind::CommandChanged));
        self::assertSame(
            ['tests/GreetingTest.php', 'composer.json'],
            $audit->paths(),
        );

        // What the attempt's row gets: one entry per finding, so the next
        // person reads what was found rather than how many things were.
        $evidence = $audit->evidence();
        self::assertSame(3, $evidence->count());
        self::assertSame(1, $evidence->omitted());
        self::assertStringContainsString('a test file removed', $evidence->toMarkdown());

        $details = implode("\n", $audit->details());
        self::assertStringContainsString('a test file removed:', $details);
        self::assertStringContainsString('in 4 ways', $audit->summary());
    }

    #[Test]
    public function an_index_with_nothing_in_it_is_not_audited_at_all(): void
    {
        $workspace = $this->givenAWorkspace();

        $audit = (new ChecksAuditor())->audit(
            $this->checks(),
            $workspace,
            StagedChanges::nothingStaged($workspace->path()),
            $this->diffPath(),
        );

        self::assertTrue($audit->isClean());
        self::assertFileDoesNotExist($this->diffPath());
    }

    #[Test]
    public function a_workspace_that_cannot_be_read_refuses_rather_than_passing(): void
    {
        // A diff this package could not examine is not one it commits, which is
        // how the stager treats an index it cannot read. The alternative — a
        // clean audit by default — would make every unreadable workspace a
        // silent bypass of the whole item.
        $workspace = $this->givenAWorkspace();
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");
        $staged = WorkspaceStager::forProject($this->configuration())->stage($workspace);

        $gone = TaskWorktree::created(
            $workspace->generation(),
            $this->project->path('.ralph/runtime/worktrees/not-there'),
            $this->base(),
        );

        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('could not be opened to read its staged diff');

        (new ChecksAuditor())->audit($this->checks(), $gone, $staged);
    }

    /**
     * Stage the workspace the way a pass does, then audit what was staged.
     */
    private function audit(
        TaskWorktree $workspace,
        ?Configuration $configuration = null,
        ?VerificationSnapshot $checks = null,
        ?TaskGeneration $generation = null,
        ?RecordingSink $sink = null,
    ): ChecksAudit {
        $configuration ??= $this->configuration();
        $staged = WorkspaceStager::forProject($configuration)->stage($workspace);

        return (new ChecksAuditor())->audit(
            $checks ?? $this->checks(),
            $workspace,
            $staged,
            $this->diffPath($generation ?? $this->generation()),
            $sink,
        );
    }

    /**
     * The checks a run froze, defaulting to this project's `composer test`.
     *
     * @param list<VerificationCommand>|null $commands
     */
    private function checks(?array $commands = null): VerificationSnapshot
    {
        return VerificationSnapshot::ofCommands(
            $commands ?? [new VerificationCommand('tests', ['composer', 'test'], 60)],
            'the test project',
        );
    }

    /**
     * Where a pass would keep the patch for one attempt.
     */
    private function diffPath(?TaskGeneration $generation = null): string
    {
        return sprintf(
            '%s/.ralph/runtime/logs/%s/attempt-01-staged.diff',
            $this->project->root(),
            ($generation ?? $this->generation())->slug(),
        );
    }

    private function givenAWorkspace(
        ?Configuration $configuration = null,
        ?TaskGeneration $generation = null,
    ): TaskWorktree {
        $configuration ??= $this->configuration();

        return TaskWorktrees::forProject($configuration)
            ->prepare($generation ?? $this->generation(), $this->base());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    private function base(): BaseCommit
    {
        return BaseResolver::forProject($this->configuration())->resolve();
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first(TaskId::fromDescription('Make greeting louder'));
    }

    /**
     * Write a file in the workspace, the way an agent would.
     */
    private function inWorkspace(TaskWorktree $workspace, string $relative, string $contents): void
    {
        $path = $workspace->path() . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            self::fail('Could not create ' . $directory);
        }

        file_put_contents($path, $contents);
    }

    private function phpunitConfiguration(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <phpunit>
                <testsuite name="unit">
                    <directory>tests</directory>
                </testsuite>
            </phpunit>

            XML;
    }

    private function composerManifest(): string
    {
        return <<<'JSON'
            {
                "name": "example/project",
                "require": {
                    "php": "^8.3"
                },
                "scripts": {
                    "test": "phpunit"
                }
            }

            JSON;
    }
}
