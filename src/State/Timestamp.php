<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A moment as the state store writes it down.
 *
 * SQLite has no date type, so every timestamp column is text, and the spelling
 * of that text is a promise rather than an implementation detail: rows written
 * by this build are read back by the next one, sorted by SQLite with a plain
 * string comparison, and read by a user running `sqlite3` by hand. All three
 * depend on the same two properties — that the text sorts chronologically, and
 * that it says what timezone it is in.
 *
 * The form is `2026-11-01T09:30:00+00:00`: ISO-8601, always UTC, always with
 * the offset present. It is exactly what `gmdate('c')` produces, which is what
 * {@see Migrator} has been writing into the migration ledger since the schema
 * was created, so there is one format in the database rather than two. A
 * literal expected value is pinned by a test.
 *
 * **Seconds, no fractions.** Two rows written in the same second are ordinary —
 * an attempt starting and its first check beginning — and the order between
 * them is never read off a timestamp. It comes from the counters the schema
 * carries for exactly this purpose: `attempts.number` within a generation,
 * `verification_results.position` within an attempt. Precision that is not
 * relied on is precision that invites being relied on.
 *
 * **Durations are not stored as a pair of timestamps.** `duration_ms` columns
 * are measured by the runner with a monotonic reading, because a wall clock that
 * is corrected by NTP mid-check would otherwise report a command as having
 * taken a negative amount of time. These timestamps say *when*; the duration
 * columns say *how long*.
 */
final class Timestamp
{
    /**
     * The format every stored timestamp is written in.
     *
     * `P` rather than a literal `Z`, to match the ledger rows already in
     * existing databases. Both are legal ISO-8601 and {@see self::fromStorage()}
     * reads either; only this one is produced.
     */
    public const string FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * The same moment with a `Z` suffix, which {@see self::fromStorage()}
     * accepts.
     *
     * Not produced, only read: a user who edits a row by hand, or a future
     * version that changes its mind, should not make the database unreadable.
     */
    private const string ZULU_FORMAT = 'Y-m-d\TH:i:s\Z';

    private function __construct(private readonly DateTimeImmutable $moment)
    {
    }

    /**
     * The moment a clock is showing.
     */
    public static function now(Clock $clock): self
    {
        return self::of($clock->now());
    }

    /**
     * A given moment, truncated to the second and moved to UTC.
     */
    public static function of(DateTimeImmutable $moment): self
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));

        return new self($utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        ));
    }

    /**
     * Read a timestamp back from a column.
     *
     * Strict about the shape and forgiving about nothing else: a value that is
     * not one of the two accepted spellings is refused rather than guessed at,
     * because a timestamp read wrongly is a history that silently reorders
     * itself.
     *
     * @throws InvalidArgumentException when the text is not a stored timestamp
     */
    public static function fromStorage(string $value): self
    {
        $candidate = trim($value);

        foreach ([self::FORMAT, self::ZULU_FORMAT] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $candidate, new DateTimeZone('UTC'));

            if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $candidate) {
                return new self($parsed->setTimezone(new DateTimeZone('UTC')));
            }
        }

        throw new InvalidArgumentException(sprintf(
            '"%s" is not a stored timestamp; expected a value like "2026-11-01T09:30:00+00:00".',
            $value,
        ));
    }

    /**
     * Read a nullable column.
     *
     * The nullable timestamps are the ones that answer "has this happened yet?"
     * — `finished_at` on a pass still running, `applied_at` on a checklist
     * write still pending — so null is a fact rather than missing data and is
     * passed through as one.
     *
     * @throws InvalidArgumentException when a present value is not a timestamp
     */
    public static function recorded(?string $value): ?self
    {
        return $value === null ? null : self::fromStorage($value);
    }

    /**
     * The text a column holds.
     */
    public function value(): string
    {
        return $this->moment->format(self::FORMAT);
    }

    /**
     * The moment itself, in UTC.
     */
    public function moment(): DateTimeImmutable
    {
        return $this->moment;
    }

    /**
     * Seconds since the epoch, for arithmetic.
     */
    public function epochSeconds(): int
    {
        return $this->moment->getTimestamp();
    }

    public function equals(self $other): bool
    {
        return $this->epochSeconds() === $other->epochSeconds();
    }

    public function isBefore(self $other): bool
    {
        return $this->epochSeconds() < $other->epochSeconds();
    }

    public function isAfter(self $other): bool
    {
        return $this->epochSeconds() > $other->epochSeconds();
    }

    /**
     * Whether this moment is at or after another.
     *
     * The comparison the time budget of PROJECT.md section H, item 2 is checked
     * with, where "exactly at the deadline" has to count as reached.
     */
    public function hasReached(self $other): bool
    {
        return !$this->isBefore($other);
    }

    /**
     * Whole seconds from this moment to a later one, negative if it is earlier.
     */
    public function secondsUntil(self $other): int
    {
        return $other->epochSeconds() - $this->epochSeconds();
    }

    /**
     * The same moment, moved forward.
     *
     * @throws InvalidArgumentException when moved backwards, which no caller
     *                                 means
     */
    public function plusSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A stored timestamp is moved forwards, not back.');
        }

        return new self($this->moment->modify(sprintf('+%d seconds', $seconds)));
    }

    /**
     * How it reads in command output.
     *
     * The stored text, which is the one thing a user can paste back into a
     * query against their own database.
     */
    public function describe(): string
    {
        return $this->value();
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
