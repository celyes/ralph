<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Log\Sinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Writing to the terminal and the log as though they were one destination.
 */
#[CoversClass(Sinks::class)]
final class SinksTest extends TestCase
{
    #[Test]
    public function every_sink_gets_every_chunk(): void
    {
        $first = new RecordingSink();
        $second = new RecordingSink();

        $sinks = Sinks::of($first, $second);
        $sinks->write(OutputStream::Standard, 'working');
        $sinks->note('done');
        $sinks->close();

        foreach ([$first, $second] as $sink) {
            self::assertSame('working', $sink->textOf(OutputStream::Standard));
            self::assertSame(['done'], $sink->notes);
            self::assertSame(1, $sink->closes);
        }
    }

    #[Test]
    public function writing_nowhere_is_a_sink_rather_than_a_special_case(): void
    {
        $sinks = Sinks::none();

        $sinks->write(OutputStream::Standard, 'nobody is reading this');
        $sinks->note('nor this');
        $sinks->close();

        self::assertTrue($sinks->isEmpty());
        self::assertSame(0, $sinks->count());
    }

    /**
     * Otherwise adding a sink to an existing fan-out would build a tree whose
     * depth decided the order chunks arrived in.
     */
    #[Test]
    public function nesting_a_fan_out_flattens_it(): void
    {
        $inner = Sinks::of(new RecordingSink(), new RecordingSink());

        self::assertSame(3, Sinks::of($inner, new RecordingSink())->count());
    }

    /**
     * A terminal write failing on a closed pipe must not take the attempt's log
     * file with it: the file is the copy worth keeping.
     */
    #[Test]
    public function a_sink_that_throws_does_not_stop_the_others(): void
    {
        $broken = new ThrowingSink();
        $kept = new RecordingSink();

        $sinks = Sinks::of($broken, $kept);
        $sinks->write(OutputStream::Standard, 'first');
        $sinks->write(OutputStream::Standard, ' second');

        self::assertSame('first second', $kept->textOf(OutputStream::Standard));
    }

    /**
     * One failed write, not one per chunk for the rest of the attempt.
     */
    #[Test]
    public function a_sink_that_throws_is_not_tried_again(): void
    {
        $broken = new ThrowingSink();

        $sinks = Sinks::of($broken, new RecordingSink());
        $sinks->write(OutputStream::Standard, 'first');
        $sinks->write(OutputStream::Standard, 'second');
        $sinks->note('third');

        self::assertSame(1, $broken->attempts);
        self::assertSame(1, $sinks->count());
    }

    /**
     * It may be holding a file open, and the file is the thing worth saving.
     */
    #[Test]
    public function a_dropped_sink_is_still_closed(): void
    {
        $broken = new ThrowingSink();

        $sinks = Sinks::of($broken);
        $sinks->write(OutputStream::Standard, 'first');
        $sinks->close();

        self::assertSame(1, $broken->closes);
    }

    #[Test]
    public function a_sink_that_throws_on_the_way_out_does_not_stop_the_others_closing(): void
    {
        $broken = new ThrowingSink(throwOnClose: true);
        $kept = new RecordingSink();

        Sinks::of($broken, $kept)->close();

        self::assertSame(1, $broken->closes);
        self::assertSame(1, $kept->closes);
    }

    #[Test]
    public function closing_twice_closes_each_sink_once(): void
    {
        $sink = new RecordingSink();

        $sinks = Sinks::of($sink);
        $sinks->close();
        $sinks->close();

        self::assertSame(1, $sink->closes);
    }
}
