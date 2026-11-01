<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

/**
 * The notes one scope contributed, and how many it had.
 *
 * The count matters as much as the notes. "Bounded" means older notes are left
 * out, and a prompt that quietly showed three of a task's eleven notes would
 * leave both the agent and the user reading a run's output unable to tell a
 * task with a short history from one whose history was trimmed. The files are
 * all still on disk; this is what lets the rendering say so.
 */
final class MemorySelection
{
    /**
     * @param list<MemoryNote> $notes oldest first
     * @param int $available notes the scope's directory holds
     */
    public function __construct(
        private readonly MemoryScope $scope,
        private readonly array $notes,
        private readonly int $available,
    ) {
    }

    /**
     * A scope with nothing recorded for it yet.
     */
    public static function empty(MemoryScope $scope): self
    {
        return new self($scope, [], 0);
    }

    public function scope(): MemoryScope
    {
        return $this->scope;
    }

    /**
     * The selected notes, oldest first.
     *
     * @return list<MemoryNote>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Notes the scope holds, including the ones not selected.
     */
    public function available(): int
    {
        return $this->available;
    }

    /**
     * Notes left out of the selection.
     */
    public function omitted(): int
    {
        return max(0, $this->available - count($this->notes));
    }

    public function isEmpty(): bool
    {
        return $this->notes === [];
    }

    /**
     * Characters of note text in the selection.
     */
    public function length(): int
    {
        return array_sum(array_map(
            static fn (MemoryNote $note): int => $note->length(),
            $this->notes,
        ));
    }
}
