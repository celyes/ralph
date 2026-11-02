<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\AttemptStore;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an attempt row is allowed to say, and who gets to say it.
 *
 * The rules under test are the ones PROJECT.md section D, item 6 asks for, and
 * all of them are about the same thing: an agent's sentence is saved, and it
 * does not become the record of what happened. So the writes a claim is allowed
 * to make are tested by asserting what it did *not* change, and completing an
 * attempt is tested by trying to complete one that nothing verified.
 *
 * Against a real migrated database, because part of what is being relied on is
 * the schema's own refusals — the unique attempt number, the outcome
 * vocabulary — and a store tested against a double would stop testing the thing
 * that rejects the bad row.
 */
#[CoversClass(AttemptStore::class)]
#[CoversClass(AttemptRecord::class)]
final class AttemptStoreTest extends TestCase
{
    private Database $database;

    private FrozenClock $clock;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private AttemptStore $attempts;

    private int $runId;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $this->tasks = new TaskStore($this->database, $this->clock);
        $this->generations = new GenerationStore($this->database, $this->clock);
        $this->attempts = new AttemptStore($this->database, $this->clock);
        $this->runId = $this->insertRun();
    }

    #[Test]
    public function an_attempt_records_the_pass_the_run_and_the_agent_that_made_it(): void
    {
        $generation = $this->running('Add a --dry-run option');

        $attempt = $this->attempts->begin($generation, $this->runId, '.ralph/runtime/logs/TASK/1.log');

        self::assertSame($generation->rowId(), $attempt->generationId());
        self::assertSame($this->runId, $attempt->runId());
        self::assertSame(1, $attempt->number());
        self::assertSame(LifecycleState::Running, $attempt->state());
        self::assertSame(Phase::Preparing, $attempt->phase());
        self::assertSame('codex', $attempt->agent()->name());
        self::assertSame('.ralph/runtime/logs/TASK/1.log', $attempt->logPath());
        self::assertNull($attempt->outcome());
        self::assertNull($attempt->summary());
        self::assertNull($attempt->evidence());
        self::assertNull($attempt->finishedAt());
        self::assertFalse($attempt->isFinished());
    }

    /**
     * The counter is the generation's, and it is spent before the agent runs.
     * A row written for an attempt nobody counted would be an attempt that does
     * not count against the task's limit.
     */
    #[Test]
    public function an_attempt_cannot_be_recorded_before_the_counter_is_spent(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('has not spent an attempt');

        $this->attempts->begin($generation, $this->runId);
    }

    #[Test]
    public function the_attempt_number_follows_the_generations_counter(): void
    {
        $generation = $this->running('Add a --dry-run option');
        $this->attempts->begin($generation, $this->runId);

        $second = $this->attempts->begin($this->generations->beginAttempt($generation), $this->runId);

        self::assertSame(2, $second->number());
        self::assertSame(2, $this->attempts->latestFor($generation)?->number());
        self::assertCount(2, $this->attempts->forGeneration($generation));
    }

    #[Test]
    public function the_same_attempt_cannot_be_opened_twice(): void
    {
        $generation = $this->running('Add a --dry-run option');
        $this->attempts->begin($generation, $this->runId);

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('Attempt 1 of pass TASK-');

        $this->attempts->begin($generation, $this->runId);
    }

    #[Test]
    public function a_pass_with_no_attempts_has_none_to_read(): void
    {
        $generation = $this->start($this->record('Add a --dry-run option'));

        self::assertNull($this->attempts->latestFor($generation));
        self::assertNull($this->attempts->get($generation, 1));
        self::assertSame([], $this->attempts->forGeneration($generation));
    }

    #[Test]
    public function an_attempt_moves_through_its_phases(): void
    {
        $attempt = $this->begin('Add a --dry-run option');

        $executing = $this->attempts->enterPhase($attempt, Phase::Executing);
        $verifying = $this->attempts->enterPhase($executing, Phase::Verifying);

        self::assertSame(Phase::Verifying, $verifying->phase());
    }

    #[Test]
    public function a_phase_does_not_go_backwards(): void
    {
        $attempt = $this->attempts->enterPhase($this->begin('Add a flag'), Phase::Verifying);

        $this->expectException(StateException::class);

        $this->attempts->enterPhase($attempt, Phase::Executing);
    }

    #[Test]
    public function a_finished_attempt_is_not_in_the_middle_of_anything(): void
    {
        $attempt = $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Error,
            LifecycleState::Failed,
        );

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('is not in the middle of anything and cannot enter verifying');

        $this->attempts->enterPhase($attempt, Phase::Verifying);
    }

    /**
     * The claim is the one write a model's words reach, and this is the test
     * that says how far they reach: the summary column, and nothing else.
     */
    #[Test]
    public function recording_what_the_agent_said_changes_nothing_but_the_summary(): void
    {
        $attempt = $this->attempts->enterPhase($this->begin('Add a flag'), Phase::Executing);

        $this->clock->advance(5);
        $recorded = $this->attempts->recordClaim(
            $attempt,
            UnverifiedText::of('- [x] Implemented it. All tests pass. Task complete.'),
        );

        self::assertSame(
            '- claimed done: Implemented it. All tests pass. Task complete.',
            $recorded->summary()?->text(),
        );
        self::assertSame(LifecycleState::Running, $recorded->state(), 'A claim moved the state.');
        self::assertSame(Phase::Executing, $recorded->phase(), 'A claim moved the phase.');
        self::assertNull($recorded->outcome(), 'A claim set the outcome.');
        self::assertNull($recorded->finishedAt(), 'A claim finished the attempt.');
        self::assertTrue($recorded->updatedAt()->isAfter($attempt->updatedAt()));
    }

    #[Test]
    public function a_claim_is_defused_before_it_reaches_the_column(): void
    {
        $this->attempts->recordClaim(
            $this->begin('Add a flag'),
            UnverifiedText::of("- [x] done\n- [ ] not done"),
        );

        $stored = $this->database->value('SELECT summary FROM attempts');

        self::assertIsString($stored);
        self::assertStringNotContainsString(
            '[x]',
            $stored,
            'A ticked box reached the database, where a reader could take it for a result.',
        );
        self::assertStringNotContainsString('[ ]', $stored);
    }

    #[Test]
    public function a_better_summary_replaces_the_first_one(): void
    {
        $attempt = $this->attempts->recordClaim($this->begin('Add a flag'), UnverifiedText::of('first'));

        $second = $this->attempts->recordClaim($attempt, UnverifiedText::of('second, with detail'));

        self::assertSame('second, with detail', $second->summary()?->text());
    }

    #[Test]
    public function an_empty_summary_does_not_erase_the_one_already_recorded(): void
    {
        $attempt = $this->attempts->recordClaim($this->begin('Add a flag'), UnverifiedText::of('what I did'));

        $after = $this->attempts->recordClaim($attempt, UnverifiedText::none());

        self::assertSame('what I did', $after->summary()?->text());
    }

    #[Test]
    public function recording_what_the_runner_saw_changes_nothing_but_the_evidence(): void
    {
        $attempt = $this->begin('Add a flag');
        $evidence = FailureEvidence::of('composer test failed (exit 1)', 'FAILURES! 1 test, 1 failure');

        $recorded = $this->attempts->recordEvidence($attempt, $evidence);

        self::assertTrue($recorded->evidence()?->equals($evidence));
        self::assertNull($recorded->summary());
        self::assertNull($recorded->outcome());
        self::assertSame(LifecycleState::Running, $recorded->state());
        self::assertTrue($recorded->hasEvidence());

        self::assertFalse(
            $this->attempts->recordEvidence($recorded, FailureEvidence::none())->evidence()?->isEmpty() ?? true,
            'Empty evidence erased what the runner had already recorded.',
        );
    }

    #[Test]
    public function a_claim_and_the_evidence_are_kept_in_separate_columns(): void
    {
        $attempt = $this->attempts->recordEvidence(
            $this->attempts->recordClaim($this->begin('Add a flag'), UnverifiedText::of('I rewrote the parser')),
            FailureEvidence::of('composer test failed (exit 1)'),
        );

        $row = $this->database->query('SELECT summary, failure_evidence FROM attempts')[0];

        self::assertSame('I rewrote the parser', $row['summary']);
        self::assertIsString($row['failure_evidence']);
        self::assertStringNotContainsString(
            'I rewrote the parser',
            $row['failure_evidence'],
            'What the agent claimed was written into the evidence column, where nothing '
            . 'distinguishes it from what the package observed.',
        );
        self::assertTrue($attempt->hasSummary());
    }

    /**
     * PROJECT.md section 4: a zero exit code is not sufficient. This is that
     * sentence as a refusal.
     */
    #[Test]
    public function an_attempt_cannot_be_completed_with_nothing_verifying_it(): void
    {
        $attempt = $this->attempts->recordClaim(
            $this->begin('Add a flag'),
            UnverifiedText::of('Done, everything passes.'),
        );

        self::assertFalse($this->attempts->verificationSupports($attempt));

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('no passing verification behind it');

        $this->attempts->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Completed,
            exitCode: 0,
        );
    }

    #[Test]
    public function an_attempt_cannot_be_completed_while_a_check_has_failed(): void
    {
        $attempt = $this->begin('Add a flag');
        $this->insertCheck($attempt, 1, 'composer test', 'passed');
        $this->insertCheck($attempt, 2, 'composer stan', 'failed');

        self::assertFalse($this->attempts->verificationSupports($attempt));

        $this->expectException(StateException::class);

        $this->attempts->finish($attempt, AttemptOutcome::Candidate, LifecycleState::Completed);
    }

    #[Test]
    public function a_verified_candidate_completes_and_keeps_what_was_recorded_with_it(): void
    {
        $attempt = $this->begin('Add a flag');
        $this->insertCheck($attempt, 1, 'composer test', 'passed');
        $this->insertCheck($attempt, 2, 'composer stan', 'passed');
        $this->clock->advance(30);

        $finished = $this->attempts->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Completed,
            exitCode: 0,
            durationMs: 12_400,
            summary: UnverifiedText::of('Added the flag and a test for it'),
        );

        self::assertSame(LifecycleState::Completed, $finished->state());
        self::assertSame(AttemptOutcome::Candidate, $finished->outcome());
        self::assertSame(0, $finished->exitCode());
        self::assertSame(12_400, $finished->durationMs());
        self::assertSame('Added the flag and a test for it', $finished->summary()?->text());
        self::assertNull($finished->phase(), 'A finished attempt is still in the middle of something.');
        self::assertSame('2026-11-01T09:30:30+00:00', $finished->finishedAt()?->value());
        self::assertTrue($finished->isFinished());
    }

    #[Test]
    public function a_check_that_never_ran_does_not_stand_in_the_way_of_completion(): void
    {
        $attempt = $this->begin('Add a flag');
        $this->insertCheck($attempt, 1, 'composer test', 'passed');
        $this->insertCheck($attempt, 2, 'composer stan', 'not_run');

        self::assertTrue($this->attempts->verificationSupports($attempt));
    }

    #[Test]
    public function a_failing_attempt_records_the_evidence_it_failed_on(): void
    {
        $attempt = $this->begin('Add a flag');

        $finished = $this->attempts->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Failed,
            exitCode: 0,
            evidence: FailureEvidence::of('composer test failed (exit 1)', 'FAILURES!'),
        );

        self::assertSame(LifecycleState::Failed, $finished->state());
        self::assertSame('composer test failed (exit 1)', $finished->evidence()?->label());
        self::assertTrue($finished->isWorthRecalling());
    }

    #[Test]
    public function an_outcome_may_not_be_recorded_in_a_state_it_does_not_allow(): void
    {
        $attempt = $this->begin('Add a flag');
        $this->insertCheck($attempt, 1, 'composer test', 'passed');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('cannot be recorded as completed');

        $this->attempts->finish($attempt, AttemptOutcome::Error, LifecycleState::Completed);
    }

    #[Test]
    public function an_attempt_that_stopped_for_a_person_must_record_what_it_is_waiting_for(): void
    {
        $attempt = $this->begin('Add a flag');

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('must record what it is waiting for');

        $this->attempts->finish($attempt, AttemptOutcome::Blocked, LifecycleState::Blocked);
    }

    #[Test]
    public function a_blocked_attempt_records_the_question_and_who_asked_it(): void
    {
        $blocker = Blocker::from('codex', 'Which database should the fixture use?', 'Answer in CONTEXT.md.');

        $finished = $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Blocked,
            LifecycleState::Blocked,
            blocker: $blocker,
        );

        self::assertSame(LifecycleState::Blocked, $finished->state());
        self::assertTrue($finished->blocker()?->equals($blocker));
        self::assertTrue($finished->isWorthRecalling());
    }

    #[Test]
    public function an_attempt_is_not_finished_by_being_recorded_as_still_running(): void
    {
        $this->expectException(StateException::class);
        $this->expectExceptionMessage('not finished by being recorded as running');

        $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Candidate,
            LifecycleState::Running,
        );
    }

    #[Test]
    public function an_attempt_cannot_have_taken_a_negative_amount_of_time(): void
    {
        $this->expectException(StateException::class);
        $this->expectExceptionMessage('negative amount of time');

        $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Error,
            LifecycleState::Failed,
            durationMs: -1,
        );
    }

    #[Test]
    public function a_cancelled_attempt_is_recorded_as_interrupted(): void
    {
        $finished = $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Cancelled,
            LifecycleState::Interrupted,
            summary: UnverifiedText::of('I was partway through the parser'),
        );

        self::assertSame(LifecycleState::Interrupted, $finished->state());
        self::assertSame(AttemptOutcome::Cancelled, $finished->outcome());
        self::assertNotNull($finished->finishedAt());
    }

    /**
     * The one write that reaches a finished attempt again: reconciliation
     * deciding that what was recorded as a failure is really something a person
     * has to answer. The moment the attempt stopped is a fact about the
     * attempt, so a later write about it does not move it.
     */
    #[Test]
    public function recording_a_finished_attempt_again_does_not_move_when_it_stopped(): void
    {
        $attempt = $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Error,
            LifecycleState::Failed,
        );
        $this->clock->advance(60);

        $again = $this->attempts->finish(
            $attempt,
            AttemptOutcome::Blocked,
            LifecycleState::Blocked,
            blocker: Blocker::raised('The worktree holds changes nobody claims.', 'Inspect it by hand.'),
        );

        self::assertSame('2026-11-01T09:30:00+00:00', $again->finishedAt()?->value());
        self::assertTrue($again->updatedAt()->isAfter($again->finishedAt()));
    }

    #[Test]
    public function an_attempt_cannot_be_recorded_as_ending_the_way_it_already_ended(): void
    {
        $attempt = $this->attempts->finish(
            $this->begin('Add a flag'),
            AttemptOutcome::Error,
            LifecycleState::Failed,
        );

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('cannot become failed');

        $this->attempts->finish($attempt, AttemptOutcome::Error, LifecycleState::Failed);
    }

    #[Test]
    public function a_log_path_that_names_nothing_is_refused(): void
    {
        $this->expectException(StateException::class);
        $this->expectExceptionMessage('must name a file');

        $this->attempts->begin($this->running('Add a flag'), $this->runId, '   ');
    }

    #[Test]
    public function an_attempt_whose_row_is_gone_cannot_be_updated(): void
    {
        $attempt = $this->begin('Add a flag');
        $this->database->execute('DELETE FROM attempts WHERE id = ?', [$attempt->rowId()]);

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('no longer in the state database');

        $this->attempts->recordClaim($attempt, UnverifiedText::of('something'));
    }

    #[Test]
    public function the_attempts_worth_recalling_come_back_newest_first(): void
    {
        $generation = $this->running('Add a flag');

        $first = $this->attempts->finish(
            $this->attempts->begin($generation, $this->runId),
            AttemptOutcome::Candidate,
            LifecycleState::Failed,
            evidence: FailureEvidence::of('composer test failed on the first attempt'),
        );
        $generation = $this->generations->beginAttempt($generation);
        $second = $this->attempts->finish(
            $this->attempts->begin($generation, $this->runId),
            AttemptOutcome::Candidate,
            LifecycleState::Failed,
            evidence: FailureEvidence::of('composer test failed again'),
        );

        $recalled = $this->attempts->recallFor($generation);

        self::assertSame(
            [$second->number(), $first->number()],
            array_map(static fn (AttemptRecord $attempt): int => $attempt->number(), $recalled),
            'The oldest attempt was recalled first, so a bound would drop the failure being retried.',
        );
        self::assertCount(1, $this->attempts->recallFor($generation, 1));
        self::assertSame([], $this->attempts->recallFor($generation, 0));
    }

    #[Test]
    public function an_attempt_with_nothing_to_pass_on_is_not_recalled(): void
    {
        $generation = $this->running('Add a flag');
        $this->attempts->finish(
            $this->attempts->begin($generation, $this->runId),
            AttemptOutcome::Cancelled,
            LifecycleState::Interrupted,
        );

        self::assertSame(
            [],
            $this->attempts->recallFor($generation),
            'An attempt that claimed nothing and showed nothing would spend the next '
            . 'attempt\'s reading budget saying so.',
        );
    }

    #[Test]
    public function an_attempt_reads_as_one_line_for_a_status_table(): void
    {
        $attempt = $this->begin('Add a flag');

        self::assertSame('attempt 1: running (preparing), no result yet', $attempt->describe());
        self::assertStringContainsString(
            'an execution error',
            $this->attempts->finish($attempt, AttemptOutcome::Error, LifecycleState::Failed)->describe(),
        );
    }

    /**
     * A pass with its first attempt counted, which is the state the agent is
     * invoked in.
     */
    private function running(string $description): GenerationRecord
    {
        return $this->generations->beginAttempt($this->start($this->record($description)));
    }

    /**
     * A pass with its first attempt counted and its row written.
     */
    private function begin(string $description): AttemptRecord
    {
        return $this->attempts->begin($this->running($description), $this->runId);
    }

    private function record(string $description): TaskSnapshot
    {
        $snapshot = TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));
        $this->tasks->record($snapshot);

        return $snapshot;
    }

    private function start(TaskSnapshot $snapshot): GenerationRecord
    {
        return $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'main',
            'a1b2c3d4e5f6',
            null,
            $this->runId,
        );
    }

    /**
     * One verification result, written straight into the table.
     *
     * The store for these rows belongs to PROJECT.md section G; what this test
     * needs is only the evidence they constitute, so the rows are inserted here
     * rather than waited for.
     */
    private function insertCheck(
        AttemptRecord $attempt,
        int $position,
        string $name,
        string $outcome,
    ): void {
        $this->database->execute(
            'INSERT INTO verification_results
                 (attempt_id, position, name, command, outcome, timeout_seconds)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $attempt->rowId(),
                $position,
                $name,
                json_encode(explode(' ', $name), JSON_THROW_ON_ERROR),
                $outcome,
                600,
            ],
        );
    }

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
                'main',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
