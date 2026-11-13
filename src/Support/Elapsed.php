<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

/**
 * How long something took, measured on a clock that only moves forward.
 *
 * {@see Clock} answers "when did this happen", and a wall clock is the only
 * honest answer to that. It is the wrong instrument for "how long did this
 * take": NTP corrects the wall clock, and a suspended virtual machine — WSL
 * among them — steps it on resume, in either direction and without warning. A
 * subtraction across such a step gives a duration that never elapsed.
 *
 * Both directions have bitten a run. Backwards, a check is recorded as having
 * taken a negative amount of time, which the result it is handed to rejects as
 * impossible, ending a run that was going perfectly well. Forwards, a command's
 * timeout can expire moments after it started, and the loop kills a healthy
 * worker and reports that it overran.
 *
 * `hrtime()` is monotonic, so neither can happen. Its zero point is arbitrary
 * and means nothing on its own: a reading is only useful subtracted from a
 * later one, which is the whole of this class's interface.
 */
final class Elapsed
{
    /**
     * Nanoseconds in a second, as `hrtime()` counts them.
     */
    private const int NANOSECONDS = 1_000_000_000;

    /**
     * A reading to measure from, in seconds from an arbitrary origin.
     */
    public static function reading(): float
    {
        return hrtime(true) / self::NANOSECONDS;
    }

    /**
     * How many seconds have passed since a reading. Never negative.
     */
    public static function since(float $reading): float
    {
        return self::reading() - $reading;
    }
}
