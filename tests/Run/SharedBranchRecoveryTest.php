<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchName;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\SharedBranch;
use Celyes\RalphPhp\Git\SharedBranches;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskCommit;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Git\WorktreeCleaner;
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
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reconciling the shared branch and its commit history on resume (PROJECT.md
 * section K, item 5).
 *
 * {@see PassRecoveryTest} holds the same reconciliation for a branch a pass
 * owns alone, where the pass's commit is the only commit on the branch and the
 * tip is therefore the whole of the evidence. The default mode breaks that
 * assumption on purpose: every task of the run commits to one branch, so a
 * pass's commit stops being the tip as soon as the next task commits, and a
 * reading that compared the two would refuse a run for doing exactly what the
 * mode is for.
 *
 * So each test here puts the branch into a state a stopped run leaves behind
 * and asserts which of two things the reconciliation does: adopt or allow the
 * advance when every commit in it has a row naming it, or stop with nothing
 * written when one of them does not. The commits are made the way a run makes
 * them — {@see TaskCommitter}, in a real linked worktree, onto the run's shared
 * branch — and the ones nobody recorded are made with `git commit-tree`, which
 * is a person committing to the branch from the outside.
 *
 * Every refusal is asserted to have changed nothing: no commit adopted into a
 * row, no reference moved. A reconciliation that tidied up after an ambiguity
 * it could not resolve would lose the work it was meant to protect.
 */
#[CoversClass(PassRecovery::class)]
#[CoversClass(Recovery::class)]
final class SharedBranchRecoveryTest extends TestCase
{
    private const string FIRST = 'Add a greeting command';

    private const string SECOND = 'Add a farewell command';

    /**
     * The branch the default settings derive: the branch prefix and the shared
     * segment, which is what a project that only names the strategy gets.
     */
    private const string SHARED = 'ralph/shared';

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
     * The named crash of section H, item 5 in the default mode: the second task
     * committed to the shared branch and the process stopped before the row
     * could say so.
     */
    #[Test]
    public function a_commit_the_crash_never_recorded_is_adopted_from_the_shared_branch(): void
    {
        $first = $this->givenAFinishedTask();
        $second = $this->givenAPass(self::SECOND, $first->sha());
        $committed = $this->givenACommit($second, self::SECOND);
        $crashed = $this->crashedAfterCommitting($committed->recorded());

        $sink = new RecordingSink();
        $recovery = $this->recovery()->reconcile($crashed, $sink);

        self::assertSame(RecoveryVerdict::Reconciled, $recovery->verdict());
        self::assertTrue($recovery->adoptedCommit());
        self::assertTrue($recovery->allowsResume());
        self::assertSame($committed->sha(), $recovery->commit()?->sha());
        self::assertSame(
            $committed->sha(),
            $this->generations->get($second->generation())->commitSha(),
        );
        self::assertStringContainsString('adopted the commit', implode("\n", $recovery->actions()));
        self::assertStringContainsString('shared branch', implode("\n", $sink->notes));

        // Nothing was committed again: the branch still holds one commit per
        // task and its tip is the adopted commit.
        self::assertSame(2, $this->countOfCommitsOnShared());
        self::assertSame($committed->sha(), $this->tipOfShared());
    }

    /**
     * The state a per-task branch cannot reach and a shared branch reaches
     * whenever a run gets past the task it is reconciling: the pass's commit is
     * intact, and the branch has moved on with the next task's.
     */
    #[Test]
    public function a_pass_whose_commit_a_later_task_committed_on_top_of_is_consistent(): void
    {
        $first = $this->givenACommit($this->givenAPass(self::FIRST), self::FIRST);
        $interrupted = $this->interrupted($first->recorded(), Phase::Publishing);
        $this->releaseTheBranchFrom($first->recorded(), $first->sha());
        $second = $this->givenAFinishedTask(self::SECOND, $first->sha());

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertTrue($recovery->allowsResume());
        self::assertNull($recovery->blocker());
        self::assertSame($first->sha(), $recovery->commit()?->sha());
        self::assertStringContainsString('has moved 1 commit past', implode("\n", $recovery->findings()));
        self::assertSame($second->sha(), $this->tipOfShared());
    }

