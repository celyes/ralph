<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\GenerationHistory;
use Celyes\RalphPhp\Tasks\RecordedGeneration;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;

/**
 * Reads and writes the `generations` table: everything a pass at a task owns.
 *
 * The facts PROJECT.md section D, item 4 requires be durable all live on this
 * row, and they are written here rather than by the runner so that the rules
 * about them are stated once:
 *
 * - **The attempt counter only goes up**, and it goes up before the agent is
 *   invoked rather than after it returns ({@see self::beginAttempt()}). An
 *   attempt that is interrupted still counts, which is the only way a task
 *   cannot escape its limit by being killed at the right moment.
 * - **Timestamps.** `started_at` is written once; `updated_at` moves on every
 *   write, so a status table can show which pass is actually making progress;
 *   `finished_at` is written exactly when the pass reaches a state it does not
 *   come back from, and never cleared.
 * - **The agent is recorded on the pass**, not only on the run, because resume
 *   must continue with the agent the work was started under even if the
 *   project's default has changed since.
 * - **State changes go through {@see LifecycleState::transitionTo()}**, so a
 *   move the lifecycle does not allow is refused here instead of producing a
 *   row no later run can interpret. The database refuses the vocabulary; this
 *   refuses the sequence.
 *
 * One invariant is the database's, and the readable failure for it is this
 * class's: a task has at most one pass that can still be continued. It is a
 * partial unique index, so it holds even against a process that crashed
 * halfway, and it would otherwise surface as a bare SQLite constraint message
 * naming an index.
 */
