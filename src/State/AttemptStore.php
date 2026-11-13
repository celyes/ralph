<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Support\UnverifiedText;

/**
 * Reads and writes the `attempts` table: what each invocation of the agent said
 * and what it was judged to be.
 *
 * This is where PROJECT.md section D, item 6 is enforced. Saving what a model
 * wrote is easy; saving it without letting it become the record of what
 * happened is the part that needs a rule, and the rules are these:
 *
 * - **A claim is written through its own method and touches nothing else.**
 *   {@see self::recordClaim()} writes `summary` and only `summary`. It cannot
 *   move the state, the outcome, the phase or the timestamps that say the
 *   attempt is over, so there is no path by which an agent's sentence changes
 *   the row's verdict. {@see self::recordEvidence()} is its counterpart for
 *   what the runner saw.
 * - **The verdict comes from the runner's own vocabulary.** {@see self::finish()}
 *   takes an {@see AttemptOutcome} and a {@see LifecycleState}, both of them
 *   enums the package computes; neither is parsed out of worker output.
 * - **An outcome may not be recorded in a state it does not allow.** A blocked
 *   attempt is `blocked`, an error is `failed`. Only
 *   {@see AttemptOutcome::Candidate} leaves the state open, because a candidate
 *   is the one outcome that is a claim.
 * - **`completed` requires verification rows.** PROJECT.md section 4: a zero
 *   exit code is not sufficient. An attempt cannot be completed unless at least
 *   one verification result has been recorded for it and none of them failed —
 *   so an agent that exits zero and says the work is done produces, on its own
 *   say-so, a `failed` or still-running attempt and nothing else.
 *
 * The attempt counter lives on the generation and is spent by
 * {@see GenerationStore::beginAttempt()} before the agent is invoked. This store
 * writes the row for the attempt that counter has already paid for, which is why
 * {@see self::begin()} takes its number from the generation rather than from a
 * count of rows here: a crash between the counter going up and this row being
 * written must not give a task a free attempt.
 */
