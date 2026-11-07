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
 * - **Ownership is recorded, not re-derived.** The branch, the base commit the
 *   branch started from, and whether this pass created its worktree are
 *   written when they become true and read back as they were. A run that looked
 *   at the repository and guessed would guess wrong exactly after an
 *   interruption, which is when the directory has something in it.
 * - **The agent is recorded on the pass**, not only on the run, because resume
 *   must continue with the agent the work was started under even if the
 *   project's default has changed since.
 * - **State changes go through {@see LifecycleState::transitionTo()}**, so a
 *   move the lifecycle does not allow is refused here instead of producing a
 *   row no later run can interpret. The database refuses the vocabulary; this
 *   refuses the sequence.
 *
 * Two invariants are the database's, and the readable failures for them are
 * this class's: a branch belongs to exactly one generation, and a task has at
 * most one pass that can still be continued. Both are partial unique indexes,
 * so they hold even against a process that crashed halfway; both would
 * otherwise surface as a bare SQLite constraint message naming an index.
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
     * @param PushResult|null $push what publication is expected to require,
     *                              from the run's frozen configuration;
     *                              defaults to not required
     * @param int|null        $runId the run this pass belongs to
     *
     * @throws StateException when the task is unknown, the branch or the open
     *                        pass is already taken, or the row cannot be
     *                        written
     */
    public function start(
        TaskGeneration $generation,
        TaskSnapshot $snapshot,
        AgentRecord $agent,
        string $baseReference,
        string $baseSha,
        ?PushResult $push = null,
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
        $publication = ($push ?? PushResult::notRequired())->toColumns();
        $now = Timestamp::now($this->clock);

        $this->guard(
            $generation->slug(),
            fn (): int => $this->database->execute(
                'INSERT INTO generations
                     (task_id, number, slug, run_id, state, phase, attempts, content_hash,
                      agent, adapter, agent_settings, base_reference, base_sha,
                      push_state, push_remote, push_error, started_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                    $this->reference($baseReference, 'base reference'),
                    $this->reference($baseSha, 'base commit'),
                    $publication['state'],
                    $publication['remote'],
                    $publication['error'],
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
     * The pass named by a slug, which is what a branch or a log path carries.
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
     * and which branches are already owned, so a new pass is never handed one.
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
     * Every branch a generation already owns.
     *
     * @return list<string>
     *
     * @throws StateException when the rows cannot be read
     */
    public function reservedBranches(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['branch'],
            $this->database->query(
                'SELECT branch FROM generations WHERE branch IS NOT NULL ORDER BY id',
            ),
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
     * Claim a branch for this pass.
     *
     * The storage half of branch ownership. The database allows a branch name
     * on at most one generation, so a second pass asking for a branch an
     * earlier one owns is refused here rather than resetting it — which is the
     * behaviour PROJECT.md section F, item 4 requires and the reason reopening
     * a task cannot destroy the commit the first pass left behind.
     *
     * Re-claiming the branch this pass already owns is allowed and does
     * nothing, so resume does not have to remember whether it got this far.
     *
     * @throws StateException when the branch belongs to another pass
     */
    public function assignBranch(GenerationRecord $generation, string $branch): GenerationRecord
    {
        $name = $this->reference($branch, 'branch');

        if ($generation->ownsBranch($name)) {
            return $generation;
        }

        return $this->guard(
            $generation->slug(),
            fn (): GenerationRecord => $this->write($generation, ['branch' => $name]),
        );
    }

    /**
     * Record the workspace this pass is using and whether it created it.
     */
    public function recordWorktree(
        GenerationRecord $generation,
        WorktreeOwnership $worktree,
    ): GenerationRecord {
        $columns = $worktree->toColumns();

        return $this->write($generation, [
            'worktree_path' => $columns['path'],
            'owns_worktree' => $columns['owned'],
        ]);
    }

    /**
     * Record the commit this pass produced.
     *
     * Writing this row is the point at which a commit stops being something
     * only Git knows about. The reverse — a commit in the repository with no
     * row — is the crash this schema is shaped around, and it is why the Git
     * log of PROJECT.md section F is appended to before the commit is
     * attempted, not only after.
     *
     * @throws StateException when the SHA is blank, or a different commit is
     *                        already recorded, which means something ran twice
     *                        and must be reconciled rather than overwritten
     */
    public function recordCommit(GenerationRecord $generation, string $sha): GenerationRecord
    {
        $commit = $this->reference($sha, 'commit');
        $recorded = $generation->commitSha();

        if ($recorded === $commit) {
            return $generation;
        }

        if ($recorded !== null) {
            throw new StateException(
                sprintf(
                    'Generation %s already records commit %s and cannot record %s as well.',
                    $generation->slug(),
                    $recorded,
                    $commit,
                ),
                [
                    'A pass commits once. Two commits means the implementation ran twice, '
                    . 'which resume is meant to prevent, and the recorded one is kept.',
                ],
            );
        }

        return $this->write($generation, ['commit_sha' => $commit, 'no_change' => 0]);
    }

    /**
     * Record that the task was already satisfied, so there was nothing to
     * commit.
     *
     * A verified outcome rather than a failure, and deliberately not an empty
     * commit (PROJECT.md section G, item 6). This column says only that no
     * commit is owed; the case for saying it is
     * {@see \Celyes\RalphPhp\Verification\Satisfaction}, which the pass makes
     * before calling this and which is refused rather than recorded when the
     * facts contradict it. The durable half of that case is the pass's
     * verification results, and a copy of the whole of it is written beside the
     * attempt's logs.
     *
     * @throws StateException when a commit is already recorded, in which case
     *                        the pass did change something
     */
    public function recordNoChange(GenerationRecord $generation): GenerationRecord
    {
        if ($generation->hasCommit()) {
            throw new StateException(sprintf(
                'Generation %s recorded commit %s, so it did not leave the task unchanged.',
                $generation->slug(),
                (string) $generation->commitSha(),
            ));
        }

        return $this->write($generation, ['no_change' => 1]);
    }

    /**
     * Record where publication stands.
     *
     * @throws StateException when the row cannot be written
     */
    public function recordPush(GenerationRecord $generation, PushResult $push): GenerationRecord
    {
        $columns = $push->toColumns();

        return $this->write($generation, [
            'push_state' => $columns['state'],
            'push_remote' => $columns['remote'],
            'push_error' => $columns['error'],
        ]);
    }

    /**
     * Finish the pass as complete.
     *
     * Refuses a pass that has no implementation recorded, or one whose required
     * push has not succeeded. Those are the Git-side conditions PROJECT.md puts
     * on ticking a box, and a store that let them slide would make the checked
     * box the only place the lie showed up.
     *
     * @throws StateException when the pass has not earned completion, or cannot
     *                        reach it from the state it is in
     */
    public function complete(GenerationRecord $generation): GenerationRecord
    {
        if (!$generation->hasImplementation()) {
            throw new StateException(
                sprintf(
                    'Generation %s has neither a commit nor a recorded no-change result, '
                    . 'so it cannot be completed.',
                    $generation->slug(),
                ),
                ['Record the commit, or record a verified no-change outcome, first.'],
            );
        }

        if ($generation->push()->isOutstanding()) {
            throw new StateException(
                sprintf(
                    'Generation %s cannot be completed while %s.',
                    $generation->slug(),
                    $generation->push()->describe(),
                ),
                ['The local commit is kept; resume retries the publication without re-running the work.'],
            );
        }

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
     * resume whether to invoke the agent again or to retry a push.
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
     * Run a write, turning the two ownership indexes into readable failures.
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

        if (str_contains($message, 'generations.branch')) {
            return new StateException(
                sprintf('That branch is already owned by another pass, so %s cannot take it.', $slug),
                [
                    'A branch belongs to exactly one execution generation, so reopening a task '
                    . 'can never reset the branch an earlier pass left behind.',
                    'Delete the branch yourself if it is genuinely unwanted, or let the pass that '
                    . 'owns it finish.',
                ],
            );
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
                    . 'deliberately skipped; anything else is continued on the branch it owns.',
                    'Run "ralph status" to see which pass is open and what it is waiting for.',
                ],
            );
        }

        return $failure;
    }

    /**
     * @throws StateException when a reference that must name something is blank
     */
    private function reference(string $value, string $what): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new StateException(sprintf('A recorded %s must name something.', $what));
        }

        return $trimmed;
    }
}
