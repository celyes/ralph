<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

/**
 * Something a model wrote, kept short and stripped of anything that claims a
 * result.
 *
 * An agent's own account of what it did is worth keeping: it is the one cheap
 * thing to put in front of the next attempt, and it is often right. It is also
 * the one piece of text in the package that nothing checked. So it is held
 * apart from the record of what ran — {@see \Celyes\RalphPhp\State\FailureEvidence}
 * is the half the runner observed — and it passes through here first.
 *
 * Two things happen on the way in.
 *
 * **Checkbox markers are defused.** A line reading `- [x] parser written, tests
 * pass` is the single sentence most likely to make the next attempt do nothing,
 * and in a package whose completion signal *is* a Markdown checkbox it is also
 * the shape most likely to be mistaken for one. The marker is replaced by a word
 * that says whose claim it is, keeping the sense the marker carried: a ticked
 * box becomes `claimed done:` and an unticked one `claimed outstanding:`. The
 * text is otherwise passed through as written — this is not an attempt to judge
 * what the agent said, only to stop the syntax of a verified result being
 * reused for an unverified one.
 *
 * **It is bounded.** A summary read on every later attempt has to stay a
 * summary; an agent that writes an essay has the whole of it in the task log,
 * where length costs nothing.
 *
 * Deliberately in `Support` rather than in `Memory` or `State`: the same text
 * is written to a memory note and to the `attempts.summary` column, so both
 * sides need it and neither owns it.
 */
final class UnverifiedText
{
    /**
     * Characters of a claim that are kept.
     *
     * Chosen against {@see \Celyes\RalphPhp\Memory\MemoryBudget::NOTE_CHARACTERS}:
     * a claim, the evidence beside it and the headings over both have to fit in
     * one note, so that a note the package writes is never trimmed when the
     * next attempt reads it back. A test pins the sum.
     */
    public const int LIMIT = 800;

    /**
     * What replaces a ticked box.
     */
    public const string DONE = 'claimed done:';

    /**
     * What replaces a box that is not ticked.
     */
    public const string OUTSTANDING = 'claimed outstanding:';

    /**
     * A checkbox item, with or without the bullet in front of it.
     *
     * The marker is one character at most, which is what keeps an ordinary
     * Markdown link at the start of a line — `[see the README](...)` — from
     * being read as a checkbox.
     */
    private const string CHECKBOX = '/^([ \t]*)(?:([-*+]|\d{1,9}[.)])[ \t]+)?\[([^\[\]]?)\][ \t]*/mu';

    /**
     * The same, matched a byte at a time.
     *
     * Used when the first fails, which it does on invalid UTF-8 — and this text
     * came out of another process's output, so that is reachable rather than
     * theoretical. Checkbox syntax is ASCII either way, so the fallback defuses
     * exactly the same lines; what it loses is the guarantee that a cut lands
     * between characters, and a cut is not what this pattern does.
     */
    private const string CHECKBOX_BYTES = '/^([ \t]*)(?:([-*+]|\d{1,9}[.)])[ \t]+)?\[([^\[\]]?)\][ \t]*/m';

    private function __construct(
        private readonly string $text,
        private readonly int $originalLength,
        private readonly int $defused,
        private readonly bool $trimmed,
    ) {
    }

    /**
     * Take in what a model wrote.
     *
     * A limit of zero or less is not accepted as "keep nothing": the caller
     * that wants nothing kept passes no text at all, and silently dropping a
     * claim is how evidence disappears. Anything up to the limit is kept as
     * written.
     */
    public static function of(string $text, int $limit = self::LIMIT): self
    {
        [$defused, $count] = self::defuse(trim($text));
        $length = mb_strlen($defused);
        $ceiling = max(1, $limit);

        if ($length <= $ceiling) {
            return new self($defused, $length, $count, false);
        }

        return new self(
            rtrim(mb_substr($defused, 0, $ceiling - 1)) . '…',
            $length,
            $count,
            true,
        );
    }

    /**
     * A claim that was not made.
     *
     * An agent that returns nothing but an exit code has said nothing, and a
     * note with an empty "what the agent reported" section under a heading
     * reads as though something was lost.
     */
    public static function none(): self
    {
        return new self('', 0, 0, false);
    }

    /**
     * Read the column back.
     *
     * Stored text has already been defused, and running it through again
     * changes nothing: the replacements leave no checkbox behind.
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::of($value);
    }

    /**
     * The claim, as it may be shown.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * Whether there is nothing here worth a heading.
     */
    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /**
     * Characters kept.
     */
    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * Characters the model wrote, before the limit was applied.
     */
    public function originalLength(): int
    {
        return $this->originalLength;
    }

    /**
     * Whether the claim was cut short.
     */
    public function wasTrimmed(): bool
    {
        return $this->trimmed;
    }

    /**
     * How many checkbox markers were replaced.
     */
    public function defusedMarkers(): int
    {
        return $this->defused;
    }

    /**
     * Whether anything was done to the text on the way in.
     */
    public function wasAltered(): bool
    {
        return $this->trimmed || $this->defused > 0;
    }

    /**
     * A sentence saying what was done to the text, or null when nothing was.
     *
     * Shown next to the claim rather than folded into it, so what is presented
     * as the agent's words stays the agent's words.
     */
    public function notice(): ?string
    {
        $parts = [];

        if ($this->defused > 0) {
            $parts[] = sprintf(
                '%d checkbox %s replaced: a tick here is a claim, not a result',
                $this->defused,
                $this->defused === 1 ? 'marker was' : 'markers were',
            );
        }

        if ($this->trimmed) {
            $parts[] = sprintf(
                'trimmed to %d of %d characters; the whole of it is in the task log',
                $this->length(),
                $this->originalLength,
            );
        }

        if ($parts === []) {
            return null;
        }

        return ucfirst(implode('; ', $parts)) . '.';
    }

    /**
     * What the column holds: the defused, bounded text.
     */
    public function toStorage(): string
    {
        return $this->text;
    }

    public function equals(self $other): bool
    {
        return $this->text === $other->text;
    }

    /**
     * Replace every checkbox marker, counting as it goes.
     *
     * @return array{string, int} the defused text and how many markers it had
     */
    private static function defuse(string $text): array
    {
        $count = 0;
        $replace = static function (array $match): string {
            $marker = strtolower($match[3]);

            return $match[1]
                . ($match[2] === '' ? '-' : $match[2])
                . ' '
                . ($marker === 'x' ? self::DONE : self::OUTSTANDING)
                . ' ';
        };

        $defused = preg_replace_callback(self::CHECKBOX, $replace, $text, -1, $count);

        if ($defused === null) {
            // The Unicode pattern refused the subject, which for text out of
            // another process means invalid UTF-8 in it. Matched by bytes
            // instead: defusing the markers matters more than reading the text
            // as characters, and a marker is ASCII.
            $defused = preg_replace_callback(self::CHECKBOX_BYTES, $replace, $text, -1, $count);
        }

        // Both refused, which leaves a failure of the engine itself — the
        // backtrack limit on a pathological subject. The text is kept rather
        // than dropped: a claim nobody can read is still better than a claim
        // nobody can read *and* no note saying there was one.
        return [$defused ?? $text, $count];
    }
}
