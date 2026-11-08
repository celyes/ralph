<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\State\WorktreeOwnership;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Everything a pass at a task owns, and the writes it refuses.
 *
 * This is where the facts PROJECT.md section D, item 4 requires be durable
 * actually become durable, so most of what is tested is the direction each one
 * is allowed to move in. An attempt counter that only goes up, and goes up
 * before the agent is invoked, is what stops a task escaping its limit by being
 * killed at the right moment. A branch that belongs to exactly one generation
 * is what stops reopening a task destroying the commit the first pass left. A
 * commit that cannot be overwritten is what turns "the implementation ran
 * twice" into a reported error rather than a silently replaced SHA.
 *
 * The clock is frozen and advanced by hand, because the timestamps are evidence
 * and a test that could not control them could only assert that the columns
 * hold *something* — which is the assertion that would pass if the store wrote
 * the wrong one of them.
 */
#[CoversClass(GenerationStore::class)]
#[CoversClass(GenerationRecord::class)]
final class GenerationStoreTest extends TestCase
{
    private Database $database;

    private FrozenClock $clock;

    private TaskStore $tasks;

    private GenerationStore $generations;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $this->tasks = new TaskStore($this->database, $this->clock);
        $this->generations = new GenerationStore($this->database, $this->clock);
    }

    #[Test]
    public function a_new_pass_records_the_task_the_agent_and_the_base_it_branched_from(): void
    {
        $snapshot = $this->record('Add a --dry-run option');

        $generation = $this->start($snapshot);

        self::assertTrue($generation->task()->equals($snapshot->id()));
        self::assertSame(1, $generation->number());
        self::assertSame($snapshot->contentHash(), $generation->contentHash());
        self::assertSame('main', $generation->baseReference());
        self::assertSame('a1b2c3d4e5f6', $generation->baseSha());
        self::assertSame('codex', $generation->agent()->name());
        self::assertSame('codex', $generation->agent()->adapter());
    }

    /**
     * Not `running` until an attempt begins: the gap between the row existing
     * and the agent being invoked is real — preparation can fail in it — and a
     * row that claimed to be running in that gap would be reconciled as an
     * interrupted execution.
     */
    #[Test]
    public function a_new_pass_starts_pending_with_no_phase_and_no_attempts(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        self::assertSame(LifecycleState::Pending, $generation->state());
        self::assertNull($generation->phase());
        self::assertSame(0, $generation->attempts());
        self::assertTrue($generation->isOpen());
        self::assertFalse($generation->isComplete());
    }

    #[Test]
    public function a_new_pass_owns_nothing_yet(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        self::assertNull($generation->branch());
        self::assertFalse($generation->hasBranch());
        self::assertNull($generation->commitSha());
        self::assertFalse($generation->hasCommit());
        self::assertFalse($generation->isNoChange());
        self::assertFalse($generation->worktree()->exists());
        self::assertNull($generation->blocker());
        self::assertNull($generation->finishedAt());
    }

    /**
     * Publication is opt-in, so the default is a pass that owes nothing — and
     * what it does owe comes from the run's frozen configuration rather than
     * from re-reading a file.
     */
    #[Test]
    public function what_publication_requires_is_recorded_when_the_pass_starts(): void
    {
        $off = $this->start($this->record('Pushes nothing'));
        $on = $this->start($this->record('Pushes something'), push: PushResult::pending('origin'));

        self::assertSame(PushState::NotRequired, $off->push()->state());
        self::assertSame(PushState::Pending, $on->push()->state());
        self::assertSame('origin', $on->push()->remote());
    }

    /**
     * A pass at a task no reading ever saw is a mistake worth reporting as
     * one, rather than a foreign-key failure later.
     */
    #[Test]
    public function a_pass_at_an_unrecorded_task_is_refused(): void
    {
        $snapshot = $this->snapshot('Never recorded');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('has no record of task');

        $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            $this->agent(),
            'main',
            'a1b2c3d4e5f6',
        );
    }

    #[Test]
    public function a_generation_belonging_to_another_task_is_refused(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $this->record('A different task');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('is not a pass at task');

        $this->generations->start(
            TaskGeneration::first(TaskId::fromDescription('A different task')),
            $snapshot,
            $this->agent(),
            'main',
            'a1b2c3d4e5f6',
        );
    }

    #[Test]
    #[DataProvider('blankReferences')]
    public function a_base_reference_and_a_base_commit_must_name_something(
        string $reference,
        string $sha,
    ): void {
        $snapshot = $this->record('Add a --dry-run option');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('must name something');

        $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            $this->agent(),
            $reference,
            $sha,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blankReferences(): iterable
    {
        yield 'no reference' => ['  ', 'a1b2c3d4e5f6'];
        yield 'no commit' => ['main', ''];
    }

    #[Test]
    public function a_pass_is_found_by_its_generation_and_by_its_slug(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $generation = $this->start($snapshot);

        self::assertSame(
            $generation->rowId(),
            $this->generations->find(TaskGeneration::first($snapshot->id()))?->rowId(),
        );
        self::assertSame(
            $generation->rowId(),
            $this->generations->findBySlug($generation->slug())?->rowId(),
        );
    }

    #[Test]
    public function a_pass_that_was_never_recorded_is_not_found(): void
    {
        $generation = TaskGeneration::first(TaskId::fromDescription('Never recorded'));

        self::assertNull($this->generations->find($generation));
        self::assertNull($this->generations->findBySlug('TASK-00000000-g1'));
    }

    #[Test]
    public function asking_for_a_pass_that_was_never_recorded_is_refused_readably(): void
    {
        $this->expectException(StateException::class);
        $this->expectExceptionMessage('has no record of generation');

        $this->generations->get(TaskGeneration::first(TaskId::fromDescription('Never recorded')));
    }

    /**
     * The counter goes up before the agent is invoked, so an attempt that is
     * interrupted still counts.
     */
    #[Test]
    public function beginning_an_attempt_spends_it_and_starts_the_pass_running(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $running = $this->generations->beginAttempt($generation);

        self::assertSame(1, $running->attempts());
        self::assertSame(LifecycleState::Running, $running->state());
        self::assertSame(Phase::initial(), $running->phase());
    }

    #[Test]
    public function each_attempt_spends_another(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );
        $interrupted = $this->generations->interrupt($generation);

        $second = $this->generations->beginAttempt($interrupted);

        self::assertSame(2, $second->attempts());
    }

    /**
     * A new attempt starts the phase sequence again, which is the one place the
     * phase of a pass legitimately moves backwards.
     */
    #[Test]
    public function a_new_attempt_restarts_the_phase_sequence(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );
        $verifying = $this->generations->enterPhase($generation, Phase::Executing);
        $verifying = $this->generations->enterPhase($verifying, Phase::Verifying);

        $retried = $this->generations->beginAttempt($this->generations->fail($verifying));

        self::assertSame(Phase::initial(), $retried->phase());
        self::assertSame(2, $retried->attempts());
    }

    #[Test]
    public function the_attempt_counter_survives_being_read_back(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));
        $this->generations->beginAttempt($generation);

        self::assertSame(1, $this->generations->get($generation->generation())->attempts());
    }

    #[Test]
    public function attempts_are_counted_against_a_limit(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        self::assertSame(2, $generation->attemptsRemaining(3));
        self::assertFalse($generation->hasExhausted(3));
        self::assertSame(0, $generation->attemptsRemaining(1));
        self::assertTrue($generation->hasExhausted(1));
        self::assertSame(
            0,
            $generation->attemptsRemaining(0),
            'A limit below the attempts spent is reported as nothing remaining, not as a negative.',
        );
    }

    #[Test]
    public function an_already_running_pass_can_begin_another_attempt(): void
    {
        $running = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        $again = $this->generations->beginAttempt($running);

        self::assertSame(2, $again->attempts());
        self::assertSame(LifecycleState::Running, $again->state());
    }

    #[Test]
    public function a_completed_pass_cannot_begin_another_attempt(): void
    {
        $completed = $this->completed('Add a --dry-run option');

        $this->expectException(StateException::class);

        $this->generations->beginAttempt($completed);
    }

    #[Test]
    public function the_phase_moves_through_the_sequence(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        foreach ([Phase::Executing, Phase::Verifying, Phase::Committing, Phase::Finalizing] as $phase) {
            $generation = $this->generations->enterPhase($generation, $phase);

            self::assertSame($phase, $generation->phase());
        }
    }

    /**
     * The database refuses the vocabulary; the store refuses the sequence.
     */
    #[Test]
    public function the_phase_cannot_go_backwards(): void
    {
        $generation = $this->generations->enterPhase(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            Phase::Verifying,
        );

        $this->expectException(StateException::class);

        $this->generations->enterPhase($generation, Phase::Executing);
    }

    #[Test]
    public function a_pass_that_is_not_in_the_middle_of_anything_records_no_phase(): void
    {
        $completed = $this->completed('Add a --dry-run option');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('is not in the middle of anything');

        $this->generations->enterPhase($completed, Phase::Executing);
    }

    #[Test]
    public function a_pass_claims_a_branch_and_keeps_it(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $assigned = $this->generations->assignBranch($generation, 'ralph/task-19e0214a');

        self::assertSame('ralph/task-19e0214a', $assigned->branch());
        self::assertTrue($assigned->hasBranch());
        self::assertTrue($assigned->ownsBranch('ralph/task-19e0214a'));
    }

    /**
     * Git refs are case-sensitive, so a near-match is someone else's branch.
     */
    #[Test]
    public function branch_ownership_is_compared_exactly(): void
    {
        $assigned = $this->generations->assignBranch(
            $this->start($this->record('Add a --dry-run option')),
            'ralph/task-19e0214a',
        );

        self::assertFalse($assigned->ownsBranch('ralph/TASK-19E0214A'));
        self::assertFalse($assigned->ownsBranch('ralph/task-19e0214'));
    }

    /**
     * So resume does not have to remember whether it got this far.
     */
    #[Test]
    public function reclaiming_the_branch_a_pass_already_owns_does_nothing(): void
    {
        $assigned = $this->generations->assignBranch(
            $this->start($this->record('Add a --dry-run option')),
            'ralph/task-19e0214a',
        );
        $this->clock->advance(60);

        $again = $this->generations->assignBranch($assigned, 'ralph/task-19e0214a');

        self::assertSame('ralph/task-19e0214a', $again->branch());
        self::assertSame(
            $assigned->updatedAt()->value(),
            $again->updatedAt()->value(),
            'Re-claiming the same branch is not a write.',
        );
    }

    /**
     * The behaviour PROJECT.md section F, item 4 requires, and the reason
     * reopening a task cannot destroy the commit the first pass left behind.
     */
    #[Test]
    public function a_branch_another_pass_owns_is_refused_rather_than_reset(): void
    {
        $first = $this->start($this->record('First task'));
        $this->generations->assignBranch($first, 'ralph/shared');
        $second = $this->start($this->record('Second task'));

        try {
            $this->generations->assignBranch($second, 'ralph/shared');
            self::fail('A branch owned by another pass must not be taken.');
        } catch (StateException $refusal) {
            self::assertStringContainsString('already owned by another pass', $refusal->getMessage());
            self::assertStringContainsString(
                'belongs to exactly one execution generation',
                implode(' ', $refusal->hints()),
            );
        }

        self::assertSame(
            'ralph/shared',
            $this->generations->get($first->generation())->branch(),
            'The owning pass keeps its branch.',
        );
    }

    #[Test]
    public function a_branch_must_name_something(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('must name something');

        $this->generations->assignBranch($generation, '   ');
    }

    #[Test]
    public function a_workspace_is_recorded_with_who_created_it(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $owned = $this->generations->recordWorktree(
            $generation,
            WorktreeOwnership::created('/repo/.ralph/runtime/worktrees/g1'),
        );

        self::assertSame('/repo/.ralph/runtime/worktrees/g1', $owned->worktree()->path());
        self::assertTrue($owned->worktree()->isOwned());

        $adopted = $this->generations->recordWorktree(
            $owned,
            WorktreeOwnership::adopted('/repo/.ralph/runtime/worktrees/g1'),
        );

        self::assertTrue($adopted->worktree()->mustBePreserved());
    }

    #[Test]
    public function a_recorded_commit_is_read_back(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $committed = $this->generations->recordCommit($generation, 'f1e2d3c4b5a6');

        self::assertSame('f1e2d3c4b5a6', $committed->commitSha());
        self::assertTrue($committed->hasCommit());
        self::assertTrue($committed->hasImplementation());
        self::assertFalse($committed->isNoChange());
    }

    #[Test]
    public function recording_the_same_commit_twice_does_nothing(): void
    {
        $committed = $this->generations->recordCommit(
            $this->start($this->record('Add a --dry-run option')),
            'f1e2d3c4b5a6',
        );
        $this->clock->advance(60);

        $again = $this->generations->recordCommit($committed, 'f1e2d3c4b5a6');

        self::assertSame($committed->updatedAt()->value(), $again->updatedAt()->value());
    }

    /**
     * Two commits means the implementation ran twice, which resume is meant to
     * prevent — so it is reported rather than overwritten.
     */
    #[Test]
    public function a_second_different_commit_is_refused(): void
    {
        $committed = $this->generations->recordCommit(
            $this->start($this->record('Add a --dry-run option')),
            'f1e2d3c4b5a6',
        );

        try {
            $this->generations->recordCommit($committed, '0a0b0c0d0e0f');
            self::fail('A pass commits once.');
        } catch (StateException $refusal) {
            self::assertStringContainsString('already records commit f1e2d3c4b5a6', $refusal->getMessage());
        }

        self::assertSame(
            'f1e2d3c4b5a6',
            $this->generations->get($committed->generation())->commitSha(),
            'The recorded commit is kept.',
        );
    }

    #[Test]
    public function a_commit_must_name_something(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('must name something');

        $this->generations->recordCommit($generation, '');
    }

    /**
     * A complete outcome rather than a failure, and deliberately not an empty
     * commit (PROJECT.md section G, item 6).
     */
    #[Test]
    public function an_already_satisfied_task_is_recorded_as_a_no_change_result(): void
    {
        $generation = $this->start($this->record('Already satisfied'));

        $unchanged = $this->generations->recordNoChange($generation);

        self::assertTrue($unchanged->isNoChange());
        self::assertFalse($unchanged->hasCommit());
        self::assertTrue($unchanged->hasImplementation());
    }

    #[Test]
    public function a_pass_that_committed_cannot_also_claim_it_changed_nothing(): void
    {
        $committed = $this->generations->recordCommit(
            $this->start($this->record('Add a --dry-run option')),
            'f1e2d3c4b5a6',
        );

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('did not leave the task unchanged');

        $this->generations->recordNoChange($committed);
    }

    /**
     * The other direction: a pass recorded as unchanged that then commits is a
     * pass that did change something, and the commit is the stronger fact.
     */
    #[Test]
    public function a_commit_after_a_no_change_result_replaces_it(): void
    {
        $unchanged = $this->generations->recordNoChange(
            $this->start($this->record('Add a --dry-run option')),
        );

        $committed = $this->generations->recordCommit($unchanged, 'f1e2d3c4b5a6');

        self::assertSame('f1e2d3c4b5a6', $committed->commitSha());
        self::assertFalse($committed->isNoChange());
    }

    #[Test]
    public function where_publication_stands_is_recorded(): void
    {
        $generation = $this->start(
            $this->record('Add a --dry-run option'),
            push: PushResult::pending('origin'),
        );

        $published = $this->generations->recordPush($generation, PushResult::published('origin'));

        self::assertSame(PushState::Published, $published->push()->state());
        self::assertSame('origin', $published->push()->remote());
        self::assertNull($published->push()->error());
    }

    #[Test]
    public function a_failed_push_keeps_its_reason(): void
    {
        $generation = $this->start(
            $this->record('Add a --dry-run option'),
            push: PushResult::pending('origin'),
        );

        $failed = $this->generations->recordPush(
            $generation,
            PushResult::failed('origin', '! [rejected] (non-fast-forward)'),
        );

        self::assertTrue($failed->push()->hasFailed());
        self::assertStringContainsString('non-fast-forward', (string) $failed->push()->error());
    }

    /**
     * The state resume reads to retry only the publication, without repeating
     * the implementation or the commit.
     */
    #[Test]
    public function a_committed_pass_with_a_failed_push_awaits_publication_and_nothing_else(): void
    {
        $generation = $this->generations->recordCommit(
            $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
            'f1e2d3c4b5a6',
        );

        $failed = $this->generations->recordPush(
            $generation,
            PushResult::failed('origin', 'rejected'),
        );

        self::assertTrue($failed->awaitsPublication());
        self::assertFalse($failed->isFinalizable());
        self::assertTrue($failed->hasImplementation());
    }

    #[Test]
    public function a_pass_with_nothing_outstanding_is_finalizable(): void
    {
        $generation = $this->generations->recordCommit(
            $this->start($this->record('Add a --dry-run option')),
            'f1e2d3c4b5a6',
        );

        self::assertTrue($generation->isFinalizable());
        self::assertFalse($generation->awaitsPublication());
    }

    #[Test]
    public function a_pass_with_no_implementation_is_not_finalizable(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        self::assertFalse($generation->isFinalizable());
        self::assertFalse($generation->awaitsPublication());
    }

    /**
     * The way back into a pass that owes nothing but the write to the user's
     * checklist (PROJECT.md section G, item 8).
     *
     * The attempt counter does not move, which is the point: everything an
     * attempt produces is already recorded, and counting one would let a
     * read-only file eventually exhaust the budget of work that has passed.
     */
    #[Test]
    public function a_blocked_pass_with_its_work_recorded_resumes_at_finalizing(): void
    {
        $blocked = $this->generations->block(
            $this->generations->recordCommit(
                $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
                'f1e2d3c4b5a6',
            ),
            Blocker::raised('its box could not be ticked', 'Make the checklist writable.'),
        );
        self::assertSame(1, $blocked->attempts());

        $resumed = $this->generations->resumeFinalizing($blocked);

        self::assertSame(LifecycleState::Running, $resumed->state());
        self::assertSame(Phase::Finalizing, $resumed->phase());
        self::assertSame(1, $resumed->attempts(), 'The write is not an attempt at the task.');
        self::assertSame('f1e2d3c4b5a6', $resumed->commitSha());

        // And from there the pass can be completed, which is the whole journey
        // a refused checkbox write has left to make.
        self::assertTrue($this->generations->complete($resumed)->isComplete());
    }

    /**
     * A pass interrupted before its work was recorded has work to redo, and
     * resuming it at the last phase would move a row into a state its history
     * does not support.
     */
    #[Test]
    public function a_pass_with_nothing_recorded_cannot_resume_at_finalizing(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        try {
            $this->generations->resumeFinalizing($generation);
            self::fail('A pass with nothing to show cannot be finalized.');
        } catch (StateException $refused) {
            self::assertStringContainsString('has not reached finalization', $refused->getMessage());
            self::assertStringContainsString('no-change result', $refused->getMessage());
        }
    }

    #[Test]
    public function a_pass_whose_push_is_outstanding_cannot_resume_at_finalizing(): void
    {
        $generation = $this->generations->recordCommit(
            $this->generations->beginAttempt(
                $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
            ),
            'f1e2d3c4b5a6',
        );

        try {
            $this->generations->resumeFinalizing($generation);
            self::fail('A pass that still owes a push is not pending its checklist write.');
        } catch (StateException $refused) {
            self::assertStringContainsString('has not reached finalization', $refused->getMessage());
            self::assertStringContainsString('push pending', $refused->getMessage());
        }
    }

    /**
     * The way back into a pass that owes nothing but its push (PROJECT.md
     * section H, item 7).
     *
     * The attempt counter does not move here either, and for the same reason it
     * does not move for a refused checkbox write: a remote that is down has
     * nothing to do with how many tries the task itself deserves.
     */
    #[Test]
    public function a_failed_pass_whose_only_debt_is_the_push_resumes_at_publishing(): void
    {
        $failed = $this->generations->fail(
            $this->generations->recordPush(
                $this->generations->recordCommit(
                    $this->generations->beginAttempt(
                        $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
                    ),
                    'f1e2d3c4b5a6',
                ),
                PushResult::failed('origin', 'fatal: "origin" does not appear to be a Git repository'),
            ),
            Blocker::raised('the push to origin was refused', 'Resolve the remote, then resume.'),
        );
        self::assertSame(1, $failed->attempts());

        $resumed = $this->generations->resumePublishing($failed);

        self::assertSame(LifecycleState::Running, $resumed->state());
        self::assertSame(Phase::Publishing, $resumed->phase());
        self::assertSame(1, $resumed->attempts(), 'A push is not an attempt at the task.');
        self::assertSame('f1e2d3c4b5a6', $resumed->commitSha(), 'The implementation is kept.');
        self::assertTrue($resumed->awaitsPublication(), 'The push is still the thing that is owed.');

        // The reason it stopped was the thing now being done again, so it is
        // not left on the row for `ralph status` to report against a task that
        // goes on to publish.
        self::assertNull($resumed->blocker());
    }

    /**
     * An interrupted pass reaches the same place: a stop between the commit and
     * the push leaves the publication owed, not the implementation.
     */
    #[Test]
    public function an_interrupted_pass_that_had_committed_resumes_at_publishing(): void
    {
        $interrupted = $this->generations->interrupt(
            $this->generations->recordCommit(
                $this->generations->beginAttempt(
                    $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
                ),
                'f1e2d3c4b5a6',
            ),
        );

        $resumed = $this->generations->resumePublishing($interrupted);

        self::assertSame(LifecycleState::Running, $resumed->state());
        self::assertSame(Phase::Publishing, $resumed->phase());
        self::assertSame(1, $resumed->attempts());
    }

    #[Test]
    public function a_pass_with_nothing_recorded_cannot_resume_at_publishing(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
        );

        try {
            $this->generations->resumePublishing($generation);
            self::fail('A pass with nothing committed has work to redo, not a push to retry.');
        } catch (StateException $refused) {
            self::assertStringContainsString('is not waiting to be published', $refused->getMessage());
            self::assertStringContainsString('no-change result', $refused->getMessage());
        }
    }

    /**
     * A push that already succeeded is not retried: that pass owes the
     * checklist write, and pushing again would report an obligation it has
     * discharged.
     */
    #[Test]
    public function a_pass_whose_push_is_settled_cannot_resume_at_publishing(): void
    {
        $published = $this->generations->recordPush(
            $this->generations->recordCommit(
                $this->generations->beginAttempt(
                    $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
                ),
                'f1e2d3c4b5a6',
            ),
            PushResult::published('origin'),
        );

        try {
            $this->generations->resumePublishing($published);
            self::fail('A published pass does not owe a push.');
        } catch (StateException $refused) {
            self::assertStringContainsString('is not waiting to be published', $refused->getMessage());
            self::assertStringContainsString('pushed "origin"', $refused->getMessage());
        }

        // The other resumption is the one it does qualify for.
        self::assertSame(
            Phase::Finalizing,
            $this->generations->resumeFinalizing($published)->phase(),
        );
    }

    #[Test]
    public function completing_a_pass_records_it_as_complete(): void
    {
        $generation = $this->generations->recordCommit(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            'f1e2d3c4b5a6',
        );

        $completed = $this->generations->complete($generation);

        self::assertSame(LifecycleState::Completed, $completed->state());
        self::assertTrue($completed->isComplete());
        self::assertFalse($completed->isOpen());
        self::assertNull($completed->phase(), 'A finished pass is not in the middle of anything.');
    }

    /**
     * A store that let this slide would make the checked box the only place the
     * lie showed up.
     */
    #[Test]
    public function a_pass_with_no_implementation_cannot_be_completed(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        try {
            $this->generations->complete($generation);
            self::fail('A pass with nothing to show cannot be completed.');
        } catch (StateException $refusal) {
            self::assertStringContainsString(
                'neither a commit nor a recorded no-change result',
                $refusal->getMessage(),
            );
        }
    }

    #[Test]
    public function a_pass_whose_push_is_outstanding_cannot_be_completed(): void
    {
        $generation = $this->generations->recordCommit(
            $this->generations->beginAttempt(
                $this->start($this->record('Add a --dry-run option'), push: PushResult::pending('origin')),
            ),
            'f1e2d3c4b5a6',
        );

        try {
            $this->generations->complete($generation);
            self::fail('A pass that owes a push cannot be completed.');
        } catch (StateException $refusal) {
            self::assertStringContainsString('cannot be completed while', $refusal->getMessage());
            self::assertStringContainsString(
                'resume retries the publication',
                implode(' ', $refusal->hints()),
            );
        }
    }

    #[Test]
    public function a_no_change_pass_can_be_completed(): void
    {
        $generation = $this->generations->recordNoChange(
            $this->generations->beginAttempt($this->start($this->record('Already satisfied'))),
        );

        self::assertTrue($this->generations->complete($generation)->isComplete());
    }

    #[Test]
    public function blocking_a_pass_records_the_reason_and_the_next_action(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );
        $blocker = Blocker::from('codex', 'It needs a decision.', 'Decide and retry.');

        $blocked = $this->generations->block($generation, $blocker);

        self::assertSame(LifecycleState::Blocked, $blocked->state());
        self::assertNotNull($blocked->blocker());
        self::assertTrue($blocked->blocker()->equals($blocker));
        self::assertSame('codex', $blocked->blocker()->reportedBy());
    }

    #[Test]
    public function failing_a_pass_can_record_why(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        $failed = $this->generations->fail(
            $generation,
            Blocker::raised('The checks never passed.', 'Read the task log.'),
        );

        self::assertSame(LifecycleState::Failed, $failed->state());
        self::assertSame('The checks never passed.', $failed->blocker()?->reason());
    }

    #[Test]
    public function failing_a_pass_without_a_reason_records_no_blocker(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        self::assertNull($this->generations->fail($generation)->blocker());
    }

    /**
     * The phase is left exactly as it was: it is the only thing that tells
     * resume whether to invoke the agent again or to retry a push.
     */
    #[Test]
    public function interrupting_a_pass_keeps_the_phase_it_was_in(): void
    {
        $generation = $this->generations->enterPhase(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            Phase::Executing,
        );

        $interrupted = $this->generations->interrupt($generation);

        self::assertSame(LifecycleState::Interrupted, $interrupted->state());
        self::assertSame(Phase::Executing, $interrupted->phase());
    }

    #[Test]
    public function skipping_a_pass_before_it_starts_is_allowed(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $skipped = $this->generations->skip($generation);

        self::assertSame(LifecycleState::Skipped, $skipped->state());
        self::assertFalse($skipped->isOpen());
    }

    /**
     * Skipping is a decision taken before the work begins, so the lifecycle
     * refuses it afterwards.
     */
    #[Test]
    public function a_pass_that_has_already_started_cannot_be_skipped(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        $this->expectException(StateException::class);

        $this->generations->skip($generation);
    }

    #[Test]
    public function started_at_is_written_once_and_updated_moves_with_every_write(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));
        $this->clock->advance(600);

        $running = $this->generations->beginAttempt($generation);

        self::assertSame('2026-11-01T09:30:00+00:00', $running->startedAt()->value());
        self::assertSame('2026-11-01T09:40:00+00:00', $running->updatedAt()->value());
    }

    #[Test]
    public function finished_at_is_written_when_the_pass_reaches_a_state_it_does_not_come_back_from(): void
    {
        $generation = $this->generations->recordCommit(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            'f1e2d3c4b5a6',
        );
        $this->clock->advance(600);

        $completed = $this->generations->complete($generation);

        self::assertSame('2026-11-01T09:40:00+00:00', $completed->finishedAt()?->value());
    }

    #[Test]
    public function an_open_pass_has_not_finished(): void
    {
        $running = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );

        self::assertNull($running->finishedAt());
    }

    /**
     * A blocked pass has not finished: blocking is a stop that a person can
     * clear, and the lifecycle lets it run again, so it stays open and records
     * no finish time. The finish time is written when the pass reaches a state
     * it genuinely does not come back from.
     */
    #[Test]
    public function a_blocked_pass_has_not_finished_and_the_retry_that_completes_it_sets_the_time(): void
    {
        $generation = $this->generations->beginAttempt(
            $this->start($this->record('Add a --dry-run option')),
        );
        $blocked = $this->generations->block(
            $generation,
            Blocker::raised('It needs a decision.', 'Decide and retry.'),
        );

        self::assertTrue($blocked->isOpen());
        self::assertNull($blocked->finishedAt());

        $this->clock->advance(3600);
        $completed = $this->generations->complete(
            $this->generations->recordCommit(
                $this->generations->beginAttempt($blocked),
                'f1e2d3c4b5a6',
            ),
        );

        self::assertSame('2026-11-01T10:30:00+00:00', $completed->finishedAt()?->value());
    }

    /**
     * The blocker is kept when the pass runs again, because it is the record of
     * why the pass once stopped — and a later write does not invent a new one.
     */
    #[Test]
    public function retrying_a_blocked_pass_keeps_the_blocker_that_stopped_it(): void
    {
        $blocked = $this->generations->block(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            Blocker::raised('It needs a decision.', 'Decide and retry.'),
        );

        $retried = $this->generations->beginAttempt($blocked);

        self::assertSame('It needs a decision.', $retried->blocker()?->reason());
        self::assertSame(LifecycleState::Running, $retried->state());
    }

    #[Test]
    public function what_the_task_said_can_be_refreshed_for_a_pass_being_continued(): void
    {
        $snapshot = $this->record('Add a --dry-run option', ['- It must not write.']);
        $generation = $this->start($snapshot);
        $edited = $this->record('Add a --dry-run option', ['- It must not write.', '- And say so.']);

        $refreshed = $this->generations->refreshContent($generation, $edited);

        self::assertSame($edited->contentHash(), $refreshed->contentHash());
        self::assertTrue($refreshed->statesSameWork($edited));
        self::assertFalse($refreshed->statesSameWork($snapshot));
    }

    #[Test]
    public function refreshing_from_a_different_task_is_refused(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));
        $other = $this->record('A different task');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('is not a pass at task');

        $this->generations->refreshContent($generation, $other);
    }

    #[Test]
    public function the_run_working_on_a_pass_is_recorded(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));
        $runId = $this->insertRun();

        $assigned = $this->generations->assignRun($generation, $runId);

        self::assertSame($runId, $assigned->runId());
        self::assertNull($this->generations->assignRun($assigned, null)->runId());
    }

    #[Test]
    public function every_pass_at_a_task_is_listed_oldest_first(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $first = $this->start($snapshot);
        $this->generations->skip($first);
        $this->start($snapshot, TaskGeneration::of($snapshot->id(), 2));

        self::assertSame(
            [1, 2],
            array_map(
                static fn (GenerationRecord $record): int => $record->number(),
                $this->generations->forTask($snapshot->id()),
            ),
        );
        self::assertSame(2, $this->generations->latestFor($snapshot->id())?->number());
    }

    #[Test]
    public function a_task_with_no_passes_has_no_latest_one(): void
    {
        $snapshot = $this->record('Add a --dry-run option');

        self::assertSame([], $this->generations->forTask($snapshot->id()));
        self::assertNull($this->generations->latestFor($snapshot->id()));
        self::assertNull($this->generations->openFor($snapshot->id()));
    }

    /**
     * At most one exists, so a run never has to choose which open pass is the
     * current one.
     */
    #[Test]
    public function the_one_pass_that_can_be_continued_is_found(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $first = $this->start($snapshot);
        $this->generations->skip($first);
        $second = $this->start($snapshot, TaskGeneration::of($snapshot->id(), 2));

        self::assertSame($second->rowId(), $this->generations->openFor($snapshot->id())?->rowId());
    }

    #[Test]
    public function a_task_whose_passes_are_all_finished_has_none_open(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $this->generations->skip($this->start($snapshot));

        self::assertNull($this->generations->openFor($snapshot->id()));
    }

    /**
     * The database invariant, reported in terms of what a user can do about it
     * rather than as a bare constraint message naming an index.
     */
    #[Test]
    public function a_task_cannot_have_two_passes_that_can_both_be_continued(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $this->start($snapshot);

        try {
            $this->start($snapshot, TaskGeneration::of($snapshot->id(), 2));
            self::fail('A task has at most one pass that can be continued.');
        } catch (StateException $refusal) {
            self::assertStringContainsString(
                'already has a pass that can be continued',
                $refusal->getMessage(),
            );
            self::assertStringContainsString('ralph status', implode(' ', $refusal->hints()));
        }
    }

    #[Test]
    public function the_same_pass_cannot_be_started_twice(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $generation = $this->start($snapshot);
        $this->generations->skip($generation);

        try {
            $this->start($snapshot, TaskGeneration::first($snapshot->id()));
            self::fail('A recorded pass cannot be opened a second time.');
        } catch (StateException $refusal) {
            self::assertStringContainsString('is already recorded', $refusal->getMessage());
        }
    }

    /**
     * Reopening finished work starts a new pass with its own branch, and the
     * first pass keeps the one it earned.
     */
    #[Test]
    public function reopening_a_completed_task_starts_a_new_pass_with_its_own_branch(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $first = $this->completed('Add a --dry-run option', branch: 'ralph/g1');

        $second = $this->start($snapshot, TaskGeneration::of($snapshot->id(), 2));
        $second = $this->generations->assignBranch($second, 'ralph/g2');

        self::assertSame(2, $second->number());
        self::assertSame('ralph/g2', $second->branch());
        self::assertSame('ralph/g1', $this->generations->get($first->generation())->branch());
        self::assertSame('f1e2d3c4b5a6', $this->generations->get($first->generation())->commitSha());
    }

    #[Test]
    public function the_branches_already_owned_are_listed(): void
    {
        $first = $this->start($this->record('First task'));
        $this->generations->assignBranch($first, 'ralph/first');
        $second = $this->start($this->record('Second task'));
        $this->generations->assignBranch($second, 'ralph/second');
        $this->start($this->record('Third task'));

        self::assertSame(['ralph/first', 'ralph/second'], $this->generations->reservedBranches());
    }

    #[Test]
    public function no_branches_are_reserved_in_a_fresh_database(): void
    {
        self::assertSame([], $this->generations->reservedBranches());
    }

    /**
     * What the checklist layer reads to decide whether reopening a task
     * continues an open pass or starts a new one.
     */
    #[Test]
    public function the_history_carries_what_the_checklist_layer_asks_for(): void
    {
        $open = $this->record('Still running');
        $this->generations->assignBranch($this->start($open), 'ralph/open');
        $done = $this->record('Already finished');
        $this->completed('Already finished', branch: 'ralph/done');

        $history = $this->generations->history();

        self::assertTrue($history->hasOpen($open->id()));
        self::assertFalse($history->isComplete($open->id()));
        self::assertTrue($history->isComplete($done->id()));
        self::assertFalse($history->hasOpen($done->id()));
        self::assertSame(['ralph/open'], $history->branchesOf($open->id()));
        self::assertSame('f1e2d3c4b5a6', $history->latestOf($done->id())?->commit());
    }

    #[Test]
    public function the_history_of_a_fresh_database_is_empty(): void
    {
        self::assertTrue($this->generations->history()->isEmpty());
    }

    /**
     * A completed pass carries its commit into the history; an open one has
     * nothing to carry yet.
     */
    #[Test]
    public function only_a_finished_pass_reports_a_commit_to_the_checklist_layer(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $open = $this->generations->recordCommit($this->start($snapshot), 'f1e2d3c4b5a6');

        $record = $open->toRecordedGeneration();

        self::assertTrue($record->isOpen());
        self::assertNull(
            $record->commit(),
            'An open pass has not finished, so its commit is not yet a finished fact.',
        );
        self::assertSame($snapshot->contentHash(), $record->contentHash());
    }

    #[Test]
    public function a_pass_describes_itself_for_a_status_table(): void
    {
        $generation = $this->generations->assignBranch(
            $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
            'ralph/task',
        );

        $described = $generation->describe();

        self::assertStringContainsString('running', $described);
        self::assertStringContainsString('preparing', $described);
        self::assertStringContainsString('attempt 1', $described);
        self::assertStringContainsString('ralph/task', $described);
    }

    #[Test]
    public function a_pass_with_no_branch_says_so(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        self::assertStringContainsString('no branch yet', $generation->describe());
    }

    /**
     * A row deleted under a caller holding its record is reported rather than
     * silently updating nothing.
     */
    #[Test]
    public function writing_to_a_pass_that_is_gone_is_refused(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));
        $this->database->execute('DELETE FROM generations WHERE id = ?', [$generation->rowId()]);

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('no longer in the state database');

        $this->generations->beginAttempt($generation);
    }

    /**
     * The agent is recorded on the pass, not only on the run, because resume
     * must continue with the agent the work was started under even if the
     * project's default has changed since.
     */
    #[Test]
    public function the_agent_a_pass_was_started_with_is_read_back_unchanged(): void
    {
        $agent = AgentRecord::ofDefinition(new AgentDefinition(
            'careful',
            'claude',
            '/usr/local/bin/claude',
            'opus',
            ['--no-color'],
            ['ANTHROPIC_API_KEY'],
        ));
        $snapshot = $this->record('Add a --dry-run option');
        $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            $agent,
            'main',
            'a1b2c3d4e5f6',
        );

        $read = $this->generations->get(TaskGeneration::first($snapshot->id()))->agent();

        self::assertTrue($read->isSameAgent($agent));
        self::assertSame('/usr/local/bin/claude', $read->executable());
        self::assertSame('opus', $read->model());
        self::assertSame($agent->settings(), $read->settings());
    }

    /**
     * The base is recorded rather than re-resolved, which is the whole point:
     * the base moving on under a running task must not change what the task was
     * built on.
     */
    #[Test]
    public function the_base_commit_a_pass_branched_from_does_not_move(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $generation = $this->start($snapshot);

        $this->generations->beginAttempt($generation);
        $this->generations->assignBranch($generation, 'ralph/task');

        $read = $this->generations->get($generation->generation());

        self::assertSame('a1b2c3d4e5f6', $read->baseSha());
        self::assertSame('main', $read->baseReference());
    }

    /**
     * @param list<string> $details
     */
    private function record(string $description, array $details = []): TaskSnapshot
    {
        $snapshot = $this->snapshot($description, $details);
        $this->tasks->record($snapshot);

        return $snapshot;
    }

    /**
     * @param list<string> $details
     */
    private function snapshot(string $description, array $details = []): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            $details,
        )));
    }

    private function start(
        TaskSnapshot $snapshot,
        ?TaskGeneration $generation = null,
        ?PushResult $push = null,
    ): GenerationRecord {
        return $this->generations->start(
            $generation ?? TaskGeneration::first($snapshot->id()),
            $snapshot,
            $this->agent(),
            'main',
            'a1b2c3d4e5f6',
            $push,
        );
    }

    /**
     * A pass carried all the way through, for the tests about what happens
     * after one.
     */
    private function completed(string $description, ?string $branch = null): GenerationRecord
    {
        $snapshot = $this->tasks->has(TaskId::fromDescription($description))
            ? $this->snapshot($description)
            : $this->record($description);
        $generation = $this->generations->beginAttempt($this->start($snapshot));

        if ($branch !== null) {
            $generation = $this->generations->assignBranch($generation, $branch);
        }

        return $this->generations->complete(
            $this->generations->recordCommit($generation, 'f1e2d3c4b5a6'),
        );
    }

    private function agent(): AgentRecord
    {
        return AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'));
    }

    /**
     * A run row, for the one column that references one.
     */
    private function insertRun(): int
    {
        $agent = $this->agent()->toColumns();

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
                'main',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
