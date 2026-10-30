<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * A parsed task checklist: its top-level items, in file order.
 *
 * Immutable and free of I/O. A checklist with no items is a valid result and
 * not an error — a freshly initialised project has exactly that — so deciding
 * whether there is anything to do is left to the runner, which is also the only
 * thing that can say whether "nothing pending" means finished or empty.
 */
final class Checklist
{
    /**
     * @param list<ChecklistItem> $items in the order they appear in the source
     * @param string|null $source absolute path of the file read, null when parsed from a string
     */
    public function __construct(
        private readonly array $items,
        private readonly ?string $source = null,
    ) {
    }

    /**
     * Every top-level item, checked and unchecked alike, in file order.
     *
     * @return list<ChecklistItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * The file these items were read from, or null when they were parsed from a
     * string.
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * How many top-level items the checklist has.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Whether the checklist states no items at all.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * How to name this checklist at the start of a message about it.
     */
    public function subject(): string
    {
        return self::describe($this->source);
    }

    /**
     * How to name a checklist read from the given path at the start of a
     * message about it.
     *
     * Here rather than in each thing that reports a problem, so an error raised
     * while parsing and an error raised while identifying tasks name the same
     * document the same way — and so a checklist parsed from a string, which
     * has no path to print, is described without a caller having to remember
     * that it might not have one.
     */
    public static function describe(?string $source): string
    {
        return $source === null ? 'The task checklist' : 'The task checklist ' . $source;
    }
}
