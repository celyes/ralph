<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * A command this package started and can still reach.
 *
 * Exactly what {@see Terminator} needs of a running process and nothing else:
 * which process id it is, whether it is still running, and a way to signal it.
 * Starting the process, writing its input and draining its output are not
 * here, because they are not this package's work any more — symfony/process
 * does them, and {@see StartedCommand} is the one adapter between its
 * `Process` and this.
 *
 * **Why the seam exists at all.** Stopping a coding agent means stopping the
 * test runner and the language server it started (PROJECT.md section H, item
 * 4), and no process library does that: they stop the process they launched.
 * So the tree walk and the deepest-first termination stay here, and this
 * interface is the small part of them that has to touch a real process. It
 * also keeps the termination tests able to say what a process did without
 * owning the library's own state.
 *
 * Implementations are expected to be authoritative about liveness: the answer
 * {@see self::isRunning()} gives is what decides whether a run may release the
 * repository lock, so a cached or optimistic reading would be worse than no
 * reading at all.
 */
interface ManagedProcess
{
    /**
     * The process id, remembered from the moment it started.
     *
     * Still answered after the process has exited, which is the point: its
     * children outlive it, and the tree they are read from is rooted here.
     * Zero means the process never started.
     */
    public function pid(): int;

    /**
     * Whether the process is still running, read now.
     *
     * Reaps the process when it has exited, so that a finished command does
     * not stay in the process table as a zombie and get counted as alive.
     */
    public function isRunning(): bool;

    /**
     * Send a signal, and say whether it was delivered.
     *
     * False means refused, which for a process this package started and holds
     * means it has already gone. There is no third answer: the direct child is
     * always reachable through the handle that started it, unlike the
     * descendants {@see Terminator} has to reach with `posix_kill`.
     */
    public function signal(int $signal): bool;
}
