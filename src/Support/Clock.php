<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

use DateTimeImmutable;

/**
 * Where the package reads the current moment from.
 *
 * An interface rather than a call to `time()` scattered through the stores,
 * because almost every timestamp Ralph PHP writes is evidence: when a pass
 * started, when it was last touched, when it finished, how long a check ran.
 * A test that cannot control the clock can only assert that those columns hold
 * *something*, which is exactly the assertion that would pass if a store wrote
 * the wrong one of them.
 *
 * Implementations return a {@see DateTimeImmutable} in UTC. {@see Timestamp} is
 * what turns that into the text a column holds.
 */
interface Clock
{
    /**
     * The current moment, in UTC.
     */
    public function now(): DateTimeImmutable;
}
