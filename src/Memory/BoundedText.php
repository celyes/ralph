<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

/**
 * A piece of text cut down to a character budget, which remembers that it was.
 *
 * Context and memory are the two inputs to an attempt whose size the package
 * does not control: a project's `CONTEXT.md` can be any length a user cares to
 * write, and the memory directory grows by a note every attempt. Both are read
 * in full on every attempt, so both need a ceiling — and the ceiling has to be
 * visible, because a prompt that silently lost the second half of a convention
 * is worse than one that says it did.
 *
 * Distinct from {@see \Celyes\RalphPhp\Support\Excerpt}, which shortens a line to
 * quote inside an error message. This keeps a multi-line document readable: it cuts on a line
 * boundary when one is near enough to the limit, so a trimmed note ends after a
 * sentence rather than in the middle of a word, and it never cuts inside a
 * multi-byte character.
 */
final class BoundedText
{
    private function __construct(
        private readonly string $text,
        private readonly int $originalLength,
        private readonly bool $trimmed,
    ) {
    }

    /**
     * Text held to a character budget.
     *
     * A limit of zero or less yields no text at all, which is what a caller
     * that has already spent its whole allowance means; it is not an error,
     * because the allowance runs out in the ordinary course of filling a
     * budget from the newest note backwards.
     */
    public static function of(string $text, int $limit): self
    {
        $length = mb_strlen($text);

        if ($length <= $limit) {
            return new self($text, $length, false);
        }

        if ($limit <= 0) {
            return new self('', $length, true);
        }

        $cut = mb_substr($text, 0, $limit);

        // Back off to the last line break, but only when doing so keeps at
        // least half the budget: a note whose first line is longer than the
        // whole allowance would otherwise be trimmed away to nothing.
        $break = mb_strrpos($cut, "\n");
        if ($break !== false && $break >= intdiv($limit, 2)) {
            $cut = mb_substr($cut, 0, $break);
        }

        return new self(rtrim($cut), $length, true);
    }

    /**
     * The text as it should be used.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * Characters of text this holds.
     */
    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * Characters the source had before trimming.
     */
    public function originalLength(): int
    {
        return $this->originalLength;
    }

    /**
     * Whether anything was left out.
     */
    public function wasTrimmed(): bool
    {
        return $this->trimmed;
    }

    /**
     * Whether there is nothing here worth passing on.
     */
    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * A sentence saying what was left out, or null when nothing was.
     *
     * Rendered next to the text rather than appended to it, so what the agent
     * reads as the user's instructions stays the user's own words.
     */
    public function notice(): ?string
    {
        if (!$this->trimmed) {
            return null;
        }

        return sprintf(
            'Trimmed: %d of %d characters shown.',
            $this->length(),
            $this->originalLength,
        );
    }
}
