<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * A probe that answers from a list it was given.
 *
 * For tests, in the same way {@see \Celyes\RalphPhp\Support\FrozenClock} is:
 * the lock's refusals turn on whether a recorded process is still running, and
 * the only way to cover "it is" without starting and abandoning a real process
 * is to be able to say so.
 */
final class KnownProcesses implements ProcessProbe
{
    /**
     * @param list<int> $running the process ids that exist
     * @param bool      $knowable whether this environment can answer at all
     */
    private function __construct(
        private readonly array $running,
        private readonly bool $knowable = true,
    ) {
    }

    /**
     * These process ids exist, and no others.
     */
    public static function running(int ...$pids): self
    {
        return new self(array_values($pids));
    }

    /**
     * Nothing is running.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * A system that offers no way to look a process up.
     */
    public static function unknowable(): self
    {
        return new self([], false);
    }

    public function isRunning(int $pid): ?bool
    {
        if (!$this->knowable) {
            return null;
        }

        return in_array($pid, $this->running, true);
    }
}
