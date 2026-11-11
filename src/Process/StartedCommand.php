<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A started symfony/process command, as {@see Terminator} sees it.
 *
 * The whole adapter between the library that runs commands and the part of
 * stopping them that stays this package's own. {@see ProcessRunner} starts a
 * `Process`, wraps it in one of these, and hands it over; nothing else in the
 * package touches the library's type.
 *
 * **The process id is read once, at the start.** `Process::getPid()` answers
 * null once the command has exited, and the one case that must still work is
 * the agent that returned while the server it started did not: its children
 * are found by walking down from its id, so an id that disappears with the
 * process would lose them (PROJECT.md section H, item 4).
 */
final class StartedCommand implements ManagedProcess
{
    /**
     * The command's process id, kept because the library stops reporting it.
     */
    private readonly int $pid;

    public function __construct(private readonly Process $process)
    {
        $this->pid = (int) $process->getPid();
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Whether the command is still running.
     *
     * `Process::isRunning()` re-reads the process status, reaps the command
     * once it has exited, and drains whatever is in its pipes — so a liveness
     * question asked while a command is being stopped also collects the last
     * thing it printed, which is usually the explanation.
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function signal(int $signal): bool
    {
        try {
            $this->process->signal($signal);
        } catch (LogicException | RuntimeException) {
            // The library refuses to signal a process it no longer holds, and
            // a process this package started that cannot be signalled has
            // exited. Either way there is nothing left to stop.
            return false;
        }

        return true;
    }
}
