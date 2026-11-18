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
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
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
    public function a_new_pass_records_the_task_and_the_agent(): void
    {
        $snapshot = $this->record('Add a --dry-run option');

        $generation = $this->start($snapshot);

        self::assertTrue($generation->task()->equals($snapshot->id()));
        self::assertSame(1, $generation->number());
        self::assertSame($snapshot->contentHash(), $generation->contentHash());
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

        $this->generations->start(TaskGeneration::first($snapshot->id()), $snapshot, $this->agent());
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
    public function a_project_with_no_passes_reads_back_as_no_passes(): void
    {
        self::assertSame([], $this->generations->all());
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

        foreach ([Phase::Executing, Phase::Verifying, Phase::Finalizing] as $phase) {
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

    /**
     * The one fact the filesystem cannot supply afterwards: that a log is
     * missing because the package deleted it on purpose (PROJECT.md section K,
     * item 11). Written after the deletion, and only the first one counts — a
     * second wipe deleted nothing, so moving the timestamp would date the
     * removal to a run that did not make it.
     */
    #[Test]
    public function a_wiped_log_is_dated_once_and_leaves_the_rest_of_the_row_alone(): void
    {
        $completed = $this->completed('Add a --dry-run option');

        self::assertNull($completed->logsWipedAt());
        self::assertFalse($completed->logsWereWiped());

        $wiped = $this->generations->recordLogWipe($completed);

        self::assertTrue($wiped->logsWereWiped());
        self::assertSame($this->clock->now()->format(DATE_ATOM), $wiped->logsWipedAt()?->value());

        // Everything the pass is reviewed from outlives its logs.
        self::assertSame(LifecycleState::Completed, $wiped->state());

        $this->clock->advance(3600);

        $again = $this->generations->recordLogWipe($wiped);

        self::assertSame($wiped->logsWipedAt()->value(), $again->logsWipedAt()?->value());
        self::assertSame($wiped->updatedAt()->value(), $again->updatedAt()->value());
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
    public function a_blocked_pass_whose_checks_passed_resumes_at_finalizing(): void
    {
        $blocked = $this->generations->block(
            $this->generations->enterPhase(
                $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option'))),
                Phase::Verifying,
            ),
            Blocker::raised('its box could not be ticked', 'Make the checklist writable.'),
        );
        self::assertSame(1, $blocked->attempts());

        $resumed = $this->generations->resumeFinalizing($blocked);

        self::assertSame(LifecycleState::Running, $resumed->state());
        self::assertSame(Phase::Finalizing, $resumed->phase());
        self::assertSame(1, $resumed->attempts(), 'The write is not an attempt at the task.');
        self::assertNull($resumed->blocker(), 'The reason it stopped is the thing being done again.');

        // And from there the pass can be completed, which is the whole journey
        // a refused checkbox write has left to make.
        self::assertTrue($this->generations->complete($resumed)->isComplete());
    }

    #[Test]
    public function completing_a_pass_records_it_as_complete(): void
    {
        $generation = $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option')));

        $completed = $this->generations->complete($generation);

        self::assertSame(LifecycleState::Completed, $completed->state());
        self::assertTrue($completed->isComplete());
        self::assertFalse($completed->isOpen());
        self::assertNull($completed->phase(), 'A finished pass is not in the middle of anything.');
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
        $generation = $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option')));
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
            $this->generations->beginAttempt($blocked),
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
     * Reopening finished work starts a new pass, and the finished one keeps
     * everything it recorded.
     */
    #[Test]
    public function reopening_a_completed_task_starts_a_new_pass_of_its_own(): void
    {
        $snapshot = $this->record('Add a --dry-run option');
        $first = $this->completed('Add a --dry-run option');

        $second = $this->start($snapshot, TaskGeneration::of($snapshot->id(), 2));

        self::assertSame(2, $second->number());
        self::assertSame(LifecycleState::Pending, $second->state());
        self::assertSame(0, $second->attempts(), 'A reopened task starts its attempts again.');

        $kept = $this->generations->get($first->generation());
        self::assertSame(LifecycleState::Completed, $kept->state());
        self::assertSame(1, $kept->attempts(), 'The finished pass keeps the attempt it spent.');
    }

    /**
     * What the checklist layer reads to decide whether reopening a task
     * continues an open pass or starts a new one.
     */
    #[Test]
    public function the_history_carries_what_the_checklist_layer_asks_for(): void
    {
        $open = $this->record('Still running');
        $this->start($open);
        $done = $this->record('Already finished');
        $this->completed('Already finished');

        $history = $this->generations->history();

        self::assertTrue($history->hasOpen($open->id()));
        self::assertFalse($history->isComplete($open->id()));
        self::assertTrue($history->isComplete($done->id()));
        self::assertFalse($history->hasOpen($done->id()));
        self::assertTrue($history->latestOf($done->id())?->isComplete());
    }

    #[Test]
    public function the_history_of_a_fresh_database_is_empty(): void
    {
        self::assertTrue($this->generations->history()->isEmpty());
    }

    #[Test]
    public function a_pass_describes_itself_for_a_status_table(): void
    {
        $generation = $this->generations->beginAttempt($this->start($this->record('Add a --dry-run option')));

        $described = $generation->describe();

        self::assertStringContainsString('running', $described);
        self::assertStringContainsString('preparing', $described);
        self::assertStringContainsString('attempt 1', $described);
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
        $this->generations->start(TaskGeneration::first($snapshot->id()), $snapshot, $agent);

        $read = $this->generations->get(TaskGeneration::first($snapshot->id()))->agent();

        self::assertTrue($read->isSameAgent($agent));
        self::assertSame('/usr/local/bin/claude', $read->executable());
        self::assertSame('opus', $read->model());
        self::assertSame($agent->settings(), $read->settings());
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
        ?int $runId = null,
    ): GenerationRecord {
        return $this->generations->start(
            $generation ?? TaskGeneration::first($snapshot->id()),
            $snapshot,
            $this->agent(),
            $runId,
        );
    }

    /**
     * A pass carried all the way through, for the tests about what happens
     * after one.
     */
    private function completed(string $description): GenerationRecord
    {
        $snapshot = $this->tasks->has(TaskId::fromDescription($description))
            ? $this->snapshot($description)
            : $this->record($description);

        return $this->generations->complete(
            $this->generations->beginAttempt($this->start($snapshot)),
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
                  checks, tasks_path, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
