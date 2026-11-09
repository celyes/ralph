<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\DriftException;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\DriftKind;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskCommit;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceAudit;
use Celyes\RalphPhp\Git\WorkspaceAuditor;
use Celyes\RalphPhp\Git\WorkspaceDrift;
use Celyes\RalphPhp\Git\WorkspaceRepair;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Git\WorkspaceState;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Noticing what a worker did to a repository's commits and branches, against
 * real repositories, real linked worktrees and a real state store.
 *
 * The item allows two responses and forbids a third, so every case here asserts
 * which of the three happened — and the forbidden one, silent acceptance, is
 * what most of the assertions are really about. A reconciled pass is asserted to
 * have kept every byte the worker wrote *and* to have left its commits
 * reachable; a blocked pass is asserted to have changed nothing at all, because
 * a refusal that tidied up on the way out would be the destructive behaviour
 * this whole class exists to avoid.
 *
 * Three assertions recur. Git is read back directly rather than the returned
 * value trusted, because the value is a claim and `git log` is what a reviewer
 * sees. The controlling checkout — its HEAD, its working tree, its index — is
 * asserted unchanged by every case that does not deliberately commit in it,
 * because the user is sitting in it. And the file the worker wrote is asserted
 * still to be on disk after every repair, since "nothing is lost" is the only
 * thing that makes reconciling legitimate rather than convenient.
 */
#[CoversClass(WorkspaceAuditor::class)]
#[CoversClass(WorkspaceAudit::class)]
#[CoversClass(WorkspaceDrift::class)]
#[CoversClass(WorkspaceRepair::class)]
#[CoversClass(WorkspaceState::class)]
#[CoversClass(DriftKind::class)]
#[CoversClass(DriftException::class)]
final class WorkspaceAuditorTest extends TestCase
{
    private const string DESCRIPTION = 'Add a greeting command';

    private const string WORK = "<?php\n\n// the worker's implementation\n";

    private TemporaryProject $project;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private ?BaseCommit $base = null;

    private ?int $run = null;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this audits real linked worktrees.');
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
     * The ordinary case, and the one every other step of section F assumes.
     */
    #[Test]
    public function a_worker_that_only_edits_files_leaves_nothing_to_reconcile(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);

        $audit = $this->audit($pass, $workspace, $before);

        self::assertTrue($audit->isClean());
        self::assertFalse($audit->isBlocking());
        self::assertFalse($audit->isReconcilable());
        self::assertSame([], $audit->drifts());
        self::assertSame(0, $audit->count());
        self::assertStringContainsString('as it found them', $audit->summary());

        $repair = $this->reconcile($pass, $workspace, $audit);

