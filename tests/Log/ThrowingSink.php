<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Log\Emphasis;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
use RuntimeException;

/**
 * A sink that breaks its contract.
 *
 * {@see OutputSink} says a sink must not throw, because it is written to from
 * inside the loop draining a live child process's pipes. A rule that is never
 * tested against something that breaks it is a comment, so this is the thing
 * that breaks it: what must be shown is that a worker still finishes, its
 * output is still collected, and the other sinks still get their copy.
 */
final class ThrowingSink implements OutputSink
{
    public int $attempts = 0;

    public int $closes = 0;

    public function __construct(private readonly bool $throwOnClose = false)
    {
    }

    public function write(OutputStream $stream, string $chunk, Emphasis $emphasis = Emphasis::Plain): void
    {
        $this->attempts++;

        throw new RuntimeException('This sink is broken.');
    }

    public function note(string $line): void
    {
        $this->attempts++;

        throw new RuntimeException('This sink is broken.');
    }

    public function close(): void
    {
        $this->closes++;

        if ($this->throwOnClose) {
            throw new RuntimeException('This sink is broken on the way out too.');
        }
    }
}
