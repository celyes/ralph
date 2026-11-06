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
use Celyes\RalphPhp\Git\WorkspaceRetention;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Git\WorktreeCleaner;
use Celyes\RalphPhp\Git\WorktreeCleanup;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Blocker;
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
            sprintf('cleanup: %s had already gone; Git\'s record of it was cleared', $workspace->path()),
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
     * A workspace that has already been cleaned up, or that a `rm -rf` and a
     * `git worktree prune` both reached: nothing is there, and nothing is wrong.
     */
    #[Test]
    public function a_row_naming_a_workspace_that_has_entirely_gone_is_absent(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $pass = $this->generations->complete($this->generations->recordNoChange($pass));

        $this->removeByHand($workspace->path());
        Git::run($this->project->root(), 'worktree', 'prune');

        $cleanup = $this->cleaner()->clean($pass);

        self::assertTrue($cleanup->retention()->isAbsent());
        self::assertSame($workspace->path(), $cleanup->path());
        self::assertFalse($cleanup->wasRemoved());
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

        self::assertSame('cleanup: removed /tmp/w', $cleanup->describe());
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
     * Every destructive Git flag is refused by {@see WorktreeCleaner::assess()}
     * before a removal is attempted, which is what makes the behaviour correct
     * and also what makes it untestable from the outside: a `--force` added to
     * the one command here would change no outcome any case in this file can
     * reach, because nothing gets as far as the command unless Git would have
     * agreed to it anyway. So the promise is checked against the source, where
     * a flag that widens what cleanup may delete is visible whether or not any
     * situation currently reaches it.
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

        foreach (["'--force'", "'-f'", "'branch'", "'reset'", "'prune'", "'checkout'", "'clean'"] as $argument) {
            self::assertStringNotContainsString(
                $argument,
                $code,
                sprintf('Cleanup passed %s to Git; it may only remove a worktree it has judged removable.', $argument),
            );
        }

        self::assertStringContainsString("'worktree',\n            'remove',", $code);
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