final class GenerationStore
{
    private readonly TaskStore $tasks;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->tasks = new TaskStore($database, $clock);
    }

    /**
     * Open a new pass at a task.
     *
     * The task must already be recorded: a generation points at a task row with
     * a foreign key, and a pass at a task no reading ever saw is a mistake worth
     * reporting as one rather than storing.
     *
     * The pass starts `pending` with no phase. It is not `running` until
     * {@see self::beginAttempt()}, because the gap between a row existing and
     * the agent being invoked is real — preparation can fail in it — and a row
     * that claimed to be running in that gap would be reconciled as an
     * interrupted execution.
     *
     * @param int|null $runId the run this pass belongs to
     *
     * @throws StateException when the task is unknown, the task's open pass is
     *                        already taken, or the row cannot be written
     */
    public function start(
        TaskGeneration $generation,
        TaskSnapshot $snapshot,
        AgentRecord $agent,
        ?int $runId = null,
    ): GenerationRecord {
        if (!$generation->belongsTo($snapshot->id())) {
            throw new StateException(sprintf(
                'Generation %s is not a pass at task %s.',
                $generation->slug(),
                $snapshot->id()->value(),
            ));
        }

        $task = $this->tasks->get($snapshot->id());
        $selected = $agent->toColumns();
        $now = Timestamp::now($this->clock);

        $this->guard(
            $generation->slug(),
            fn (): int => $this->database->execute(
                'INSERT INTO generations
                     (task_id, number, slug, run_id, state, phase, attempts, content_hash,
                      agent, adapter, agent_settings, started_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, ?)',
                [
                    $task->rowId(),
                    $generation->number(),
                    $generation->slug(),
                    $runId,
                    LifecycleState::initial()->value,
                    $snapshot->contentHash(),
                    $selected['agent'],
                    $selected['adapter'],
                    $selected['settings'],
                    $now->value(),
                    $now->value(),
                ],
            ),
        );

        return $this->get($generation);
    }

    /**
     * The pass's row, or null when it has never been recorded.
     *
     * @throws StateException when the row cannot be read
     */
    public function find(TaskGeneration $generation): ?GenerationRecord
    {
        return $this->findBySlug($generation->slug());
    }

    /**
     * The pass named by a slug, which is what a log path carries.
     *
     * @throws StateException when the row cannot be read
     */
    public function findBySlug(string $slug): ?GenerationRecord
    {
        $rows = $this->database->query('SELECT * FROM generations WHERE slug = ?', [$slug]);

        return $rows === [] ? null : GenerationRecord::fromRow($rows[0]);
    }

    /**
     * The pass's row, which must exist.
     *
     * @throws StateException when there is no such row
     */
    public function get(TaskGeneration $generation): GenerationRecord
    {
        return $this->find($generation) ?? throw new StateException(sprintf(
            'The state database has no record of generation %s.',
            $generation->slug(),
        ));
    }

    /**
     * Every pass at a task, oldest first.
     *
     * @return list<GenerationRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function forTask(TaskId $task): array
    {
        return array_map(
            static fn (array $row): GenerationRecord => GenerationRecord::fromRow($row),
            $this->database->query(
                'SELECT g.* FROM generations g
                   JOIN tasks t ON t.id = g.task_id
                  WHERE t.task_id = ?
                  ORDER BY g.number',
                [$task->value()],
            ),
        );
    }

    /**
     * The most recent pass at a task.
     *
     * @throws StateException when the rows cannot be read
     */
    public function latestFor(TaskId $task): ?GenerationRecord
    {
        $generations = $this->forTask($task);

        return $generations === [] ? null : $generations[count($generations) - 1];
    }

    /**
     * The task's one pass that can still be continued, if it has one.
     *
     * At most one exists — the database enforces it — so this returns a record
     * rather than a list, and a run never has to choose which open pass is the
     * current one.
     *
     * @throws StateException when the rows cannot be read
     */
    public function openFor(TaskId $task): ?GenerationRecord
    {
        foreach ($this->forTask($task) as $generation) {
            if ($generation->isOpen()) {
                return $generation;
            }
        }

        return null;
    }

    /**
     * Every pass at every task, as the checklist layer reads them.
     *
     * What {@see \Celyes\RalphPhp\Tasks\GenerationSelector} needs to decide
     * whether a task is being run for the first time, continued, or reopened —
     * so a reopened task is never mistaken for one that has never been run.
     *
     * @throws StateException when the rows cannot be read
     */
    public function history(): GenerationHistory
    {
        $rows = $this->database->query('SELECT * FROM generations ORDER BY task_id, number');

        return GenerationHistory::of(...array_map(
            static fn (array $row): RecordedGeneration
                => GenerationRecord::fromRow($row)->toRecordedGeneration(),
            $rows,
        ));
    }

    /**
     * Every pass this project has recorded, oldest task first.
     *
     * What a sweep over the project's whole history needs: `ralph clean` has to
     * account for every workspace a row names, including the passes of tasks
     * that have since been reworded or deleted out of the checklist — their
     * directories are on disk whether or not the item that produced them is
     * still there.
     *
     * The rows, not {@see self::history()}'s reading of them. That reading is
     * for the checklist layer and drops exactly the columns a cleanup decision
     * is made from: the recorded workspace, its ownership and how far a
     * previous removal got.
     *
     * @return list<GenerationRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): GenerationRecord => GenerationRecord::fromRow($row),
            $this->database->query('SELECT * FROM generations ORDER BY task_id, number'),
        );
    }

    /**
     * Spend an attempt and put the pass into `running` at its first phase.
     *
     * Called before the agent is invoked. The counter goes up here whatever
     * happens next, and the phase is reset to {@see Phase::Preparing} rather
     * than transitioned — a new attempt starts the sequence again, which is the
     * one place the phase of a pass legitimately moves backwards.
     *
     * @throws StateException when the pass cannot be run from the state it is
     *                        in
     */
    public function beginAttempt(GenerationRecord $generation): GenerationRecord
    {
        $state = $generation->state()->isRunning()
            ? LifecycleState::Running
            : $generation->state()->transitionTo(LifecycleState::Running);

        return $this->write($generation, [
            'state' => $state->value,
            'phase' => Phase::initial()->value,
            'attempts' => $generation->attempts() + 1,
        ]);
    }

    /**
     * Put a pass back into `running` at {@see Phase::Finalizing}, without
     * spending an attempt.
     *
     * The one way back into a pass that owes nothing but the write to the
     * user's checklist (PROJECT.md section G, item 8). The work is done and the
     * checks have passed, so there is nothing to attempt, and counting an
     * attempt would let a read-only file eventually exhaust the budget of a
     * pass that has already done its work.
     *
     * What says the pass got that far is its planned checklist update: the row
     * is written before the file is touched and is still `pending` afterwards
     * only if the write never landed ({@see ChecklistStore}). The caller reads
     * that and this does the state move, which is why there is no condition
     * here beyond the one the lifecycle itself imposes.
     *
     * @throws StateException when the pass cannot be run from the state it is
     *                        in
     */
    public function resumeFinalizing(GenerationRecord $generation): GenerationRecord
    {
        return $this->reopenAt($generation, Phase::Finalizing);
    }

    /**
     * Put a pass back into `running` at a phase it already reached.
     *
     * Used by the resumption that spends no attempt, and the blocker is
     * cleared by it. The reason a pass in either of those states stopped was
     * the thing that is now being done again — the box to write — so leaving
     * the old reason on the row would have `ralph status` report an unwritable
     * checklist against a task that went on to complete. A retry that stops
     * again records its own.
     *
     * @throws StateException when the pass cannot be run from the state it is
     *                        in
     */
    private function reopenAt(GenerationRecord $generation, Phase $phase): GenerationRecord
    {
        $state = $generation->state()->isRunning()
            ? LifecycleState::Running
            : $generation->state()->transitionTo(LifecycleState::Running);

        return $this->write($generation, [
            'state' => $state->value,
            'phase' => $phase->value,
            'blocker' => null,
        ]);
    }

    /**
     * Record what the pass is now in the middle of.
     *
     * @throws StateException when the phase would go backwards, or the pass is
     *                        in a state that records no phase
     */
    public function enterPhase(GenerationRecord $generation, Phase $phase): GenerationRecord
    {
        if (!$generation->state()->recordsPhase()) {
            throw new StateException(sprintf(
                'A pass that is %s is not in the middle of anything; it cannot enter %s.',
                $generation->state()->value,
                $phase->value,
            ));
        }

        $current = $generation->phase();
        $next = $current === null ? $phase : $current->transitionTo($phase);

        return $this->write($generation, ['phase' => $next->value]);
    }

    /**
     * Record what the task said when this pass last read it.
     *
     * For a pass being continued after the user edited the notes under its
     * task: the work is the same work — identity comes from the description —
     * but what was asked for has moved, and the row should say so.
     *
     * @throws StateException when the snapshot is of a different task
     */
    public function refreshContent(GenerationRecord $generation, TaskSnapshot $snapshot): GenerationRecord
    {
        if (!$generation->task()->equals($snapshot->id())) {
            throw new StateException(sprintf(
                'Generation %s is not a pass at task %s.',
                $generation->slug(),
                $snapshot->id()->value(),
            ));
        }

        return $this->write($generation, ['content_hash' => $snapshot->contentHash()]);
    }

    /**
     * Record which run is working on this pass.
     *
     * Written when a later run picks up a pass an earlier one left open, which
     * is what makes `ralph status` able to say who is holding it now.
     */
    public function assignRun(GenerationRecord $generation, ?int $runId): GenerationRecord
    {
        return $this->write($generation, ['run_id' => $runId]);
    }

    /**
     * Record that this pass's own log files have been deleted on purpose.
     *
     * Written **after** the deletion and only once no eligible log is left, so
     * the column means what it says: a row with a timestamp has nothing of its
     * own left to delete, and a row without one either never wanted a wipe or
     * had one interrupted — and both of those are answered by looking again
     * ({@see \Celyes\RalphPhp\Log\LogWiper}). That is the whole of what makes
     * the deletion safe to retry, and it is why there is no "wiping" state to
     * match `cleanup_state`: a log file left in a part-wiped directory is still
     * a log file, with nothing about it to misread.
     *
     * Idempotent, and the first time is the time that is kept. A second wipe of
     * a pass whose logs have already gone deleted nothing, so moving the
     * timestamp would date the removal to a run that did not make it.
     *
     * Nothing else on the row is touched. The state, the verification evidence
     * and the completion record all outlive the logs by design — the setting
     * trades the output of a successful task for disk, not the account of what
     * it did.
     */
    public function recordLogWipe(GenerationRecord $generation): GenerationRecord
    {
        if ($generation->logsWereWiped()) {
            return $generation;
        }

        return $this->write($generation, ['logs_wiped_at' => Timestamp::now($this->clock)->value()]);
    }

    /**
     * Finish the pass as complete.
     *
     * What a pass has to have done to get here is verification: the configured
     * checks passed, against the user's own checkout, run by something that is
     * not the worker. That evidence is the rows in `verification_results` and
     * the completion record the pass writes beside its logs, not a column on
     * this row, so the condition is the caller's to establish — this refuses
     * only a move the lifecycle itself does not allow.
     *
     * @throws StateException when the pass cannot reach completion from the
     *                        state it is in
     */
    public function complete(GenerationRecord $generation): GenerationRecord
    {
        return $this->finish($generation, LifecycleState::Completed);
    }

    /**
     * Stop the pass needing a person, with the reason and the next action.
     *
     * @throws StateException when a pass in this state cannot become blocked
     */
    public function block(GenerationRecord $generation, Blocker $blocker): GenerationRecord
    {
        return $this->finish($generation, LifecycleState::Blocked, $blocker);
    }

    /**
     * Record the pass as failed.
     *
     * @throws StateException when a pass in this state cannot fail
     */
    public function fail(GenerationRecord $generation, ?Blocker $blocker = null): GenerationRecord
    {
        return $this->finish($generation, LifecycleState::Failed, $blocker);
    }

    /**
     * Record that the pass was interrupted.
     *
     * The phase is left exactly as it was: it is the only thing that tells
     * resume whether to invoke the agent again or to write the checkbox it
     * stopped before.
     *
     * @throws StateException when a pass in this state cannot be interrupted
     */
    public function interrupt(GenerationRecord $generation): GenerationRecord
    {
        return $this->finish($generation, LifecycleState::Interrupted);
    }

    /**
     * Record that the runner deliberately passed the task over.
     *
     * @throws StateException when a pass that has already started is skipped,
     *                        which the lifecycle refuses: skipping is a
     *                        decision taken before the work begins
     */
    public function skip(GenerationRecord $generation, ?Blocker $blocker = null): GenerationRecord
    {
        return $this->finish($generation, LifecycleState::Skipped, $blocker);
    }

    /**
     * Move a pass to a state and record when it got there.
     *
     * `finished_at` is written for the states a pass does not come back from
     * and left alone for the rest, so a blocked pass that is retried and
     * finished keeps one finish time rather than collecting several.
     *
     * @throws StateException when the lifecycle does not allow the move
     */
    private function finish(
        GenerationRecord $generation,
        LifecycleState $state,
        ?Blocker $blocker = null,
    ): GenerationRecord {
        $next = $generation->state()->transitionTo($state);
        $columns = ['state' => $next->value];

        if (!$next->recordsPhase()) {
            $columns['phase'] = null;
        }

        if ($blocker !== null) {
            $columns['blocker'] = $blocker->toStorage();
        }

        return $this->write($generation, $columns, finished: $next->isClosed());
    }

    /**
     * Write columns to a generation's row, moving `updated_at` with them.
     *
     * @param array<string, scalar|null> $columns
     *
     * @throws StateException when the row is gone, or the write is refused
     */
    private function write(
        GenerationRecord $generation,
        array $columns,
        bool $finished = false,
    ): GenerationRecord {
        $now = Timestamp::now($this->clock);
        $columns['updated_at'] = $now->value();

        if ($finished && $generation->finishedAt() === null) {
            $columns['finished_at'] = $now->value();
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($columns),
        ));

        $changed = $this->database->execute(
            sprintf('UPDATE generations SET %s WHERE id = ?', $assignments),
            [...array_values($columns), $generation->rowId()],
        );

        if ($changed === 0) {
            throw new StateException(sprintf(
                'Generation %s is no longer in the state database; it cannot be updated.',
                $generation->slug(),
            ));
        }

        return $this->get($generation->generation());
    }

    /**
     * Run a write, turning the ownership indexes into readable failures.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     *
     * @throws StateException when an ownership invariant would be broken
     */
    private function guard(string $slug, callable $write): mixed
    {
        try {
            return $write();
        } catch (StateException $failure) {
            throw $this->explain($failure, $slug);
        }
    }

    /**
     * The same failure said in terms of what a user can do about it.
     *
     * The strings matched are SQLite's own: a violated unique index is reported
     * as `UNIQUE constraint failed:` followed by the columns it covers. They
     * are matched rather than parsed, and anything unrecognised is passed
     * through untouched, so a message this does not know stays readable instead
     * of being replaced by a guess.
     */
    private function explain(StateException $failure, string $slug): StateException
    {
        $message = $failure->getMessage();

        if (!str_contains($message, 'UNIQUE constraint failed')) {
            return $failure;
        }

        if (
            str_contains($message, 'generations.slug')
            || str_contains($message, 'generations.number')
        ) {
            return new StateException(
                sprintf('Generation %s is already recorded and cannot be started again.', $slug),
                ['Read the recorded pass and continue it, rather than opening a second one.'],
            );
        }

        if (str_contains($message, 'generations.task_id')) {
            return new StateException(
                sprintf('That task already has a pass that can be continued, so %s cannot start.', $slug),
                [
                    'A new generation is only started when the previous one is complete or was '
                    . 'deliberately skipped; anything else is continued where it stopped.',
                    'Run "ralph status" to see which pass is open and what it is waiting for.',
                ],
            );
        }

        return $failure;
    }
}
