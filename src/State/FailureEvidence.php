<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Support\Excerpt;
use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;
use JsonException;

/**
 * What the runner saw when an attempt did not pass, in a form the next attempt
 * can be given.
 *
 * The counterpart of {@see UnverifiedText}, and the distinction between the two
 * is the point of both. A summary is what the agent said; this is what the
 * package observed — the command that failed, the status it exited with, the
 * lines it printed. One is a claim and one is evidence, they are stored in
 * different columns and rendered under different headings, and nothing merges
 * them into a single "what happened" field that would make them
 * indistinguishable a week later.
 *
 * Each observation is a short label and an optional detail: `composer test
 * failed (exit 1)` and an excerpt of the output. The label is what a status line
 * shows; the detail is what the next attempt reads.
 *
 * **Bounded, and the bound keeps the oldest.** PROJECT.md section G, item 4
 * asks for *bounded* failure evidence to be fed forward, and the first
 * observation is the one worth keeping: the checks run in sequence and stop at
 * the first failure, so observation one is the failure and the rest are
 * context. What a bound drops is therefore the later entries, and how many were
 * dropped is carried rather than quietly lost. The full output stays in the
 * verification log, which is where a megabyte of PHPUnit output belongs — this
 * is read on every later attempt and on every `ralph status`.
 */
final class FailureEvidence
{
    /**
     * Observations kept.
     *
     * Three covers the shape a failing attempt actually has — the check that
     * failed, the checks that were therefore skipped, and one note about the
     * workspace — without the evidence section growing longer than the task it
     * is about.
     */
    public const int ENTRIES = 3;

    /**
     * Characters of a label, which has to fit on one line of a terminal.
     */
    public const int LABEL_CHARACTERS = 100;

    /**
     * Characters of a detail: enough for the tail of a failing test run.
     */
    public const int DETAIL_CHARACTERS = 360;

    /**
     * Lines of a detail.
     *
     * A character bound alone is not enough, because the detail is rendered
     * indented and every line costs six characters more than it holds: three
     * hundred characters of one-character lines would arrive nearly three times
     * its own length. Bounding the lines as well is what makes the size of a
     * written note something that can be stated rather than hoped for.
     */
    public const int DETAIL_LINES = 12;

    /**
     * How a stored observation with no label of its own is named.
     */
    public const string UNLABELLED = 'Recorded evidence';

    /**
     * @param list<array{label: string, detail: string}> $entries
     * @param int                                        $omitted observations the bound left out
     */
    private function __construct(
        private readonly array $entries,
        private readonly int $omitted,
    ) {
    }

    /**
     * No observations.
     *
     * Not a failure to record anything: an attempt the agent blocked on, or one
     * cancelled before a check ran, genuinely has nothing the runner saw.
     */
    public static function none(): self
    {
        return new self([], 0);
    }

    /**
     * One observation.
     *
     * @throws InvalidArgumentException when the label says nothing
     */
    public static function of(string $label, string $detail = ''): self
    {
        return self::none()->with($label, $detail);
    }

    /**
     * This evidence and one more observation.
     *
     * Past the bound the observation is counted and not kept, which is why this
     * does not fail: evidence arrives while a pass is going wrong, and that is
     * the worst moment to raise a second problem over the amount of the first.
     *
     * @throws InvalidArgumentException when the label says nothing
     */
    public function with(string $label, string $detail = ''): self
    {
        $entry = [
            'label' => self::oneLine($label),
            'detail' => self::detail($detail),
        ];

        if (count($this->entries) >= self::ENTRIES) {
            return new self($this->entries, $this->omitted + 1);
        }

        return new self([...$this->entries, $entry], $this->omitted);
    }

    /**
     * This evidence, also answering for observations something else left out.
     *
     * Needed where evidence is assembled out of evidence that was already
     * bounded — {@see EvidenceTrail} reading several attempts' stored
     * observations — because the count a stored set carries is as much a part
     * of it as its entries. Without this, a set that honestly said "and four
     * more" would be folded into one that says nothing of the kind, and the
     * second bound would hide what the first one disclosed.
     *
     * A count of zero or less adds nothing, so a caller folding an unbounded
     * set does not have to check first.
     */
    public function alsoOmitted(int $count): self
    {
        if ($count < 1) {
            return $this;
        }

        return new self($this->entries, $this->omitted + $count);
    }

