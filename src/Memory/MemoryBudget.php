<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use InvalidArgumentException;

/**
 * How much context and memory one attempt is given.
 *
 * Memory is cumulative and context is hand-written, so without a ceiling the
 * prompt grows with every attempt until the agent refuses it or — worse —
 * quietly drops the end of it, which is the half holding the most recent
 * failure. Bounding it here means the trimming is the package's decision, taken
 * in one place, and reported.
 *
 * Deliberately not a configuration setting. PROJECT.md section B, item 1 lists
 * what `config.json` carries, and these bounds are not among it: they are a
 * property of what an agent can usefully read, not of a project, and a user who
 * raised them would be tuning against a model's context window through a file
 * that is meant to describe their repository. The defaults are generous enough
 * that a context file written to the length `ralph init` asks for, plus the
 * notes from a task's own attempts, arrive whole.
 *
 * Two kinds of bound, because they fail differently. A per-note ceiling stops
 * one runaway note from crowding out every other; the shared total stops a long
 * history from crowding out the context file. Which notes survive the total is
 * {@see MemoryStore}'s rule, not this object's.
 */
final class MemoryBudget
{
    /**
     * Characters of the project context file passed to an attempt.
     *
     * Roughly fifteen pages of prose — far more than the short, specific file
     * the context template asks for, so the limit is a guard against a
     * pathological file rather than a budget a reasonable project spends.
     */
    public const int CONTEXT_CHARACTERS = 20000;

    /**
     * Notes carried forward from one scope.
     *
     * Ten is about three attempts' worth of notes at a task's attempt limit,
     * plus room for a user's own files in the project scope.
     */
    public const int NOTES = 10;

    /**
     * Characters of any single note.
     */
    public const int NOTE_CHARACTERS = 4000;

    /**
     * Characters of notes in total, across every scope.
     */
    public const int TOTAL_NOTE_CHARACTERS = 12000;

    /**
     * The smallest allowance worth spending on a note.
     *
     * Once the total is nearly gone, the next note arrives as a few opening
     * words under a heading — long enough to look like information and too
     * short to be any. Selection stops there instead, and the note is reported
     * as omitted.
     */
    public const int MINIMUM_NOTE_CHARACTERS = 200;

    /**
     * @throws InvalidArgumentException when a bound is not positive, which
     *                                  would mean an attempt is prepared with
     *                                  nothing it could ever be given
     */
    public function __construct(
        private readonly int $contextCharacters = self::CONTEXT_CHARACTERS,
        private readonly int $notes = self::NOTES,
        private readonly int $noteCharacters = self::NOTE_CHARACTERS,
        private readonly int $totalNoteCharacters = self::TOTAL_NOTE_CHARACTERS,
    ) {
        $this->rejectNonPositive('context characters', $contextCharacters);
        $this->rejectNonPositive('note count', $notes);
        $this->rejectNonPositive('note characters', $noteCharacters);
        $this->rejectNonPositive('total note characters', $totalNoteCharacters);
    }

    /**
     * Characters of the project context file an attempt is given.
     */
    public function contextCharacters(): int
    {
        return $this->contextCharacters;
    }

    /**
     * Notes carried forward from one scope.
     */
    public function notes(): int
    {
        return $this->notes;
    }

    /**
     * Characters of any single note.
     */
    public function noteCharacters(): int
    {
        return $this->noteCharacters;
    }

    /**
     * Characters of notes in total.
     */
    public function totalNoteCharacters(): int
    {
        return $this->totalNoteCharacters;
    }

    /**
     * The least a note may be given before it is not worth including.
     *
     * Capped by the configured total, so a deliberately small budget still
     * yields its one note rather than none. Read from the budget *as
     * configured* and not from what is left of it: a remainder of fifty
     * characters out of twelve thousand is a spent allowance, even though fifty
     * would have been the whole of a budget that only ever had fifty.
     */
    public function noteFloor(): int
    {
        return min(self::MINIMUM_NOTE_CHARACTERS, $this->totalNoteCharacters);
    }

    /**
     * The same bounds with a smaller total for notes.
     *
     * What lets one allowance be spent across scopes in a chosen order: the
     * loader gives the task's own history first refusal and reads the
     * project-wide notes against what is left.
     *
     * @throws InvalidArgumentException when the remaining total is not positive
     */
    public function withTotalNoteCharacters(int $characters): self
    {
        return new self(
            $this->contextCharacters,
            $this->notes,
            $this->noteCharacters,
            $characters,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'context_characters' => $this->contextCharacters,
            'notes' => $this->notes,
            'note_characters' => $this->noteCharacters,
            'total_note_characters' => $this->totalNoteCharacters,
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function rejectNonPositive(string $what, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(
                sprintf('A memory budget\'s %s must be positive, not %d.', $what, $value),
            );
        }
    }
}
