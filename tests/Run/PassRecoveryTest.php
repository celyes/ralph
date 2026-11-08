<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\BranchRestorer;
use Celyes\RalphPhp\Git\CommitIdentity;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommit;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Run\PassRecovery;
use Celyes\RalphPhp\Run\Recovery;
use Celyes\RalphPhp\Run\RecoveryVerdict;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Putting an interrupted pass's row back in step with the repository
 * (PROJECT.md section H, item 5), against real commits, real linked worktrees
 * and a real state store.
 *
 * The named case is the one the whole file is arranged around: a crash
 * **immediately after a commit and before the database update**. It is produced
 * the way it really happens — the commit is made by {@see TaskCommitter}, and
 * then the column it would have written is cleared, which is exactly the state
 * a process killed between the two leaves behind. Every assertion about it is
 * about one thing: that the work is found rather than done again.
 *
 * Two assertions recur. The repository is read back with `git` rather than from
 * the returned value, because the value is a claim and the repository is what a
 * reviewer sees. And every refusal is asserted to have changed nothing — no
 * commit created, none adopted, no branch moved, no directory removed — since a
 * recovery that tidies up after an ambiguity it could not resolve is worse than
 * one that stops.
 */
#[CoversClass(PassRecovery::class)]
#[CoversClass(Recovery::class)]
#[CoversClass(RecoveryVerdict::class)]
#[CoversClass(BranchRestorer::class)]
final class PassRecoveryTest extends TestCase
{
    private const string DESCRIPTION = 'Add a greeting command';

    private TemporaryProject $project;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private ?BaseCommit $base = null;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this reconciles real repositories.');
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

    /**
     * The item's named case: the commit happened, the row never learned of it.
     */
    #[Test]
    public function a_commit_the_crash_never_recorded_is_adopted_from_the_branch(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $crashed = $this->crashedAfterCommitting($committed->recorded());

        $sink = new RecordingSink();
        $recovery = $this->recovery()->reconcile($crashed, $sink);

        self::assertSame(RecoveryVerdict::Reconciled, $recovery->verdict());
        self::assertTrue($recovery->adoptedCommit());
        self::assertTrue($recovery->allowsResume());
        self::assertNull($recovery->blocker());
        self::assertSame($committed->sha(), $recovery->commit()?->sha());

        // The row, read back through the store rather than from the value that
        // claims it: the next thing to happen to this pass is a push or a
        // checklist write, and that depends on the column.
        $stored = $this->generations->get($pass->generation());
        self::assertSame($committed->sha(), $stored->commitSha());
        self::assertSame($committed->sha(), $recovery->record()->commitSha());
        self::assertTrue($stored->hasImplementation());

        // And one commit, still. The duplicate of acceptance criterion 6 is
        // what adopting exists to prevent.
        self::assertSame($committed->sha(), $this->tipOf($committed->branch()->value()));
        self::assertSame(1, $this->countOfCommitsOn($committed->branch()->value()));

        self::assertStringContainsString('adopted the commit', implode("\n", $sink->notes));
        self::assertStringContainsString('adopted the commit', $recovery->describe());
    }

    /**
     * What a resumed pass is told to do next, which is the question a user who
     * has just restarted a run actually has.
     */
    #[Test]
    public function an_adopted_commit_leaves_the_pass_owing_only_what_follows_a_commit(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $crashed = $this->crashedAfterCommitting($committed->recorded());

        $recovery = $this->recovery()->reconcile($crashed);
        $written = $recovery->toArray();

        self::assertSame(Phase::Committing->resumeAction(), $recovery->nextAction());
        self::assertSame('reconciled', $written['verdict']);
        self::assertTrue($written['adopted_commit']);
        self::assertSame($pass->slug(), $written['generation']);
        self::assertNull($written['blocker']);
        self::assertCount(1, $recovery->actions());
    }

    #[Test]
    public function a_pass_interrupted_before_it_committed_anything_is_already_consistent(): void
    {
        $pass = $this->givenAPass();
        $this->givenAWorkspace($pass);
        $this->inWorkspace($pass, 'src/Greeting.php', "<?php\n\n// half written\n");
        $interrupted = $this->interrupted($pass, Phase::Executing);

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertFalse($recovery->wasReconciled());
        self::assertNull($recovery->commit());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame(Phase::Executing->resumeAction(), $recovery->nextAction());

        $lines = implode("\n", $recovery->lines());
        self::assertStringContainsString('is still in place', $lines);
        self::assertStringContainsString('had committed nothing', $lines);

        // The half-written file is still there: nothing about reconciliation
        // tidies a workspace up.
        self::assertFileExists($this->workspacePath($pass) . '/src/Greeting.php');
    }

