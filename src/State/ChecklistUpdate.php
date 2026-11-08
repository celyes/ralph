<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;

/**
 * One planned write to the user's checklist, and what became of it.
 *
 * A `checklist_updates` row. It exists *before* the file is touched, which is
 * the whole of PROJECT.md section G, item 8: a checkbox write that fails leaves
 * this row saying what was owed, so the pass is recognisable afterwards as one
 * that needs the file written and nothing else. The implementation and the
 * commit are already durable by then — the generation's columns hold them — and
 * nothing here is permission to produce either of them again.
 *
 * **Why the two hashes.** `expectedHash` is the checklist as it read when the
 * write was planned and `taskHash` is what the task said then. Together they
 * are what makes a retry safe to perform without re-reading the user's
 * intentions: a file or a task that has moved since is a reconciliation rather
 * than a retry, and the row carries enough to tell the two apart. Both are read
 * before the write is made — {@see self::describes()} guards the bytes the
 * write is derived from and {@see self::stillDescribes()} the task it was
 * earned by — so an edit that arrives in between is preserved rather than
 * overwritten and the write is refused (section H, item 8).
 *
 * **What it is not.** It is not where the tick's justification lives — that is
 * {@see \Celyes\RalphPhp\Run\CompletionEvidence}, which collects the checks,
 * the commit and the publication as one answer. This row says only which line
 * of which file the run meant to change, and whether it managed to.
 */
final class ChecklistUpdate
{
    private function __construct(
        private readonly int $rowId,
        private readonly int $generationId,
        private readonly TaskGeneration $generation,
        private readonly int $runId,
        private readonly string $path,
        private readonly ChecklistMarker $marker,
        private readonly ChecklistUpdateState $state,
        private readonly string $expectedHash,
        private readonly string $taskHash,
        private readonly ?string $resultingHash,
        private readonly int $attempts,
        private readonly ?string $error,
        private readonly Timestamp $createdAt,
        private readonly Timestamp $updatedAt,
        private readonly ?Timestamp $appliedAt,
    ) {
    }

    /**
     * Read a row.
     *
     * The generation comes back from the joined `slug` for the reason
     * {@see GenerationRecord::fromRow()} does the same: the slug is what names
     * the branch and the log directory, so reading it through
     * {@see TaskGeneration::fromSlug()} catches a row that disagrees with what
     * the runner would produce here rather than in Git.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when a column holds something the
     *                                  package never wrote
     */
    public static function fromRow(array $row): self
    {
        return new self(
            self::integer($row, 'id'),
            self::integer($row, 'generation_id'),
            TaskGeneration::fromSlug(self::string($row, 'slug')),
            self::integer($row, 'run_id'),
            self::string($row, 'path'),
            ChecklistMarker::fromStorage(self::string($row, 'marker')),
            ChecklistUpdateState::fromStorage(self::string($row, 'state')),
            self::string($row, 'expected_hash'),
            self::string($row, 'task_hash'),
            self::nullableString($row, 'resulting_hash'),
            self::integer($row, 'attempts'),
            self::nullableString($row, 'error'),
            Timestamp::fromStorage(self::string($row, 'created_at')),
            Timestamp::fromStorage(self::string($row, 'updated_at')),
            Timestamp::recorded(self::nullableString($row, 'applied_at')),
        );
    }

    /**
     * The integer primary key, for the writes that settle this row.
     */
    public function rowId(): int
    {
        return $this->rowId;
    }

    /**
     * The generation row this write belongs to.
     */
    public function generationId(): int
    {
        return $this->generationId;
    }

    /**
     * The pass this write would finish.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * The task whose box this is.
     */
    public function task(): TaskId
    {
        return $this->generation->task();
    }

    /**
     * The run that planned the write.
     *
     * Kept because resume may be a different run altogether, and which run
     * planned a write that never landed is part of reading what happened.
     */
    public function runId(): int
    {
        return $this->runId;
    }

    /**
     * The checklist the write is to, as the configuration names it.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * What the write is aiming for.
     */
    public function marker(): ChecklistMarker
    {
        return $this->marker;
    }

    public function state(): ChecklistUpdateState
    {
        return $this->state;
    }

    /**
     * The checklist's content hash when the write was planned.
     */
    public function expectedHash(): string
    {
        return $this->expectedHash;
    }

    /**
     * The task's content hash when the write was planned.
     */
    public function taskHash(): string
    {
        return $this->taskHash;
    }

    /**
     * The checklist's content hash after the write landed, or null while it
     * has not.
     */
    public function resultingHash(): ?string
    {
        return $this->resultingHash;
    }

