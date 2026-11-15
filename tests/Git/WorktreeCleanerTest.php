<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\RetentionReason;
use Celyes\RalphPhp\Git\RetentionVerdict;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkspaceRetention;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Git\WorktreeCleaner;
use Celyes\RalphPhp\Git\WorktreeCleanup;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\CleanupState;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\State\WorktreeOwnership;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\Paths;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeping the workspaces of failed and interrupted passes, and removing the
 * ones a finished pass is done with, against real repositories and real linked
 * worktrees.
 *
 * Every case here is really one of two assertions. Where a workspace is kept,
 * the test asserts the **bytes are still on disk** and the registration is
 * still Git's — because a preserved workspace whose contents went anyway is not
 * preserved, and the whole value of PROJECT.md section F, item 10 is that a
 * person can still look. Where a workspace is removed, the test asserts the
 * **branch and the commit survived it**: the branch still at the same SHA, the
 * commit still an object, the row still naming both. A cleanup that took the
 * deliverable with the workspace would be the one unrecoverable mistake
 * available here, and `git worktree remove` is run by this package rather than
 * by the user precisely so this suite can say it does not.
 */
#[CoversClass(WorktreeCleaner::class)]
#[CoversClass(WorkspaceRetention::class)]
#[CoversClass(WorktreeCleanup::class)]
#[CoversClass(RetentionReason::class)]
#[CoversClass(RetentionVerdict::class)]
#[CoversClass(CleanupState::class)]
#[CoversClass(WorkingTreeChange::class)]
final class WorktreeCleanerTest extends TestCase
{
    private const string DESCRIPTION = 'Add a greeting command';

    private const string WORK = "<?php\n\n// the implementation\n";

    private TemporaryProject $project;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private ?BaseCommit $base = null;

