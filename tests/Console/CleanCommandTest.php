<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\CleanCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Run\RepositoryLock;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph clean` on the command line, after a run that left a workspace behind.
 *
 * The two halves of PROJECT.md section K, item 7 meet here. A failed, blocked
 * or interrupted pass keeps its worktree — which the run itself does, and which
 * the cases below start from rather than assert — and this is the command that
 * clears one up afterwards. What it has to get right is the asymmetry: looking
 * is free and changes nothing, removing has to be asked for, and asking is
 * never enough to delete work that exists in no commit.
 *
 * Every case runs the real binary against a real repository with real linked
 * worktrees, because the behaviour being tested is what is on disk afterwards.
 */
#[CoversClass(CleanCommand::class)]
final class CleanCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private const string OTHER = 'Document the import command in the README';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this removes real linked worktrees.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    // ---------------------------------------------------------- the listing

    /**
     * The default form: it says what is there and removes nothing.
     */
    #[Test]
    public function it_lists_a_preserved_workspace_without_removing_it(): void
    {
        $workspace = $this->givenAFailedTaskWithAWorkspace();

        $result = $this->project->ralph(['clean']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertDirectoryExists($workspace, 'The listing form must not delete anything.');
        self::assertSame($workspace, $this->pass()?->worktree()->path());

        // Where it is, that it could go, and that nothing went.
        self::assertStringContainsString($workspace, $result->standardOutput, $result->describe());
        self::assertStringContainsString('would be removed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Nothing was removed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('1 workspace recorded', $result->standardOutput, $result->describe());
    }

    /**
     * A listing that could not say why a workspace is staying would be no use
     * at all: the reason is the whole content of the report.
     */
    #[Test]
    public function the_listing_gives_the_reason_a_workspace_would_be_kept(): void
    {
        $workspace = $this->givenAFailedTaskWithAWorkspace(
            FixtureStep::failure('could not work out the option parsing')
                ->editing(['src/Import.php' => "<?php\n\n// half of it\n"]),
        );

        $result = $this->project->ralph(['clean']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('would be kept', $result->standardOutput, $result->describe());
        self::assertStringContainsString('uncommitted change', $result->standardOutput, $result->describe());
        self::assertStringContainsString('src/Import.php', $result->standardOutput, $result->describe());
        self::assertDirectoryExists($workspace);
    }

    /**
     * A project whose runs all finished has nothing to report, and says so
     * rather than printing an empty table.
     */
    #[Test]
    public function it_reports_a_project_with_no_recorded_workspace(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $run = $this->project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $run->exitCode, $run->describe());

        $result = $this->project->ralph(['clean']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('nothing to clean up', $result->standardOutput, $result->describe());
    }

    // ------------------------------------------------------------- the action

    /**
     * The command's reason for existing: a failure that has been looked at can
     * be cleared up, and nothing the pass earned goes with it.
     */
    #[Test]
    public function it_removes_a_named_tasks_preserved_workspace(): void
    {
        $workspace = $this->givenAFailedTaskWithAWorkspace();
        $before = $this->pass();

        $result = $this->project->ralph(['clean', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('1 workspace removed, 0 kept', $result->standardOutput, $result->describe());

        // The directory and Git's record of it have both gone.
        self::assertDirectoryDoesNotExist($workspace);
        self::assertNotContains($workspace, $this->registeredWorktrees());

        // The pass is still a failure with its history, and still retryable:
        // cleaning a workspace deletes a directory, not the record of the work.
        $after = $this->pass();
        self::assertSame(LifecycleState::Failed, $after?->state());
        self::assertSame($before?->attempts(), $after->attempts());
        self::assertSame($before->branch(), $after->branch());
        self::assertNull($after->worktree()->path());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
    }

    /**
     * The acting form takes the repository lock, which is what stops a
     * workspace being deleted from one terminal while a run uses it in another.
     * The refusal itself is {@see RepositoryLock}'s, and tested there; what is
     * asserted here is that this command goes through it.
     */
    #[Test]
    public function the_action_takes_and_releases_the_repository_lock(): void
    {
        $this->givenAFailedTaskWithAWorkspace();

        $result = $this->project->ralph(['clean', '--all']);
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $holder = RepositoryLock::in($this->project->root())->holder();

        self::assertNotNull($holder, 'The acting form must go through the repository lock.');
        self::assertSame('clean', $holder->purpose());
        self::assertFalse($holder->isHeld(), 'The lock must be released when the command ends.');
    }

    /**
     * `--all` is the same action over the project's whole history, which is the
     * form somebody reaches for when `.ralph/runtime/worktrees/` has grown.
     */
    #[Test]
    public function it_removes_every_stopped_passs_workspace_with_all(): void
    {
        $workspaces = $this->givenTwoFailedTasksWithWorkspaces();

        $result = $this->project->ralph(['clean', '--all']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('2 workspaces removed, 0 kept', $result->standardOutput, $result->describe());

        foreach ($workspaces as $workspace) {
            self::assertDirectoryDoesNotExist($workspace);
        }

        self::assertSame([], array_values(array_filter(
            $this->registeredWorktrees(),
            static fn (string $path): bool => str_contains($path, 'worktrees'),
        )));
    }

    /**
     * Running it twice is not an error and does not report work it did not do.
     */
    #[Test]
    public function cleaning_twice_is_a_no_op_the_second_time(): void
    {
        $this->givenAFailedTaskWithAWorkspace();

        $first = $this->project->ralph(['clean', '--all']);
        self::assertSame(ExitCode::SUCCESS, $first->exitCode, $first->describe());

        $second = $this->project->ralph(['clean', '--all']);

        self::assertSame(ExitCode::SUCCESS, $second->exitCode, $second->describe());
        self::assertStringContainsString('nothing to clean up', $second->standardOutput, $second->describe());
    }

    /**
     * What the cleanup leaves behind is a task that can still be tried again —
     * the property that makes clearing a workspace up a safe thing to suggest.
     */
    #[Test]
    public function a_cleaned_task_can_still_be_retried(): void
    {
        $this->givenAFailedTaskWithAWorkspace();

        $cleaned = $this->project->ralph(['clean', '--all']);
        self::assertSame(ExitCode::SUCCESS, $cleaned->exitCode, $cleaned->describe());

        $this->project->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]));

        $retry = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::SUCCESS, $retry->exitCode, $retry->describe());
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());
    }

    // --------------------------------------------------------- the protection

    /**
     * The refusal PROJECT.md section K, item 7 asks for by name. An explicit
     * request is not a licence: the half-written implementation is work that
     * exists in no commit, and no form of this command deletes it.
     */
    #[Test]
    public function it_refuses_a_workspace_holding_uncommitted_changes(): void
    {
        $workspace = $this->givenAFailedTaskWithAWorkspace(
            FixtureStep::failure('could not work out the option parsing')
                ->editing(['src/Import.php' => "<?php\n\n// half of it\n"]),
        );

        $result = $this->project->ralph(['clean', TaskId::fromDescription(self::TASK)->value()]);

        // Not a failure of the command — it did all it is allowed to — but
        // something is waiting for a person.
        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());
        self::assertStringContainsString('0 workspaces removed, 1 kept', $result->standardOutput, $result->describe());
        self::assertStringContainsString('uncommitted change', $result->standardOutput, $result->describe());

        // And the work is exactly where the agent left it.
        self::assertDirectoryExists($workspace);
        self::assertStringContainsString('// half of it', (string) file_get_contents($workspace . '/src/Import.php'));
        self::assertSame($workspace, $this->pass()?->worktree()->path());

        // The report says what to do about it, with the user's own Git.
        self::assertStringContainsString('git worktree remove', $result->standardOutput, $result->describe());
    }

    /**
     * There is no option that overrides the protection above. A user who tries
     * the obvious one is told so instead of being obeyed.
     */
    #[Test]
    public function it_has_no_force_option(): void
    {
        $workspace = $this->givenAFailedTaskWithAWorkspace(
            FixtureStep::failure('could not work out the option parsing')
                ->editing(['src/Import.php' => "<?php\n\n// half of it\n"]),
        );

        $result = $this->project->ralph(['clean', '--all', '--force']);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertStringContainsString('"--force"', $result->allOutput(), $result->describe());
        self::assertDirectoryExists($workspace);
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function it_refuses_a_task_identifier_and_all_together(): void
    {
        $this->givenAFailedTaskWithAWorkspace();

        $result = $this->project->ralph(['clean', TaskId::fromDescription(self::TASK)->value(), '--all']);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertStringContainsString('not both', $result->allOutput(), $result->describe());
    }

    #[Test]
    public function it_refuses_a_task_nothing_has_been_recorded_for(): void
    {
        $this->project->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['clean', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('no workspace', $result->allOutput(), $result->describe());
    }

    #[Test]
    public function it_refuses_a_project_that_has_never_run(): void
    {
        $this->project->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['clean']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('no state store', $result->allOutput(), $result->describe());
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A run that gave up on its one task, leaving the worktree it worked in.
     *
     * @return string the preserved workspace's path, asserted to be there
     */
    private function givenAFailedTaskWithAWorkspace(?FixtureStep $step = null): string
    {
        $this->project
            ->withWorker($step ?? FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $run = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $run->exitCode, $run->describe());

        $workspace = $this->pass()?->worktree()->path();

        self::assertNotNull($workspace, 'The failed pass should have kept its workspace.');
        self::assertDirectoryExists($workspace);

        return $workspace;
    }

    /**
     * Two tasks the run gave up on, so `--all` has more than one to do.
     *
     * @return list<string> both preserved workspaces
     */
    private function givenTwoFailedTasksWithWorkspaces(): array
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK, self::OTHER])
            ->withConfiguration(['limits' => ['iterations' => 4]]);

        $run = $this->project->ralph(['run']);
        self::assertSame(ExitCode::EXECUTION, $run->exitCode, $run->describe());

        $workspaces = [];

        foreach ([self::TASK, self::OTHER] as $description) {
            $pass = $this->project->journal()->generations()->latestFor(TaskId::fromDescription($description));
            $path = $pass?->worktree()->path();

            self::assertNotNull($path, sprintf('"%s" should have kept its workspace.', $description));
            self::assertDirectoryExists($path);

            $workspaces[] = $path;
        }

        return $workspaces;
    }

    private function pass(): ?GenerationRecord
    {
        return $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::TASK));
    }

    /**
     * The worktree paths Git has registered in this repository.
     *
     * @return list<string>
     */
    private function registeredWorktrees(): array
    {
        $listing = Git::run($this->project->root(), 'worktree', 'list', '--porcelain')->standardOutput;
        $paths = [];

        foreach (explode("\n", $listing) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $paths[] = trim(substr($line, strlen('worktree ')));
            }
        }

        return $paths;
    }
}