        self::assertFalse($repair->wasNeeded());
        self::assertSame([], $repair->actions());
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertNull($this->attachedBranchOf($workspace->path()));
        self::assertNull($this->recordOf($pass)->branch());
        self::assertSame(self::WORK, file_get_contents($workspace->path() . '/src/Greeting.php'));
    }

    /**
     * The reconcilable case the item is mostly about: the implementation is in
     * a commit object rather than in the files, and putting it back is what
     * lets verification and the pass's own commit happen at all.
     */
    #[Test]
    public function a_commit_the_worker_made_is_put_back_into_the_workspace(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);
        $checkout = $this->snapshotOfTheCheckout();

        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $workersCommit = $this->commitInWorkspace($workspace, 'The worker committed this itself');

        $sink = new RecordingSink();
        $repair = $this->settle($pass, $workspace, $before, $sink);

        $audit = $repair->audit();

        self::assertTrue($audit->isReconcilable());
        self::assertTrue($audit->has(DriftKind::CommitsAdded));
        self::assertSame(1, $audit->count());
        self::assertStringContainsString('left 1 commit in the workspace', (string) $audit->first(DriftKind::CommitsAdded)?->summary());

        // The repair: HEAD is back where the workspace started, and every byte
        // the worker wrote is still on disk.
        self::assertTrue($repair->wasNeeded());
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertSame($this->base()->sha(), $repair->state()->head());
        self::assertSame(self::WORK, file_get_contents($workspace->path() . '/src/Greeting.php'));

        // Not even the index was disturbed: a soft reset leaves the worker's
        // changes staged against the commit the workspace was handed, which is
        // what makes "nothing is lost" a statement about the whole workspace
        // rather than only about the files.
        self::assertSame(['src/Greeting.php'], $this->stagedIn($workspace->path()));

        // And the worker's commit is still an object in the repository, which
        // is the difference between a soft reset and discarding its work.
        self::assertTrue($this->commitExists($workspace->path(), $workersCommit));
        self::assertNotSame($workersCommit, $this->base()->sha());

        self::assertSame($checkout, $this->snapshotOfTheCheckout());
        self::assertContains(
            sprintf(
                'workspace: moving HEAD back to %s, keeping every change the worker made',
                substr($this->base()->sha(), 0, WorkspaceDrift::SHORT_LENGTH),
            ),
            $sink->notes,
        );
    }

    /**
     * The whole point of the repair, asserted end to end: the pass's own
     * commit — verified, identified, on the pass's own branch — is made out of
     * work the worker had buried in a commit of its own.
     */
    #[Test]
    public function a_reconciled_workspace_can_still_be_staged_and_committed_by_the_pass(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $this->commitInWorkspace($workspace, 'The worker committed this itself');

        $pass = $this->settle($pass, $workspace, $before)->record();

        $staged = WorkspaceStager::forProject($this->configuration())->stage($workspace);

        self::assertSame(['src/Greeting.php'], $staged->paths());

        $commit = $this->commitAsThePass($pass, $workspace, $staged);

        self::assertSame($commit->sha(), $this->headOf($workspace->path()));
        self::assertSame($this->base()->sha(), $commit->parent());
        self::assertSame(['src/Greeting.php'], $this->filesIn($workspace->path(), $commit->sha()));
        self::assertSame($commit->sha(), $this->recordOf($pass)->commitSha());
    }

    #[Test]
    public function several_commits_are_counted_rather_than_assumed_to_be_one(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);

        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $this->commitInWorkspace($workspace, 'First');
        $this->inWorkspace($workspace, 'src/Second.php', "<?php\n");
        $this->commitInWorkspace($workspace, 'Second');

        $audit = $this->audit($pass, $workspace, $before);

        self::assertStringContainsString('left 2 commits', (string) $audit->first(DriftKind::CommitsAdded)?->summary());

        $this->reconcile($pass, $workspace, $audit);

        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertSame(self::WORK, file_get_contents($workspace->path() . '/src/Greeting.php'));
        self::assertFileExists($workspace->path() . '/src/Second.php');
    }

    /**
     * A worker that created and switched to the pass's own branch took the name
     * this pass was going to use, so the repair is the recorded claim plus the
     * same soft reset — and the state store is what keeps the next run from
     * reading the reference as a stranger's.
     */
    #[Test]
    public function the_passs_own_branch_created_by_the_worker_is_claimed_rather_than_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'switch', '--create', $branch->value());
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $this->commitInWorkspace($workspace, 'The worker committed this on a branch of its own making');

        $sink = new RecordingSink();
        $repair = $this->settle($pass, $workspace, $before, $sink);

        self::assertTrue($repair->audit()->has(DriftKind::BranchAttached));
        self::assertTrue($repair->audit()->has(DriftKind::CommitsAdded));
        self::assertTrue($repair->audit()->isReconcilable());

        // The claim, which is what ownership reads on the next run.
        self::assertSame($branch->value(), $repair->record()->branch());
        self::assertSame($branch->value(), $this->recordOf($pass)->branch());
        self::assertContains(
            sprintf('workspace: recording %s as the branch of %s', $branch->value(), $pass->slug()),
            $sink->notes,
        );

        // And the branch went back with HEAD, so the pass's own commit lands on
        // it rather than on top of the worker's.
        self::assertSame($branch->value(), $this->attachedBranchOf($workspace->path()));
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertSame($this->base()->sha(), $this->tipOf($branch->value()));
        self::assertSame(self::WORK, file_get_contents($workspace->path() . '/src/Greeting.php'));

        // The branch is now the pass's as far as ownership is concerned, which
        // is the thing the claim had to buy.
        self::assertTrue(
            TaskBranches::forProject($this->configuration())
                ->inspect($branch, $this->recordOf($pass))
                ->isOurs(),
        );
    }

    /**
     * The detached sibling: `git branch` without switching to it. There is
     * nothing in Git to put back, so the claim is the whole repair.
     */
    #[Test]
    public function the_passs_own_branch_created_at_the_base_commit_needs_only_the_recorded_claim(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', $branch->value());
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);

        $repair = $this->settle($pass, $workspace, $before);

        self::assertTrue($repair->audit()->has(DriftKind::BranchClaimed));
        self::assertSame(1, $repair->audit()->count());
        self::assertSame($branch->value(), $this->recordOf($pass)->branch());
        self::assertSame(
            [sprintf('recording %s as the branch of %s', $branch->value(), $pass->slug())],
            $repair->actions(),
        );

        // Nothing in the repository was moved: there was nothing to move.
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertNull($this->attachedBranchOf($workspace->path()));
        self::assertSame($this->base()->sha(), $this->tipOf($branch->value()));
    }

    /**
     * The pass's own name is claimable only where the pass's commit was going
     * anyway. A reference under that name anywhere else holds commits this
     * cannot account for, and claiming it would leave the branch and the
     * workspace pointing at different histories.
     */
    #[Test]
    public function the_passs_own_branch_created_somewhere_else_is_not_claimed(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);

        $this->project->write('README.md', "A second commit.\n");
        Git::run($this->project->root(), 'add', '--all');
        $elsewhere = Git::commit($this->project->root(), 'A second commit');

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', $branch->value(), $elsewhere);

        $exception = $this->refusal($pass, $workspace, $before);
        $audit = $exception->audit();

        self::assertTrue($audit->has(DriftKind::BranchCreated));
        self::assertFalse($audit->has(DriftKind::BranchClaimed));
        self::assertSame($elsewhere, $this->tipOf($branch->value()));
        self::assertNull($this->recordOf($pass)->branch());
    }

    /**
     * An operation is read from the workspace's own Git directory, so one
     * worktree's unfinished business is not reported against another's.
     */
    #[Test]
    public function a_merge_left_unfinished_in_the_users_checkout_is_not_the_workspaces(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $this->project->write('README.md', "A divergent commit.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'A divergent commit');

        $before = $this->observe($workspace);

        // The user's own checkout, left mid-merge the way a person interrupted
        // by a conflict leaves one.
        Git::run($this->project->root(), 'switch', '--detach', $this->base()->sha());
        Git::run($this->project->root(), 'merge', '--no-commit', '--no-ff', 'main');
        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);

        $audit = $this->audit($pass, $workspace, $before);

        self::assertTrue($audit->isClean());
        self::assertTrue($audit->after()->isResting());

        Git::run($this->project->root(), 'merge', '--abort');
        Git::run($this->project->root(), 'switch', 'main');
    }

    /**
     * References are shared by every worktree, which is how a commit made
     * somewhere else entirely is seen at all.
     */
    #[Test]
    public function a_commit_the_worker_made_onto_the_users_own_branch_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);

        $this->project->write('src/Greeting.php', self::WORK);
        Git::run($this->project->root(), 'add', '--all');
        $stray = Git::commit($this->project->root(), 'The worker committed this onto main');

        $exception = $this->refusal($pass, $workspace, $before);
        $audit = $exception->audit();

        self::assertTrue($audit->isBlocking());
        self::assertTrue($audit->has(DriftKind::BranchMoved));
        self::assertSame(ExitCode::BLOCKED, $exception->exitCode());
        self::assertStringContainsString('the branch main moved from', $exception->getMessage() . implode(' ', $exception->hints()));

        // Nothing was tidied up: the stray commit is still main's tip, and the
        // pass claimed nothing.
        self::assertSame($stray, $this->tipOf('main'));
        self::assertNull($this->recordOf($pass)->branch());
        self::assertNull($this->recordOf($pass)->commitSha());
        self::assertContains(
            'Ralph PHP will not force a branch, reset a workspace or delete a reference to tidy this up: '
                . 'each of those can destroy a commit somebody has and this run has never seen.',
            $exception->hints(),
        );
        self::assertContains(
            sprintf('Run "git log --oneline -5 main" in %s to see what it holds.', $workspace->path()),
            $exception->hints(),
        );
    }

    #[Test]
    public function a_workspace_the_worker_put_on_another_branch_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        Git::run($this->project->root(), 'branch', 'somebody-elses-work');

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'switch', '--no-guess', 'somebody-elses-work');

        $exception = $this->refusal($pass, $workspace, $before);

        self::assertTrue($exception->audit()->has(DriftKind::ForeignBranch));
        self::assertSame('somebody-elses-work', $this->attachedBranchOf($workspace->path()));
        self::assertNull($this->recordOf($pass)->branch());
    }

    #[Test]
    public function a_branch_the_worker_created_for_itself_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', 'the-workers-own-idea');

        $exception = $this->refusal($pass, $workspace, $before);

        self::assertTrue($exception->audit()->has(DriftKind::BranchCreated));
        self::assertSame($this->base()->sha(), $this->tipOf('the-workers-own-idea'));
    }

    /**
     * A deleted reference is recorded with the commit it was at and never
     * re-created: restoring it would reverse somebody's deliberate act, and the
     * SHA in the message is what lets them do it themselves.
     */
    #[Test]
    public function a_branch_the_worker_deleted_blocks_and_the_commit_it_was_at_is_reported(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        Git::run($this->project->root(), 'branch', 'release', $this->base()->sha());

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', '--delete', 'release');

        $exception = $this->refusal($pass, $workspace, $before);
        $deleted = $exception->audit()->first(DriftKind::BranchDeleted);

        self::assertNotNull($deleted);
        self::assertSame([$this->base()->sha()], $deleted->commits());
        self::assertStringContainsString(
            substr($this->base()->sha(), 0, WorkspaceDrift::SHORT_LENGTH),
            $deleted->summary(),
        );
        self::assertNull($this->tipOf('release'));
    }

    #[Test]
    public function a_workspace_moved_off_its_own_history_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $this->project->write('README.md', "A second commit.\n");
        Git::run($this->project->root(), 'add', '--all');
        $second = Git::commit($this->project->root(), 'A second commit');
        $elsewhere = $this->base()->sha();

        $before = $this->observeAt($workspace, $second);

        Git::run($workspace->path(), 'checkout', '--detach', $elsewhere);

        $exception = $this->refusal($pass, $workspace, $before);

        self::assertTrue($exception->audit()->has(DriftKind::HeadMoved));
        self::assertFalse($exception->audit()->has(DriftKind::CommitsAdded));
        self::assertSame($elsewhere, $this->headOf($workspace->path()));
    }

    /**
     * A workspace in the middle of an operation has no resting answer to "what
     * is HEAD", so staging and committing in one is refused however it got
     * there.
     */
    #[Test]
    public function a_half_finished_merge_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $this->project->write('README.md', "A divergent commit.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'A divergent commit');

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'merge', '--no-commit', '--no-ff', 'main');

        $exception = $this->refusal($pass, $workspace, $before);
        $audit = $exception->audit();

        self::assertTrue($audit->has(DriftKind::OperationInProgress));
        self::assertSame(DriftKind::OperationInProgress, $audit->drifts()[0]->kind());
        self::assertStringContainsString('in the middle of a merge', $audit->drifts()[0]->summary());
        self::assertFalse($audit->after()->isResting());
    }

    /**
     * The other direction, which a pass being resumed can be in: the workspace
     * was on the pass's own branch and the worker took it off. No work is lost,
     * and re-attaching it would still be a guess about which of the two
     * positions was meant.
     */
    #[Test]
    public function a_workspace_the_worker_detached_from_the_passs_branch_blocks(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);

        $pass = $this->generations->assignBranch($pass, $branch->value());
        Git::run($workspace->path(), 'switch', '--create', $branch->value());

        $before = $this->observe($workspace);

        self::assertSame($branch->value(), $before->attachedBranch());

        Git::run($workspace->path(), 'checkout', '--detach');

        $exception = $this->refusal($pass, $workspace, $before);
        $detached = $exception->audit()->first(DriftKind::HeadDetached);

        self::assertNotNull($detached);
        self::assertSame($branch->value(), $detached->branch());
        self::assertSame('the workspace detached', $detached->kind()->label());
        self::assertNull($this->attachedBranchOf($workspace->path()));
        self::assertSame($this->base()->sha(), $this->tipOf($branch->value()));
    }

    /**
     * Reading is separate from acting, so a diagnostic can look at a blocked
     * workspace without stopping anything or changing it.
     */
    #[Test]
    public function auditing_reports_a_blocking_reading_without_acting_on_it(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', 'the-workers-own-idea');

        $audit = $this->audit($pass, $workspace, $before);

        self::assertTrue($audit->isBlocking());
        self::assertFalse($audit->isReconcilable());
        self::assertSame($this->base()->sha(), $this->tipOf('the-workers-own-idea'));
        self::assertNull($this->recordOf($pass)->branch());
        self::assertSame($pass->slug(), $audit->toArray()['generation']);
    }

    /**
     * One blocking finding is enough, however much beside it could have been
     * put back: half a repair would leave a state nobody could reason about.
     */
    #[Test]
    public function a_reconcilable_finding_beside_a_blocking_one_is_still_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $before = $this->observe($workspace);

        $this->inWorkspace($workspace, 'src/Greeting.php', self::WORK);
        $workersCommit = $this->commitInWorkspace($workspace, 'The worker committed this itself');
        Git::run($workspace->path(), 'branch', 'the-workers-own-idea');

        $exception = $this->refusal($pass, $workspace, $before);
        $audit = $exception->audit();

        self::assertTrue($audit->has(DriftKind::CommitsAdded));
        self::assertTrue($audit->has(DriftKind::BranchCreated));
        self::assertCount(1, $audit->reconcilable());
        self::assertCount(1, $audit->blocking());

        // The reconcilable half was not applied either.
        self::assertSame($workersCommit, $this->headOf($workspace->path()));
        self::assertStringContainsString('Blocking:', $audit->details()[0]);
        self::assertStringContainsString('Can be put back:', $audit->details()[1]);
    }

    /**
     * The branch a pass owns is recorded once and kept, so a worker that
     * created a differently named one cannot quietly re-point the row.
     */
    #[Test]
    public function a_row_that_already_names_another_branch_is_not_rewritten(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);

        $pass = $this->generations->assignBranch($pass, 'ralph/an-earlier-name');

        $before = $this->observe($workspace);

        Git::run($workspace->path(), 'branch', $branch->value());

        $exception = $this->refusal($pass, $workspace, $before);

        self::assertStringContainsString('records ralph/an-earlier-name as its branch', $exception->getMessage());
        self::assertSame('ralph/an-earlier-name', $this->recordOf($pass)->branch());
    }

    #[Test]
    public function a_reading_of_another_workspace_cannot_be_acted_on(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $other = $this->givenAPass('Write the documentation');
        $otherWorkspace = $this->givenAWorkspace($other);

        $audit = $this->audit($other, $otherWorkspace, $this->observe($otherWorkspace));

        $this->expectException(WorktreeException::class);
        $this->expectExceptionMessageMatches('/cannot be acted on in/');

        $this->auditor()->reconcile($pass, $workspace, $audit);
    }

    #[Test]
    public function the_project_directory_is_not_a_workspace_to_reset_in(): void
    {
        $pass = $this->givenAPass();
        $theUsersCheckout = TaskWorktree::adopted($pass->generation(), $this->project->root(), $this->base());

        $this->expectException(WorktreeException::class);
        $this->expectExceptionMessageMatches('/cannot be the project directory itself/');

        $this->auditor()->observe($theUsersCheckout);
    }

    #[Test]
    public function a_row_a_workspace_and_a_branch_of_different_passes_are_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $other = $this->givenAPass('Write the documentation');

        $this->expectException(WorktreeException::class);
        $this->expectExceptionMessageMatches('/which are not the same pass/');

        $this->auditor()->audit(
            $pass,
            $workspace,
            $this->branchOf($other),
            $this->observe($workspace),
        );
    }

    #[Test]
    public function a_workspace_that_has_gone_cannot_be_read(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        Git::run($this->project->root(), 'worktree', 'remove', '--force', $workspace->path());

        $this->expectException(WorktreeException::class);
        $this->expectExceptionMessageMatches('/is not there, so its commits and branches cannot be read/');

        $this->auditor()->observe($workspace);
    }

    /**
     * A repository whose references cannot be read is refused rather than read
     * as having none: a branch silently dropped from a reading would be a
     * branch reported as created or deleted on the next one, or one whose
     * movement went unnoticed. Which of the readings notices first is Git's
     * business — asserted here is that one of them does, and that the refusal
     * says nothing was touched.
     */
    #[Test]
    public function a_repository_whose_branches_cannot_be_listed_is_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $packed = $this->project->root() . '/.git/packed-refs';

        file_put_contents($packed, "not a ref file at all\n");

        try {
            $this->observe($workspace);
            self::fail('The workspace was expected to be unreadable.');
        } catch (WorktreeException $exception) {
            self::assertStringContainsString('Git could not say', $exception->getMessage());
            self::assertStringContainsString($workspace->path(), $exception->getMessage());
            self::assertStringContainsString(
                'Nothing was staged, nothing was committed and nothing was put back.',
                implode(' ', $exception->hints()),
            );
        } finally {
            unlink($packed);
        }
    }

    /**
     * The readings are values, and two of the same repository are the same
     * value whatever order Git listed its references in.
     */
    #[Test]
    public function two_readings_of_an_untouched_repository_are_the_same_value(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $first = $this->observe($workspace);
        $second = $this->observe($workspace);

        self::assertTrue($first->equals($second));
        self::assertTrue($first->isDetached());
        self::assertTrue($first->isResting());
        self::assertSame($this->base()->sha(), $first->head());
        self::assertSame(['main'], $first->branchNames());
        self::assertSame($this->base()->sha(), $first->tipOf('main'));
        self::assertNull($first->tipOf('nothing-called-this'));
        self::assertStringContainsString('detached HEAD at', $first->describe());
        self::assertStringContainsString('1 local branch', $first->describe());

        $clean = WorkspaceAudit::unchanged(
            $pass->generation(),
            $workspace->path(),
            $this->branchOf($pass)->value(),
            $first,
        );

        self::assertTrue($clean->isClean());
        self::assertSame([], $clean->hints());
        self::assertStringContainsString('no change to commits or branches', $clean->describe());
    }

    /**
     * A repository with one commit and the ignore rules a project has, which is
     * what a task workspace is created from.
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
     * A recorded pass that has spent an attempt, which is the state a task is
     * in when its agent has just returned.
     */
    private function givenAPass(string $description = self::DESCRIPTION): GenerationRecord
    {
        $snapshot = $this->snapshot($description);
        $this->tasks->record($snapshot);

        return $this->generations->beginAttempt($this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base()->sha(),
            null,
            $this->insertRun(),
        ));
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

    private function auditor(): WorkspaceAuditor
    {
        return WorkspaceAuditor::forProject($this->configuration(), $this->generations);
    }

    private function observe(TaskWorktree $workspace): WorkspaceState
    {
        return $this->auditor()->observe($workspace);
    }

    /**
     * The reading a run would have taken, with HEAD said to be somewhere else.
     *
     * For the one case that cannot be staged: a workspace created from a commit
     * and then moved off it, where the reading has to come from before the
     * move.
     */
    private function observeAt(TaskWorktree $workspace, string $head): WorkspaceState
    {
        $state = $this->observe($workspace);

        return WorkspaceState::of($head, $state->attachedBranch(), $state->branches(), $state->operation());
    }

    private function audit(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        WorkspaceState $before,
    ): WorkspaceAudit {
        return $this->auditor()->audit($pass, $workspace, $this->branchOf($pass), $before);
    }

    private function reconcile(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        WorkspaceAudit $audit,
        ?RecordingSink $sink = null,
    ): WorkspaceRepair {
        return $this->auditor()->reconcile($pass, $workspace, $audit, $sink);
    }

    /**
     * What a run does: audit, then put back or stop.
     */
    private function settle(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        WorkspaceState $before,
        ?RecordingSink $sink = null,
    ): WorkspaceRepair {
        return $this->auditor()->require($pass, $workspace, $this->branchOf($pass), $before, $sink);
    }

    /**
     * The refusal a blocking reading produces, asserted to have been raised.
     */
    private function refusal(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        WorkspaceState $before,
    ): DriftException {
        try {
            $this->settle($pass, $workspace, $before);
        } catch (DriftException $exception) {
            return $exception;
        }

        self::fail('The pass was expected to be blocked.');
    }

    /**
     * The pass's own commit, made the way a run makes it.
     */
    private function commitAsThePass(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        StagedChanges $staged,
    ): TaskCommit {
        $configuration = $this->configuration();
        $current = $this->recordOf($pass);
        $branch = $this->branchOf($current);
        $ownership = TaskBranches::forProject($configuration)->require($branch, $current);

        return TaskCommitter::forProject($configuration, $this->generations)->commit(
            $current,
            $workspace,
            $ownership,
            $staged,
            CommitMessage::forGeneration($current, $this->snapshot(self::DESCRIPTION)),
        );
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

    /**
     * The commit the run resolved, resolved once: a run resolves its base at
     * the start and every task workspace is created from that one commit.
     */
    private function base(): BaseCommit
    {
        return $this->base ??= BaseResolver::forProject($this->configuration())->resolve();
    }

    /**
     * The run row, for the column the run identifier references.
     *
     * One per test, because one run is what a loop has: two passes of the same
     * run share it.
     */
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

    /**
     * A commit made by the worker itself, which is the thing section F, item 9
     * is about.
     */
    private function commitInWorkspace(TaskWorktree $workspace, string $message): string
    {
        Git::run($workspace->path(), 'add', '--all');

        return Git::commit($workspace->path(), $message);
    }

    private function headOf(string $directory): string
    {
        return trim(Git::run($directory, 'rev-parse', 'HEAD')->standardOutput);
    }

    private function attachedBranchOf(string $directory): ?string
    {
        $result = Git::tryRun($directory, 'symbolic-ref', '--quiet', '--short', 'HEAD');

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
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

    /**
     * What the workspace's index holds, which a soft reset leaves alone.
     *
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
     * Whether a commit is still an object in the repository, which is what a
     * soft reset leaves behind and a hard one does not.
     */
    private function commitExists(string $directory, string $sha): bool
    {
        return Git::tryRun($directory, 'cat-file', '-e', $sha . '^{commit}')->exitCode === 0;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory, string $sha): array
    {
        $output = trim(
            Git::run($directory, 'show', '--name-only', '--format=', '--no-renames', $sha)->standardOutput,
        );

        if ($output === '') {
            return [];
        }

        $paths = array_map('trim', explode("\n", $output));
        sort($paths);

        return $paths;
    }

    /**
     * Everything about the controlling checkout a run must not change.
     *
     * @return array<string, string>
     */
    private function snapshotOfTheCheckout(): array
    {
        return [
            'head' => Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput,
            'status' => Git::run($this->project->root(), 'status', '--porcelain')->standardOutput,
            'index' => Git::run($this->project->root(), 'diff', '--cached', '--name-status')->standardOutput,
        ];
    }
}
