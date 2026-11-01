<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The text a timestamp column holds, and the clocks it is read from.
 *
 * Almost nothing here is about telling the time. It is about a format that
 * outlives the process that wrote it: rows written by this build are read back
 * by the next one, sorted by SQLite with a plain string comparison, and read by
 * a user running `sqlite3` by hand. The literal spelling is pinned for that
 * reason, and so is the refusal to guess at anything else — a timestamp read
 * wrongly is a history that silently reorders itself.
 */
#[CoversClass(Timestamp::class)]
#[CoversClass(SystemClock::class)]
#[CoversClass(FrozenClock::class)]
final class TimestampTest extends TestCase
{
    /**
     * The format is a promise, so it is pinned to a literal rather than to
     * another call to the same formatter.
     */
    #[Test]
    public function a_stored_timestamp_is_iso_8601_in_utc_with_the_offset_present(): void
    {
        $moment = Timestamp::of(new DateTimeImmutable('2026-11-01 09:30:00', new DateTimeZone('UTC')));

        self::assertSame('2026-11-01T09:30:00+00:00', $moment->value());
        self::assertSame('2026-11-01T09:30:00+00:00', (string) $moment);
        self::assertSame('2026-11-01T09:30:00+00:00', $moment->describe());
    }

    /**
     * The format {@see Timestamp::FORMAT} names and the format it produces are
     * the same thing; a test that only used the constant would pass if both
     * changed together.
     */
    #[Test]
    public function the_declared_format_is_the_one_produced(): void
    {
        self::assertSame('Y-m-d\TH:i:sP', Timestamp::FORMAT);
        self::assertSame(
            '2026-11-01T09:30:00+00:00',
            (new DateTimeImmutable('2026-11-01 09:30:00', new DateTimeZone('UTC')))
                ->format(Timestamp::FORMAT),
        );
    }

    /**
     * The property SQLite's ordering rests on: the text sorts the way the
     * moments do.
     */
    #[Test]
    public function the_stored_text_sorts_chronologically(): void
    {
        $moments = [
            Timestamp::fromStorage('2026-11-01T09:30:00+00:00'),
            Timestamp::fromStorage('2026-01-09T23:59:59+00:00'),
            Timestamp::fromStorage('2025-12-31T00:00:00+00:00'),
            Timestamp::fromStorage('2026-11-01T09:29:59+00:00'),
        ];

        $text = array_map(static fn (Timestamp $moment): string => $moment->value(), $moments);
        $epochs = array_map(static fn (Timestamp $moment): int => $moment->epochSeconds(), $moments);

        sort($text, SORT_STRING);
        sort($epochs);

        self::assertSame(
            $epochs,
            array_map(
                static fn (string $value): int => Timestamp::fromStorage($value)->epochSeconds(),
                $text,
            ),
            'Sorting the stored text as a string must order the moments chronologically.',
        );
    }

    /**
     * A moment taken anywhere is stored in UTC, so a project worked on from two
     * machines does not produce a history that appears to go backwards.
     */
    #[Test]
    public function a_moment_in_another_zone_is_stored_as_the_same_instant_in_utc(): void
    {
        $tokyo = new DateTimeImmutable('2026-11-01 18:30:00', new DateTimeZone('Asia/Tokyo'));

        $stored = Timestamp::of($tokyo);

        self::assertSame('2026-11-01T09:30:00+00:00', $stored->value());
        self::assertSame($tokyo->getTimestamp(), $stored->epochSeconds());
    }

