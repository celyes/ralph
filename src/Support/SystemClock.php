<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock, in UTC.
 *
 * UTC is not a formatting preference. Stored timestamps are compared against
 * each other across runs, and a project worked on from two machines — or from
 * one machine either side of a daylight-saving change — would otherwise produce
 * a history that appears to go backwards. The user's local time is a display
 * concern, and nothing stored depends on it.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
