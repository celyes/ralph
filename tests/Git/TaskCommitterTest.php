<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\BranchOwnership;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommit;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspacePreparer;
use Celyes\RalphPhp\Git\WorkspaceStager;
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
 * Turning a verified task's staged index into a recorded commit, against real
 * repositories, real linked worktrees and a real state store.
 *
 * The item is one sentence with two halves — make the commit, persist the SHA —
 * and almost everything that can go wrong lives between them, so the
 * repository and the database are both asserted against after every case. A
 * commit Git has and the row does not is the crash PROJECT.md section H, item 5
 * is about; a row naming a commit that is not there would be worse, since it is
 * the record a later run believes.
 *
 * Three assertions recur, each standing for one way this step could do real
 * harm. The commit is read back out of Git rather than from the returned value,
 * because the value is a claim and `git log` is what a reviewer will see. The
 * controlling checkout — its HEAD, its working tree, its index — is asserted
 * unchanged by every case including every refusal, because the user is sitting
 * in it. And a refusal is asserted to have left no commit *and no second
 * commit*: a pass commits once, and the duplicate this guards against is the
 * one acceptance criterion 6 names.
 */
#[CoversClass(TaskCommitter::class)]
#[CoversClass(TaskCommit::class)]
#[CoversClass(CommitException::class)]
final class TaskCommitterTest extends TestCase
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
            self::markTestSkipped('Git is not installed, and this commits in real linked worktrees.');
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

    #[Test]
    public function a_verified_tasks_staged_changes_become_a_commit_on_the_passs_own_branch(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $before = $this->snapshotOfTheCheckout();

        $commit = $this->commit($pass, $workspace);

        self::assertSame(1, preg_match('/^[0-9a-f]{40}$/', $commit->sha()));
        self::assertSame($commit->sha(), $this->headOf($workspace->path()));
        self::assertSame($commit->sha(), $this->tipOf($commit->branch()->value()));
        self::assertSame($this->base()->sha(), $commit->parent());
        self::assertTrue($commit->wasBranchCreated());
        self::assertSame($commit->branch()->value(), $this->attachedBranchOf($workspace->path()));

        // The commit the reviewer will see, read out of Git rather than from
        // the value that claims it.
        self::assertSame(
            ['src/Greeting.php'],
            $this->filesIn($workspace->path(), $commit->sha()),
        );
        self::assertSame($commit->message()->subject(), $this->subjectOf($workspace->path(), $commit->sha()));

        self::assertSame($before, $this->snapshotOfTheCheckout());
    }

    /**
     * The other half of the item: a commit stops being something only Git knows
     * about.
     */
    #[Test]
    public function the_resulting_sha_is_persisted_on_the_passs_row(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $commit = $this->commit($pass, $workspace);

        // Read back from the store rather than from the record the commit
        // carries, which is the same object the write returned.
        $stored = $this->generations->get($pass->generation());

        self::assertSame($commit->sha(), $stored->commitSha());
        self::assertTrue($stored->hasCommit());
        self::assertTrue($stored->hasImplementation());
        self::assertFalse($stored->isNoChange());
        self::assertSame($commit->branch()->value(), $stored->branch());
        self::assertSame($commit->sha(), $commit->recorded()->commitSha());
    }

    /**
     * The identifiers that let a reviewer in a clone with no state database
     * find the run, the pass and the attempt that produced the commit.
     */
    #[Test]
    public function the_commit_message_carries_the_task_and_run_identifiers(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $commit = $this->commit($pass, $workspace);
        $message = $this->messageOf($workspace->path(), $commit->sha());

        self::assertStringContainsString(
            sprintf('%s: %s', $pass->task()->value(), self::DESCRIPTION),
            $message,
        );
        self::assertStringContainsString('Ralph-Task: ' . $pass->task()->value(), $message);
        self::assertStringContainsString('Ralph-Generation: 1', $message);
        self::assertStringContainsString('Ralph-Attempt: 1', $message);
        self::assertStringContainsString('Ralph-Base: ' . $this->base()->sha(), $message);
        self::assertStringContainsString('Ralph-Agent: codex', $message);

        // Greppable, which is the point of putting them in trailers at all.
        self::assertSame(
            [$commit->sha()],
            $this->commitsMatching($workspace->path(), 'Ralph-Task: ' . $pass->task()->value()),
        );
    }

    /**
     * Staging decided what the commit contains; committing does not widen it.
     */
    #[Test]
    public function the_commit_contains_only_what_staging_chose(): void
    {
        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]);

        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass, $configuration);
        WorkspacePreparer::forProject($configuration)->prepare($workspace);

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");
        $this->inWorkspace($workspace, '.ralph/CONTEXT.md', "Conventions.\n");

        $commit = $this->commit($pass, $workspace, $configuration);

        self::assertSame(['src/Greeting.php'], $this->filesIn($workspace->path(), $commit->sha()));
        self::assertCount(2, $commit->changes()->excluded());
    }

    /**
     * The message is pinned against the repository's own `commit.cleanup`, for
     * the reason {@see StagedChanges} pins `--find-renames`: a project
     * configured for `strip` would get a different message from the same task,
     * and an identifier a local setting can edit is not an identifier.
     */
    #[Test]
    public function a_projects_message_cleanup_setting_cannot_edit_the_message(): void
    {
        // "strip" drops every line beginning with a hash, which a task's own
        // wording may well do.
        Git::run($this->project->root(), 'config', 'commit.cleanup', 'strip');

        $description = '#dry-run: implement the sequential loop that selects a pending task, prepares '
            . 'its workspace, invokes the adapter and verifies the result';
        $pass = $this->givenAPass($description);
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $message = CommitMessage::forGeneration($pass, $this->snapshot($description));
        $commit = TaskCommitter::forProject($this->configuration(), $this->generations)->commit(
            $pass,
            $workspace,
            $this->ownershipOf($pass),
            $this->stage($workspace),
            $message,
        );

        self::assertSame($description, $message->body());
        self::assertStringContainsString($description, $this->messageOf($workspace->path(), $commit->sha()));
    }

    /**
     * The guard that makes resume safe: a pass with a commit owes a push or a
     * checklist write, never another implementation.
     */
    #[Test]
    public function a_pass_that_has_committed_is_never_committed_again(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $commit = $this->commit($pass, $workspace);
        $this->inWorkspace($workspace, 'src/Farewell.php', "<?php\n");

        $exception = $this->refusal($commit->recorded(), $workspace);

        self::assertStringContainsString('already recorded commit ' . $commit->sha(), $exception->getMessage());
        self::assertStringContainsString('A pass commits once', implode("\n", $exception->hints()));
        self::assertSame(ExitCode::BLOCKED, $exception->exitCode());

        // One commit on the branch, and the second file is still only staged.
        self::assertSame($commit->sha(), $this->tipOf($commit->branch()->value()));
        self::assertSame(2, $this->countOfCommits($workspace->path()));
        self::assertSame($commit->sha(), $this->generations->get($pass->generation())->commitSha());
    }

    /**
     * A verified task that changed nothing is a no-change result with its
     * evidence (PROJECT.md section G, item 6), which is the runner's decision
     * to take and not an empty commit to make here.
     */
    #[Test]
    public function an_empty_index_is_refused_rather_than_committed_as_an_empty_commit(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);

        $exception = $this->refusal($pass, $workspace);

        self::assertStringContainsString('has nothing staged', $exception->getMessage());
        self::assertStringContainsString('no-change result', implode("\n", $exception->hints()));

        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertNull($this->tipOf($this->branchOf($pass)->value()));
        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertNull($this->generations->get($pass->generation())->branch());
    }

    /**
     * The staged summary is the record of what a commit contains, so one taken
     * of another directory describes a commit this is not making.
     */
    #[Test]
    public function a_staged_summary_taken_of_another_workspace_is_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");
        $this->stage($workspace);

        $elsewhere = StagedChanges::parse(
            $this->project->root(),
            "M\0src/Greeting.php\0",
            "1\t0\tsrc/Greeting.php\0",
        );

        $exception = $this->refusalOf($pass, $workspace, $this->ownershipOf($pass), $elsewhere);

        self::assertStringContainsString('rather than of its workspace', $exception->getMessage());
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
    }

    /**
     * Defence in depth, and the one refusal whose absence would be
     * unrecoverable: the user is sitting in the controlling checkout, with
     * their own work in it.
     */
    #[Test]
    public function the_project_directory_is_never_committed_in(): void
    {
        $pass = $this->givenAPass();
        $root = TaskWorktree::created($pass->generation(), $this->project->root(), $this->base());

        $before = $this->snapshotOfTheCheckout();

        $exception = $this->refusalOf(
            $pass,
            $root,
            $this->ownershipOf($pass),
            StagedChanges::parse($this->project->root(), "M\0src/Greeting.php\0", "1\t0\tsrc/Greeting.php\0"),
        );

        self::assertStringContainsString('cannot be made in the project directory itself', $exception->getMessage());
        self::assertSame($before, $this->snapshotOfTheCheckout());
    }

    /**
     * {@see TaskBranches::require()} has refused this reading already by the
     * time a run reaches here; this is the second lock on the same door, and
     * the one that matters, because committing is the only step that writes a
     * reference.
     */
    #[Test]
    public function a_branch_that_belongs_to_unrelated_work_is_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        // A person who names branches the way the configured prefix does.
        $name = $this->branchOf($pass)->value();
        Git::run($this->project->root(), 'branch', $name, $this->base()->sha());

        $unrelated = TaskBranches::forProject($this->configuration())->inspect($this->branchOf($pass), $pass);
        self::assertTrue($unrelated->isUnrelated());

        $exception = $this->refusalOf($pass, $workspace, $unrelated, $this->stage($workspace));

        self::assertStringContainsString($name, $exception->getMessage());
        self::assertSame($this->base()->sha(), $this->tipOf($name));
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * The branch a pass owns is what resume uses to find its work, so it is
     * recorded once and kept.
     */
    #[Test]
    public function a_row_that_records_another_branch_is_refused(): void
    {
        $pass = $this->generations->assignBranch($this->givenAPass(), 'ralph/somewhere-else');
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $exception = $this->refusalOf(
            $pass,
            $workspace,
            $this->generatedOwnershipOf($pass),
            $this->stage($workspace),
        );

        self::assertStringContainsString('records ralph/somewhere-else as its branch', $exception->getMessage());
        self::assertSame('ralph/somewhere-else', $this->generations->get($pass->generation())->branch());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * An interrupted run that claimed its branch and attached its workspace to
     * it, but was killed before committing: the branch is not created a second
     * time, and the commit lands on it.
     */
    #[Test]
    public function a_workspace_already_on_the_passs_branch_is_committed_onto_it(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $branch = $this->branchOf($pass);
        $pass = $this->generations->assignBranch($pass, $branch->value());
        Git::run($workspace->path(), 'switch', '--create', $branch->value());

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $commit = $this->commit($pass, $workspace);

        self::assertFalse($commit->wasBranchCreated());
        self::assertSame($commit->sha(), $this->tipOf($branch->value()));
        self::assertSame($this->base()->sha(), $commit->parent());
    }

    /**
     * Which of the two histories was wanted is not something this can decide,
     * and the convenient answers — force the branch, reset the workspace —
     * destroy work nobody here has seen.
     */
    #[Test]
    public function a_branch_and_a_workspace_that_have_diverged_are_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        // Something else moved the pass's branch on since the workspace was
        // checked out.
        $branch = $this->branchOf($pass);
        $pass = $this->generations->assignBranch($pass, $branch->value());
        $this->project->write('NOTES.md', "Somebody else's commit.\n");
        Git::run($this->project->root(), 'add', 'NOTES.md');
        $elsewhere = Git::commit($this->project->root(), 'Unrelated work');
        Git::run($this->project->root(), 'branch', $branch->value(), $elsewhere);

        $exception = $this->refusalOf($pass, $workspace, $this->ownershipOf($pass), $this->stage($workspace));

        self::assertStringContainsString('have diverged', $exception->getMessage());
        self::assertSame($elsewhere, $this->tipOf($branch->value()));
        self::assertSame($this->base()->sha(), $this->headOf($workspace->path()));
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    #[Test]
    public function a_workspace_on_a_branch_that_is_not_the_passs_is_refused(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        Git::run($workspace->path(), 'switch', '--create', 'somebody-elses-work');
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $exception = $this->refusalOf($pass, $workspace, $this->ownershipOf($pass), $this->stage($workspace));

        self::assertStringContainsString('is on the branch somebody-elses-work', $exception->getMessage());
        self::assertSame($this->base()->sha(), $this->tipOf('somebody-elses-work'));
        self::assertNull($this->generations->get($pass->generation())->commitSha());
    }

    /**
     * The project's own hooks are honoured rather than bypassed, so a failure
     * arrives after the checks have passed — and the message has to say where
     * the work is.
     */
    #[Test]
    public function a_hook_that_refuses_the_change_leaves_the_work_staged_and_nothing_recorded(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->givenAHookThatRefuses();
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $exception = $this->refusalOf($pass, $workspace, $this->ownershipOf($pass), $this->stage($workspace));

        self::assertStringContainsString('could not be committed', $exception->getMessage());
        self::assertStringContainsString('project hook that refuses the change', implode("\n", $exception->hints()));

        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame(1, $this->countOfCommits($workspace->path()));
        self::assertSame(
            ['src/Greeting.php'],
            $this->stagedIn($workspace->path()),
        );
    }

    #[Test]
    public function the_commit_is_noted_as_it_is_made(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $sink = new RecordingSink();
        $commit = $this->commit($pass, $workspace, null, $sink);
        $notes = implode("\n", $sink->notes);

        self::assertStringContainsString('owns the branch ' . $commit->branch()->value(), $notes);
        self::assertStringContainsString('created the branch ' . $commit->branch()->value(), $notes);
        self::assertStringContainsString($commit->shortSha(), $notes);
        self::assertStringContainsString('recorded against ' . $pass->slug(), $notes);
        self::assertSame(0, $sink->closes);
    }

    #[Test]
    public function the_commit_serialises_for_a_record_of_the_run(): void
    {
        $pass = $this->givenAPass();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $commit = $this->commit($pass, $workspace);
        $written = $commit->toArray();

        self::assertSame($pass->slug(), $written['generation']);
        self::assertSame($commit->sha(), $written['sha']);
        self::assertSame($this->base()->sha(), $written['parent']);
        self::assertSame($commit->branch()->value(), $written['branch']);
        self::assertTrue($written['branch_created']);
        self::assertStringContainsString($commit->shortSha(), $commit->describe());
        self::assertSame(substr($commit->sha(), 0, TaskCommit::SHORT_LENGTH), $commit->shortSha());
    }

    /**
     * Stage and commit, the way a run does once its checks have passed.
     */
    private function commit(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        ?Configuration $configuration = null,
        ?RecordingSink $sink = null,
    ): TaskCommit {
        $configuration ??= $this->configuration();

        return TaskCommitter::forProject($configuration, $this->generations)->commit(
            $pass,
            $workspace,
            $this->ownershipOf($pass, $configuration),
            $this->stage($workspace, $configuration),
            $this->messageFor($pass),
            $sink,
        );
    }

    private function refusal(GenerationRecord $pass, TaskWorktree $workspace): CommitException
    {
        return $this->refusalOf($pass, $workspace, $this->ownershipOf($pass), $this->stage($workspace));
    }

    private function refusalOf(
        GenerationRecord $pass,
        TaskWorktree $workspace,
        BranchOwnership $ownership,
        StagedChanges $staged,
    ): CommitException {
        try {
            TaskCommitter::forProject($this->configuration(), $this->generations)->commit(
                $pass,
                $workspace,
                $ownership,
                $staged,
                $this->messageFor($pass),
            );
        } catch (CommitException $exception) {
            return $exception;
        }

        self::fail('The commit was expected to be refused.');
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
     * A hook of the project's own that refuses whatever is staged.
     *
     * Written into the repository's common hook directory, which is where a
     * linked worktree reads its hooks from — the same place a project's real
     * `pre-commit` lives.
     */
    private function givenAHookThatRefuses(): void
    {
        $path = $this->project->root() . '/.git/hooks/pre-commit';

        file_put_contents($path, "#!/bin/sh\necho 'not like that' >&2\nexit 1\n");
        chmod($path, 0o755);
    }

    /**
     * A recorded pass that has spent an attempt, which is the state a task is
     * in when its checks have just passed.
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
    private function givenAWorkspace(
        GenerationRecord $pass,
        ?Configuration $configuration = null,
    ): TaskWorktree {
        $configuration ??= $this->configuration();

        $workspace = TaskWorktrees::forProject($configuration)->prepare($pass->generation(), $this->base());

        // The runner records ownership when it prepares the workspace, and
        // branch ownership is read against it: a branch checked out in a
        // directory the row does not claim is somebody else's.
        $this->generations->recordWorktree($pass, $workspace->ownership());

        return $workspace;
    }

    private function stage(TaskWorktree $workspace, ?Configuration $configuration = null): StagedChanges
    {
        return WorkspaceStager::forProject($configuration ?? $this->configuration())->stage($workspace);
    }

    private function messageFor(GenerationRecord $pass): CommitMessage
    {
        return CommitMessage::forGeneration($pass, $this->snapshot(self::DESCRIPTION));
    }

    private function branchOf(GenerationRecord $pass, ?Configuration $configuration = null): TaskBranch
    {
        return BranchNamer::forProject($configuration ?? $this->configuration())
            ->forRecord($pass, self::DESCRIPTION);
    }

    /**
     * The branch reading a run has when it reaches the commit, taken against
     * the row as it stands rather than against the copy the test is holding.
     */
    private function ownershipOf(GenerationRecord $pass, ?Configuration $configuration = null): BranchOwnership
    {
        $configuration ??= $this->configuration();
        $current = $this->generations->get($pass->generation());

        return TaskBranches::forProject($configuration)->require($this->branchOf($current, $configuration), $current);
    }

    /**
     * The reading for the name the pass's description generates, whatever its
     * row records — for the case where the two differ.
     */
    private function generatedOwnershipOf(GenerationRecord $pass): BranchOwnership
    {
        $configuration = $this->configuration();
        $generated = BranchNamer::forProject($configuration)->resolve($pass->generation(), self::DESCRIPTION);

        return TaskBranches::forProject($configuration)->require($generated, $pass);
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

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    /**
     * The commit the run resolved, resolved once.
     *
     * Memoised deliberately: a run resolves its base at the start and every
     * task is created from that one commit ({@see BaseCommit}), so a test that
     * re-resolved it would quietly follow the branch it moves on in the cases
     * that commit something else.
     */
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

        return $this->database->lastInsertId();
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

    private function countOfCommits(string $directory): int
    {
        return (int) trim(Git::run($directory, 'rev-list', '--count', 'HEAD')->standardOutput);
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

    private function messageOf(string $directory, string $sha): string
    {
        return Git::run($directory, 'log', '-1', '--format=%B', $sha)->standardOutput;
    }

    private function subjectOf(string $directory, string $sha): string
    {
        return trim(Git::run($directory, 'log', '-1', '--format=%s', $sha)->standardOutput);
    }

    /**
     * @return list<string>
     */
    private function commitsMatching(string $directory, string $pattern): array
    {
        $output = trim(
            Git::run($directory, 'log', '--format=%H', '--fixed-strings', '--grep=' . $pattern)->standardOutput,
        );

        return $output === '' ? [] : array_map('trim', explode("\n", $output));
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