    /**
     * Dropped from the moment itself, not merely left out of the format.
     *
     * The stored text could not show them either way, so asserting on
     * {@see Timestamp::value()} alone would pass with the fraction still
     * attached — and {@see Timestamp::moment()} is public, so a caller
     * comparing two moments directly would then find two timestamps that say
     * the same thing and are not equal.
     */
    #[Test]
    public function fractions_of_a_second_are_dropped(): void
    {
        $moment = Timestamp::of(
            new DateTimeImmutable('2026-11-01 09:30:00.987654', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-11-01T09:30:00+00:00', $moment->value());
        self::assertSame('000000', $moment->moment()->format('u'));
        self::assertEquals(
            Timestamp::fromStorage('2026-11-01T09:30:00+00:00')->moment(),
            $moment->moment(),
        );
    }

    /**
     * The spelling {@see \Celyes\RalphPhp\State\Migrator} has been writing into
     * the migration ledger, so there is one format in the database rather than
     * two.
     */
    #[Test]
    public function the_format_is_the_one_the_migration_ledger_already_uses(): void
    {
        $ledger = gmdate('c', 1_762_010_200);

        self::assertSame($ledger, Timestamp::fromStorage($ledger)->value());
    }

    #[Test]
    public function a_stored_timestamp_reads_back_as_the_same_moment(): void
    {
        $original = Timestamp::of(new DateTimeImmutable('2026-11-01 09:30:00', new DateTimeZone('UTC')));

        $read = Timestamp::fromStorage($original->value());

        self::assertTrue($read->equals($original));
        self::assertSame('UTC', $read->moment()->getTimezone()->getName());
    }

    /**
     * Read, never produced: a user who edits a row by hand should not make the
     * database unreadable.
     */
    #[Test]
    public function a_zulu_suffix_is_read_and_normalised_to_the_stored_form(): void
    {
        $read = Timestamp::fromStorage('2026-11-01T09:30:00Z');

        self::assertSame('2026-11-01T09:30:00+00:00', $read->value());
    }

    /**
     * A hand-written row with a real offset on it names an unambiguous instant,
     * so it is read as that instant rather than refused — and comes back in the
     * stored spelling, which is the one the package writes.
     *
     * The offset being *dropped* rather than honoured is what would be
     * dangerous: it would move the moment by nine hours.
     */
    #[Test]
    public function a_hand_written_offset_is_read_as_the_instant_it_names(): void
    {
        $read = Timestamp::fromStorage('2026-11-01T18:30:00+09:00');

        self::assertSame('2026-11-01T09:30:00+00:00', $read->value());
        self::assertTrue($read->equals(Timestamp::fromStorage('2026-11-01T09:30:00+00:00')));
    }

    #[Test]
    public function surrounding_whitespace_is_tolerated(): void
    {
        self::assertSame(
            '2026-11-01T09:30:00+00:00',
            Timestamp::fromStorage("  2026-11-01T09:30:00+00:00\n")->value(),
        );
    }

    /**
     * Strict about the shape, because anything else is a guess at what a row
     * meant.
     *
     * The cases that would be *accepted* by a lenient reader are the point: a
     * date with no time, a space instead of the `T`, a local-time offset that
     * would silently shift the instant, and a two-digit year.
     */
    #[Test]
    #[DataProvider('unreadableValues')]
    public function a_value_that_is_not_a_stored_timestamp_is_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a stored timestamp');

        Timestamp::fromStorage($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'empty' => [''];
        yield 'date only' => ['2026-11-01'];
        yield 'a space instead of the T' => ['2026-11-01 09:30:00+00:00'];
        yield 'no offset at all' => ['2026-11-01T09:30:00'];
        yield 'fractional seconds' => ['2026-11-01T09:30:00.123+00:00'];
        yield 'a two-digit year' => ['26-11-01T09:30:00+00:00'];
        yield 'an impossible month' => ['2026-13-01T09:30:00+00:00'];
        yield 'an epoch count' => ['1762010200'];
        yield 'prose' => ['last Tuesday'];
    }

    /**
     * Null is a fact — "this has not happened yet" — and is passed through as
     * one.
     */
    #[Test]
    public function a_nullable_column_reads_null_as_not_yet(): void
    {
        self::assertNull(Timestamp::recorded(null));
        self::assertSame(
            '2026-11-01T09:30:00+00:00',
            Timestamp::recorded('2026-11-01T09:30:00+00:00')?->value(),
        );
    }

    #[Test]
    public function a_present_but_unreadable_nullable_column_is_still_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Timestamp::recorded('whenever');
    }

    #[Test]
    public function timestamps_compare_as_moments(): void
    {
        $earlier = Timestamp::fromStorage('2026-11-01T09:30:00+00:00');
        $later = Timestamp::fromStorage('2026-11-01T09:31:00+00:00');

        self::assertTrue($earlier->isBefore($later));
        self::assertFalse($later->isBefore($earlier));
        self::assertTrue($later->isAfter($earlier));
        self::assertFalse($earlier->isAfter($later));
        self::assertFalse($earlier->equals($later));
        self::assertTrue($earlier->equals(Timestamp::fromStorage('2026-11-01T09:30:00Z')));
    }

    /**
     * The comparison the run-time budget is checked with, where "exactly at the
     * deadline" has to count as reached.
     */
    #[Test]
    public function reaching_a_deadline_includes_arriving_exactly_on_it(): void
    {
        $deadline = Timestamp::fromStorage('2026-11-01T09:30:00+00:00');

        self::assertTrue($deadline->hasReached($deadline));
        self::assertTrue($deadline->plusSeconds(1)->hasReached($deadline));
        self::assertFalse(
            Timestamp::fromStorage('2026-11-01T09:29:59+00:00')->hasReached($deadline),
        );
    }

    #[Test]
    public function the_distance_between_two_moments_is_signed(): void
    {
        $earlier = Timestamp::fromStorage('2026-11-01T09:30:00+00:00');
        $later = Timestamp::fromStorage('2026-11-01T09:32:30+00:00');

        self::assertSame(150, $earlier->secondsUntil($later));
        self::assertSame(-150, $later->secondsUntil($earlier));
        self::assertSame(0, $earlier->secondsUntil($earlier));
    }

    #[Test]
    public function a_timestamp_moves_forward_without_changing_the_original(): void
    {
        $start = Timestamp::fromStorage('2026-11-01T09:30:00+00:00');

        $later = $start->plusSeconds(90);

        self::assertSame('2026-11-01T09:31:30+00:00', $later->value());
        self::assertSame('2026-11-01T09:30:00+00:00', $start->value());
        self::assertSame($start->value(), $start->plusSeconds(0)->value());
    }

    #[Test]
    public function a_timestamp_refuses_to_move_backwards(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('moved forwards, not back');

        Timestamp::fromStorage('2026-11-01T09:30:00+00:00')->plusSeconds(-1);
    }

    #[Test]
    public function the_system_clock_reads_the_current_moment_in_utc(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
    }

    #[Test]
    public function a_frozen_clock_reads_the_same_moment_every_time(): void
    {
        $clock = FrozenClock::at('2026-11-01T09:30:00+00:00');

        self::assertSame($clock->now()->getTimestamp(), $clock->now()->getTimestamp());
        self::assertSame('2026-11-01T09:30:00+00:00', Timestamp::now($clock)->value());
    }

    /**
     * The reason a frozen clock is mutable: everything holding it has to see
     * the same time after it moves.
     */
    #[Test]
    public function advancing_a_frozen_clock_moves_it_for_every_holder(): void
    {
        $clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $holder = static fn (Clock $clock): string => Timestamp::now($clock)->value();

        $clock->advance(60);

        self::assertSame('2026-11-01T09:31:00+00:00', $holder($clock));
        self::assertSame('2026-11-01T09:31:00+00:00', Timestamp::now($clock)->value());
    }

    #[Test]
    public function advancing_returns_the_same_clock_so_calls_can_be_chained(): void
    {
        $clock = FrozenClock::at('2026-11-01T09:30:00+00:00');

        self::assertSame($clock, $clock->advance(30));
        self::assertSame('2026-11-01T09:31:00+00:00', Timestamp::now($clock->advance(30))->value());
    }

    #[Test]
    public function a_frozen_clock_can_be_set_from_a_moment_in_another_zone(): void
    {
        $clock = new FrozenClock(
            new DateTimeImmutable('2026-11-01 18:30:00', new DateTimeZone('Asia/Tokyo')),
        );

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-11-01T09:30:00+00:00', Timestamp::now($clock)->value());
    }

    #[Test]
    public function a_frozen_clock_refuses_to_be_advanced_backwards(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('advanced forwards');

        FrozenClock::at('2026-11-01T09:30:00+00:00')->advance(-1);
    }

    #[Test]
    public function a_clock_cannot_be_stopped_at_something_that_is_not_a_moment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a moment a clock can be stopped at');

        FrozenClock::at('half past the kettle');
    }
}