    #[Test]
    public function a_pass_whose_recorded_commit_is_still_on_its_branch_is_already_consistent(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $interrupted = $this->interrupted($committed->recorded(), Phase::Publishing);

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertFalse($recovery->adoptedCommit());
        self::assertSame($committed->sha(), $recovery->commit()?->sha());
        self::assertStringContainsString(
            'holds its commit ' . $committed->shortSha(),
            implode("\n", $recovery->lines()),
        );
        self::assertSame(Phase::Publishing->resumeAction(), $recovery->nextAction());
    }

    /**
     * The branch is a reference; the commit is the work. A reference that has
     * gone is created again at the commit the row records, which moves nothing
     * and discards nothing.
     */
    #[Test]
    public function a_branch_deleted_from_under_a_recorded_commit_is_created_again(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $branch = $committed->branch()->value();

        Git::run($workspace->path(), 'switch', '--detach');
        Git::run($this->project->root(), 'branch', '--delete', '--force', $branch);
        self::assertNull($this->tipOf($branch));

        $sink = new RecordingSink();
        $recovery = $this->recovery()->reconcile($this->interrupted($committed->recorded(), Phase::Publishing), $sink);

        self::assertSame(RecoveryVerdict::Reconciled, $recovery->verdict());
        self::assertFalse($recovery->adoptedCommit(), 'the commit was already recorded');
        self::assertSame($committed->sha(), $this->tipOf($branch));
        self::assertStringContainsString('re-created the branch ' . $branch, implode("\n", $recovery->actions()));
        self::assertStringContainsString('re-created the branch', implode("\n", $sink->notes));
    }

    #[Test]
    public function a_recorded_commit_the_repository_no_longer_has_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $invented = $this->givenTheRowRecords($committed->recorded(), '0f1e2d3c4b5a69788796a5b4c3d2e1f009182736');

        $recovery = $this->recovery()->reconcile($invented);

        self::assertTrue($recovery->isBlocked());
        self::assertFalse($recovery->allowsResume());
        self::assertStringContainsString(
            'and the repository does not have it',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('ralph retry', (string) $recovery->blocker()?->nextAction());
        self::assertSame($recovery->blocker()?->nextAction(), $recovery->nextAction());

        // Nothing taken on, and the real commit left where it is.
        self::assertSame($committed->sha(), $this->tipOf($committed->branch()->value()));
    }

