<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\TaskId;

/**
 * The `checklist_updates` rows: what a run means to write in the user's
 * checklist, and what became of it.
 *
 * PROJECT.md section G, item 8. The one thing this table is for is making a
 * failed checkbox write *survivable*: the row is created before the file is
 * touched, so a pass whose implementation was verified, committed and published
 * and whose checklist write was refused is afterwards recognisable as owing one
 * line in a Markdown file — and nothing else. Resume writes that line. It does
 * not invoke the agent, run the checks, or commit again.
 *
 * **Why the generation's columns are not enough.** A pass blocked on an
 * unwritable checklist and a pass blocked because the agent asked a question
 * are the same row in `generations`: closed, blocked, with a blocker on it.
 * Only one of them is finished by making a file writable, and guessing which
 * from a free-text blocker is not something a recovery path should do. The row
 * here says it in a column.
 *
 * **Why `pending` and `failed` are different states.** Both are a write that
 * did not happen. A `pending` row is retried unchanged — the work is done, the
 * file said no. A `failed` row must not be: what it was planned against has
 * changed, and applying it would tick a line nobody asked about. See
 * {@see ChecklistUpdateState}.
 *
 * **The write attempts are counted here and not against the task.** Spending a
 * task's attempt budget on `chmod` would eventually make a verified,
 * committed pass unfinishable for a reason that has nothing to do with the
 * work.
 */