    private ?int $run = null;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this removes real linked worktrees.');
        }

        $this->project = TemporaryProject::create();
        $this->givenACommittedProject();

        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->tasks = new TaskStore($this->database);
        $this->generations = new GenerationStore($this->database);
    }

    protected function tearDown(): void
    {
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
    }

    // ---------------------------------------------------------------- removal

    /**
     * The whole point, end to end: a verified pass's workspace goes, and
     * everything that makes the work reviewable stays.
     */
    #[Test]
    public function a_completed_passs_workspace_is_removed_and_its_branch_is_left_alone(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $commit = (string) $pass->commitSha();
        $branch = (string) $pass->branch();
        $pass = $this->generations->complete($pass);

        $sink = new RecordingSink();
        $cleanup = $this->cleaner()->clean($pass, $sink);

        self::assertTrue($cleanup->wasRemoved());
        self::assertFalse($cleanup->wasKept());
        self::assertFalse($cleanup->clearedRegistrationOnly());
        self::assertSame(RetentionVerdict::Removable, $cleanup->retention()->verdict());
        self::assertSame([], $cleanup->details());

        // The directory and the registration have both gone.
        self::assertDirectoryDoesNotExist($workspace->path());
        self::assertNull($this->registrationAt($workspace->path()));

        // And the deliverable is exactly where it was.
        self::assertSame($commit, $this->tipOf($branch));
        self::assertTrue($this->commitExists($commit));
        self::assertSame(['src/Greeting.php'], $this->filesIn($commit));

        // The row keeps the evidence and stops naming a directory that is gone.
        $record = $this->recordOf($pass);
        self::assertSame($commit, $record->commitSha());
        self::assertSame($branch, $record->branch());
        self::assertNull($record->worktree()->path());
        self::assertFalse($record->worktree()->isOwned());

        self::assertContains(
            sprintf('cleanup: removed %s', $workspace->path()),
            $sink->notes,
        );
    }

    /**
     * A pass that found the task already satisfied has no commit and no branch,
     * so the only thing holding its workspace is the workspace itself.
     */
    #[Test]
    public function a_verified_no_change_pass_has_its_workspace_removed(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path());
        self::assertNull($this->registrationAt($workspace->path()));
    }

    /**
     * Skipped is closed, and a passed-over task's empty checkout is not
     * evidence of anything.
     */
    #[Test]
    public function a_skipped_passs_workspace_is_removed(): void
    {
        $pass = $this->givenAPass(started: false);
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->skip($this->recordOf($pass));

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path());
    }

    /**
     * The one case that cleans up without reading the workspace first: there is
     * nothing there to read, and the leftover record is what makes
     * `git worktree add` refuse the path next time.
     */
    #[Test]
    public function a_registration_whose_directory_has_gone_is_cleared_without_deleting_anything(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        self::assertNotNull($this->registrationAt($workspace->path()));
        $this->removeByHand($workspace->path());

        $sink = new RecordingSink();
        $cleanup = $this->cleaner()->clean($pass, $sink);

        self::assertTrue($cleanup->wasRemoved());
        self::assertTrue($cleanup->clearedRegistrationOnly());
        self::assertNull($this->registrationAt($workspace->path()));
        self::assertNull($this->recordOf($pass)->worktree()->path());
        self::assertContains(
            sprintf('cleanup: %s had already gone; the record of it was cleared', $workspace->path()),
            $sink->notes,
        );
    }

    /**
     * The documented boundary, asserted rather than assumed: `git worktree
     * remove` takes the files Git never tracked with it, which is what makes an
     * *untracked* file enough to keep a workspace and a prepared `vendor/` not.
     */
    #[Test]
    public function files_the_project_ignores_go_with_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $this->inWorkspace($workspace, 'vendor/autoload.php', "<?php\n");
        $pass = $this->generations->complete($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path() . '/vendor');
    }

    /**
     * Once the reason a workspace was kept is gone, the same decision comes out
     * the other way — so preserving is not a one-way door a user has to clear
     * up by hand.
     */
    #[Test]
    public function a_workspace_kept_for_its_contents_becomes_removable_once_they_are_gone(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $this->inWorkspace($workspace, 'notes.txt', "looked at this\n");
        $pass = $this->generations->complete($pass);

        self::assertTrue($this->cleaner()->assess($pass)->isPreserved());

        unlink($workspace->path() . '/notes.txt');

        self::assertTrue($this->cleaner()->assess($pass)->isRemovable());
        self::assertTrue($this->cleaner()->clean($pass)->wasRemoved());
    }

    // ------------------------------------------------------------ inspection

    /**
     * The item's headline: a failure a person can still look at.
     */
    #[Test]
    public function a_failed_passs_workspace_is_kept_for_inspection(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $pass = $this->generations->fail($pass, Blocker::raised('The tests did not pass.', 'Read the log and retry the task.'));

        $sink = new RecordingSink();
        $cleanup = $this->cleaner()->clean($pass, $sink);

        self::assertTrue($cleanup->wasKept());
        self::assertFalse($cleanup->wasRemoved());
        self::assertTrue($cleanup->isForInspection());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::PassUnfinished));

        // What "kept for inspection" has to mean to be worth anything.
        self::assertDirectoryExists($workspace->path());
        self::assertSame(self::WORK, file_get_contents($workspace->path() . '/src/Greeting.php'));
        self::assertNotNull($this->registrationAt($workspace->path()));
        self::assertSame($workspace->path(), $this->recordOf($pass)->worktree()->path());

        self::assertStringContainsString('failed', $cleanup->describe());
        self::assertContains('cleanup: The pass has not finished, so its workspace is kept for inspection: the pass is failed (stopped after an unsuccessful attempt)', $sink->notes);
        self::assertContains('Run `ralph logs TASK-ID` to read what happened, or `ralph retry TASK-ID` to continue in it.', $cleanup->hints());
    }

    /**
     * An interruption leaves the index as the worker left it, and resume
     * continues in it — so the index is part of what is preserved, not only the
     * files.
     */
    #[Test]
    public function an_interrupted_passs_workspace_is_kept_with_its_index_intact(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        Git::run($workspace->path(), 'add', 'src/Greeting.php');
        $pass = $this->generations->interrupt($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertTrue($cleanup->isForInspection());
        self::assertDirectoryExists($workspace->path());
        self::assertSame(['src/Greeting.php'], $this->stagedIn($workspace->path()));
    }

    #[Test]
    public function a_blocked_passs_workspace_is_kept(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->block($pass, Blocker::raised('The agent asked a question.', 'Answer it, then retry the task.'));

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::PassUnfinished));
        self::assertDirectoryExists($workspace->path());
    }

    /**
     * A workspace a runner is holding right now is no more removable than one
     * it abandoned.
     */
    #[Test]
    public function a_running_passs_workspace_is_kept(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $retention = $this->cleaner()->assess($this->recordOf($pass));

        self::assertTrue($retention->isPreserved());
        self::assertSame(
            'the pass is running (in progress)',
            $retention->observationFor(RetentionReason::PassUnfinished),
        );
        self::assertDirectoryExists($workspace->path());
    }

    // ------------------------------------------------------------- ownership

    /**
     * An interrupted run's worktree that a later pass continued in, or one a
     * user prepared by hand: finished with or not, it was never this pass's to
     * delete.
     */
    #[Test]
    public function an_adopted_workspace_is_kept_even_when_the_pass_is_complete(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->generations->recordWorktree($pass, WorktreeOwnership::adopted($workspace->path()));
        $pass = $this->generations->complete(
            $this->generations->recordNoChange($this->recordOf($pass)),
        );

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertFalse($cleanup->isForInspection());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::NotOwned));
        self::assertDirectoryExists($workspace->path());
        self::assertSame($workspace->path(), $this->recordOf($pass)->worktree()->path());
    }

    /**
     * Defence in depth against the one mistake with no recovery. A row that
     * named the user's checkout must not produce a `git worktree remove` on it.
     */
    #[Test]
    public function a_recorded_path_outside_the_worktrees_root_is_refused(): void
    {
        $pass = $this->givenAPass();
        $this->generations->recordWorktree($pass, WorktreeOwnership::created($this->project->root()));
        $pass = $this->generations->complete(
            $this->generations->recordNoChange($this->recordOf($pass)),
        );

        $retention = $this->cleaner()->assess($pass);

        self::assertTrue($retention->isPreserved());
        self::assertTrue($retention->hasReason(RetentionReason::NotOurPath));
        self::assertStringContainsString(
            'where Ralph PHP creates task workspaces',
            (string) $retention->observationFor(RetentionReason::NotOurPath),
        );

        self::assertTrue($this->cleaner()->clean($pass)->wasKept());
        self::assertDirectoryExists($this->project->root());
        self::assertFileExists($this->project->root() . '/src/Greeting.php');
    }

    /**
     * A directory at the workspace path that Git has no record of arrived some
     * other way, and `rm -rf` on it is what this item forbids.
     */
    #[Test]
    public function a_directory_git_does_not_know_about_is_never_deleted(): void
    {
        $pass = $this->givenAPass();
        $path = TaskWorktrees::forProject($this->configuration())->pathFor($pass->generation());

        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            self::fail('Could not create ' . $path);
        }

        file_put_contents($path . '/somebody-elses.txt', "please keep this\n");
        $this->generations->recordWorktree($pass, WorktreeOwnership::created($path));
        $pass = $this->generations->complete(
            $this->generations->recordNoChange($this->recordOf($pass)),
        );

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::NotRegistered));
        self::assertSame("please keep this\n", file_get_contents($path . '/somebody-elses.txt'));
    }

    // -------------------------------------------------------------- contents

    #[Test]
    public function an_untracked_file_keeps_a_completed_passs_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $this->inWorkspace($workspace, 'notes.txt', "something nobody asked to lose\n");
        $pass = $this->generations->complete($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::UncommittedWork));
        self::assertSame(
            '1 uncommitted change(s): notes.txt',
            $cleanup->retention()->observationFor(RetentionReason::UncommittedWork),
        );
        self::assertSame(
            "something nobody asked to lose\n",
            file_get_contents($workspace->path() . '/notes.txt'),
        );
    }

    #[Test]
    public function a_modified_tracked_file_keeps_a_completed_passs_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK . "// and one more thought\n");
        $pass = $this->generations->complete($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertStringContainsString(
            'src/Greeting.php',
            (string) $cleanup->retention()->observationFor(RetentionReason::UncommittedWork),
        );
        self::assertDirectoryExists($workspace->path());
    }

    /**
     * Enough names to recognise the work, and a count that does not lie about
     * how much there is.
     */
    #[Test]
    public function many_uncommitted_changes_are_counted_and_summarised(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);

        foreach (range(1, 7) as $index) {
            $this->inWorkspace($workspace, sprintf('note-%d.txt', $index), "kept\n");
        }

        $pass = $this->generations->complete($pass);

        $observation = (string) $this->cleaner()
            ->assess($pass)
            ->observationFor(RetentionReason::UncommittedWork);

        self::assertStringStartsWith('7 uncommitted change(s): note-1.txt', $observation);
        self::assertStringEndsWith('and 2 more', $observation);
    }

    // --------------------------------------------------------------- commits

    /**
     * A commit the state store does not name is reachable from this worktree's
     * HEAD and nothing else, so removing the worktree is what would lose it.
     */
    #[Test]
    public function a_commit_the_row_does_not_name_keeps_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $recorded = (string) $pass->commitSha();
        $pass = $this->generations->complete($pass);

        $this->inWorkspace($workspace, 'src/Afterthought.php', "<?php\n");
        $stray = $this->commitInWorkspace($workspace, 'Something committed afterwards');

        $cleanup = $this->cleaner()->clean($pass);
        $observation = (string) $cleanup->retention()->observationFor(RetentionReason::UnrecordedCommit);

        self::assertTrue($cleanup->wasKept());
        self::assertStringContainsString($stray, $observation);
        self::assertStringContainsString($recorded, $observation);
        self::assertDirectoryExists($workspace->path());
        self::assertTrue($this->commitExists($stray));
    }

    /**
     * The other half of protecting a completed branch: the branch is *asked*,
     * not assumed, to be holding the commit before the workspace that also
     * referred to it goes.
     */
    #[Test]
    public function a_commit_whose_branch_is_not_in_the_repository_keeps_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $commit = $this->commitInWorkspaceAsThePass($workspace);
        $pass = $this->generations->recordCommit($pass, $commit);
        $pass = $this->generations->assignBranch($pass, 'ralph/task-never-created');
        $pass = $this->generations->complete($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertSame(
            sprintf(
                'branch ralph/task-never-created is not in the repository, so nothing but this workspace refers to commit %s',
                $commit,
            ),
            $cleanup->retention()->observationFor(RetentionReason::CommitUnreferenced),
        );
        self::assertDirectoryExists($workspace->path());
    }

    #[Test]
    public function a_branch_that_does_not_contain_the_commit_keeps_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $commit = $this->commitInWorkspaceAsThePass($workspace);
        Git::run($this->project->root(), 'branch', 'ralph/elsewhere', $this->base()->sha());

        $pass = $this->generations->assignBranch(
            $this->generations->recordCommit($pass, $commit),
            'ralph/elsewhere',
        );
        $pass = $this->generations->complete($pass);

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertSame(
            sprintf(
                'branch ralph/elsewhere is at %s, which does not contain commit %s',
                $this->base()->sha(),
                $commit,
            ),
            $cleanup->retention()->observationFor(RetentionReason::CommitUnreferenced),
        );
    }

    #[Test]
    public function a_recorded_commit_with_no_branch_keeps_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $commit = $this->commitInWorkspaceAsThePass($workspace);
        $pass = $this->generations->complete($this->generations->recordCommit($pass, $commit));

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertStringContainsString(
            'recorded with no branch',
            (string) $cleanup->retention()->observationFor(RetentionReason::CommitUnreferenced),
        );
        self::assertDirectoryExists($workspace->path());
    }

    // ------------------------------------------------------------ Git saying no

    #[Test]
    public function a_locked_worktree_is_kept_with_the_reason_git_gives(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        Git::run($this->project->root(), 'worktree', 'lock', '--reason', 'on a removable disk', $workspace->path());

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertSame(
            'Git has it locked: on a removable disk',
            $cleanup->retention()->observationFor(RetentionReason::Locked),
        );
        self::assertDirectoryExists($workspace->path());

        Git::tryRun($this->project->root(), 'worktree', 'unlock', $workspace->path());
    }

    #[Test]
    public function a_worktree_locked_without_a_reason_is_still_kept(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        Git::run($this->project->root(), 'worktree', 'lock', $workspace->path());

        $retention = $this->cleaner()->assess($pass);

        self::assertSame(
            'Git has it locked, with no reason given',
            $retention->observationFor(RetentionReason::Locked),
        );

        Git::tryRun($this->project->root(), 'worktree', 'unlock', $workspace->path());
    }

    /**
     * A repository whose references cannot be read answers nothing, and nothing
     * is the one answer that must not be read as "there is nothing there".
     */
    #[Test]
    public function a_repository_that_cannot_be_listed_keeps_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        $worktrees = $this->project->root() . '/.git/worktrees';
        self::assertDirectoryExists($worktrees);
        file_put_contents($this->project->root() . '/.git/HEAD', "this is not a reference\n");

        $retention = $this->cleaner()->assess($pass);

        self::assertTrue($retention->isPreserved());
        self::assertTrue($retention->hasReason(RetentionReason::Unreadable));
        self::assertDirectoryExists($workspace->path());
    }

    // ------------------------------------------------------- nothing to do

    #[Test]
    public function a_pass_that_never_had_a_workspace_is_nothing_to_clean_up(): void
    {
        $pass = $this->givenAPass();
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        $sink = new RecordingSink();
        $cleanup = $this->cleaner()->clean($pass, $sink);

        self::assertFalse($cleanup->wasRemoved());
        self::assertFalse($cleanup->wasKept());
        self::assertTrue($cleanup->retention()->isAbsent());
        self::assertNull($cleanup->path());
        self::assertContains('cleanup: ' . $pass->generation()->label() . ': no workspace prepared', $sink->notes);
    }

    /**
     * A row naming a workspace that nothing is left of: the last step of a
     * cleanup, carried out on its own.
     *
     * Reached by a `rm -rf` and a `git worktree prune`, and reached by a crash
     * between Git confirming a removal and the state store hearing about it.
     * Either way the only thing still referring to the workspace is the row,
     * and clearing it is the whole of what is left to do — nothing is deleted,
     * because there is nothing there to delete.
     */
    #[Test]
    public function a_row_naming_a_workspace_that_has_entirely_gone_is_finished_off(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        $this->removeByHand($workspace->path());
        Git::run($this->project->root(), 'worktree', 'prune');

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasRemoved());
        self::assertTrue($cleanup->clearedRegistrationOnly());
        self::assertSame($workspace->path(), $cleanup->path());

        $record = $this->recordOf($pass);
        self::assertNull($record->worktree()->path());
        self::assertSame(CleanupState::Removed, $record->cleanup());
    }

    /**
     * A directory that has gone while the pass is still open keeps its
     * registration: a retry prepares the same path again, and clearing the
     * record is work with nothing to gain.
     */
    #[Test]
    public function a_missing_directory_of_an_unfinished_pass_keeps_its_registration(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->fail($pass);

        $this->removeByHand($workspace->path());

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->wasKept());
        self::assertTrue($cleanup->retention()->hasReason(RetentionReason::PassUnfinished));
        self::assertNotNull($this->registrationAt($workspace->path()));
    }

    // ------------------------------------------ idempotence and recovery

    /**
     * Cleanup is safe to call again, and the second call says so.
     *
     * The first requirement of PROJECT.md section K, item 7. Nothing is
     * remembered between calls — the decision is taken from the row and the
     * repository every time — so what makes the second call a no-op is that the
     * first one left the row saying the workspace has gone, which is also what
     * lets the line a person reads tell "already removed" from "never had one".
     */
    #[Test]
    public function cleaning_up_the_same_pass_twice_changes_nothing_the_second_time(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $commit = (string) $pass->commitSha();
        $branch = (string) $pass->branch();
        $pass = $this->generations->complete($pass);

        self::assertTrue($this->cleaner()->clean($pass)->wasRemoved());

        $sink = new RecordingSink();
        $again = $this->cleaner()->clean($this->recordOf($pass), $sink);

        self::assertFalse($again->wasRemoved());
        self::assertFalse($again->wasKept());
        self::assertTrue($again->retention()->isAbsent());
        self::assertTrue($again->retention()->wasAlreadyCleaned());
        self::assertNull($again->path());
        self::assertContains(
            sprintf('cleanup: %s: its workspace has already been removed', $pass->generation()->label()),
            $sink->notes,
        );

        // And the deliverable is untouched by either call.
        self::assertSame($commit, $this->tipOf($branch));
        self::assertSame($commit, $this->recordOf($pass)->commitSha());
        self::assertSame(CleanupState::Removed, $this->recordOf($pass)->cleanup());
    }

    /**
     * A sweep over a run's history after it has already been swept: every pass
     * is accounted for and nothing is done twice.
     */
    #[Test]
    public function a_second_sweep_accounts_for_every_pass_and_repeats_nothing(): void
    {
        $finished = $this->givenAPass();
        $finishedWorkspace = $this->givenAWorkspace($finished);
        $finished = $this->generations->complete($this->generations->recordNoChange($finished));

        $failed = $this->givenAPass('Add a farewell command');
        $failedWorkspace = $this->givenAWorkspace($failed);
        $this->inWorkspace($failedWorkspace, 'src/Farewell.php', self::WORK);
        $failed = $this->generations->fail($failed);

        $this->cleaner()->sweep([$finished, $failed]);
        $again = $this->cleaner()->sweep([$this->recordOf($finished), $this->recordOf($failed)]);

        self::assertCount(2, $again);
        self::assertTrue($again[0]->retention()->wasAlreadyCleaned());
        self::assertTrue($again[1]->wasKept());

        self::assertDirectoryDoesNotExist($finishedWorkspace->path());
        self::assertSame(self::WORK, file_get_contents($failedWorkspace->path() . '/src/Farewell.php'));
    }

    /**
     * The classic crash: Git removed the workspace, and the process died before
     * the state store heard about it.
     *
     * The row is left naming a directory that is not there and marked with a
     * removal that was never confirmed, which is exactly what
     * {@see WorktreeCleaner::clean()} writes before it issues the command. The
     * next cleanup finishes the one step left — clearing the row — and deletes
     * nothing, because there is nothing left to delete.
     */
    #[Test]
    public function a_removal_the_state_store_never_heard_about_is_finished_by_the_next_cleanup(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $commit = (string) $pass->commitSha();
        $branch = (string) $pass->branch();
        $pass = $this->generations->complete($pass);

        $this->givenAnInterruptedRemoval($pass);
        Git::run($this->project->root(), 'worktree', 'remove', $workspace->path());
        self::assertSame($workspace->path(), $this->recordOf($pass)->worktree()->path());

        $cleanup = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($cleanup->wasRemoved());
        self::assertTrue($cleanup->clearedRegistrationOnly());

        $record = $this->recordOf($pass);
        self::assertNull($record->worktree()->path());
        self::assertSame(CleanupState::Removed, $record->cleanup());

        // The commit and the branch are what the pass is reviewed from, and
        // neither the crash nor the recovery touched them.
        self::assertSame($commit, $this->tipOf($branch));
        self::assertSame($commit, $record->commitSha());
    }

    /**
     * The crash in the middle: Git deleted part of the workspace and stopped.
     *
     * `git worktree remove` refuses such a tree without `--force`, because its
     * own deletions read as modified files — so without the recorded removal to
     * recognise the husk by, the directory would be preserved for ever. This is
     * the case the mark on the row exists for.
     */
    #[Test]
    public function a_part_deleted_workspace_is_finished_off_only_when_a_removal_was_recorded(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $commit = (string) $pass->commitSha();
        $branch = (string) $pass->branch();
        $pass = $this->generations->complete($pass);

        $this->givenAPartDeletedWorkspace($workspace);

        // Without the mark: Git would refuse, and so does this.
        $kept = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($kept->wasKept());
        self::assertTrue($kept->retention()->hasReason(RetentionReason::UncommittedWork));
        self::assertDirectoryExists($workspace->path());

        // With it: the husk is what is left of a removal this package issued,
        // and finishing it is finishing that.
        $this->givenAnInterruptedRemoval($this->recordOf($pass));
        $cleanup = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($cleanup->wasRemoved());
        self::assertFalse($cleanup->clearedRegistrationOnly());
        self::assertDirectoryDoesNotExist($workspace->path());
        self::assertNull($this->registrationAt($workspace->path()));

        $record = $this->recordOf($pass);
        self::assertNull($record->worktree()->path());
        self::assertSame(CleanupState::Removed, $record->cleanup());
        self::assertSame($commit, $this->tipOf($branch));
    }

    /**
     * The same crash, after it had taken the workspace's `.git` file.
     *
     * Git declines to remove a worktree it cannot validate even with
     * `--force`, which would leave the package unable to finish a deletion it
     * started. `git worktree repair` writes the link back, and the removal then
     * goes through — the one other command here that writes anything, and one
     * that deletes nothing.
     */
    #[Test]
    public function a_workspace_whose_link_the_interrupted_removal_took_is_repaired_and_removed(): void
    {
        if (version_compare((string) Git::version(), '2.30.0', '<')) {
            self::markTestSkipped('"git worktree repair" arrived in Git 2.30.');
        }

        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $commit = (string) $pass->commitSha();
        $branch = (string) $pass->branch();
        $pass = $this->generations->complete($pass);

        $this->givenAPartDeletedWorkspace($workspace);
        unlink($workspace->path() . '/.git');
        self::assertSame(
            128,
            Git::tryRun($this->project->root(), 'worktree', 'remove', '--force', $workspace->path())->exitCode,
            'Git is expected to refuse a worktree whose link to the repository has gone.',
        );

        $this->givenAnInterruptedRemoval($this->recordOf($pass));
        $cleanup = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($cleanup->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path());
        self::assertNull($this->registrationAt($workspace->path()));
        self::assertNull($this->recordOf($pass)->worktree()->path());
        self::assertSame($commit, $this->tipOf($branch));
    }

    /**
     * A removal that exits zero and leaves the workspace where it is.
     *
     * "Do not mark cleanup complete until Git confirms the worktree is
     * removed", asserted against a `git` that claims to have done it. What the
     * package records is checked against the repository and the filesystem, not
     * against an exit status — so nothing is written down, the row goes on
     * naming the workspace, and the removal stays recorded as unconfirmed for
     * the next run to finish. Which it then does, with a real Git.
     */
    #[Test]
    public function a_removal_git_reports_as_done_while_the_workspace_stays_is_not_recorded_as_complete(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $pass = $this->generations->complete($pass);

        $sink = new RecordingSink();
        $cleanup = $this->cleanerUsing($this->givenAGitThatOnlyPretendsToRemove())
            ->clean($pass, $sink);

        self::assertTrue($cleanup->wasKept());
        self::assertFalse($cleanup->wasRemoved());
        self::assertSame(
            sprintf('%s is still there after the removal ran', $workspace->path()),
            $cleanup->retention()->observationFor(RetentionReason::RemovalUnconfirmed),
        );
        self::assertContains(
            'cleanup: The removal ran and the workspace is still there: '
                . sprintf('%s is still there after the removal ran', $workspace->path()),
            $sink->notes,
        );

        // Nothing was recorded as finished, and the row still names the
        // workspace — which is what makes the next run try again.
        $record = $this->recordOf($pass);
        self::assertSame($workspace->path(), $record->worktree()->path());
        self::assertSame(CleanupState::Removing, $record->cleanup());
        self::assertDirectoryExists($workspace->path());

        self::assertTrue($this->cleaner()->clean($record)->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path());
        self::assertSame(CleanupState::Removed, $this->recordOf($pass)->cleanup());
    }

    /**
     * The narrowing that keeps the forced retry from being a licence.
     *
     * A removal that reported success without removing anything leaves the
     * workspace whole and the mark in place, and the message sends a person to
     * look at the path — so the next run must not delete what they wrote while
     * they were there. Deletions are the removal's own work and do not stop it;
     * anything the removal could not have produced does.
     */
    #[Test]
    public function a_resumed_removal_keeps_a_workspace_somebody_has_written_in(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->givenACommittedImplementation($pass, $workspace);
        $pass = $this->generations->complete($pass);

        $this->givenAPartDeletedWorkspace($workspace);
        $this->inWorkspace($workspace, 'what-i-found.md', "worth keeping\n");
        $this->givenAnInterruptedRemoval($this->recordOf($pass));

        $cleanup = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($cleanup->wasKept());
        self::assertSame(
            '1 change(s) the interrupted removal cannot account for: untracked file: what-i-found.md',
            $cleanup->retention()->observationFor(RetentionReason::UncommittedWork),
        );
        self::assertSame(
            "worth keeping\n",
            file_get_contents($workspace->path() . '/what-i-found.md'),
        );
        self::assertSame(CleanupState::Removing, $this->recordOf($pass)->cleanup());

        // And once what they wrote is gone, the removal finishes as it would
        // have — preserving is not a one-way door.
        unlink($workspace->path() . '/what-i-found.md');

        self::assertTrue($this->cleaner()->clean($this->recordOf($pass))->wasRemoved());
        self::assertDirectoryDoesNotExist($workspace->path());
    }

    /**
     * The one question a resumed removal still asks.
     *
     * Finishing a removal skips what can only be read inside the workspace,
     * because a part-deleted checkout answers those questions about its own
     * dismantling. It does not skip the promise that the pass's commit survives
     * the directory: that is asked of the project's repository, which can still
     * answer, and it is still a reason to stop.
     */
    #[Test]
    public function a_resumed_removal_still_refuses_to_take_a_commit_no_branch_holds(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $commit = $this->commitInWorkspaceAsThePass($workspace);
        $pass = $this->generations->complete($this->generations->recordCommit($pass, $commit));

        $this->givenAnInterruptedRemoval($pass);
        $cleanup = $this->cleaner()->clean($this->recordOf($pass));

        self::assertTrue($cleanup->wasKept());
        self::assertStringContainsString(
            'recorded with no branch',
            (string) $cleanup->retention()->observationFor(RetentionReason::CommitUnreferenced),
        );
        self::assertDirectoryExists($workspace->path());
        self::assertTrue($this->commitExists($commit));
        self::assertSame(CleanupState::Removing, $this->recordOf($pass)->cleanup());
    }

    /**
     * Preparing a workspace is also what forgets the cleanup of the last one:
     * the mark is about the directory the row names, and a pass given a fresh
     * one has had no removal issued against it.
     */
    #[Test]
    public function recording_a_fresh_workspace_forgets_the_cleanup_of_the_last_one(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->givenAnInterruptedRemoval($pass);

        self::assertTrue($this->recordOf($pass)->cleanupIsUnfinished());

        $this->generations->recordWorktree(
            $this->recordOf($pass),
            $workspace->ownership(),
        );

        self::assertNull($this->recordOf($pass)->cleanup());
        self::assertFalse($this->recordOf($pass)->cleanupIsUnfinished());
    }

    // ------------------------------------------------------------------ sweep

    /**
     * A run's whole history in one call: the finished pass is tidied up, the
     * failed one is kept, and neither decision depends on the other.
     */
    #[Test]
    public function a_sweep_removes_what_it_may_and_keeps_the_rest(): void
    {
        $finished = $this->givenAPass();
        $finishedWorkspace = $this->givenAWorkspace($finished);
        $finished = $this->generations->complete($this->generations->recordNoChange($finished));

        $failed = $this->givenAPass('Add a farewell command');
        $failedWorkspace = $this->givenAWorkspace($failed);
        $this->inWorkspace($failedWorkspace, 'src/Farewell.php', self::WORK);
        $failed = $this->generations->fail($failed);

        $never = $this->givenAPass('Document the greeting command');
        $never = $this->generations->complete($this->generations->recordNoChange($never));

        $outcomes = $this->cleaner()->sweep([$finished, $failed, $never]);

        self::assertCount(3, $outcomes);
        self::assertTrue($outcomes[0]->wasRemoved());
        self::assertTrue($outcomes[1]->wasKept());
        self::assertTrue($outcomes[2]->retention()->isAbsent());

        self::assertDirectoryDoesNotExist($finishedWorkspace->path());
        self::assertSame(self::WORK, file_get_contents($failedWorkspace->path() . '/src/Farewell.php'));
    }

    #[Test]
    public function a_sweep_of_nothing_is_an_empty_report(): void
    {
        self::assertSame([], $this->cleaner()->sweep([]));
    }

    // -------------------------------------------------------- the value types

    #[Test]
    public function every_reason_says_what_it_is_and_what_to_do_about_it(): void
    {
        foreach (RetentionReason::all() as $reason) {
            self::assertNotSame('', $reason->describe(), $reason->value);
            self::assertNotSame('', $reason->hint(), $reason->value);
        }

        self::assertTrue(RetentionReason::PassUnfinished->isInspection());
        self::assertFalse(RetentionReason::NotOwned->isInspection());
        self::assertTrue(RetentionReason::RemovalRefused->isGitRefusal());
        self::assertFalse(RetentionReason::UncommittedWork->isGitRefusal());

        foreach (RetentionVerdict::cases() as $verdict) {
            self::assertNotSame('', $verdict->describe(), $verdict->value);
        }
    }

    #[Test]
    public function a_decision_reports_a_line_per_reason_without_repeating_a_shared_hint(): void
    {
        $retention = WorkspaceRetention::preserved('TASK-ABCD1234/1', '/tmp/w', [
            RetentionReason::UncommittedWork->value => '2 uncommitted change(s): a.php, b.php',
            RetentionReason::Locked->value => '',
        ]);

        self::assertSame(
            [
                'The workspace holds changes that are in no commit: 2 uncommitted change(s): a.php, b.php',
                'Git has the worktree locked.',
            ],
            $retention->details(),
        );
        self::assertSame(
            [RetentionReason::UncommittedWork, RetentionReason::Locked],
            $retention->reasons(),
        );
        self::assertCount(2, $retention->hints());
        self::assertFalse($retention->isForInspection());
        self::assertStringContainsString('2 uncommitted change(s)', $retention->describe());
        self::assertStringContainsString('Git has the worktree locked', $retention->describe());
    }

    #[Test]
    public function a_removal_git_refuses_is_reported_as_a_workspace_that_stayed(): void
    {
        $refused = WorkspaceRetention::removable('TASK-ABCD1234/1', '/tmp/w')
            ->withReason(RetentionReason::RemovalRefused, 'exited with 128: it is in use');
        $cleanup = WorktreeCleanup::kept($refused);

        self::assertFalse($cleanup->wasRemoved());
        self::assertTrue($cleanup->wasKept());
        self::assertSame(RetentionVerdict::Preserved, $cleanup->retention()->verdict());
        self::assertSame(
            ['Git refused to remove the workspace: exited with 128: it is in use'],
            $cleanup->details(),
        );
        self::assertSame(
            [
                'removed' => false,
                'registration_only' => false,
                'retention' => [
                    'generation' => 'TASK-ABCD1234/1',
                    'path' => '/tmp/w',
                    'verdict' => 'preserved',
                    'for_inspection' => false,
                    'reasons' => [
                        RetentionReason::RemovalRefused->value => 'exited with 128: it is in use',
                    ],
                ],
            ],
            $cleanup->toArray(),
        );
    }

    #[Test]
    public function a_workspace_cannot_be_kept_for_no_stated_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must say why it is being kept');

        WorkspaceRetention::preserved('TASK-ABCD1234/1', '/tmp/w', []);
    }

    #[Test]
    public function a_workspace_cannot_be_kept_for_a_reason_this_version_does_not_know(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown reason "it looked untidy"');

        WorkspaceRetention::preserved('TASK-ABCD1234/1', '/tmp/w', ['it looked untidy' => 'no']);
    }

    #[Test]
    public function a_decision_about_no_workspace_cannot_acquire_a_reason_to_keep_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no workspace for TASK-ABCD1234/1 to keep');

        WorkspaceRetention::absent('TASK-ABCD1234/1')
            ->withReason(RetentionReason::RemovalRefused, 'exited with 128');
    }

    #[Test]
    public function a_decision_must_name_the_pass_and_the_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkspaceRetention::removable('  ', '/tmp/w');
    }

    #[Test]
    public function a_removable_decision_must_name_a_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a directory');

        WorkspaceRetention::removable('TASK-ABCD1234/1', '   ');
    }

    #[Test]
    public function an_outcome_cannot_claim_a_removal_the_decision_refused(): void
    {
        $kept = WorkspaceRetention::preserved('TASK-ABCD1234/1', '/tmp/w', [
            RetentionReason::UncommittedWork->value => '1 uncommitted change(s): a.php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have been removable');

        WorktreeCleanup::removed($kept);
    }

    #[Test]
    public function an_outcome_cannot_claim_a_cleared_registration_the_decision_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have been removable');

        WorktreeCleanup::cleared(WorkspaceRetention::absent('TASK-ABCD1234/1'));
    }

    #[Test]
    public function an_outcome_cannot_report_a_workspace_as_kept_without_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must say why');

        WorktreeCleanup::kept(WorkspaceRetention::removable('TASK-ABCD1234/1', '/tmp/w'));
    }

    #[Test]
    public function an_outcome_cannot_report_nothing_when_there_was_a_workspace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have found no workspace');

        WorktreeCleanup::nothing(WorkspaceRetention::removable('TASK-ABCD1234/1', '/tmp/w'));
    }

    #[Test]
    public function a_removed_workspace_reports_itself_for_a_log_and_for_json(): void
    {
        $cleanup = WorktreeCleanup::removed(
            WorkspaceRetention::removable('TASK-ABCD1234/1', '/tmp/w'),
        );

        // No prefix of its own: the caller supplies the context, so the same
        // phrase reads as `cleanup: …` in a log and as `workspace: …` beside a
        // completion's commit and push.
        self::assertSame('removed /tmp/w', $cleanup->describe());
        self::assertSame('TASK-ABCD1234/1', $cleanup->label());
        self::assertSame([], $cleanup->hints());
        self::assertSame(
            [
                'removed' => true,
                'registration_only' => false,
                'retention' => [
                    'generation' => 'TASK-ABCD1234/1',
                    'path' => '/tmp/w',
                    'verdict' => 'removable',
                    'for_inspection' => false,
                    'reasons' => [],
                ],
            ],
            $cleanup->toArray(),
        );
    }

    /**
     * The class's central promise, asserted the only way it can be.
     *
     * Most of what makes cleanup safe is refused by
     * {@see WorktreeCleaner::assess()} before a removal is attempted, which is
     * what makes the behaviour correct and also what makes much of it
     * invisible from the outside: a `reset` or a `branch -D` added beside the
     * one command here would change no outcome any case in this file can
     * reach. So the promise is checked against the source, where a command
     * that widens what cleanup may destroy is visible whether or not any
     * situation currently reaches it.
     *
     * `--force` is the exception, and it is checked **twice** — here, that
     * there is exactly one of it, and behaviourally in
     * `a_part_deleted_workspace_is_finished_off_only_when_a_removal_was_recorded()`
     * and its companion, which together show that the gate is a removal this
     * package recorded issuing and nothing else.
     */
    #[Test]
    public function cleanup_issues_no_command_that_can_delete_more_than_it_was_asked_to(): void
    {
        $source = (string) file_get_contents(Paths::inPackage('src/Git/WorktreeCleaner.php'));
        $code = implode("\n", array_filter(
            explode("\n", $source),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '*')
                && !str_starts_with(ltrim($line), '//')
                && !str_starts_with(ltrim($line), '/*'),
        ));

        foreach (["'-f'", "'branch'", "'reset'", "'prune'", "'checkout'", "'clean'"] as $argument) {
            self::assertStringNotContainsString(
                $argument,
                $code,
                sprintf('Cleanup passed %s to Git; it may only remove a worktree it has judged removable.', $argument),
            );
        }

        // One `--force`, in the one place a removal that is being resumed may
        // reach: a second would mean a first attempt could overrule Git.
        self::assertSame(
            1,
            substr_count($code, "'--force'"),
            'Cleanup may force exactly one removal: the retry of one it recorded issuing.',
        );

        // The two commands that write, and nothing else. `repair` rewrites the
        // link between a workspace and its repository; it deletes nothing.
        self::assertStringContainsString("\$arguments = ['worktree', 'remove'];", $code);
        self::assertStringContainsString("'worktree',\n            'repair',", $code);
    }

    // ---------------------------------------------------------------- helpers

    private function givenACommittedProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('README.md', "An example project.\n");
        $this->project->write('src/Greeting.php', "<?php\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    /**
     * A recorded pass, by default one that has spent an attempt — which is the
     * state a task is in by the time cleanup has anything to decide.
     */
    private function givenAPass(string $description = self::DESCRIPTION, bool $started = true): GenerationRecord
    {
        $snapshot = $this->snapshot($description);
        $this->tasks->record($snapshot);

        $generation = $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base()->sha(),
            null,
            $this->insertRun(),
        );

        return $started ? $this->generations->beginAttempt($generation) : $generation;
    }

    /**
     * A real linked worktree for the pass, created the way a run creates it.
     */
    private function givenAWorkspace(GenerationRecord $pass): TaskWorktree
    {
        $workspace = TaskWorktrees::forProject($this->configuration())
            ->prepare($pass->generation(), $this->base());

        $this->generations->recordWorktree($pass, $workspace->ownership());

        return $workspace;
    }

    /**
     * The pass's work, staged, branched and committed the way a run does it, so
     * that what cleanup decides about is a real commit on a real branch.
     */
    private function givenACommittedImplementation(
        GenerationRecord $pass,
        TaskWorktree $workspace,
    ): GenerationRecord {
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);

        $configuration = $this->configuration();
        $staged = WorkspaceStager::forProject($configuration)->stage($workspace);
        $current = $this->recordOf($pass);
        $branch = $this->branchOf($current);
        $ownership = TaskBranches::forProject($configuration)->require($branch, $current);

        TaskCommitter::forProject($configuration, $this->generations)->commit(
            $current,
            $workspace,
            $ownership,
            $staged,
            CommitMessage::forGeneration($current, $this->snapshot(self::DESCRIPTION)),
        );

        return $this->recordOf($pass);
    }

    /**
     * The row as {@see WorktreeCleaner::clean()} leaves it when it is
     * interrupted between marking the removal and confirming it.
     *
     * Written through the store rather than by hand, so the test and the run
     * reach the state the same way.
     */
    private function givenAnInterruptedRemoval(GenerationRecord $pass): GenerationRecord
    {
        return $this->generations->beginWorktreeCleanup($this->recordOf($pass));
    }

    /**
     * A workspace as a removal that was interrupted part way leaves it: some of
     * the tree gone, the rest still there.
     *
     * Deletions of tracked files, which is what Git's own `remove_dir_recursively`
     * had got through — and which `git status` reports as modifications, which
     * is why Git refuses to remove the remains without being forced.
     */
    private function givenAPartDeletedWorkspace(TaskWorktree $workspace): void
    {
        unlink($workspace->path() . '/src/Greeting.php');
        unlink($workspace->path() . '/README.md');

        self::assertDirectoryExists($workspace->path());
        self::assertNotNull($this->registrationAt($workspace->path()));
    }

    /**
     * A `git` that reports a removal it has not carried out.
     *
     * Everything else is passed through to the real Git, so the assessment
     * before the removal and the confirmation after it are both answered by the
     * repository as it actually is. The only lie is the one being tested: a
     * `worktree remove` that exits zero and touches nothing.
     *
     * @return string the path to the executable
     */
    private function givenAGitThatOnlyPretendsToRemove(): string
    {
        $path = $this->project->root() . '/.ralph/runtime/git-that-pretends';
        $script = sprintf(
            "#!%s\n<?php\n"
                . "\$arguments = array_slice(\$argv, 1);\n"
                . "if ((\$arguments[0] ?? '') === 'worktree' && (\$arguments[1] ?? '') === 'remove') {\n"
                . "    exit(0);\n"
                . "}\n"
                . "\$command = array_map('escapeshellarg', ['git', ...\$arguments]);\n"
                . "passthru(implode(' ', \$command), \$status);\n"
                . "exit(\$status);\n",
            PHP_BINARY,
        );

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true) && !is_dir(dirname($path))) {
            self::fail('Could not create ' . dirname($path));
        }

        if (file_put_contents($path, $script) === false || !chmod($path, 0o700)) {
            self::fail('Could not install the git stub at ' . $path);
        }

        return $path;
    }

    private function cleanerUsing(string $executable): WorktreeCleaner
    {
        return WorktreeCleaner::forProject($this->configuration(), $this->generations, $executable);
    }

    private function cleaner(): WorktreeCleaner
    {
        return WorktreeCleaner::forProject($this->configuration(), $this->generations);
    }

    private function branchOf(GenerationRecord $pass): TaskBranch
    {
        return BranchNamer::forProject($this->configuration())
            ->resolve($pass->generation(), self::DESCRIPTION);
    }

    private function recordOf(GenerationRecord $pass): GenerationRecord
    {
        return $this->generations->get($pass->generation());
    }

    private function snapshot(string $description): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->fromArray([], $this->project->root());
    }

    private function base(): BaseCommit
    {
        return $this->base ??= BaseResolver::forProject($this->configuration())->resolve();
    }

    private function insertRun(): int
    {
        if ($this->run !== null) {
            return $this->run;
        }

        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-0001',
                'running',
                $agent['agent'],
                $agent['adapter'],
                'project_default',
                $agent['settings'],
                '{}',
                '[]',
                '.ralph/tasks.md',
                'HEAD',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->run = $this->database->lastInsertId();
    }

    private function inWorkspace(TaskWorktree $workspace, string $relative, string $contents): void
    {
        $path = $workspace->path() . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            self::fail('Could not create ' . $directory);
        }

        file_put_contents($path, $contents);
    }

    /**
     * A commit made in the workspace while it is still detached, which is what
     * a pass's own commit looks like before a branch is involved.
     */
    private function commitInWorkspaceAsThePass(TaskWorktree $workspace): string
    {
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);

        return $this->commitInWorkspace($workspace, 'The pass committed this');
    }

    private function commitInWorkspace(TaskWorktree $workspace, string $message): string
    {
        Git::run($workspace->path(), 'add', '--all');

        return Git::commit($workspace->path(), $message);
    }

    /**
     * What a user does when they have finished looking at a preserved
     * workspace: delete the directory and leave Git's record behind.
     */
    private function removeByHand(string $path): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private function registrationAt(string $path): ?string
    {
        $output = Git::run($this->project->root(), 'worktree', 'list', '--porcelain')->standardOutput;

        foreach (explode("\n", $output) as $line) {
            if (trim($line) === 'worktree ' . $path) {
                return $path;
            }
        }

        return null;
    }

    private function tipOf(string $branch): ?string
    {
        $result = Git::tryRun(
            $this->project->root(),
            'rev-parse',
            '--verify',
            '--quiet',
            'refs/heads/' . $branch,
        );

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    private function commitExists(string $sha): bool
    {
        return Git::tryRun($this->project->root(), 'cat-file', '-e', $sha . '^{commit}')->exitCode === 0;
    }

    /**
     * @return list<string>
     */
    private function stagedIn(string $directory): array
    {
        $output = trim(
            Git::run($directory, 'diff', '--cached', '--name-only', '--no-renames')->standardOutput,
        );

        if ($output === '') {
            return [];
        }

        $paths = array_map('trim', explode("\n", $output));
        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $sha): array
    {
        $output = trim(
            Git::run($this->project->root(), 'show', '--name-only', '--format=', '--no-renames', $sha)->standardOutput,
        );

        if ($output === '') {
            return [];
        }

        $paths = array_map('trim', explode("\n", $output));
        sort($paths);

        return $paths;
    }
}
