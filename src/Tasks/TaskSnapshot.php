<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * A task exactly as it read when the runner picked it up.
 *
 * Taken before an agent is invoked and kept for as long as the task is being
 * worked on, so that when the work comes back the runner can ask the question
 * PROJECT.md section C, item 7 insists on: is this still the task that was
 * asked for? The checklist is a file the user owns and may edit at any moment —
 * including while the agent is running — and progress produced against the old
 * wording must not be quietly recorded against the new one.
 *
 * Two things are compared, and they answer different questions:
 *
 * - the {@see TaskId}, which comes from the description alone, so an edit to
 *   the description makes this a *different* task rather than a changed one;
 * - the {@see self::contentHash()}, which also covers the indented details,
 *   so an edit to the implementation notes or acceptance criteria under a task
 *   is detected even though its identity is untouched.
 *
 * The hash is the "task content hash" PROJECT.md section D, item 4 persists, so
 * its recipe is a promise rather than an implementation detail: SHA-256 of the
 * normalised description, a newline, and the normalised details. Both halves go
 * through {@see TaskBody}, which collapses whitespace and folds case, so
 * re-indenting a bullet list, rewrapping a long line or sentence-casing a note
 * is not reported as changed work — while adding, removing or rewording a
 * requirement is. The newline between them cannot occur inside either half,
 * which is what keeps "a" + "b" from hashing like "a b" + "". A literal
 * expected value is pinned by a test, because stored hashes outlive the
 * process.
 *
 * The line number is recorded but deliberately not compared. A task that moved
 * because the user reordered the checklist, or because an item above it was
 * ticked, is the same task at a new address; identity ignores position and so
 * does this.
 */
final class TaskSnapshot
{
    private function __construct(
        private readonly TaskId $id,
        private readonly string $description,
        private readonly string $details,
        private readonly string $contentHash,
        private readonly int $line,
        private readonly bool $checked,
    ) {
    }

    /**
     * Record a task as it stands.
     */
    public static function of(Task $task): self
    {
        return new self(
            $task->id(),
            $task->description(),
            $task->details(),
            self::hash($task->description(), $task->details()),
            $task->line(),
            $task->isChecked(),
        );
    }

    /**
     * The content hash of a description and the details written under it.
     *
     * Public so that the state store can recompute a stored task's hash without
     * owning the recipe, and so a test can pin it.
     */
    public static function hash(string $description, string $details = ''): string
    {
        return hash(
            'sha256',
            TaskBody::of($description)->normalized() . "\n" . TaskBody::of($details)->normalized(),
        );
    }

    /**
     * The task's stable identifier.
     */
    public function id(): TaskId
    {
        return $this->id;
    }

    /**
     * The description as the checklist stated it when the run started.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * The details as the checklist stated them when the run started.
     */
    public function details(): string
    {
        return $this->details;
    }

    /**
     * The hash of the description and details together.
     */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * 1-based line the task was read from.
     *
     * Where it was, not where it has to be: see the class note on reordering.
     */
    public function line(): int
    {
        return $this->line;
    }

    /**
     * Whether the checklist marked the task done when the snapshot was taken.
     */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /**
     * Whether a task read later states the same work, details included.
     *
     * The marker is not part of this: whether someone ticked the item while it
     * ran is a separate finding with a separate remedy.
     */
    public function statesSameWork(Task $task): bool
    {
        return $this->id->equals($task->id())
            && $this->contentHash === self::hash($task->description(), $task->details());
    }

    /**
     * Whether a task read later is identical to this reading in every respect
     * this class compares — the work and the marker.
     */
    public function matches(Task $task): bool
    {
        return $this->statesSameWork($task) && $this->checked === $task->isChecked();
    }
}