final class ChecklistStore
{
    /**
     * Every column plus the generation's slug, which {@see ChecklistUpdate}
     * rebuilds the pass from.
     */
    private const string SELECT = 'SELECT u.*, g.slug AS slug
         FROM checklist_updates u
         JOIN generations g ON g.id = u.generation_id';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Record a write before the file is touched.
     *
     * An outstanding row planning the same write is reused rather than added
     * to: a retry is the same write being attempted again, and a second row
     * would make "is this pass still owed a checklist write" a question with
     * two answers. Its path, its run and its hashes are refreshed to the
     * attempt that is about to happen, so what the row says it was planned
     * against stays true of the write that is about to be made rather than of
     * one that was refused some runs ago.
     *
     * @param int    $runId         the run making the attempt, which on resume
     *                              is not the run that planned it
     * @param string $path          the checklist as the configuration names it
     * @param string $checklistHash the file's content hash as just read
     * @param string $taskHash      the task's content hash as just read
     *
     * @throws StateException when the generation is gone, or the row cannot be
     *                        written
     */
    public function plan(
        GenerationRecord $generation,
        int $runId,
        string $path,
        string $checklistHash,
        string $taskHash,
        ChecklistMarker $marker = ChecklistMarker::Checked,
    ): ChecklistUpdate {
        $outstanding = $this->outstandingFor($generation);

        if ($outstanding !== null && $outstanding->plans($marker)) {
            return $this->update($outstanding, [
                'run_id' => $runId,
                'path' => $path,
                'expected_hash' => $checklistHash,
                'task_hash' => $taskHash,
            ]);
        }

        $now = Timestamp::now($this->clock);

        try {
            $this->database->execute(
                'INSERT INTO checklist_updates
                     (generation_id, run_id, path, marker, state, expected_hash, task_hash,
                      attempts, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [
                    $generation->rowId(),
                    $runId,
                    $path,
                    $marker->value,
                    ChecklistUpdateState::Pending->value,
                    $checklistHash,
                    $taskHash,
                    $now->value(),
                    $now->value(),
                ],
            );
        } catch (StateException $failure) {
            throw new StateException(
                sprintf(
                    'The checklist write for %s could not be recorded: %s',
                    $generation->slug(),
                    $failure->getMessage(),
                ),
                [
                    ...$failure->hints(),
                    'The pass must exist in this same state database for its checklist write to '
                    . 'reference it.',
                ],
            );
        }

        return $this->get($this->database->lastInsertId());
    }

    /**
     * Record that the file now says what the run meant it to say.
     *
     * Counts the attempt, because an applied write was an attempt that
     * succeeded and the count is how often the file was touched.
     *
     * @param string $resultingHash the file's content hash afterwards, which
     *        for a marker that already read as planned is the hash it went in
     *        with
     *
     * @throws StateException when the row is gone, or has been superseded
     */
    public function applied(ChecklistUpdate $update, string $resultingHash): ChecklistUpdate
    {
        return $this->settle($update, ChecklistUpdateState::Applied, [
            'resulting_hash' => $resultingHash,
            'error' => null,
            'applied_at' => Timestamp::now($this->clock)->value(),
            'attempts' => $update->attempts() + 1,
        ]);
    }

    /**
     * Record a write the filesystem refused, leaving it owed.
     *
     * The state does not move: the row stays `pending`, because that is what
     * pending finalization *is*. What changes is the count and the reason, so a
     * user reading `ralph status` is told why the box is not ticked and a
     * resume knows the write has already been tried.
     *
     * @throws StateException when the row is gone, or has been superseded
     */
    public function deferred(ChecklistUpdate $update, string $error): ChecklistUpdate
    {
        return $this->settle($update, ChecklistUpdateState::Pending, [
            'error' => $error,
            'attempts' => $update->attempts() + 1,
        ]);
    }

    /**
     * Close a write that cannot be retried as planned.
     *
     * For the case the retry must not be allowed to paper over: the task was
     * reworded or removed, or the file no longer parses, so the line this plan
     * names is not the line it was made for.
     *
     * @throws StateException when the row is gone, or has been superseded
     */
    public function abandoned(ChecklistUpdate $update, string $error): ChecklistUpdate
    {
        return $this->settle($update, ChecklistUpdateState::Failed, [
            'error' => $error,
            'attempts' => $update->attempts() + 1,
        ]);
    }

    /**
     * Hand a task's outstanding writes over to a later pass.
     *
     * Called when a new generation opens for a task that already has one: the
     * new pass owns the box from then on, and an older plan left outstanding
     * would otherwise be a second claim on the same line. The rows are kept as
     * history rather than deleted.
     *
     * @return int how many plans were superseded
     *
     * @throws StateException when the rows cannot be written
     */
    public function supersede(TaskId $task, int $exceptGenerationId): int
    {
        $now = Timestamp::now($this->clock);

        return $this->database->execute(
            'UPDATE checklist_updates
                SET state = ?, updated_at = ?
              WHERE state = ?
                AND generation_id <> ?
                AND generation_id IN (
                    SELECT g.id FROM generations g
                    JOIN tasks t ON t.id = g.task_id
                    WHERE t.task_id = ?
                )',
            [
                ChecklistUpdateState::Superseded->value,
                $now->value(),
                ChecklistUpdateState::Pending->value,
                $exceptGenerationId,
                $task->value(),
            ],
        );
    }

    /**
     * The write this pass still owes, or null when it owes none.
     *
     * The reading behind pending finalization. A pass has at most one
     * outstanding plan — {@see self::plan()} reuses rather than adds — and the
     * newest is returned if an older database ever holds more.
     *
     * @throws StateException when the rows cannot be read
     */
    public function outstandingFor(GenerationRecord $generation): ?ChecklistUpdate
    {
        $rows = $this->database->query(
            self::SELECT . ' WHERE u.generation_id = ? AND u.state = ? ORDER BY u.id DESC LIMIT 1',
            [$generation->rowId(), ChecklistUpdateState::Pending->value],
        );

        return $rows === [] ? null : ChecklistUpdate::fromRow($rows[0]);
    }

    /**
     * Every write still owed, oldest first.
     *
     * What a recovery path scans: each row is a pass whose work is done and
     * whose checklist is not written.
     *
     * @return list<ChecklistUpdate>
     *
     * @throws StateException when the rows cannot be read
     */
    public function outstanding(): array
    {
        return $this->read(
            self::SELECT . ' WHERE u.state = ? ORDER BY u.id',
            [ChecklistUpdateState::Pending->value],
        );
    }

    /**
     * A pass's planned writes, oldest first.
     *
     * @return list<ChecklistUpdate>
     *
     * @throws StateException when the rows cannot be read
     */
    public function forGeneration(GenerationRecord $generation): array
    {
        return $this->read(
            self::SELECT . ' WHERE u.generation_id = ? ORDER BY u.id',
            [$generation->rowId()],
        );
    }

    /**
     * A pass's most recent planned write, whatever became of it.
     *
     * @throws StateException when the rows cannot be read
     */
    public function latestFor(GenerationRecord $generation): ?ChecklistUpdate
    {
        $rows = $this->database->query(
            self::SELECT . ' WHERE u.generation_id = ? ORDER BY u.id DESC LIMIT 1',
            [$generation->rowId()],
        );

        return $rows === [] ? null : ChecklistUpdate::fromRow($rows[0]);
    }

    /**
     * One row by its primary key.
     *
     * @throws StateException when the row is gone
     */
    public function get(int $rowId): ChecklistUpdate
    {
        $rows = $this->database->query(self::SELECT . ' WHERE u.id = ?', [$rowId]);

        if ($rows === []) {
            throw new StateException(sprintf(
                'The checklist write %d is no longer in the state database.',
                $rowId,
            ));
        }

        return ChecklistUpdate::fromRow($rows[0]);
    }

    /**
     * Move a row to a state, refusing one a later pass has taken over.
     *
     * A superseded plan is the one row a pass must not settle: the task was
     * reopened while this write was owed, and the generation that owns the box
     * now is not this one. Writing `applied` to it would claim a tick that
     * belongs to other work.
     *
     * @param array<string, scalar|null> $columns
     *
     * @throws StateException when the row is gone, or has been superseded
     */
    private function settle(
        ChecklistUpdate $update,
        ChecklistUpdateState $state,
        array $columns,
    ): ChecklistUpdate {
        $stored = $this->get($update->rowId());

        if ($stored->state()->isSuperseded()) {
            throw new StateException(
                sprintf(
                    'The checklist write for %s was superseded by a later pass at %s and cannot be settled.',
                    $stored->generation()->label(),
                    $stored->task()->value(),
                ),
                [$stored->nextAction()],
            );
        }

        return $this->update($stored, ['state' => $state->value] + $columns);
    }

    /**
     * Write columns to a row, moving `updated_at` with them.
     *
     * @param array<string, scalar|null> $columns
     *
     * @throws StateException when the row is gone, or the write is refused
     */
    private function update(ChecklistUpdate $update, array $columns): ChecklistUpdate
    {
        $columns['updated_at'] = Timestamp::now($this->clock)->value();

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($columns),
        ));

        $changed = $this->database->execute(
            sprintf('UPDATE checklist_updates SET %s WHERE id = ?', $assignments),
            [...array_values($columns), $update->rowId()],
        );

        if ($changed === 0) {
            throw new StateException(sprintf(
                'The checklist write for %s is no longer in the state database; it cannot be updated.',
                $update->generation()->label(),
            ));
        }

        return $this->get($update->rowId());
    }

    /**
     * @param list<scalar|null> $parameters
     *
     * @return list<ChecklistUpdate>
     *
     * @throws StateException when the rows cannot be read
     */
    private function read(string $sql, array $parameters): array
    {
        return array_map(
            static fn (array $row): ChecklistUpdate => ChecklistUpdate::fromRow($row),
            $this->database->query($sql, $parameters),
        );
    }
}