    /**
     * A branch holding a commit nothing recorded is the case that must never be
     * adopted: a commit no check of this run ran against would be pushed and
     * ticked off as the task's work.
     */
    #[Test]
    public function a_stranger_commit_on_the_claimed_branch_is_never_adopted(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchFor($pass);
        $this->generations->assignBranch($pass, $branch->value());

        $stranger = $this->commitInWorkspaceOn($workspace, $branch, "Someone else's work\n");
        $interrupted = $this->interrupted($this->generations->get($pass->generation()), Phase::Committing);

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('points at ' . substr($stranger, 0, CommitIdentity::SHORT_LENGTH), (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('no pass recorded', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('git log ' . $branch->value(), (string) $recovery->blocker()?->nextAction());

        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame($stranger, $this->tipOf($branch->value()));
    }

    /**
     * A reopened task's second pass finding the first pass's commit needs its
     * own words: that commit belongs to work that is already complete.
     */
    #[Test]
    public function a_commit_of_another_pass_at_the_same_task_is_never_adopted(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchFor($pass);
        $this->generations->assignBranch($pass, $branch->value());

        $elsewhere = TaskGeneration::of($pass->task(), 2);
        $this->commitInWorkspaceOn(
            $workspace,
            $branch,
            CommitMessage::of($elsewhere, self::DESCRIPTION, $this->base()->sha(), 1, 1, 'codex')->text(),
        );

        $recovery = $this->recovery()->reconcile($this->generations->get($pass->generation()));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString(
            'names generation 2 of this task rather than generation 1',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * One commit past the base is what a pass leaves. Two means something
     * committed that this package did not, and the trailers cannot say which of
     * them is the task's work.
     */
    #[Test]
    public function a_branch_holding_more_than_the_passs_own_commit_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchFor($pass);
        $this->generations->assignBranch($pass, $branch->value());

        // A worker's commit first, then one carrying this pass's trailers on
        // top of it.
        $this->commitInWorkspaceOn($workspace, $branch, "A commit of the agent's own\n");
        $this->commitInWorkspaceOn(
            $workspace,
            $branch,
            CommitMessage::of($pass->generation(), self::DESCRIPTION, $this->base()->sha(), 1, 1, 'codex')->text(),
        );

        $recovery = $this->recovery()->reconcile($this->generations->get($pass->generation()));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('holds 2 commits past the base commit', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('A pass commits once', (string) $recovery->blocker()?->nextAction());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * The pass's own commit, written against a base this run never resolved:
     * the work was verified against something else.
     */
    #[Test]
    public function a_commit_of_this_pass_written_against_another_base_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchFor($pass);
        $this->generations->assignBranch($pass, $branch->value());

        $elsewhere = '0f1e2d3c4b5a69788796a5b4c3d2e1f009182736';
        $this->commitInWorkspaceOn(
            $workspace,
            $branch,
            CommitMessage::of($pass->generation(), self::DESCRIPTION, $elsewhere, 1, 1, 'codex')->text(),
        );

        $recovery = $this->recovery()->reconcile($this->generations->get($pass->generation()));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('was written against the base commit', (string) $recovery->blocker()?->reason());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * A branch that moved on past the recorded commit would publish somebody
     * else's commits under the task's name.
     */
    #[Test]
    public function a_branch_that_moved_past_the_recorded_commit_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);

        $this->inWorkspace($pass, 'src/Unrelated.php', "<?php\n");
        Git::run($workspace->path(), 'add', '--all');
        Git::run($workspace->path(), 'commit', '--message', "Something after the fact\n");
        $moved = $this->tipOf($committed->branch()->value());

        $recovery = $this->recovery()->reconcile($this->interrupted($committed->recorded(), Phase::Publishing));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString(
            'what would be published is not what the pass committed',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('moved 1 commit past', (string) $recovery->blocker()?->nextAction());
        self::assertSame($moved, $this->tipOf($committed->branch()->value()), 'the branch was left alone');
        self::assertSame($committed->sha(), $this->generations->get($pass->generation())->commitSha());
    }

    #[Test]
    public function a_workspace_that_has_gone_is_reported_rather_than_blocking(): void
    {
        $pass = $this->givenAPass();
        $this->givenAWorkspace($pass);
        $interrupted = $this->interrupted($pass, Phase::Executing);

        TemporaryDirectory::remove($this->workspacePath($pass));
        Git::run($this->project->root(), 'worktree', 'prune');

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'so whatever the agent had written in it is lost',
            implode("\n", $recovery->findings()),
        );
    }

    /**
     * A directory in the right place that Git does not list cannot be committed
     * from and will not be replaced, so a pass with nothing recorded stops.
     */
    #[Test]
    public function a_workspace_git_no_longer_lists_stops_a_pass_with_nothing_recorded(): void
    {
        $pass = $this->givenAPass();
        $this->givenAWorkspace($pass);
        $this->inWorkspace($pass, 'src/Greeting.php', "<?php\n\n// work in progress\n");

        $this->forgetTheRegistrationOf($pass);

        $recovery = $this->recovery()->reconcile($this->interrupted($pass, Phase::Executing));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('no longer lists it as a worktree', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('git worktree repair', (string) $recovery->blocker()?->nextAction());

        // Still there, with the work in it: a directory this package cannot
        // account for is not one it removes.
        self::assertFileExists($this->workspacePath($pass) . '/src/Greeting.php');
    }

    /**
     * The same directory, for a pass whose work is already committed: there is
     * nothing the workspace is owed, so it is reported and the recovery goes on
     * to the commit.
     */
    #[Test]
    public function a_workspace_git_no_longer_lists_does_not_stop_a_pass_that_has_committed(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);

        $this->forgetTheRegistrationOf($pass);

        $recovery = $this->recovery()->reconcile($this->interrupted($committed->recorded(), Phase::Publishing));

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'the pass\'s recorded work does not need it',
            implode("\n", $recovery->findings()),
        );
    }

    /**
     * PROJECT.md section G, item 6's outcome: an implementation, no commit, and
     * a branch that should never have acquired one.
     */
    #[Test]
    public function a_verified_no_change_outcome_is_consistent_with_an_empty_branch(): void
    {
        $pass = $this->givenAPass();
        $this->givenAWorkspace($pass);
        $unchanged = $this->generations->recordNoChange($this->interrupted($pass, Phase::Finalizing));

        $recovery = $this->recovery()->reconcile($unchanged);

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertNull($recovery->commit());
        self::assertStringContainsString(
            'recorded as changing nothing, and claimed no branch',
            implode("\n", $recovery->findings()),
        );
    }

    #[Test]
    public function a_commit_on_a_no_change_passs_branch_contradicts_its_evidence(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchFor($pass);
        $this->generations->assignBranch($pass, $branch->value());
        $this->commitInWorkspaceOn($workspace, $branch, "A commit nobody recorded\n");

        $unchanged = $this->generations->recordNoChange($this->generations->get($pass->generation()));

        $recovery = $this->recovery()->reconcile($unchanged);

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString(
            'recorded as leaving the task unchanged',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('satisfaction record', (string) $recovery->blocker()?->nextAction());
    }

    /**
     * A pass stopped during preparation has no workspace yet, and nothing about
     * that is a problem to put right.
     */
    #[Test]
    public function a_pass_interrupted_before_it_had_a_workspace_is_already_consistent(): void
    {
        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->givenAPass(Phase::Preparing), Phase::Preparing),
        );

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'no workspace was recorded for this pass',
            implode("\n", $recovery->findings()),
        );
        self::assertSame(Phase::Preparing->resumeAction(), $recovery->nextAction());
    }

    /**
     * Git's registration without the directory is the one workspace state that
     * can simply be checked out again, and `prepare` already does it.
     */
    #[Test]
    public function a_registered_workspace_whose_directory_has_gone_will_be_checked_out_again(): void
    {
        $pass = $this->givenAPass();
        $this->givenAWorkspace($pass);
        TemporaryDirectory::remove($this->workspacePath($pass));

        $recovery = $this->recovery()->reconcile($this->interrupted($pass, Phase::Executing));

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'its directory has gone; it will be checked out again from the base commit',
            implode("\n", $recovery->findings()),
        );
    }

