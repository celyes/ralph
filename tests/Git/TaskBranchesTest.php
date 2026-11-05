<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Git\BranchClaim;
use Celyes\RalphPhp\Git\BranchName;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\BranchOwnership;
use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\State\WorktreeOwnership;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\GenerationHistory;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whose branch a task branch is, against real repositories.
 *
 * The question only exists because the name is generated, so every case here is
 * a different way for a name to be produced twice: a person who created the
 * branch first, a pass being resumed onto the branch it already owns, a branch
 * that was reset off the commit a pass recorded, a branch another pass's row
 * holds, a branch Git has checked out somewhere else. None of that can be
 * faked — `git branch -f`, `git worktree add` and the ancestry Git computes are
 * the subject — so these tests create repositories and ask the installed `git`,
 * as PROJECT.md section J, item 5 requires.
 *
 * One assertion recurs and is the point of the class: {@see self::refs()} is
 * identical before and after every refusal. The three convenient responses to
 * finding a branch in the way all destroy commits, so what is being tested is
 * as much that nothing happened as that something was reported.
 */
#[CoversClass(TaskBranches::class)]
#[CoversClass(BranchOwnership::class)]
#[CoversClass(BranchClaim::class)]
#[CoversClass(BranchException::class)]
final class TaskBranchesTest extends TestCase
{
    private const string DESCRIPTION = 'Add a greeting';

    private TemporaryProject $project;

