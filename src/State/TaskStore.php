<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tasks\VerifiedTasks;

/**
 * Reads and writes the `tasks` table.
 *
 * A task's row is the record of what the checklist has said about it: its text
 * and details, the hash those produce, where its completion came from, and
 * when it was first seen, last seen and last changed. The row is keyed by the
 * task's own identifier, which comes from its wording and from nothing else, so
 * a checklist that is reordered, renamed or partly ticked still maps onto the
 * same rows.
 *
 * **What recording a reading does to completion** is the one rule in here worth
 * reading twice, because it is where the package either tells the truth about
 * what it has done or does not:
 *
 * - An unticked item is `pending`. That includes an item that was
 *   `runner_verified` yesterday: unticking is how a user reopens finished work,
 *   and the pass that earned the tick is kept as its own generation rather than
 *   being contradicted here.
 * - A ticked item stays `runner_verified` if the row already said so, and is
 *   otherwise `user_completed`. A run never promotes a tick it did not earn,
 *   which is what {@see Completion} exists to keep honest, and
 *   {@see self::markVerified()} — called only after verification, Git and any
 *   required push have all succeeded — is the only thing that writes the
 *   stronger verdict.
 *
 * An edit to the details under a task does *not* change its completion here.
 * That judgement needs a human and belongs to reconciliation; what this does is
 * record the new hash, which is what makes the edit visible at all.
 */
