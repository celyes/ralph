<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * A request from outside the process to stop what it is doing.
 *
 * Ctrl+C, in the form the rest of the package can reason about: which signal
 * arrived, when, and what to call it in a stop reason a person reads afterwards
 * (PROJECT.md section H, item 4). A value rather than a boolean because the
 * three signals a run stops for mean different things to the user who sent
 * them — a keyboard interrupt, a `kill`, a closed terminal — and a run that
 * recorded only "interrupted" would leave them unable to tell a deliberate
 * Ctrl+C from a dropped SSH connection.
 *
 * The numbers are literals rather than the `SIG*` constants, because those
 * exist only with ext-pcntl loaded and this class is read on the way out of a
 * run whether the extension was there or not. They are the values Linux and
 * every other POSIX platform uses, and {@see Interruptions} is the one place
 * that hands them to the operating system.
 */
final class Interruption
{
    /**
     * Keyboard interrupt: Ctrl+C in the terminal running the loop.
     */
    public const int SIGINT = 2;

    /**
     * Polite termination: `kill`, a process supervisor, a CI job being
     * cancelled.
     */
    public const int SIGTERM = 15;

    /**
     * The terminal went away: a closed window, a dropped SSH session.
     */
    public const int SIGHUP = 1;

    private function __construct(
        private readonly int $signal,
        private readonly float $at,
    ) {
    }

    /**
     * The interruption a signal number represents.
     *
     * Any number is accepted, including one this package does not install a
     * handler for: a signal that reached the process is a fact, and refusing to
     * describe it would lose the only record of why a run stopped.
     */
    public static function fromSignal(int $signal): self
    {
        return new self($signal, microtime(true));
    }

    public function signal(): int
    {
        return $this->signal;
    }

    /**
     * When it arrived, as a wall-clock microtime.
     */
    public function at(): float
    {
        return $this->at;
    }

    /**
     * The signal's name, or its number when it has none here.
     */
    public function name(): string
    {
        return match ($this->signal) {
            self::SIGINT => 'SIGINT',
            self::SIGTERM => 'SIGTERM',
            self::SIGHUP => 'SIGHUP',
            default => sprintf('signal %d', $this->signal),
        };
    }

    /**
     * The signal and what sends it, for a log note or a terminal line.
     */
    public function describe(): string
    {
        return match ($this->signal) {
            self::SIGINT => 'SIGINT (Ctrl+C)',
            self::SIGTERM => 'SIGTERM',
            self::SIGHUP => 'SIGHUP (the terminal closed)',
            default => $this->name(),
        };
    }

    /**
     * What the run's row records as the reason it stopped.
     */
    public function reason(): string
    {
        return 'stopped by ' . $this->describe();
    }

    public function is(int $signal): bool
    {
        return $this->signal === $signal;
    }
}