    /**
     * How many times the write has been attempted.
     *
     * Not the task's attempt counter and deliberately separate from it: writing
     * a file the user has made read-only must not spend the budget the agent's
     * work is measured against.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Why the last attempt at the write did not land, or null when none failed.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    public function createdAt(): Timestamp
    {
        return $this->createdAt;
    }

    public function updatedAt(): Timestamp
    {
        return $this->updatedAt;
    }

    /**
     * When the file came to say what the run meant it to say.
     */
    public function appliedAt(): ?Timestamp
    {
        return $this->appliedAt;
    }

    /**
     * Whether the write is still owed.
     *
     * The reading that makes a pass *pending finalization*: taken together with
     * {@see GenerationRecord::isFinalizable()} it says the work is done and the
     * checklist is not written.
     */
    public function isOutstanding(): bool
    {
        return $this->state->isOutstanding();
    }

    /**
     * Whether the file says what the run meant it to say.
     */
    public function isApplied(): bool
    {
        return $this->state->isApplied();
    }

    /**
     * Whether nothing further will be done about this row.
     */
    public function isSettled(): bool
    {
        return $this->state->isSettled();
    }

    /**
     * Whether the write was attempted and refused at least once.
     *
     * An outstanding row with no attempts behind it was planned and interrupted
     * before the file was touched; one with attempts behind it met a filesystem
     * that said no. Both are retried, and only the second has something to
     * report about why it is still owed.
     */
    public function wasRefused(): bool
    {
        return $this->isOutstanding() && $this->attempts > 0;
    }

    /**
     * Whether the checklist still reads as this row was planned against.
     *
     * The byte-level half of PROJECT.md section H, item 8. A write is derived
     * from the file it was read from, so the reading the row holds is what the
     * write may safely be applied to; a file that hashes to something else has
     * been edited since, and the answer decides between applying the planned
     * write and reading the file again. What it must not decide on its own is
     * whether to refuse: an edit elsewhere in the document is the user's and is
     * preserved by writing the box against the file as it now reads. The edit
     * that does refuse a write is one to the task itself, which
     * {@see self::stillDescribes()} answers.
     */
    public function describes(string $checklistHash): bool
    {
        return $this->expectedHash === $checklistHash;
    }

    /**
     * Whether the task still says what it said when the write was planned.
     *
     * The other check, and the one that closes a plan rather than retrying it:
     * a task reworded or replaced on that line is not the task this write was
     * earned by, and ticking its box would mark work done that nobody verified.
     * Compared against {@see \Celyes\RalphPhp\Tasks\TaskSnapshot::hash()},
     * which ignores formatting, so reflowing a task's details is not an edit
     * for this purpose.
     */
    public function stillDescribes(string $taskHash): bool
    {
        return $this->taskHash === $taskHash;
    }

    /**
     * Whether this row plans the same write as the one described.
     *
     * What keeps a retry from inserting a second plan beside the first. The
     * marker alone decides it: the plan is "set this task's box this way in the
     * project's checklist", and where that checklist lives is the
     * configuration's answer rather than part of the write's identity. A run
     * started after the `tasks` setting moved is still the same write owed.
     */
    public function plans(ChecklistMarker $marker): bool
    {
        return $this->marker === $marker;
    }

    /**
     * One line for a status table or a log.
     */
    public function describe(): string
    {
        $described = sprintf(
            '%s: %s in %s — %s',
            $this->generation->label(),
            $this->marker->describe(),
            $this->path,
            $this->state->describe(),
        );

        if ($this->error !== null && !$this->state->isApplied()) {
            $described .= sprintf(' (%s)', $this->error);
        }

        return $described;
    }

    /**
     * What is left to do about this write.
     */
    public function nextAction(): string
    {
        return $this->state->nextAction();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation->slug(),
            'task' => $this->task()->value(),
            'run' => $this->runId,
            'path' => $this->path,
            'marker' => $this->marker->value,
            'state' => $this->state->value,
            'expected_hash' => $this->expectedHash,
            'task_hash' => $this->taskHash,
            'resulting_hash' => $this->resultingHash,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'created_at' => $this->createdAt->value(),
            'updated_at' => $this->updatedAt->value(),
            'applied_at' => $this->appliedAt?->value(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the column is absent or not text
     */
    private static function string(array $row, string $column): string
    {
        $value = self::nullableString($row, $column);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'A stored checklist update has no %s.',
                $column,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'The %s of a stored checklist update is not text.',
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

        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new InvalidArgumentException(sprintf(
                'The %s of a stored checklist update is not a number.',
                $column,
            ));
        }

        return (int) $value;
    }
}
