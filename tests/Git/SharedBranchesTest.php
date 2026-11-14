<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BranchClaim;
use Celyes\RalphPhp\Git\BranchName;
use Celyes\RalphPhp\Git\BranchOwnership;
use Celyes\RalphPhp\Git\SharedBranch;
use Celyes\RalphPhp\Git\SharedBranches;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\RunStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The branch a shared-branch run commits to: established once, then its own
 * (PROJECT.md section K, item 3).
 *
 * Two levels are tested here because the mode asks the question twice. Once per
 * run — may this branch carry this run's commits at all? — and once per pass —
 * may *this workspace* be committed onto it? The second is the one that
 * protects commits already on the branch, and the case that matters most is the
 * second task of a run: until a task's workspace can be made from the branch's
 * tip (section K, item 4), committing it would move the branch onto a commit
 * that does not contain the first task's work. So the refusal is asserted
 * together with the refs being untouched, as in {@see TaskBranchesTest}: what
 * is being tested is as much that nothing happened as that something was said.
 *
 * Real repositories throughout. Branch tips, worktree registrations and the
 * ancestry Git computes are the subject, and none of them can be faked.
 */
#[CoversClass(SharedBranches::class)]
#[CoversClass(SharedBranch::class)]
final class SharedBranchesTest extends TestCase
{
    private const string FIRST = 'Add a greeting';

    private const string SECOND = 'Add a farewell';

    private const string SHARED = 'ralph/shared';

    private TemporaryProject $project;

