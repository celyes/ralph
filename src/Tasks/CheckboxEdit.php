<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * What a checkbox update did: the document after it, and whether it moved.
 *
 * Two outcomes, and the second is not a failure. Setting a marker that already
 * reads the way it was asked to leaves the document untouched and reports
 * {@see self::isChanged()} as false, which is what resume depends on: a run
 * that crashed between ticking the box and recording that it had must be able
 * to ask for the tick again and be told the checklist already says so, rather
 * than meeting an error it cannot tell apart from a real one (PROJECT.md
 * section G, item 8).
 *
 * The line number is reported because it is the line the marker was found on
 * *now*, which need not be the line the task was parsed on earlier — identity
 * survives reordering, so a task that moved is still found and the runner's log
 * should name where it actually is.
 */
final class CheckboxEdit
{
    private function __construct(
        private readonly string $markdown,
        private readonly TaskId $id,
        private readonly int $line,
        private readonly bool $checked,
        private readonly bool $changed,
    ) {
    }

    /**
     * The marker was rewritten; the document differs by those bytes alone.
     */
    public static function applied(string $markdown, TaskId $id, int $line, bool $checked): self
    {
        return new self($markdown, $id, $line, $checked, true);
    }

    /**
     * The marker already read this way; the document is unchanged.
     */
    public static function unchanged(string $markdown, TaskId $id, int $line, bool $checked): self
    {
        return new self($markdown, $id, $line, $checked, false);
    }

    /**
     * The checklist after the update.
     *
     * Identical to the document that went in when nothing changed.
     */
    public function markdown(): string
    {
        return $this->markdown;
    }

    /**
     * The task whose marker was asked about.
     */
    public function taskId(): TaskId
    {
        return $this->id;
    }

    /**
     * 1-based line the marker was found on.
     */
    public function line(): int
    {
        return $this->line;
    }

    /**
     * What the marker says now: ticked, or still open.
     */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /**
     * Whether this update altered the document.
     */
    public function isChanged(): bool
    {
        return $this->changed;
    }
}