final class AttemptStore
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Open the row for the attempt the generation's counter has just paid for.
     *
     * Starts `running` at {@see Phase::Preparing}: unlike a generation, an
     * attempt has no meaningful life before it is under way — the row is
     * written because the agent is about to be invoked.
     *
     * @param int         $runId   the run making the attempt
     * @param string|null $logPath where this attempt's worker output is kept
     *
     * @throws StateException when the generation has not spent an attempt, when
     *                        the row already exists, or when the write is
     *                        refused
     */
    public function begin(
        GenerationRecord $generation,
        int $runId,
        ?string $logPath = null,
    ): AttemptRecord {
        $number = $generation->attempts();

        if ($number < 1) {
            throw new StateException(
                sprintf(
                    'Pass %s has not spent an attempt, so there is no attempt to record.',
                    $generation->slug(),
                ),
                ['Call GenerationStore::beginAttempt() first; it is what counts the attempt.'],
            );
        }

        $agent = $generation->agent()->toColumns();
        $now = Timestamp::now($this->clock);

        $this->guard(
            $generation->slug(),
            $number,
            fn (): int => $this->database->execute(
                'INSERT INTO attempts
                     (generation_id, run_id, number, state, phase, agent, adapter, agent_settings,
                      log_path, started_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $generation->rowId(),
                    $runId,
                    $number,
                    LifecycleState::Running->value,
                    Phase::initial()->value,
                    $agent['agent'],
                    $agent['adapter'],
                    $agent['settings'],
                    $this->path($logPath),
                    $now->value(),
                    $now->value(),
                ],
            ),
        );

        return $this->read($this->database->lastInsertId());
    }

    /**
     * One attempt at a generation, or null when it was never recorded.
     *
     * @throws StateException when the row cannot be read
     */
    public function get(GenerationRecord $generation, int $number): ?AttemptRecord
    {
        $rows = $this->database->query(
            'SELECT * FROM attempts WHERE generation_id = ? AND number = ?',
            [$generation->rowId(), $number],
        );

        return $rows === [] ? null : AttemptRecord::fromRow($rows[0]);
    }

    /**
     * The most recent attempt at a generation, or null when it has had none.
     *
     * Ordered by the attempt number rather than by `started_at`: two attempts
     * can share a timestamp, and the number is what the schema carries to say
     * which came second.
     *
     * @throws StateException when the rows cannot be read
     */
    public function latestFor(GenerationRecord $generation): ?AttemptRecord
    {
        $rows = $this->database->query(
            'SELECT * FROM attempts WHERE generation_id = ? ORDER BY number DESC LIMIT 1',
            [$generation->rowId()],
        );

        return $rows === [] ? null : AttemptRecord::fromRow($rows[0]);
    }

    /**
     * Every attempt at a generation, oldest first.
     *
     * @return list<AttemptRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function forGeneration(GenerationRecord $generation): array
    {
        return array_map(
            static fn (array $row): AttemptRecord => AttemptRecord::fromRow($row),
            $this->database->query(
                'SELECT * FROM attempts WHERE generation_id = ? ORDER BY number',
                [$generation->rowId()],
            ),
        );
    }

    /**
     * Record what the attempt is in the middle of.
     *
     * @throws StateException when the phase would go backwards, or the attempt
     *                        is finished
     */
    public function enterPhase(AttemptRecord $attempt, Phase $phase): AttemptRecord
    {
        // Read off `finished_at` and not off the state, because a *failed*
        // state does record a phase — the phase a pass failed in is evidence,
        // and the pass can be retried. The attempt that failed in it cannot.
        if ($attempt->isFinished()) {
            throw new StateException(sprintf(
                'Attempt %d finished at %s; it is not in the middle of anything and cannot enter %s.',
                $attempt->number(),
                $attempt->finishedAt()?->value() ?? 'an unrecorded time',
                $phase->value,
            ));
        }

        if (!$attempt->state()->recordsPhase()) {
            throw new StateException(sprintf(
                'Attempt %d is %s; it is not in the middle of anything and cannot enter %s.',
                $attempt->number(),
                $attempt->state()->value,
                $phase->value,
            ));
        }

        $current = $attempt->phase();
        $next = $current === null ? $phase : $current->transitionTo($phase);

        return $this->write($attempt, ['phase' => $next->value]);
    }

    /**
     * Save what the agent said about the attempt.
     *
     * Writes the summary column and nothing else — see the class comment. The
     * text has already been defused and bounded by {@see UnverifiedText}, and
     * it is taken as a {@see UnverifiedText} rather than as a string so that
     * there is no overload a caller could reach with raw worker output.
     *
     * A claim is replaceable: an agent that is asked for a summary and then
     * produces a better one has written the second, not two. An *empty* claim
     * does not overwrite a recorded one, because that is how a later step that
     * simply has nothing to add would erase the evidence.
     *
     * @throws StateException when the row is gone
     */
    public function recordClaim(AttemptRecord $attempt, UnverifiedText $summary): AttemptRecord
    {
        if ($summary->isEmpty()) {
            return $attempt;
        }

        return $this->write($attempt, ['summary' => $summary->toStorage()]);
    }

    /**
     * Save what the runner observed.
     *
     * The counterpart of {@see self::recordClaim()}, and written to its own
     * column for the same reason: a week later, a user reading the row has to
     * be able to tell which half the package stood behind.
     *
     * @throws StateException when the row is gone
     */
    public function recordEvidence(AttemptRecord $attempt, FailureEvidence $evidence): AttemptRecord
    {
        if ($evidence->isEmpty()) {
            return $attempt;
        }

        return $this->write($attempt, ['failure_evidence' => $evidence->toStorage()]);
    }

    /**
     * Record how the attempt ended.
     *
     * The state is the runner's judgement and is checked against the outcome:
     * {@see AttemptOutcome::allows()} is what refuses a `completed` row for an
     * attempt the agent blocked on, and {@see self::verificationSupports()} is
     * what refuses one that no verification result stands behind.
     *
     * The summary and the evidence may be passed here as a convenience, and
     * they go through exactly the same columns as the two methods above.
     *
     * @param int|null $exitCode   the worker's exit status, where it exited
     * @param int|null $durationMs how long the invocation took
     *
     * @throws StateException when the outcome does not allow the state, when a
     *                        blocked attempt records no blocker, when a
     *                        completion has no verification behind it, or when
     *                        the lifecycle refuses the move
     */
    public function finish(
        AttemptRecord $attempt,
        AttemptOutcome $outcome,
        LifecycleState $state,
        ?int $exitCode = null,
        ?int $durationMs = null,
        ?UnverifiedText $summary = null,
        ?FailureEvidence $evidence = null,
        ?Blocker $blocker = null,
    ): AttemptRecord {
        if ($state === LifecycleState::Running || $state === LifecycleState::Pending) {
            throw new StateException(sprintf(
                'An attempt is not finished by being recorded as %s.',
                $state->value,
            ));
        }

        if (!$outcome->allows($state)) {
            throw new StateException(
                sprintf(
                    'An attempt that came back with %s cannot be recorded as %s.',
                    $outcome->describe(),
                    $state->value,
                ),
                [
                    $outcome->settles() === null
                        ? 'A candidate implementation is verified before it is anything else.'
                        : sprintf('That outcome is recorded as %s.', $outcome->settles()->value),
                ],
            );
        }

        if ($outcome->requiresBlocker() && $blocker === null && $attempt->blocker() === null) {
            throw new StateException(
                'An attempt that stopped for human input must record what it is waiting for.',
                ['Record the agent\'s question as the blocker\'s reason and the next action.'],
            );
        }

        if ($state === LifecycleState::Completed && !$this->verificationSupports($attempt)) {
            throw new StateException(
                sprintf(
                    'Attempt %d has no passing verification behind it, so it cannot be completed.',
                    $attempt->number(),
                ),
                [
                    'A zero exit code is not a result: the configured checks decide whether an '
                    . 'attempt passed.',
                    'Record the verification results first, or record the attempt as failed.',
                ],
            );
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new StateException('An attempt cannot have taken a negative amount of time.');
        }

        $next = $attempt->state()->transitionTo($state);
        $columns = [
            'state' => $next->value,
            'phase' => null,
            'outcome' => $outcome->value,
        ];

        if ($exitCode !== null) {
            $columns['exit_code'] = $exitCode;
        }

        if ($durationMs !== null) {
            $columns['duration_ms'] = $durationMs;
        }

        if ($summary !== null && !$summary->isEmpty()) {
            $columns['summary'] = $summary->toStorage();
        }

        if ($evidence !== null && !$evidence->isEmpty()) {
            $columns['failure_evidence'] = $evidence->toStorage();
        }

        if ($blocker !== null) {
            $columns['blocker'] = $blocker->toStorage();
        }

        return $this->write($attempt, $columns, finished: true);
    }

    /**
     * Whether the verification rows recorded for an attempt support completing
     * it.
     *
     * At least one check must have passed and none may have failed or timed
     * out. A check that never ran is neither: the checks are a sequence that
     * stops at the first failure, so `not_run` rows sit behind a failure that
     * is already refusing this.
     *
     * **One attempt is allowed no rows at all: one whose run froze no checks.**
     * `checks` is empty by default, because only a project knows how it is
     * verified, and a run that has nothing to verify with still has to be able
     * to finish a task — on the agent's result and the commit, which is what it
     * told the user it would do before it started. The question is asked of the
     * run's own frozen set rather than of today's configuration, so a `checks`
     * entry added while a run was under way cannot retrospectively invalidate
     * the work, and — the direction that matters — a run that *did* freeze
     * checks can never complete an attempt whose checks are missing, however
     * the file has since been edited.
     *
     * @throws StateException when the rows cannot be read
     */
    public function verificationSupports(AttemptRecord $attempt): bool
    {
        $rows = $this->database->query(
            'SELECT outcome, COUNT(*) AS total
             FROM verification_results
             WHERE attempt_id = ?
             GROUP BY outcome',
            [$attempt->rowId()],
        );

        $passed = 0;
        foreach ($rows as $row) {
            $outcome = is_string($row['outcome']) ? $row['outcome'] : '';

            if ($outcome === 'failed' || $outcome === 'timed_out') {
                return false;
            }

            if ($outcome === 'passed') {
                $passed += (int) $row['total'];
            }
        }

        if ($passed > 0) {
            return true;
        }

        return $rows === [] && $this->runFrozeNoChecks($attempt);
    }

    /**
     * Whether the run this attempt belongs to froze an empty check set.
     *
     * Read from the run's row, which is where the set was frozen (section G,
     * item 3). A row that cannot be read, or whose column does not hold a JSON
     * array, answers no: an unreadable frozen set is not permission to complete
     * work nothing examined.
     *
     * @throws StateException when the row cannot be read
     */
    private function runFrozeNoChecks(AttemptRecord $attempt): bool
    {
        $rows = $this->database->query(
            'SELECT checks FROM runs WHERE id = ?',
            [$attempt->runId()],
        );

        $checks = $rows[0]['checks'] ?? null;

        if (!is_string($checks)) {
            return false;
        }

        $decoded = json_decode($checks, true);

        return is_array($decoded) && $decoded === [];
    }

    /**
     * The attempts worth telling the next one about, newest first.
     *
     * What the memory writer and `ralph logs` read. An attempt with neither a
     * claim nor an observation nor a blocker is left out: a note saying only
     * that an attempt happened spends the next attempt's reading budget without
     * telling it anything.
     *
     * @param int $limit how many to return, newest first
     *
     * @return list<AttemptRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function recallFor(GenerationRecord $generation, int $limit = 3): array
    {
        if ($limit < 1) {
            return [];
        }

        $recalled = [];

        foreach (array_reverse($this->forGeneration($generation)) as $attempt) {
            if (count($recalled) >= $limit) {
                break;
            }

            if (!$attempt->isWorthRecalling()) {
                continue;
            }

            $recalled[] = $attempt;
        }

        return $recalled;
    }

    /**
     * Read a row by its primary key.
     *
     * @throws StateException when the row is not there
     */
    private function read(int $rowId): AttemptRecord
    {
        $rows = $this->database->query('SELECT * FROM attempts WHERE id = ?', [$rowId]);

        if ($rows === []) {
            throw new StateException(sprintf('Attempt row %d is not in the state database.', $rowId));
        }

        return AttemptRecord::fromRow($rows[0]);
    }

    /**
     * Write columns to an attempt's row, moving `updated_at` with them.
     *
     * @param array<string, scalar|null> $columns
     *
     * @throws StateException when the row is gone, or the write is refused
     */
    private function write(AttemptRecord $attempt, array $columns, bool $finished = false): AttemptRecord
    {
        $now = Timestamp::now($this->clock);
        $columns['updated_at'] = $now->value();

        if ($finished && $attempt->finishedAt() === null) {
            $columns['finished_at'] = $now->value();
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($columns),
        ));

        $changed = $this->database->execute(
            sprintf('UPDATE attempts SET %s WHERE id = ?', $assignments),
            [...array_values($columns), $attempt->rowId()],
        );

        if ($changed === 0) {
            throw new StateException(sprintf(
                'Attempt %d is no longer in the state database; it cannot be updated.',
                $attempt->number(),
            ));
        }

        return $this->read($attempt->rowId());
    }

    /**
     * Run a write, turning the one unique index on this table into a readable
     * failure.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     *
     * @throws StateException when the attempt is already recorded
     */
    private function guard(string $slug, int $number, callable $write): mixed
    {
        try {
            return $write();
        } catch (StateException $failure) {
            if (!str_contains($failure->getMessage(), 'UNIQUE constraint failed')) {
                throw $failure;
            }

            throw new StateException(
                sprintf('Attempt %d of pass %s is already recorded.', $number, $slug),
                [
                    'Read the recorded attempt and continue it, rather than opening a second row '
                    . 'for the same attempt.',
                ],
            );
        }
    }

    /**
     * A log path, or null when there is none.
     *
     * @throws StateException when a path is given that names nothing
     */
    private function path(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new StateException('A recorded log path must name a file.');
        }

        return $trimmed;
    }
}
