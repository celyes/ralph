<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * Which of a worker's two output streams a chunk of text arrived on.
 *
 * Carried alongside every chunk rather than merged away, because the two
 * streams mean different things to everything downstream: the terminal sends
 * one to standard output and the other to standard error so that `ralph status`
 * can be piped while a worker's complaints stay visible, a log marks which is
 * which so a reader can tell a progress line from a stack trace, and
 * {@see \Celyes\RalphPhp\Agent\ProcessAdapter::evidenceOf()} prefers error
 * output when it has to say what went wrong.
 *
 * A worker's own idea of which stream to use is not always right — plenty of
 * programs write ordinary progress to standard error — so this records where
 * the bytes came from and nothing about how important they are.
 */
enum OutputStream
{
    /**
     * The worker's standard output: descriptor 1.
     */
    case Standard;

    /**
     * The worker's error output: descriptor 2.
     */
    case Error;

    /**
     * The stream a process descriptor number names.
     */
    public static function forDescriptor(int $descriptor): self
    {
        return $descriptor === 2 ? self::Error : self::Standard;
    }

    /**
     * What the stream is called in a log marker and in diagnostics.
     *
     * The conventional short names, because that is what a user reading a log
     * already knows them as.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'stdout',
            self::Error => 'stderr',
        };
    }

    public function isError(): bool
    {
        return $this === self::Error;
    }
}