    /**
     * The refusal that protects the advance: a commit on top of the pass's that
     * no row accounts for would be published as the run's work.
     */
    #[Test]
    public function a_commit_nobody_recorded_above_the_passs_commit_stops_the_pass(): void
    {
        $committed = $this->givenACommit($this->givenAPass(self::FIRST), self::FIRST);
        $interrupted = $this->interrupted($committed->recorded(), Phase::Publishing);
        $stranger = $this->givenAHandCommit('Something somebody did by hand');

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('no pass of this project recorded', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString(
            'Something somebody did by hand',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('git show ' . $committed->sha(), (string) $recovery->blocker()?->nextAction());

        // Nothing was changed: the stranger is still the tip and the pass still
        // records its own commit.
        self::assertSame($stranger, $this->tipOfShared());
        self::assertSame(
            $committed->sha(),
            $this->generations->get($committed->recorded()->generation())->commitSha(),
        );
    }

    /**
     * A commit is adopted on what it says about itself, and a commit somebody
     * else made says nothing about this pass however convenient its position.
     */
    #[Test]
    public function a_commit_nobody_recorded_is_not_adopted_as_the_passs_own(): void
    {
        $pass = $this->givenAPass(self::FIRST);
        $this->generations->assignBranch($pass, self::SHARED, shared: true);
        $stranger = $this->givenAHandCommit('Something somebody did by hand');

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($pass->generation()), Phase::Executing),
        );

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('records no commit', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('no pass recorded', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('its own trailers', (string) $recovery->blocker()?->nextAction());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame($stranger, $this->tipOfShared());
    }

    /**
     * The crash case with somebody else's commit on top of it. The pass's
     * commit is found and left alone rather than adopted: resuming it would
     * push a branch carrying work no check of the run ran against.
     */
    #[Test]
    public function an_unrecorded_commit_above_the_adoptable_commit_stops_the_adoption(): void
    {
        $pass = $this->givenAPass(self::FIRST);
        $committed = $this->givenACommit($pass, self::FIRST);
        $crashed = $this->crashedAfterCommitting($committed->recorded());
        $stranger = $this->givenAHandCommit('Something somebody did by hand');

        $recovery = $this->recovery()->reconcile($crashed);

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString(
            'stopped before recording it',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('no pass of this project recorded', (string) $recovery->blocker()?->reason());
        self::assertSame($committed->sha(), $recovery->commit()?->sha());
        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame($stranger, $this->tipOfShared());
    }

    /**
     * A pass commits onto the commit its own workspace was made from, so a
     * commit of this pass's with work recorded *after* it underneath cannot be
     * taken on — it was written against a branch that has since been built on.
     *
     * The row is edited to produce it, because a run would not write this
     * state: what is being tested is that the reconciliation does not assume
     * it cannot happen.
     */
    #[Test]
    public function a_commit_of_the_passs_own_above_later_work_is_not_adopted(): void
    {
        $first = $this->givenAFinishedTask();
        $second = $this->givenAPass(self::SECOND, $first->sha());
        $committed = $this->givenACommit($second, self::SECOND);

        $this->database->execute(
            'UPDATE generations SET commit_sha = NULL, base_sha = ? WHERE id = ?',
            [$this->base()->sha(), $second->rowId()],
        );

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($second->generation()), Phase::Committing),
        );

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('sits above 1 commit', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString($first->recorded()->slug(), (string) $recovery->blocker()?->reason());
        self::assertNull($this->generations->get($second->generation())->commitSha());
        self::assertSame($committed->sha(), $this->tipOfShared());
    }

    /**
     * The branch moved on and none of it is this pass's: it had committed
     * nothing, which is the ordinary reading of a pass interrupted while the
     * agent was working.
     */
    #[Test]
    public function a_pass_that_committed_nothing_is_consistent_while_the_branch_moved_on(): void
    {
        $pass = $this->givenAPass(self::SECOND);
        $this->generations->assignBranch($pass, self::SHARED, shared: true);
        $first = $this->givenAFinishedTask();

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($pass->generation()), Phase::Executing),
        );

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'none of them is this pass\'s, so it had committed nothing',
            implode("\n", $recovery->findings()),
        );
        self::assertNull($this->generations->get($pass->generation())->commitSha());
        self::assertSame($first->sha(), $this->tipOfShared());
    }

    /**
     * A branch rewritten away from the commit the workspace was made from is a
     * branch this pass cannot be read off at all.
     */
    #[Test]
    public function a_shared_branch_rewritten_away_from_the_passs_base_stops_the_pass(): void
    {
        $first = $this->givenAFinishedTask();
        $second = $this->givenAPass(self::SECOND, $first->sha());
        $this->generations->assignBranch($second, self::SHARED, shared: true);

        // What `git reset --hard` on the shared branch looks like from here:
        // the branch is back at the run's base, and the commit the second
        // task's workspace was made from is no longer on it.
        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . self::SHARED, $this->base()->sha());

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($second->generation()), Phase::Executing),
        );

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString('no longer contains', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString(
            'the commit its workspace was made from',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('git reflog', (string) $recovery->blocker()?->nextAction());
        self::assertSame($this->base()->sha(), $this->tipOfShared());
    }

    /**
     * A deleted shared branch is put back where the run had it, not where this
     * pass's commit is: re-creating it at the pass's own commit would leave
     * every later task's commit reachable from nothing.
     */
    #[Test]
    public function a_deleted_shared_branch_is_put_back_at_the_furthest_commit_the_run_recorded(): void
    {
        $first = $this->givenACommit($this->givenAPass(self::FIRST), self::FIRST);
        $interrupted = $this->interrupted($first->recorded(), Phase::Publishing);
        $this->releaseTheBranchFrom($first->recorded(), $first->sha());
        $second = $this->givenAFinishedTask(self::SECOND, $first->sha());

        Git::run($this->project->root(), 'update-ref', '-d', 'refs/heads/' . self::SHARED);

        $recovery = $this->recovery()->reconcile($interrupted);

        self::assertSame(RecoveryVerdict::Reconciled, $recovery->verdict());
        self::assertFalse($recovery->adoptedCommit());
        self::assertSame($second->sha(), $this->tipOfShared());
        self::assertStringContainsString(
            're-created the shared branch',
            implode("\n", $recovery->actions()),
        );
        self::assertStringContainsString($second->recorded()->slug(), implode("\n", $recovery->actions()));
        self::assertSame(2, $this->countOfCommitsOnShared());
    }

    /**
     * A no-change outcome is contradicted by a commit of the pass's own, not by
     * the branch having a tip the pass did not write.
     */
    #[Test]
    public function a_no_change_pass_is_consistent_while_the_shared_branch_moves_on(): void
    {
        $pass = $this->givenAPass(self::SECOND);
        $this->generations->assignBranch($pass, self::SHARED, shared: true);
        $this->generations->recordNoChange($this->generations->get($pass->generation()));
        $first = $this->givenAFinishedTask();

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($pass->generation()), Phase::Finalizing),
        );

        self::assertSame(RecoveryVerdict::Consistent, $recovery->verdict());
        self::assertStringContainsString(
            'recorded as changing nothing',
            implode("\n", $recovery->findings()),
        );
        self::assertStringContainsString('none of them its own', implode("\n", $recovery->findings()));
        self::assertSame($first->sha(), $this->tipOfShared());
    }

    #[Test]
    public function a_no_change_pass_stops_when_the_branch_gains_a_commit_nobody_recorded(): void
    {
        $pass = $this->givenAPass(self::SECOND);
        $this->generations->assignBranch($pass, self::SHARED, shared: true);
        $this->generations->recordNoChange($this->generations->get($pass->generation()));
        $stranger = $this->givenAHandCommit('Something somebody did by hand');

        $recovery = $this->recovery()->reconcile(
            $this->interrupted($this->generations->get($pass->generation()), Phase::Finalizing),
        );

        self::assertTrue($recovery->isBlocked());
        self::assertStringContainsString(
            'recorded as leaving the task unchanged',
            (string) $recovery->blocker()?->reason(),
        );
        self::assertStringContainsString('no pass of this project recorded', (string) $recovery->blocker()?->reason());
        self::assertStringContainsString('satisfaction record', (string) $recovery->blocker()?->nextAction());
        self::assertSame($stranger, $this->tipOfShared());
    }

    private function recovery(): PassRecovery
    {
        return PassRecovery::forProject($this->configuration(), $this->generations);
    }

    /**
     * A task of the run that is done with: its commit is on the shared branch
     * and its row records it.
     */
    private function givenAFinishedTask(string $description = self::FIRST, ?string $base = null): TaskCommit
    {
        $committed = $this->givenACommit($this->givenAPass($description, $base), $description);
        $completed = $this->generations->complete($committed->recorded());

        // The sweep a finished pass gets ({@see \Celyes\RalphPhp\Run\TaskPass}):
        // the workspace goes, which is also what frees the shared branch for
        // the next task — Git allows a branch in one worktree at a time.
        WorktreeCleaner::forProject($this->configuration(), $this->generations)->clean($completed);

        return $committed;
    }

    /**
     * A pass at a task, mid-work, built on the commit the shared branch carried
     * when it was taken on.
     */
    private function givenAPass(
        string $description,
        ?string $base = null,
        Phase $phase = Phase::Executing,
    ): GenerationRecord {
        $snapshot = $this->snapshot($description);

        if (!$this->tasks->has($snapshot->id())) {
            $this->tasks->record($snapshot);
        }

        $started = $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            $base === null ? 'HEAD' : self::SHARED,
            $base ?? $this->base()->sha(),
            null,
            $this->insertRun(),
        );

        $attempting = $this->generations->beginAttempt($started);

        return $phase === Phase::initial()
            ? $attempting
            : $this->generations->enterPhase($attempting, $phase);
    }

    /**
     * The pass's commit, made the way a shared-branch run makes it: in the
     * pass's own worktree, onto the branch the run established.
     */
    private function givenACommit(GenerationRecord $pass, string $description): TaskCommit
    {
        $configuration = $this->configuration();
        $workspace = $this->givenAWorkspace($pass);
        $this->inWorkspace($workspace, sprintf('src/%s.php', $pass->generation()->slug()), "<?php\n");

        $current = $this->generations->get($pass->generation());
        $branch = TaskBranch::shared($current->generation(), BranchName::fromString(self::SHARED));
        $shared = $this->sharedBranch();

        return TaskCommitter::forProject($configuration, $this->generations)->commit(
            $current,
            $workspace,
            SharedBranches::forProject($configuration, $this->generations)
                ->requireForPass($shared, $branch, $current),
            WorkspaceStager::forProject($configuration)->stage($workspace),
            CommitMessage::forGeneration($current, $this->snapshot($description)),
        );
    }

    /**
     * The run's reading of its branch, as the run would have established it.
     */
    private function sharedBranch(): SharedBranch
    {
        $name = BranchName::fromString(self::SHARED);
        $tip = $this->tipOfShared();

        return $tip === null
            ? SharedBranch::available($name)
            : SharedBranch::continuing($name, $tip);
    }

    private function givenAWorkspace(GenerationRecord $pass): TaskWorktree
    {
        $configuration = $this->configuration();
        $workspace = TaskWorktrees::forProject($configuration)->prepare(
            $pass->generation(),
            BaseCommit::of($pass->baseReference(), $pass->baseSha()),
        );
        $this->generations->recordWorktree($pass, $workspace->ownership());

        return $workspace;
    }

    /**
     * The state a process killed between the commit and the row it would have
     * written leaves behind: the commit on the branch, the column null.
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
     * Take the shared branch out of a preserved workspace's HEAD, leaving its
     * files and its registration alone.
     *
     * Git allows a branch in one worktree at a time, so a workspace kept for
     * inspection holds the name until somebody detaches it. What follows in
     * these tests — a later task committing to the branch — is only reachable
     * once it has been, which is the shape of a run resumed after the pass's
     * own workspace was looked at and let go of.
     */
    private function releaseTheBranchFrom(GenerationRecord $pass, string $commit): void
    {
        Git::run(
            TaskWorktrees::forProject($this->configuration())->pathFor($pass->generation()),
            'checkout',
            '--detach',
            $commit,
        );
    }

    private function interrupted(GenerationRecord $pass, Phase $phase): GenerationRecord
    {
        $current = $this->generations->get($pass->generation());

        return $this->generations->interrupt(
            $current->phase() === $phase ? $current : $this->generations->enterPhase($current, $phase),
        );
    }

    /**
     * A commit on the shared branch that this package did not make, added
     * without moving the controlling checkout.
     */
    private function givenAHandCommit(string $subject): string
    {
        $parent = $this->tipOfShared() ?? $this->base()->sha();
        $tree = trim(Git::run($this->project->root(), 'rev-parse', $parent . '^{tree}')->standardOutput);

        $commit = trim(Git::run(
            $this->project->root(),
            '-c',
            'user.name=Test User',
            '-c',
            'user.email=test@example.com',
            'commit-tree',
            $tree,
            '-p',
            $parent,
            '-m',
            $subject,
        )->standardOutput);

        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . self::SHARED, $commit);

        return $commit;
    }

    private function tipOfShared(): ?string
    {
        $result = Git::tryRun(
            $this->project->root(),
            'rev-parse',
            '--verify',
            '--quiet',
            'refs/heads/' . self::SHARED,
        );

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    private function countOfCommitsOnShared(): int
    {
        return (int) trim(Git::run(
            $this->project->root(),
            'rev-list',
            '--count',
            $this->base()->sha() . '..refs/heads/' . self::SHARED,
        )->standardOutput);
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

    private function snapshot(string $description): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            '- [ ] ' . $description,
            [],
        )));
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->fromArray(
            ['git' => ['strategy' => 'shared']],
            $this->project->root(),
        );
    }

    private function base(): BaseCommit
    {
        return $this->base ??= BaseResolver::forProject($this->configuration())->resolve();
    }

    private function givenACommittedProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('README.md', "An example project.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    /**
     * A run row, for the column the run identifier references.
     */
    private function insertRun(): int
    {
        $existing = $this->database->query('SELECT id FROM runs WHERE run_id = ?', ['RUN-0001']);

        if ($existing !== []) {
            return (int) $existing[0]['id'];
        }

        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, shared_branch, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-0001',
                'interrupted',
                $agent['agent'],
                $agent['adapter'],
                'project_default',
                $agent['settings'],
                '{"git":{"strategy":"shared","shared_branch":"' . self::SHARED . '"}}',
                '[]',
                '.ralph/tasks.md',
                'HEAD',
                self::SHARED,
                '2026-11-14T09:30:00+00:00',
                '2026-11-14T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