    /**
     * Read the column back.
     *
     * @throws InvalidArgumentException when a present value holds no readable
     *                                 observation
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::fromStorage($value);
    }

    /**
     * Read stored evidence, in any of the forms the column can hold.
     *
     * Forgiving in the same way {@see Blocker::fromStorage()} is, and for the
     * same reason: this is read while reporting a failure, and refusing to show
     * a user their evidence because its shape is unexpected is the least useful
     * moment to be strict. Anything that is not the object this writes is shown
     * as prose under a generic label.
     *
     * @throws InvalidArgumentException when the value has nothing in it at all
     */
    public static function fromStorage(string $value): self
    {
        $text = trim($value);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::of(self::UNLABELLED, $text);
        }

        if (!is_array($decoded)) {
            return self::of(self::UNLABELLED, $text);
        }

        $omitted = 0;
        if (!array_is_list($decoded)) {
            $omitted = isset($decoded['omitted']) && is_int($decoded['omitted'])
                ? max(0, $decoded['omitted'])
                : 0;
            /** @var mixed $decoded */
            $decoded = $decoded['entries'] ?? [];

            if (!is_array($decoded) || !array_is_list($decoded)) {
                return self::of(self::UNLABELLED, $text);
            }
        }

        $evidence = self::none();
        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $evidence = $evidence->with(Excerpt::of($entry, self::LABEL_CHARACTERS), $entry);

                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $label = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : '';
            $detail = isset($entry['detail']) && is_string($entry['detail']) ? $entry['detail'] : '';

            if (trim($label) === '' && trim($detail) === '') {
                continue;
            }

            $evidence = $evidence->with(
                trim($label) === '' ? self::UNLABELLED : $label,
                $detail,
            );
        }

        if ($evidence->isEmpty()) {
            return self::of(self::UNLABELLED, $text);
        }

        return new self($evidence->entries, $evidence->omitted + $omitted);
    }

    /**
     * Whether the runner saw nothing worth passing on.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The observations kept, in the order they were recorded.
     *
     * @return list<array{label: string, detail: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * How many observations are being shown.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * How many the bound left out.
     */
    public function omitted(): int
    {
        return $this->omitted;
    }

    /**
     * One line for a status table: the first observation, and how many follow.
     */
    public function label(): string
    {
        if ($this->entries === []) {
            return 'no evidence recorded';
        }

        $first = $this->entries[0]['label'];
        $rest = count($this->entries) - 1 + $this->omitted;

        return $rest === 0 ? $first : sprintf('%s (+%d more)', $first, $rest);
    }

    /**
     * The observations as Markdown, for the note an attempt reads.
     *
     * Details are indented into code blocks rather than fenced, because the
     * detail is captured process output and a fence would be ended early by
     * output that contains a fence of its own.
     */
    public function toMarkdown(): string
    {
        if ($this->entries === []) {
            return '';
        }

        $lines = [];

        foreach ($this->entries as $entry) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = sprintf('- **%s**', $entry['label']);

            if ($entry['detail'] === '') {
                continue;
            }

            $lines[] = '';
            foreach (explode("\n", $entry['detail']) as $line) {
                $lines[] = $line === '' ? '' : '      ' . $line;
            }
        }

        if ($this->omitted > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d further %s not shown; the verification logs hold the whole of it.',
                $this->omitted,
                $this->omitted === 1 ? 'observation is' : 'observations are',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * What the column holds.
     *
     * An object rather than a bare list, so that the number of observations the
     * bound left out survives a round trip: evidence that says "and three more"
     * is honest, and evidence that silently shows four of seven is not.
     */
    public function toStorage(): string
    {
        return json_encode(
            ['entries' => $this->entries, 'omitted' => $this->omitted],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function equals(self $other): bool
    {
        return $this->entries === $other->entries && $this->omitted === $other->omitted;
    }

    /**
     * A label, collapsed onto one line and shortened to fit it.
     *
     * @throws InvalidArgumentException when it says nothing
     */
    private static function oneLine(string $value): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($collapsed === '') {
            throw new InvalidArgumentException('An observation must say what was seen.');
        }

        return Excerpt::of($collapsed, self::LABEL_CHARACTERS);
    }

    /**
     * A detail, bounded, keeping its line breaks.
     *
     * The *end* of the output is kept, not the start: a failing test run prints
     * its summary last, and the first few hundred characters of PHPUnit are its
     * version banner. Held to both bounds — last lines first, then characters —
     * and an elision marker says that something came before.
     */
    private static function detail(string $value): string
    {
        $text = trim($value);

        if ($text === '') {
            return '';
        }

        $lines = explode("\n", $text);
        if (count($lines) > self::DETAIL_LINES) {
            $text = '…' . "\n" . implode("\n", array_slice($lines, -self::DETAIL_LINES));
        }

        if (mb_strlen($text) <= self::DETAIL_CHARACTERS) {
            return $text;
        }

        return '…' . ltrim(mb_substr($text, -(self::DETAIL_CHARACTERS - 1)));
    }
}