    /**
     * A commit with no branch to publish it from cannot be written by a
     * complete pass — the branch is claimed first — so it is reported as the
     * defect it is rather than guessed about.
     */
    #[Test]
    public function a_recorded_commit_with_no_branch_recorded_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);

        $this->database->execute(
            'UPDATE generations SET branch = NULL WHERE id = ?',
            [$pass->rowId()],
        );

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($pass->generation()), Phase::Publishing),
        );

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('and no branch', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('git show ' . $committed->sha(), (string) $recovery->blocker()?->nextAction());
        self::assertSame($committed->sha(), $recovery->commit()?->sha());
    }

    #[Test]
    public function a_pass_that_is_finished_with_has_nothing_to_reconcile(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $committed = $this->givenACommit($pass, $workspace);
        $completed = $this->generations->complete($committed->recorded());

        try {
            $this->recovery()->reconcile($completed);
        } catch (StateException $exception) {
            self::assertStringContainsString('is completed, so there is nothing of it', $exception->getMessage());
            self::assertStringContainsString('taken on as a new pass', implode("\n", $exception->hints()));

            return;
        }

        self::fail('A completed pass was expected to be refused.');
    }

    /**
     * A row whose workspace is not where this project puts workspaces: the
     * work may be in a directory nothing will look at again.
     */
    #[Test]
    public function a_workspace_recorded_somewhere_else_stops_the_pass(): void
    {
        $pass = $this->givenAPass();
        $elsewhere = $this->project->path('somewhere-else');
        $this->database->execute(
            'UPDATE generations SET worktree_path = ?, owns_worktree = 1 WHERE id = ?',
            [$elsewhere, $pass->rowId()],
        );

        $recovery = $this->recovery()->reconcile($this->generations->get($pass->generation()));

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('recorded its workspace at ' . $elsewhere, (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('puts task workspaces in', (string) $recovery->blocker()?->reason());
    }

    private function recovery(): PassRecovery
    {
        return PassRecovery::forProject($this->configuration(), $this->generations);
    }

    /**
     * A repository with one commit, which is what a task workspace is created
     * from.
     */
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
     * A pass that has spent an attempt and is in the middle of its work.
     */
    private function givenAPass(Phase $phase = Phase::Executing): GenerationRecord
    {
        $snapshot = $this->snapshot();
        $this->tasks->record($snapshot);

        $started = $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base()->sha(),
            null,
            $this->insertRun(),
        );

        $attempting = $this->generations->beginAttempt($started);

        // `beginAttempt` leaves the pass at the first phase, and a phase never
        // goes backwards, so the initial one is reached by not moving.
        return $phase === Phase::initial()
            ? $attempting
            : $this->generations->enterPhase($attempting, $phase);
    }

    private function givenAWorkspace(GenerationRecord $pass): TaskWorktree
    {
        $workspace = TaskWorktrees::forProject($this->configuration())->prepare($pass->generation(), $this->base());
        $this->generations->recordWorktree($pass, $workspace->ownership());

        return $workspace;
    }

    /**
     * The pass's one commit, made the way a run makes it.
     */
    private function givenACommit(GenerationRecord $pass, TaskWorktree $workspace): TaskCommit
    {
        $configuration = $this->configuration();
        $this->inWorkspace($pass, 'src/Greeting.php', "<?php\n\n// changed\n");

        $current = $this->generations->get($pass->generation());
        $branch = $this->branchFor($current);

        return TaskCommitter::forProject($configuration, $this->generations)->commit(
            $current,
            $workspace,
            TaskBranches::forProject($configuration)->require($branch, $current),
            WorkspaceStager::forProject($configuration)->stage($workspace),
            CommitMessage::forGeneration($current, $this->snapshot()),
        );
    }

    /**
     * The state a process killed between the commit and the row it would have
     * written leaves behind: the commit in the repository, the column null.
     */
    private function crashedAfterCommitting(GenerationRecord $pass): GenerationRecord
    {
        $this->database->execute(
            'UPDATE generations SET commit_sha = NULL WHERE id = ?',
            [$pass->rowId()],
        );

        return $this->interrupted($this->generations->get($pass->generation()), Phase::Committing);
    }

    /**
     * Make the row name a commit of this test's choosing.
     */
    private function givenTheRowRecords(GenerationRecord $pass, string $sha): GenerationRecord
    {
        $this->database->execute(
            'UPDATE generations SET commit_sha = ? WHERE id = ?',
            [$sha, $pass->rowId()],
        );

        return $this->generations->get($pass->generation());
    }

    /**
     * Stop the pass where a crash would have left it.
     */
    private function interrupted(GenerationRecord $pass, Phase $phase): GenerationRecord
    {
        $current = $this->generations->get($pass->generation());

        return $this->generations->interrupt(
            $current->phase() === $phase ? $current : $this->generations->enterPhase($current, $phase),
        );
    }

    /**
     * Take Git's registration of the pass's worktree away, leaving the
     * directory and its contents where they are.
     */
    private function forgetTheRegistrationOf(GenerationRecord $pass): void
    {
        $metadata = sprintf('%s/.git/worktrees/%s', $this->project->root(), $pass->generation()->slug());

        self::assertDirectoryExists($metadata);
        TemporaryDirectory::remove($metadata);
    }

    /**
     * A commit made in the pass's workspace, on the pass's branch, by something
     * that is not this package.
     */
    private function commitInWorkspaceOn(TaskWorktree $workspace, TaskBranch $branch, string $message): string
    {
        $attached = trim(
            Git::tryRun($workspace->path(), 'symbolic-ref', '--quiet', '--short', 'HEAD')->standardOutput,
        );

        if ($attached !== $branch->value()) {
            Git::run($workspace->path(), 'switch', '--create', $branch->value());
        }

        file_put_contents(
            $workspace->path() . '/NOTES.md',
            sprintf("Something written at %s.\n", bin2hex(random_bytes(4))),
        );
        Git::run($workspace->path(), 'add', '--all');
        Git::run($workspace->path(), 'commit', '--message', $message, '--cleanup=verbatim');

        return trim(Git::run($workspace->path(), 'rev-parse', 'HEAD')->standardOutput);
    }

    private function branchFor(GenerationRecord $pass): TaskBranch
    {
        return BranchNamer::forProject($this->configuration())->forRecord($pass, self::DESCRIPTION);
    }

    private function workspacePath(GenerationRecord $pass): string
    {
        return TaskWorktrees::forProject($this->configuration())->pathFor($pass->generation());
    }

    private function inWorkspace(GenerationRecord $pass, string $relative, string $contents): void
    {
        $path = $this->workspacePath($pass) . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            self::fail('Could not create ' . $directory);
        }

        file_put_contents($path, $contents);
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

    private function countOfCommitsOn(string $branch): int
    {
        return (int) trim(Git::run(
            $this->project->root(),
            'rev-list',
            '--count',
            $this->base()->sha() . '..refs/heads/' . $branch,
        )->standardOutput);
    }

    private function snapshot(): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            self::DESCRIPTION,
            false,
            1,
            sprintf('- [ ] %s', self::DESCRIPTION),
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

    /**
     * A run row, for the column the run identifier references.
     */
    private function insertRun(): int
    {
        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-0001',
                'interrupted',
                $agent['agent'],
                $agent['adapter'],
                'project_default',
                $agent['settings'],
                '{}',
                '[]',
                '.ralph/tasks.md',
                'HEAD',
                '2026-11-08T09:30:00+00:00',
                '2026-11-08T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
