<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\Schema;
use Celyes\RalphPhp\State\StopKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The six ways a run ends, and which of them want a person.
 *
 * The spellings are pinned to literals because they go into the `stop_kind`
 * column and are read back by later runs; the partition is pinned because it is
 * what PROJECT.md section H, item 9 is about and what section I, item 5 will
 * choose exit codes over. A kind that drifted into or out of
 * {@see StopKind::needsAttention()} would change what a `ralph run` tells the
 * user — and, once the exit codes exist, what a script makes of it.
 */
#[CoversClass(StopKind::class)]
final class StopKindTest extends TestCase
{
    #[Test]
    public function the_stored_spellings_are_pinned(): void
    {
        self::assertSame(
            ['finished', 'decision', 'attempts', 'limit', 'interrupted', 'failed'],
            StopKind::values(),
        );
    }

    /**
     * The three situations of section H, item 9 are the three that want a
     * person. A run that reached a configured limit did what it was told to.
     */
    #[Test]
    public function only_the_endings_that_wait_on_somebody_ask_for_attention(): void
    {
        self::assertTrue(StopKind::Decision->needsAttention());
        self::assertTrue(StopKind::Attempts->needsAttention());
        self::assertTrue(StopKind::Failed->needsAttention());

        self::assertFalse(StopKind::Finished->needsAttention());
        self::assertFalse(StopKind::Limit->needsAttention());
        self::assertFalse(StopKind::Interrupted->needsAttention());
    }

    #[Test]
    public function every_kind_can_say_what_it_is(): void
    {
        foreach (StopKind::cases() as $kind) {
            self::assertNotSame('', $kind->describe(), $kind->value . ' has nothing to say for itself.');
        }

        self::assertSame('a task is waiting on a person', StopKind::Decision->describe());
        self::assertSame('a task ran out of attempts', StopKind::Attempts->describe());
    }

    /**
     * The column's vocabulary comes from the enum, so a kind added here cannot
     * be refused by the database that has to store it.
     */
    #[Test]
    public function the_column_accepts_exactly_these(): void
    {
        self::assertSame(StopKind::values(), Schema::stopKinds());
    }
}