    private string $base;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this is about real branches.');
        }

        $this->project = TemporaryProject::create();
        $this->base = $this->givenACommittedProject();

        $this->database = (new StateBootstrap())->openInMemory()->database();
        $clock = FrozenClock::at('2026-11-05T10:00:00+00:00');
        $this->tasks = new TaskStore($this->database, $clock);
        $this->generations = new GenerationStore($this->database, $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            Git::tryRun($this->project->root(), 'worktree', 'prune');
            $this->project->remove();
        }
    }

    #[Test]
    public function a_name_nothing_holds_is_available_to_the_pass(): void
    {
        $branch = $this->generatedBranch();

        $ownership = $this->branches()->require($branch, $this->started());

        self::assertSame(BranchClaim::Available, $ownership->claim());
        self::assertTrue($ownership->isAvailable());
        self::assertTrue($ownership->isUsable());
        self::assertTrue($ownership->claim()->needsCreating());
        self::assertFalse($ownership->exists());
        self::assertNull($ownership->tip());
        self::assertNull($ownership->owner());
        self::assertNull($ownership->checkedOutIn());
        self::assertSame([], $ownership->hints());
        self::assertStringContainsString('is available for', $ownership->summary());
    }

    /**
     * The ordinary collision: nothing in the state store mentions the branch,
     * so whoever created it, it is not the run's to take.
     */
    #[Test]
    public function a_branch_a_person_created_first_stops_the_run(): void
    {
        $branch = $this->generatedBranch();
        $theirs = $this->givenABranch($branch->value(), $this->unrelatedCommit());
        $before = $this->refs();

        $refusal = $this->refusal($branch, $this->started());

        self::assertStringContainsString($branch->value(), $refusal->getMessage());
        self::assertStringContainsString('no pass at this task records it', $refusal->getMessage());
        $ownership = $this->readingOf($refusal);
        self::assertSame($theirs, $ownership->tip());
        self::assertSame(BranchClaim::Unrelated, $ownership->claim());
        self::assertSame($before, $this->refs());
    }

    /**
     * The refusal says what it will not do, and names the commit it left alone
     * so the user can go and look at it.
     */
    #[Test]
    public function the_refusal_names_the_work_it_refused_to_touch(): void
    {
        $branch = $this->generatedBranch();
        $this->givenABranch($branch->value(), $this->unrelatedCommit());

        $hints = $this->refusal($branch, $this->started())->hints();

        self::assertStringContainsString('will not reset, move or delete', implode("\n", $hints));
        self::assertStringContainsString(
            sprintf('git log --oneline -1 %s', $branch->value()),
            implode("\n", $hints),
        );
        self::assertStringContainsString('git.branch_prefix', implode("\n", $hints));
    }

    /**
     * Resume: the branch exists, the pass's own row records it, and it still
     * holds what the row says. Continued, never reset.
     */
    #[Test]
    public function a_pass_continues_on_the_branch_its_own_row_records(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $this->givenABranch($branch->value(), $this->base);

        $ownership = $this->branches()->require($branch, $record, $this->history());

        self::assertSame(BranchClaim::Ours, $ownership->claim());
        self::assertTrue($ownership->isOurs());
        self::assertFalse($ownership->claim()->needsCreating());
        self::assertTrue($ownership->exists());
        self::assertSame($this->base, $ownership->tip());
        self::assertStringContainsString('already owns', $ownership->summary());
        self::assertStringContainsString('read back from the pass\'s own record', implode("\n", $ownership->details()));
    }

    /**
     * A branch carrying the commit the pass recorded is the pass's, and so is
     * one that has moved on from it: an extra commit on the pass's own branch
     * is PROJECT.md section F, item 9's business, not ownership's.
     */
    #[Test]
    public function a_branch_still_carrying_the_passs_commit_is_the_passs(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $commit = $this->givenACommitOn($branch->value());
        $record = $this->generations->recordCommit($record, $commit);

        self::assertSame(
            BranchClaim::Ours,
            $this->branches()->require($branch, $record, $this->history())->claim(),
        );

        $later = $this->givenACommitOn($branch->value());
        $ownership = $this->branches()->require($branch, $record, $this->history());

        self::assertSame(BranchClaim::Ours, $ownership->claim());
        self::assertSame($later, $ownership->tip());
    }

    /**
     * `git branch -f` onto a history without the pass's commit makes the name a
     * different branch. Refused rather than committed onto.
     */
    #[Test]
    public function a_branch_reset_off_the_passs_commit_is_no_longer_the_passs(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $commit = $this->givenACommitOn($branch->value());
        $record = $this->generations->recordCommit($record, $commit);

        $elsewhere = $this->unrelatedCommit();
        Git::run($this->project->root(), 'branch', '--force', $branch->value(), $elsewhere);
        $before = $this->refs();

        $refusal = $this->refusal($branch, $record);

        self::assertStringContainsString('no longer contains', $refusal->getMessage());
        self::assertStringContainsString(substr($commit, 0, BranchOwnership::SHORT_LENGTH), $refusal->getMessage());
        self::assertStringContainsString('its commit', $refusal->getMessage());
        self::assertSame($elsewhere, $this->readingOf($refusal)->tip());
        self::assertSame($before, $this->refs());
    }

    /**
     * Before a pass has committed, the branch it owns must still descend from
     * the base commit the run resolved; a branch with an unrelated history is
     * somebody else's under a name that happens to match.
     */
    #[Test]
    public function a_recorded_branch_whose_history_misses_the_base_is_refused(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $this->givenABranch($branch->value(), $this->unrelatedCommit());

        $refusal = $this->refusal($branch, $record);

        self::assertStringContainsString('no longer contains', $refusal->getMessage());
        self::assertStringContainsString('its base commit', $refusal->getMessage());
    }

    /**
     * A recorded branch that is simply not there is not a refusal: nothing owns
     * the name, so the pass may create it again. What the vanished branch means
     * for a recorded commit is reconciliation's question, and the reading says
     * plainly that Git has no such branch.
     */
    #[Test]
    public function a_recorded_branch_that_has_gone_is_available_again(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);

        $ownership = $this->branches()->require($branch, $record, $this->history());

        self::assertSame(BranchClaim::Available, $ownership->claim());
        self::assertStringContainsString(
            sprintf('No branch named %s exists', $branch->value()),
            implode("\n", $ownership->details()),
        );
    }

    /**
     * A name another pass's row holds is taken before Git has a branch under
     * it: that pass's commit is going there.
     */
    #[Test]
    public function a_name_another_pass_has_reserved_is_refused_before_it_exists(): void
    {
        $first = $this->withBranch($this->started());
        $reopened = TaskGeneration::of($this->taskId(), 2);
        $collision = TaskBranch::generated($reopened, BranchName::fromString((string) $first->branch()));

        $refusal = $this->refusal($collision, null, $this->history());

        $ownership = $this->readingOf($refusal);
        self::assertStringContainsString('is recorded as the branch of', $refusal->getMessage());
        self::assertSame($first->generation()->label(), $ownership->owner());
        self::assertFalse($ownership->exists());
        self::assertStringContainsString('where that pass\'s work lives', implode("\n", $ownership->hints()));
        self::assertSame([], $this->refs());
    }

    /**
     * Git allows a branch in one worktree at a time, so a branch checked out
     * somewhere the pass does not work cannot be committed to from the pass's
     * workspace. Reported here rather than as a failed checkout later.
     */
    #[Test]
    public function a_branch_checked_out_in_another_worktree_is_refused(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $elsewhere = $this->project->path('elsewhere');
        Git::run($this->project->root(), 'worktree', 'add', '-b', $branch->value(), $elsewhere, $this->base);

        $refusal = $this->refusal($branch, $record);

        $ownership = $this->readingOf($refusal);
        self::assertStringContainsString('is checked out in ' . $elsewhere, $refusal->getMessage());
        self::assertSame($elsewhere, $ownership->checkedOutIn()?->path());
        self::assertStringContainsString('one worktree at a time', implode("\n", $ownership->hints()));
    }

    /**
     * The same reading in the pass's own workspace is the ordinary case, which
     * is what makes the recorded worktree path worth storing.
     */
    #[Test]
    public function the_passs_own_workspace_holding_its_branch_is_not_a_refusal(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $workspace = $this->project->path('.ralph/runtime/worktrees/' . $record->slug());
        Git::run($this->project->root(), 'worktree', 'add', '-b', $branch->value(), $workspace, $this->base);
        $record = $this->generations->recordWorktree($record, WorktreeOwnership::created($workspace));

        $ownership = $this->branches()->require($branch, $record, $this->history());

        self::assertSame(BranchClaim::Ours, $ownership->claim());
        self::assertSame($workspace, $ownership->checkedOutIn()?->path());
    }

    /**
     * A question Git does not answer is a refusal, not an empty answer: a run
     * that cannot establish who owns a branch must neither take it nor commit
     * to it.
     */
    #[Test]
    public function a_repository_that_cannot_be_asked_stops_the_run(): void
    {
        $directory = sys_get_temp_dir();

        if (Git::tryRun($directory, 'rev-parse', '--git-dir')->exitCode === 0) {
            self::markTestSkipped(sprintf('%s is inside a repository, so Git can answer there.', $directory));
        }

        $outside = new TaskBranches(Repository::at($directory));

        $this->expectException(BranchException::class);
        $this->expectExceptionMessage('could not be determined');

        $outside->inspect($this->generatedBranch(), $this->started());
    }

    /**
     * A recorded commit the repository no longer has makes the ancestry
     * question unanswerable, which is reported rather than read as "the branch
     * is not ours".
     */
    #[Test]
    public function a_commit_the_repository_lost_is_reported_rather_than_guessed(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $this->givenABranch($branch->value(), $this->base);
        $record = $this->generations->recordCommit($record, str_repeat('a', 40));

        $this->expectException(BranchException::class);
        $this->expectExceptionMessage('is still on ' . $branch->value());

        $this->branches()->require($branch, $record, $this->history());
    }

    #[Test]
    public function deciding_ownership_changes_nothing_in_the_repository(): void
    {
        $branch = $this->generatedBranch();
        $this->givenABranch($branch->value(), $this->base);
        $before = $this->snapshot();

        $this->branches()->inspect($branch, $this->started(), $this->history());

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function a_reading_is_reportable_as_data(): void
    {
        $record = $this->withBranch($this->started());
        $branch = $this->retainedBranch($record);
        $this->givenABranch($branch->value(), $this->base);

        $ownership = $this->branches()->inspect($branch, $record, $this->history());

        self::assertSame(
            [
                'branch' => $branch->value(),
                'generation' => $record->slug(),
                'claim' => 'ours',
                'usable' => true,
                'exists' => true,
                'tip' => $this->base,
                'checked_out_in' => null,
                'owner' => null,
                'reason' => null,
            ],
            $ownership->toArray(),
        );
        self::assertSame($branch->value() . ': owned by this pass', $ownership->describe());
        self::assertSame($branch->value(), $ownership->name());
        self::assertTrue($ownership->branch()->name()->equals($branch->name()));
    }

    #[Test]
    public function the_claims_describe_themselves(): void
    {
        self::assertSame('available', BranchClaim::Available->describe());
        self::assertSame('owned by this pass', BranchClaim::Ours->describe());
        self::assertSame('owned by unrelated work', BranchClaim::Unrelated->describe());
        self::assertTrue(BranchClaim::Available->isUsable());
        self::assertTrue(BranchClaim::Ours->isUsable());
        self::assertFalse(BranchClaim::Unrelated->isUsable());
        self::assertFalse(BranchClaim::Ours->needsCreating());
    }

    private function branches(): TaskBranches
    {
        return TaskBranches::forProject(
            (new ConfigurationLoader())->fromArray([], $this->project->root()),
        );
    }

    private function namer(): BranchNamer
    {
        return BranchNamer::forProject(
            (new ConfigurationLoader())->fromArray([], $this->project->root()),
        );
    }

    private function generatedBranch(): TaskBranch
    {
        return $this->namer()->resolve($this->generation(), self::DESCRIPTION);
    }

    private function retainedBranch(GenerationRecord $record): TaskBranch
    {
        return $this->namer()->forRecord($record, self::DESCRIPTION);
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first($this->taskId());
    }

    private function taskId(): TaskId
    {
        return TaskId::fromDescription(self::DESCRIPTION);
    }

    /**
     * A pass at the task, recorded but owning nothing yet.
     */
    private function started(): GenerationRecord
    {
        $snapshot = TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            self::DESCRIPTION,
            false,
            1,
            '- [ ] ' . self::DESCRIPTION,
            [],
        )));

        if (!$this->tasks->has($snapshot->id())) {
            $this->tasks->record($snapshot);
        }

        $existing = $this->generations->openFor($snapshot->id());

        return $existing ?? $this->generations->start(
            $this->generation(),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base,
        );
    }

    /**
     * The pass with the branch this run would name it assigned, which is what
     * makes the name retained rather than generated.
     */
    private function withBranch(GenerationRecord $record): GenerationRecord
    {
        return $this->generations->assignBranch(
            $record,
            $this->namer()->nameFor($record->generation(), self::DESCRIPTION)->value(),
        );
    }

    /**
     * Every pass the state store knows about, as the runner reads them.
     */
    private function history(): GenerationHistory
    {
        return $this->generations->history();
    }

    /**
     * The reading a refusal carries, which is what a caller records rather than
     * the sentence about it.
     */
    private function readingOf(BranchException $refusal): BranchOwnership
    {
        $ownership = $refusal->ownership();

        self::assertNotNull($ownership, 'the refusal carried no reading');

        return $ownership;
    }

    private function refusal(
        TaskBranch $branch,
        ?GenerationRecord $record = null,
        ?GenerationHistory $history = null,
    ): BranchException {
        try {
            $this->branches()->require($branch, $record, $history ?? $this->history());
        } catch (BranchException $exception) {
            return $exception;
        }

        self::fail('a branch owned by unrelated work was accepted');
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
     * A commit with no relation to the base, which is what a branch somebody
     * else created from somewhere else looks like.
     */
    private function unrelatedCommit(): string
    {
        $empty = trim(Git::run($this->project->root(), 'hash-object', '-t', 'tree', '/dev/null', '-w')->standardOutput);

        return trim(Git::run(
            $this->project->root(),
            '-c',
            'user.name=Someone Else',
            '-c',
            'user.email=someone@example.com',
            'commit-tree',
            $empty,
            '-m',
            'Their own work',
        )->standardOutput);
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
            if (trim($line) === '' || !str_starts_with($line, 'refs/heads/ralph/')) {
                continue;
            }

            [$name, $object] = explode(' ', $line, 2);
            $refs[$name] = $object;
        }

        return $refs;
    }

    /**
     * Everything about the checkout a careless branch command would have
     * changed.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $root = $this->project->root();

        return [
            'head' => Git::run($root, 'rev-parse', 'HEAD')->standardOutput,
            'branch' => Git::run($root, 'rev-parse', '--abbrev-ref', 'HEAD')->standardOutput,
            'status' => Git::run($root, 'status', '--porcelain=v1')->standardOutput,
            'refs' => Git::run($root, 'for-each-ref', '--format=%(refname) %(objectname)')->standardOutput,
        ];
    }

    /**
     * A repository with one commit and a tracked file, and that commit's SHA.
     */
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
