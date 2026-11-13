<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Support\Elapsed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * That a measured duration cannot be a duration that never happened.
 *
 * The property this class exists for is one a test cannot demonstrate
 * directly: no test may step the machine's clock, and so none can watch the
 * failure it prevents — a check recorded as having taken a negative amount of
 * time, which is rejected as impossible and ends an otherwise healthy run.
 *
 * What is testable is the behaviour that makes that impossible: readings never
 * go backwards, and an elapsed time is never negative and never counts time a
 * sleep did not spend. The guarantee itself lives in the choice of `hrtime()`
 * over `microtime()`, so the last test here asserts on the source: a later
 * reader restoring the wall clock for being simpler would reintroduce the bug
 * with the suite still green.
 */
#[CoversClass(Elapsed::class)]
final class ElapsedTest extends TestCase
{
    #[Test]
    public function readings_never_go_backwards(): void
    {
        $first = Elapsed::reading();
        $second = Elapsed::reading();

        self::assertGreaterThanOrEqual(
            $first,
            $second,
            'A later reading was earlier than an earlier one, so a duration between them can be negative.',
        );
    }

    #[Test]
    public function nothing_takes_a_negative_amount_of_time(): void
    {
        $reading = Elapsed::reading();

        self::assertGreaterThanOrEqual(
            0.0,
            Elapsed::since($reading),
            'A duration came out negative, which is what CheckRun refuses to record.',
        );
    }

    #[Test]
    public function a_wait_is_measured_as_at_least_as_long_as_it_was(): void
    {
        $reading = Elapsed::reading();
        usleep(20_000);
        $elapsed = Elapsed::since($reading);

        // A lower bound only. The sleep is the one part of this the test
        // controls; how much longer a loaded machine takes to come back from it
        // is not, so an upper bound here would fail on a busy CI runner for no
        // reason.
        self::assertGreaterThanOrEqual(
            0.02,
            $elapsed,
            'Twenty milliseconds of sleeping was measured as less than that.',
        );
    }

    #[Test]
    public function the_measurement_does_not_read_the_wall_clock(): void
    {
        $source = (string) file_get_contents(Paths::inPackage('src/Support/Elapsed.php'));

        self::assertStringContainsString(
            'hrtime(true)',
            $source,
            'Elapsed no longer uses the monotonic clock, so NTP and a resumed VM can invent durations again.',
        );
        self::assertStringNotContainsString(
            'microtime(',
            $source,
            'Elapsed reads the wall clock, which steps in both directions and is not a measure of elapsed time.',
        );
    }
}
