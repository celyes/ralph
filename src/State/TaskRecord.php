<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use InvalidArgumentException;

/**
 * One row of `tasks`, read back as a value.
 *
 * The task-side half of PROJECT.md section D, item 4: what the checklist said
 * about this task the last time it was read, hashed so an edit is noticed, and
 * when it was first seen, last seen and last changed.
 *
 * The three timestamps answer three different questions and none of them can be
 * derived from the others:
 *
 * - **First seen** is when the task entered the project. It never changes, and
 *   it is what makes a status table orderable by age rather than by file
 *   position.
 * - **Last seen** is the most recent run that read this task out of the
 *   checklist. It moves every time, including when nothing about the task
 *   changed, and it is what identifies a task that has *stopped* appearing — a
 *   row whose last_seen is older than the current reading is a task the user
 *   deleted, which PROJECT.md section C, item 7 requires be noticed rather than
 *   have old progress applied to it.
 * - **Updated** is when the content hash or the completion last changed. It is
 *   the one a user wants when they ask what moved.
 *
 * There is deliberately no lifecycle state here. A reopened task has a finished
 * pass and a running one at the same time, and one column could not say both;
 * state belongs to {@see GenerationRecord}.
 */
final class TaskRecord
{
    private function __construct(
        private readonly int $rowId,
        private readonly TaskId $id,
        private readonly string $description,
        private readonly string $details,
        private readonly string $contentHash,
        private readonly Completion $completion,
        private readonly Timestamp $firstSeenAt,
        private readonly Timestamp $lastSeenAt,
        private readonly Timestamp $updatedAt,
    ) {
    }

    /**
     * Read a row.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when a column holds something the
     *                                 package never wrote
     */
    public static function fromRow(array $row): self
    {
        return new self(
            self::integer($row, 'id'),
            TaskId::fromString(self::string($row, 'task_id')),
            self::string($row, 'description'),
            self::text($row, 'details'),
            self::string($row, 'content_hash'),
            self::completionOf(self::string($row, 'completion')),
            Timestamp::fromStorage(self::string($row, 'first_seen_at')),
            Timestamp::fromStorage(self::string($row, 'last_seen_at')),
            Timestamp::fromStorage(self::string($row, 'updated_at')),
        );
    }

    /**
     * The integer primary key, for the tables that reference a task.
     */
    public function rowId(): int
    {
        return $this->rowId;
    }

    public function id(): TaskId
    {
        return $this->id;
    }

    /**
     * The task text as the checklist last stated it.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * The implementation notes written under it, empty when there are none.
     */
    public function details(): string
    {
        return $this->details;
    }

    /**
     * The hash of the description and details together.
     *
     * {@see TaskSnapshot::hash()} is the recipe; this is what it produced the
     * last time the task was read.
     */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Whether a task read now states the same work this row recorded.
     *
     * Identity is not compared, because it cannot differ: a task whose
     * description changed has a different {@see TaskId} and is a different row.
     * What this catches is an edit to the details underneath it.
     */
    public function statesSameWork(TaskSnapshot $snapshot): bool
    {
        return $this->contentHash === $snapshot->contentHash();
    }

    /**
     * Whether a task read now has had its details edited since this reading.
     */
    public function hasChangedFrom(Task $task): bool
    {
        return $this->contentHash !== TaskSnapshot::hash($task->description(), $task->details());
    }

    /**
     * Where this task's completion came from: nowhere yet, the user's
     * checklist, or a verified run.
     */
    public function completion(): Completion
    {
        return $this->completion;
    }

    /**
     * Whether Ralph PHP carried this task through itself.
     */
    public function isVerified(): bool
    {
        return $this->completion === Completion::RunnerVerified;
    }

    /**
     * Whether a run should pick this task up.
     */
    public function isPending(): bool
    {
        return $this->completion->isPending();
    }

    /**
     * When the task first appeared in the checklist.
     */
    public function firstSeenAt(): Timestamp
    {
        return $this->firstSeenAt;
    }

    /**
     * When the task was last read out of the checklist.
     */
    public function lastSeenAt(): Timestamp
    {
        return $this->lastSeenAt;
    }

    /**
     * When its content or completion last changed.
     */
    public function updatedAt(): Timestamp
    {
        return $this->updatedAt;
    }

    /**
     * Whether this task was absent from a reading taken at the given moment.
     *
     * The test for a task the user removed: every task present in a reading has
     * its last_seen set to that reading's moment, so a row left behind is one
     * the checklist no longer states.
     */
    public function wasAbsentFrom(Timestamp $reading): bool
    {
        return $this->lastSeenAt->isBefore($reading);
    }

    /**
     * One line for a status table.
     */
    public function describe(): string
    {
        return sprintf('%s %s [%s]', $this->id->value(), $this->description, $this->completion->label());
    }

    /**
     * @throws InvalidArgumentException when the stored value is not one this
     *                                 package writes, which would otherwise be
     *                                 a bare ValueError naming an enum the user
     *                                 has never heard of
     */
    private static function completionOf(string $value): Completion
    {
        return Completion::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a task completion; the stored value must be one of %s.',
            $value,
            implode(', ', Schema::completions()),
        ));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the column is absent or empty
     */
    private static function string(array $row, string $column): string
    {
        $value = self::text($row, $column);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('A stored task has no %s.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return '';
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'The %s of a stored task is not text.',
                $column,
            ));
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('The %s of a stored task is not a number.', $column));
    }
}