    private string $base;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private RunStore $runs;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this is about real branches.');
        }

        $this->project = TemporaryProject::create();
        $this->base = $this->givenACommittedProject();

        $this->database = (new StateBootstrap())->openInMemory()->database();
        $clock = FrozenClock::at('2026-11-14T10:00:00+00:00');
        $this->tasks = new TaskStore($this->database, $clock);
        $this->generations = new GenerationStore($this->database, $clock);
        $this->runs = new RunStore($this->database, $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            Git::tryRun($this->project->root(), 'worktree', 'prune');
            $this->project->remove();
        }
    }

    #[Test]
    public function a_branch_nothing_holds_is_created_by_the_first_task_to_commit(): void
    {
        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());

        self::assertSame(self::SHARED, $shared->value());
        self::assertSame(BranchClaim::Available, $shared->claim());
        self::assertTrue($shared->needsCreating());
        self::assertFalse($shared->exists());
        self::assertNull($shared->tip());
        self::assertSame([], $shared->hints());
        self::assertSame('ralph/shared (new)', $shared->describe());
        self::assertFalse($shared->wasEstablished());
    }

    /**
     * A branch sitting at the run's base holds nothing the run has not got, so
     * it is continued rather than refused: `ralph/shared` left behind by a run
     * that stopped before its first commit is the ordinary way this happens.
     */
    #[Test]
    public function a_branch_at_the_runs_base_commit_is_continued(): void
    {
        $this->givenABranch(self::SHARED, $this->base);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());

        self::assertSame(BranchClaim::Ours, $shared->claim());
        self::assertTrue($shared->exists());
        self::assertSame($this->base, $shared->tip());
        self::assertNull($shared->recordedBy());
        self::assertStringContainsString('which this run continues', $shared->summary());
    }

    /**
     * The branch an earlier run of this project left behind. Its tip has a row
     * naming the pass that wrote it, which is what tells it from a commit
     * somebody made by hand.
     */
    #[Test]
    public function a_branch_holding_a_commit_this_project_recorded_is_continued(): void
    {
        $pass = $this->passAt(self::FIRST);
        $commit = $this->givenACommitOn(self::SHARED);
        $this->generations->recordCommit($pass, $commit);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());

        self::assertSame(BranchClaim::Ours, $shared->claim());
        self::assertSame($commit, $shared->tip());
        self::assertSame($pass->slug(), $shared->recordedBy());
        self::assertStringContainsString(
            sprintf('recorded as the work of %s', $pass->slug()),
            implode("\n", $shared->details()),
        );
    }

    #[Test]
    public function a_branch_holding_work_nobody_recorded_stops_the_run(): void
    {
        $commit = $this->givenACommitOn(self::SHARED);
        $before = $this->refs();

        $refusal = $this->refusal();

        self::assertStringContainsString('is not a branch this run may commit to', $refusal->getMessage());
        self::assertStringContainsString('nor a commit this project recorded', $refusal->getMessage());
        self::assertStringContainsString(substr($commit, 0, 12), $refusal->getMessage());
        self::assertStringContainsString(
            'will not reset, move or delete the shared branch',
            implode("\n", $refusal->hints()),
        );
        self::assertStringContainsString('git.shared_branch', implode("\n", $refusal->hints()));
        self::assertSame($before, $this->refs());
    }

    /**
     * Git allows a branch in one worktree at a time, and the run's commits are
     * made in the task workspaces: a branch somebody has checked out elsewhere
     * could not be committed to anyway, and finding that out here costs nothing.
     */
    #[Test]
    public function a_branch_checked_out_somewhere_the_run_does_not_own_stops_the_run(): void
    {
        $elsewhere = $this->project->path('elsewhere');
        Git::run($this->project->root(), 'worktree', 'add', '-b', self::SHARED, $elsewhere, $this->base);
        $before = $this->refs();

        $refusal = $this->refusal();

        self::assertStringContainsString('is checked out in', $refusal->getMessage());
        self::assertStringContainsString('which is not a workspace of this run', $refusal->getMessage());
        self::assertStringContainsString('one worktree at a time', implode("\n", $refusal->hints()));
        self::assertSame($before, $this->refs());
    }

    /**
     * The persisted identity: a run that established a branch keeps it, even
     * when `git.shared_branch` has been edited since.
     */
    #[Test]
    public function the_branch_a_run_established_is_the_one_it_is_validated_against(): void
    {
        $run = $this->runs->recordSharedBranch($this->givenARun(), 'release/october');
        $this->givenABranch('release/october', $this->base);

        $shared = $this->shared()->require($run, $this->baseCommit());

        self::assertSame('release/october', $shared->value());
        self::assertTrue($shared->wasEstablished());
        self::assertStringContainsString(
            'read back from this run\'s own record',
            implode("\n", $shared->details()),
        );
    }

    #[Test]
    public function a_per_task_run_has_no_shared_branch_to_establish(): void
    {
        $this->expectException(BranchException::class);
        $this->expectExceptionMessage('records no branch for its tasks to share');

        $this->shared()->establish($this->givenARun('per-task'), $this->baseCommit());
    }

    #[Test]
    public function a_configured_branch_git_would_refuse_is_reported_against_the_setting(): void
    {
        $run = $this->givenARun();
        $this->database->execute(
            'UPDATE runs SET configuration = ? WHERE run_id = ?',
            ['{"git":{"strategy":"shared","shared_branch":"ralph/shared.lock","branch_prefix":"ralph/"}}', $run->runId()],
        );

        try {
            $this->shared()->establish($this->runs->get($run->rowId()), $this->baseCommit());
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString('"ralph/shared.lock"', $refusal->getMessage());
            self::assertStringContainsString('Git would refuse it', $refusal->getMessage());
            self::assertStringContainsString('"git.shared_branch"', implode("\n", $refusal->hints()));

            return;
        }

        self::fail('a branch name Git would refuse was accepted');
    }

    /**
     * A recorded name this package could not have written. Reported rather than
     * replaced with a generated one: the branch it names may hold the run's
     * commits, and renaming would leave them with nothing naming them.
     */
    #[Test]
    public function a_recorded_branch_git_could_not_hold_is_reported_without_renaming_the_run(): void
    {
        $run = $this->givenARun();
        $this->database->execute(
            'UPDATE runs SET shared_branch = ? WHERE run_id = ?',
            ['ralph/shared..broken', $run->runId()],
        );

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('records the shared branch "ralph/shared..broken"');

        $this->shared()->establish($this->runs->get($run->rowId()), $this->baseCommit());
    }

    #[Test]
    public function the_first_pass_may_commit_to_a_branch_that_does_not_exist_yet(): void
    {
        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());
        $pass = $this->passAt(self::FIRST);

        $ownership = $this->shared()->requireForPass(
            $shared,
            $this->branchFor($pass, $shared),
            $pass,
            $this->baseCommit(),
        );

        self::assertSame(BranchClaim::Available, $ownership->claim());
        self::assertTrue($ownership->claim()->needsCreating());
    }

    #[Test]
    public function a_pass_whose_workspace_starts_where_the_branch_is_may_commit(): void
    {
        $this->givenABranch(self::SHARED, $this->base);
        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());
        $pass = $this->passAt(self::FIRST);

        $ownership = $this->shared()->requireForPass(
            $shared,
            $this->branchFor($pass, $shared),
            $pass,
            $this->baseCommit(),
        );

        self::assertSame(BranchClaim::Ours, $ownership->claim());
        self::assertSame($this->base, $ownership->tip());
    }

    /**
     * The case the whole pass-level reading exists for. The first task has
     * committed, so the branch is past the commit the second task's workspace
     * was made from — and a commit made in that workspace would move the branch
     * onto history that does not contain the first task's work.
     */
    #[Test]
    public function a_later_pass_is_stopped_while_its_workspace_cannot_continue_the_branch(): void
    {
        $first = $this->passAt(self::FIRST);
        $committed = $this->givenACommitOn(self::SHARED);
        $this->generations->recordCommit($first, $committed);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());
        $second = $this->passAt(self::SECOND);
        $before = $this->refs();

        $refusal = $this->passRefusal($shared, $second);
        $reading = $refusal->ownership();

        self::assertNotNull($reading);
        self::assertSame(BranchClaim::Unrelated, $reading->claim());
        self::assertSame($committed, $reading->tip());
        self::assertSame($first->slug(), $reading->owner());
        self::assertStringContainsString('would leave', $refusal->getMessage());
        // In the reason rather than only in the hints, because the reason is
        // what a run prints when it stops on a task.
        self::assertStringContainsString('section K, item 4', $refusal->getMessage());
        self::assertStringContainsString('"git.strategy" to "per-task"', implode("\n", $refusal->hints()));
        self::assertSame($before, $this->refs());
    }

    /**
     * The branch is where the second task's workspace starts — the project
     * merged it and ran again — so nothing would be lost by committing. The row
     * is what cannot be shared yet, and the refusal says so in those terms
     * rather than leaving the state store to complain about an index.
     */
    #[Test]
    public function a_pass_at_a_branch_another_pass_records_is_stopped_in_the_modes_own_terms(): void
    {
        $first = $this->passAt(self::FIRST);
        $this->generations->assignBranch($first, self::SHARED);
        $this->givenABranch(self::SHARED, $this->base);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());
        $second = $this->passAt(self::SECOND);
        $before = $this->refs();

        $refusal = $this->passRefusal($shared, $second);

        self::assertStringContainsString(
            sprintf('is recorded as the branch of %s', $first->slug()),
            $refusal->getMessage(),
        );
        self::assertStringContainsString('held by one pass', $refusal->getMessage());
        self::assertStringContainsString('section K, item 4', $refusal->getMessage());
        self::assertStringContainsString('commits on ralph/shared are intact', implode("\n", $refusal->hints()));
        self::assertSame($before, $this->refs());
    }

    /**
     * Resume: the pass has a commit on the branch, and the branch still carries
     * it. That is the pass's branch whatever else has landed on top.
     */
    #[Test]
    public function a_pass_whose_commit_is_still_on_the_branch_keeps_it(): void
    {
        $pass = $this->passAt(self::FIRST);
        $commit = $this->givenACommitOn(self::SHARED);
        $pass = $this->generations->recordCommit($pass, $commit);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());

        $ownership = $this->shared()->requireForPass(
            $shared,
            $this->branchFor($pass, $shared),
            $pass,
            $this->baseCommit(),
        );

        self::assertSame(BranchClaim::Ours, $ownership->claim());
        self::assertSame($commit, $ownership->tip());
    }

    #[Test]
    public function a_pass_whose_commit_has_been_taken_off_the_branch_is_stopped(): void
    {
        $pass = $this->passAt(self::FIRST);
        $commit = $this->givenACommitOn(self::SHARED);
        $pass = $this->generations->recordCommit($pass, $commit);

        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());

        // Somebody moved the branch back, which is what a `git reset --hard`
        // on the shared branch looks like from here.
        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . self::SHARED, $this->base);

        $refusal = $this->passRefusal($shared, $pass);

        self::assertStringContainsString('no longer contains', $refusal->getMessage());
        self::assertStringContainsString('records as its commit', $refusal->getMessage());
    }

    /**
     * A pass carrying a branch of its own — started under per-task mode, say —
     * is not the run's shared branch to validate, and saying so is better than
     * reading its name as the shared one.
     */
    #[Test]
    public function a_branch_that_is_not_the_runs_is_refused_for_the_pass(): void
    {
        $shared = $this->shared()->require($this->givenARun(), $this->baseCommit());
        $pass = $this->passAt(self::FIRST);

        $refusal = $this->passRefusal(
            $shared,
            $pass,
            TaskBranch::retained($pass->generation(), BranchName::fromString('ralph/elsewhere')),
        );

        self::assertStringContainsString('is not the branch this run established', $refusal->getMessage());
        self::assertStringContainsString('ralph/shared', $refusal->getMessage());
    }

    private function shared(): SharedBranches
    {
        return SharedBranches::forProject($this->configuration(), $this->generations);
    }

    private function configuration(string $strategy = 'shared'): Configuration
    {
        return (new ConfigurationLoader())->fromArray(
            ['git' => ['strategy' => $strategy]],
            $this->project->root(),
        );
    }

    private function givenARun(string $strategy = 'shared'): RunRecord
    {
        return $this->runs->open(
            new AgentSelection(AgentDefinition::builtIn('codex'), AgentSource::ProjectDefault),
            $this->configuration($strategy),
            $this->base,
        );
    }

    private function baseCommit(): BaseCommit
    {
        return BaseCommit::of('HEAD', $this->base);
    }

    /**
     * The branch a pass commits to in shared mode: the run's, with the pass's
     * own generation on it, which is what {@see \Celyes\RalphPhp\Run\TaskPass}
     * builds.
     */
    private function branchFor(GenerationRecord $record, SharedBranch $shared): TaskBranch
    {
        return TaskBranch::generated($record->generation(), $shared->name());
    }

    /**
     * A recorded pass at a task, owning nothing yet.
     */
    private function passAt(string $description): GenerationRecord
    {
        $snapshot = TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            '- [ ] ' . $description,
            [],
        )));

        if (!$this->tasks->has($snapshot->id())) {
            $this->tasks->record($snapshot);
        }

        return $this->generations->openFor($snapshot->id()) ?? $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base,
        );
    }

    private function refusal(): BranchException
    {
        try {
            $this->shared()->require($this->givenARun(), $this->baseCommit());
        } catch (BranchException $exception) {
            return $exception;
        }

        self::fail('a shared branch holding unrelated work was accepted');
    }

    private function passRefusal(
        SharedBranch $shared,
        GenerationRecord $record,
        ?TaskBranch $branch = null,
    ): BranchException {
        try {
            $this->shared()->requireForPass(
                $shared,
                $branch ?? $this->branchFor($record, $shared),
                $record,
                $this->baseCommit(),
            );
        } catch (BranchException $exception) {
            return $exception;
        }

        self::fail('a pass was allowed to commit over the shared branch');
    }

    private function givenABranch(string $name, string $commit): string
    {
        Git::run($this->project->root(), 'branch', $name, $commit);

        return $commit;
    }

    /**
     * A commit on a branch, made without checking it out, so the controlling
     * checkout stays where it is.
     */
    private function givenACommitOn(string $branch): string
    {
        $start = Git::tryRun($this->project->root(), 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch);
        $parent = $start->exitCode === 0 ? trim($start->standardOutput) : $this->base;
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
            'Work on ' . $branch,
        )->standardOutput);

        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . $branch, $commit);

        return $commit;
    }

    /**
     * @return array<string, string>
     */
    private function refs(): array
    {
        $refs = [];

        foreach (explode("\n", trim(Git::run(
            $this->project->root(),
            'for-each-ref',
            '--format=%(refname) %(objectname)',
            'refs/heads/',
        )->standardOutput)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$name, $object] = explode(' ', $line, 2);
            $refs[$name] = $object;
        }

        return $refs;
    }

    private function givenACommittedProject(): string
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        $this->project->write('src/Foo.php', "<?php\n// original\n");
        $this->project->write('.gitignore', "/.ralph/\n");
        Git::run($this->project->root(), 'add', 'src/Foo.php', '.gitignore');

        return Git::commit($this->project->root());
    }
}
