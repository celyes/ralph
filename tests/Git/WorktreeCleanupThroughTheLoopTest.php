<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\WorktreeCleaner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\CliResult;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a real run leaves in `.ralph/runtime/worktrees/` and in Git's own list
 * of worktrees.
 *
 * PROJECT.md section K, items 6 to 8 are about a directory and a registration,
 * and {@see WorktreeCleanerTest} settles every decision that goes into them:
 * which passes may be cleaned up, which must be kept, what a half-finished
 * removal looks like and which Git commands are allowed to run. What it cannot
 * show is that the run *asks* — a cleaner that decided perfectly and was never
 * reached would pass all of it while every run filled the disk with worktrees.
 *
 * So each case here drives `ralph` as a subprocess and then reads the result
 * the way a user would: `git worktree list`, the directory listing, `git log`
 * and `git branch`. Four things are being established:
 *
 *  - cleanup is part of a *successful* finalization (item 6), in both branch
 *    strategies, and it takes Git's registration with it rather than leaving a
 *    prunable entry behind;
 *  - in shared-branch mode what goes is the task's workspace only: the branch
 *    and every commit on it are still there for the next task (item 8);
 *  - a pass that failed keeps its workspace, registration included, because
 *    that is the copy of the work somebody has to look at (item 7);
 *  - a removal an interruption cut in half is finished later rather than
 *    abandoned (item 6's "idempotent and recoverable").
 */
#[CoversClass(WorktreeCleaner::class)]
final class WorktreeCleanupThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    private const string SHARED = 'ralph/shared';

    /**
     * Output the slow worker prints itself, so a run signalled on it is a run
     * whose agent had really started.
     */
    private const string WORKING = ', mode slow';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and these are real linked worktrees.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * A task that passed leaves no workspace and no registration — and its
     * commit is still on its branch.
     *
     * The order item 6 asks for is what makes the removal safe, so the case
     * asserts both halves together: everything the pass earned is durable (the
     * commit, the branch, the ticked box, the recorded completion) *and* the
     * directory is gone. The removal is also read back off the pass's own
     * completion evidence, because a run that deleted the directory without
     * recording that it had could not finish the job after an interruption.
     */
    #[Test]
    public function a_completed_tasks_workspace_and_its_registration_are_both_gone(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $pass = $this->passFor(self::FIRST);
        $removed = $this->removedWorkspaces($result);

        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertCount(1, $removed, $result->describe());

        $workspace = $removed[0];

        // Gone from the filesystem and from Git's list, and recorded as gone:
        // a row marked removed no longer names a directory, which is what makes
        // asking again safe.
        self::assertSame($this->project->path('.ralph/runtime/worktrees'), dirname($workspace));
        self::assertDirectoryDoesNotExist($workspace);
        self::assertNotContains($workspace, $this->registeredWorktrees(), 'Git still has the worktree.');
        self::assertTrue($pass->cleanupIsFinished());
        self::assertNull($pass->worktree()->path());

        // And nothing the pass earned went with it.
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertSame($pass->commitSha(), $this->project->headOf(self::SHARED));
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * Per-task mode: a workspace per task, every one of them cleaned up, every
     * branch kept.
     *
     * The strategy decides which branches a run leaves behind and nothing else
     * about cleanup, which is the property worth pinning: two tasks, two
     * branches each holding its own commit, and an empty worktrees directory
     * with no registration left in Git.
     */
    #[Test]
    public function per_task_mode_removes_every_workspace_and_leaves_every_branch(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['strategy' => 'per-task']]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame(2, substr_count($result->standardOutput, 'cleanup: removed '));

        $registered = $this->registeredWorktrees();

        foreach ($this->removedWorkspaces($result) as $workspace) {
            self::assertDirectoryDoesNotExist($workspace);
            self::assertNotContains($workspace, $registered);
        }

        foreach ([self::FIRST, self::SECOND] as $description) {
            $pass = $this->passFor($description);
            $branch = (string) $pass->branch();

            self::assertTrue($pass->cleanupIsFinished(), $description . ' did not finish its cleanup.');
            self::assertNull($pass->worktree()->path());

            // The branch is the lasting half: still a branch, still holding the
            // one commit the pass made on it.
            self::assertCount(1, $this->project->commitsOn($branch));
            self::assertSame($pass->commitSha(), $this->project->headOf($branch));
        }

        // One registration: the user's own checkout.
        self::assertSame([$this->project->root()], $registered);
        self::assertSame([], $this->workspacesOnDisk());
    }

    /**
     * Shared-branch mode: the task's workspace goes, the shared branch and the
     * commits on it stay.
     *
     * Item 8 by name. The danger the item is about is a cleanup that took the
     * branch with the directory — the next task would then have nothing to
     * build on, and the run's earlier commits would be unreachable. So the
     * assertions are about what survives two cleanups: one branch, both
     * commits, in order, with the first task's file inside the second task's
     * tree.
     */
    #[Test]
    public function a_shared_runs_cleanups_leave_the_branch_and_its_commits_alone(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // A cleanup per task, not one at the end of the run.
        self::assertSame(2, substr_count($result->standardOutput, 'cleanup: removed '));
        self::assertSame([], $this->workspacesOnDisk());
        self::assertSame([$this->project->root()], $this->registeredWorktrees());

        // The branch the cleanups had to leave behind, with both commits on it.
        self::assertSame([self::SHARED], $this->project->taskBranches());

        $second = $this->passFor(self::SECOND);

        self::assertCount(2, $this->project->commitsOn(self::SHARED));
        self::assertSame($second->commitSha(), $this->project->headOf(self::SHARED));
        self::assertTrue($this->project->branchContains(self::SHARED, (string) $this->passFor(self::FIRST)->commitSha()));
        self::assertSame(
            self::EDIT,
            Git::run($this->project->root(), 'show', self::SHARED . ':' . self::EDITED)->standardOutput,
        );
    }

    /**
     * A task that failed keeps its workspace, and Git keeps knowing about it.
     *
     * Item 7's default, from the user's side: the half-finished implementation
     * is the only copy of what the agent did, the run says why it is staying,
     * and the worktree is still registered — so `git -C <workspace> diff` and
     * `git worktree list` both work on it, which is what "for inspection" has
     * to mean.
     */
    #[Test]
    public function a_failed_tasks_workspace_is_kept_and_still_registered(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing')
                ->editing([self::EDITED => "<?php\n\n// half of it\n"]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());

        $pass = $this->passFor(self::FIRST);
        $workspace = (string) $pass->worktree()->path();

        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertDirectoryExists($workspace);
        self::assertContains($workspace, $this->registeredWorktrees());
        self::assertFalse($pass->cleanupIsFinished());

        // The agent's unfinished work is in it, and the run said why the
        // directory is staying.
        self::assertStringEqualsFile($workspace . DIRECTORY_SEPARATOR . self::EDITED, "<?php\n\n// half of it\n");
        self::assertStringContainsString('kept for inspection', $result->standardOutput, $result->describe());
        self::assertStringContainsString('the pass is failed', $result->standardOutput);
    }

    /**
     * A removal that was cut off part-way is finished the next time cleanup is
     * asked for, and asking again does nothing.
     *
     * The state is produced the way an interruption produces it: the directory
     * is taken while Git's registration still names it, which is what is left
     * when a process stops between the two. `ralph clean --all` is then the
     * cleanup that comes after the interruption, and what it must do is finish
     * the job — clear the record and the registration — without deleting
     * anything, because there is nothing left to delete.
     *
     * The second invocation is half the case: a cleanup that reported work it
     * had not done, or redid it, would be a cleanup nobody could run twice
     * after a crash.
     */
    #[Test]
    public function a_removal_an_interruption_left_half_done_is_finished_by_the_next_cleanup(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        self::assertSame(ExitCode::EXECUTION, $this->project->ralph(['run'])->exitCode);

        $workspace = (string) $this->passFor(self::FIRST)->worktree()->path();

        self::assertDirectoryExists($workspace);

        // The interruption: the directory goes, the registration does not.
        TemporaryDirectory::remove($workspace);

        self::assertDirectoryDoesNotExist($workspace);
        self::assertContains($workspace, $this->registeredWorktrees());

        $cleaned = $this->project->ralph(['clean', '--all']);

        self::assertSame(ExitCode::SUCCESS, $cleaned->exitCode, $cleaned->describe());
        self::assertStringContainsString('had already gone was cleared', $cleaned->standardOutput, $cleaned->describe());
        self::assertNotContains($workspace, $this->registeredWorktrees(), 'The registration was left behind.');

        // Again, with nothing left to do and nothing claimed.
        $again = $this->project->ralph(['clean', '--all']);

        self::assertSame(ExitCode::SUCCESS, $again->exitCode, $again->describe());
        self::assertStringNotContainsString('1 workspace removed', $again->standardOutput, $again->describe());

        // And the task is still a task: cleanup clears workspaces, not work.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * An interrupted task keeps its workspace, and the resume that completes it
     * is what removes it.
     *
     * The two halves of items 6 and 7 in one run, which is the sequence a user
     * actually meets: Ctrl+C, a directory left where it was, then
     * `ralph resume`, a commit, and a workspace that goes only once the pass is
     * finished. The interruption is a real signal to a real `ralph run`.
     */
    #[Test]
    public function an_interrupted_tasks_workspace_is_removed_once_the_resume_completes_it(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('The pcntl extension is not installed, so a run cannot be signalled.');
        }

        $this->project
            ->withWorker(
                FixtureStep::slow(30),
                FixtureStep::success([self::EDITED => self::EDIT]),
            )
            ->withTasks([self::FIRST])
            ->withConfiguration(['limits' => ['attempts_per_task' => 2]]);

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());

        $stopped = $this->passFor(self::FIRST);
        $workspace = (string) $stopped->worktree()->path();

        self::assertSame(LifecycleState::Interrupted, $stopped->state());
        self::assertDirectoryExists($workspace);
        self::assertContains($workspace, $this->registeredWorktrees());

        $resumed = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::SUCCESS, $resumed->exitCode, $resumed->describe());

        $finished = $this->passFor(self::FIRST);

        self::assertSame(LifecycleState::Completed, $finished->state());
        self::assertTrue($finished->cleanupIsFinished());
        self::assertContains($workspace, $this->removedWorkspaces($resumed), $resumed->describe());
        self::assertDirectoryDoesNotExist($workspace);
        self::assertNotContains($workspace, $this->registeredWorktrees());
        self::assertSame([], $this->workspacesOnDisk());

        // The commit the resume made is on the branch, which the cleanup left.
        self::assertSame($finished->commitSha(), $this->project->headOf(self::SHARED));
    }

    /**
     * The newest pass at a task, where its state, branch, commit and cleanup
     * live.
     */
    private function passFor(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor(TaskId::fromDescription($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }

    /**
     * The workspaces a run reported removing, as it named them.
     *
     * Read off the run's own output because the state store cannot answer it: a
     * row marked `removed` has had its workspace columns cleared, which is the
     * property that makes cleanup idempotent. The line is what a user sees, so
     * a case asserting against it is asserting the thing that was promised.
     *
     * @return list<string>
     */
    private function removedWorkspaces(CliResult $result): array
    {
        $paths = [];

        foreach ($result->standardOutputLines() as $line) {
            if (preg_match('/cleanup: removed (.+)$/', $line, $matches) === 1) {
                $paths[] = trim($matches[1]);
            }
        }

        return $paths;
    }

    /**
     * The worktrees Git itself has registered for this repository.
     *
     * @return list<string>
     */
    private function registeredWorktrees(): array
    {
        $paths = [];

        foreach (Git::run($this->project->root(), 'worktree', 'list', '--porcelain')->standardOutputLines() as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $paths[] = trim(substr($line, strlen('worktree ')));
            }
        }

        return $paths;
    }

    /**
     * The directories left under the project's worktrees root, by name.
     *
     * @return list<string>
     */
    private function workspacesOnDisk(): array
    {
        $root = $this->project->path('.ralph/runtime/worktrees');

        if (!is_dir($root)) {
            return [];
        }

        $entries = array_values(array_diff((array) scandir($root), ['.', '..']));
        sort($entries);

        /** @var list<string> $entries */
        return $entries;
    }
}
