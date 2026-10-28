<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * One top-level checklist item, as the file states it.
 *
 * Deliberately a record of what was read rather than of what the runner knows:
 * `checked` is the marker in the file, not a verdict that the work was verified
 * — an item the user ticked by hand is indistinguishable here, and the
 * distinction between user-completed and runner-verified belongs to the state
 * store (PROJECT.md section C, item 5).
 *
 * The position is kept because two later pieces of work need it and neither can
 * recover it from the description: the checkbox update has to change one marker
 * and leave every other byte of the file alone (section C, item 6), and an
 * error about a task has to say which line it is on. {@see self::source()} is
 * the line as it was read, so a writer can confirm the line it is about to
 * rewrite is still the one that was parsed before it touches the file.
 */
final class ChecklistItem
{
    /**
     * @param string $description the text after the checkbox, as written
     * @param bool $checked whether the file marks the item `[x]` or `[X]`
     * @param int $line 1-based line number of the item in its source
     * @param string $source the item's line as read, without its line ending
     */
    public function __construct(
        private readonly string $description,
        private readonly bool $checked,
        private readonly int $line,
        private readonly string $source,
    ) {
    }

    /**
     * The task text, as the user wrote it.
     *
     * Only surrounding whitespace is removed; Markdown inside it is left alone,
     * because it is passed to an agent as prose and normalising it is the
     * business of task identity (PROJECT.md section C, item 4).
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Whether the file marks this item as done.
     */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /**
     * 1-based line number of the item in the checklist.
     */
    public function line(): int
    {
        return $this->line;
    }

    /**
     * The item's line exactly as read, without its line ending.
     */
    public function source(): string
    {
        return $this->source;
    }
}