final class TaskStore
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Record a task as the checklist currently states it, inserting or
     * updating its row.
     *
     * @throws StateException when the row cannot be written
     */
    public function record(TaskSnapshot $snapshot): TaskRecord
    {
        return $this->recordAt($snapshot, Timestamp::now($this->clock));
    }

    /**
     * Record a whole reading of the checklist, and say when it was taken.
     *
     * Every task in the reading gets the same `last_seen_at`, which is what
     * makes {@see self::absentSince()} able to name the tasks the file no longer
     * states: with per-task timestamps a reading that took two seconds would
     * look like two readings.
     *
     * Written in one transaction. A reading is one fact about the checklist, and
     * half of it recorded is a state nothing knows how to interpret.
     *
     * @return array{reading: Timestamp, tasks: list<TaskRecord>}
     *
     * @throws StateException when the rows cannot be written
     */
    public function recordReading(TaskSnapshot ...$snapshots): array
    {
        $reading = Timestamp::now($this->clock);

        $tasks = $this->database->transaction(
            function () use ($snapshots, $reading): array {
                $records = [];

                foreach ($snapshots as $snapshot) {
                    $records[] = $this->recordAt($snapshot, $reading);
                }

                return $records;
            },
        );

        return ['reading' => $reading, 'tasks' => $tasks];
    }

    /**
     * The task's row, or null when the checklist has never been read with it
     * in.
     *
     * @throws StateException when the row cannot be read
     */
    public function find(TaskId $id): ?TaskRecord
    {
        $rows = $this->database->query(
            'SELECT * FROM tasks WHERE task_id = ?',
            [$id->value()],
        );

        return $rows === [] ? null : TaskRecord::fromRow($rows[0]);
    }

    /**
     * The task's row, which must exist.
     *
     * @throws StateException when there is no such row, which means a caller is
     *                        working on a task that was never recorded — the
     *                        kind of mistake that would otherwise surface as a
     *                        foreign-key failure three writes later
     */
    public function get(TaskId $id): TaskRecord
    {
        return $this->find($id) ?? throw new StateException(
            sprintf('The state database has no record of task %s.', $id->value()),
            [
                'Tasks are recorded when the checklist is read, so this means the task was '
                . 'not part of the reading this run started from.',
            ],
        );
    }

    /**
     * Whether the task has a row.
     *
     * @throws StateException when the row cannot be read
     */
    public function has(TaskId $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * Every recorded task, oldest first.
     *
     * Ordered by when they were first seen rather than by checklist position,
     * which this table does not store: position is a property of the file and
     * changes without the work changing.
     *
     * @return list<TaskRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): TaskRecord => TaskRecord::fromRow($row),
            $this->database->query('SELECT * FROM tasks ORDER BY first_seen_at, id'),
        );
    }

    /**
     * The tasks Ralph PHP has carried through verification itself.
     *
     * The one input the checklist layer needs to tell a box the user ticked
     * apart from one the runner earned.
     *
     * @throws StateException when the rows cannot be read
     */
    public function verified(): VerifiedTasks
    {
        $rows = $this->database->query(
            'SELECT task_id FROM tasks WHERE completion = ? ORDER BY id',
            [Completion::RunnerVerified->value],
        );

        return VerifiedTasks::fromStrings(array_map(
            static fn (array $row): string => (string) $row['task_id'],
            $rows,
        ));
    }

    /**
     * The tasks that were not part of a reading.
     *
     * Rows whose `last_seen_at` is older than the reading's moment: tasks the
     * user has removed from the checklist, or reworded, which — identity coming
     * from the wording — amounts to the same thing. Returned rather than
     * deleted, because their generations hold branches and commits that still
     * exist in the repository.
     *
     * @return list<TaskRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function absentSince(Timestamp $reading): array
    {
        return array_map(
            static fn (array $row): TaskRecord => TaskRecord::fromRow($row),
            $this->database->query(
                'SELECT * FROM tasks WHERE last_seen_at < ? ORDER BY first_seen_at, id',
                [$reading->value()],
            ),
        );
    }

    /**
     * How many tasks are recorded.
     *
     * @throws StateException when the count cannot be read
     */
    public function count(): int
    {
        return (int) $this->database->value('SELECT COUNT(*) FROM tasks');
    }

    /**
     * Record that the runner itself carried this task through.
     *
     * Called at the end of finalisation and nowhere else: PROJECT.md makes
     * passing verification, recording a commit or a justified no-change result,
     * and completing any required push the conditions for it.
     *
     * @throws StateException when there is no such task
     */
    public function markVerified(TaskId $id): TaskRecord
    {
        return $this->markCompletion($id, Completion::RunnerVerified);
    }

    /**
     * Set where a task's completion came from.
     *
     * Moves `updated_at` only when the verdict actually changes, so that column
     * keeps meaning "when something about this task moved" rather than "when a
     * run last looked at it" — which is what `last_seen_at` is for.
     *
     * @throws StateException when there is no such task
     */
    public function markCompletion(TaskId $id, Completion $completion): TaskRecord
    {
        $task = $this->get($id);

        if ($task->completion() === $completion) {
            return $task;
        }

        $now = Timestamp::now($this->clock);

        $this->database->execute(
            'UPDATE tasks SET completion = ?, updated_at = ? WHERE id = ?',
            [$completion->value, $now->value(), $task->rowId()],
        );

        return $this->get($id);
    }

    /**
     * @throws StateException when the row cannot be written
     */
    private function recordAt(TaskSnapshot $snapshot, Timestamp $reading): TaskRecord
    {
        $existing = $this->find($snapshot->id());

        if ($existing === null) {
            return $this->insert($snapshot, $reading);
        }

        return $this->update($existing, $snapshot, $reading);
    }

    /**
     * @throws StateException when the row cannot be written
     */
    private function insert(TaskSnapshot $snapshot, Timestamp $reading): TaskRecord
    {
        $this->database->execute(
            'INSERT INTO tasks
                 (task_id, description, details, content_hash, completion,
                  first_seen_at, last_seen_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $snapshot->id()->value(),
                $snapshot->description(),
                $snapshot->details(),
                $snapshot->contentHash(),
                $this->completionOf($snapshot, null)->value,
                $reading->value(),
                $reading->value(),
                $reading->value(),
            ],
        );

        return $this->get($snapshot->id());
    }

    /**
     * @throws StateException when the row cannot be written
     */
    private function update(TaskRecord $existing, TaskSnapshot $snapshot, Timestamp $reading): TaskRecord
    {
        $completion = $this->completionOf($snapshot, $existing->completion());
        $changed = !$existing->statesSameWork($snapshot)
            || $existing->description() !== $snapshot->description()
            || $existing->details() !== $snapshot->details()
            || $existing->completion() !== $completion;

        $this->database->execute(
            'UPDATE tasks
                SET description = ?, details = ?, content_hash = ?, completion = ?,
                    last_seen_at = ?, updated_at = ?
              WHERE id = ?',
            [
                $snapshot->description(),
                $snapshot->details(),
                $snapshot->contentHash(),
                $completion->value,
                $reading->value(),
                $changed ? $reading->value() : $existing->updatedAt()->value(),
                $existing->rowId(),
            ],
        );

        return $this->get($snapshot->id());
    }

    /**
     * The completion a reading implies, given what is already recorded.
     *
     * The rule in the class note, in one place: the marker decides whether the
     * task is done, and a tick is only ever reported as the runner's when the
     * row already says the runner earned it.
     */
    private function completionOf(TaskSnapshot $snapshot, ?Completion $recorded): Completion
    {
        if (!$snapshot->isChecked()) {
            return Completion::Pending;
        }

        return $recorded === Completion::RunnerVerified
            ? Completion::RunnerVerified
            : Completion::UserCompleted;
    }
}
