<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * Stops a worker and its children, and says whether it worked.
 *
 * PROJECT.md section H, item 4's "stopping the managed worker/process tree
 * where supported", and the only place in the package that signals a process it
 * started. Two callers, for two reasons that want identical behaviour: an
 * attempt that overran its timeout, and a run the user interrupted. Keeping
 * them on one path is deliberate — a timeout that left an agent's test runner
 * behind would be the same bug as an interruption that did, and the one with a
 * test would be the one that stayed fixed.
 *
 * **Asked, then killed.** A termination signal first, so an agent can finish
 * the file it is writing and remove its own temporary state; a kill only for
 * what ignores it. The grace period is short, because a user who has pressed
 * Ctrl+C is watching.
 *
 * **Deepest first.** A shell signalled before the command it is waiting on
 * would leave that command orphaned and running, and a supervisor signalled
 * before its children may start replacements. {@see ProcessTree::deepestFirst()}
 * is that order.
 *
 * **The handle is used for the root, signals for the rest.** The direct child
 * is signalled and asked about through the {@see ManagedProcess} that started
 * it, which needs no extension and is the one way to reach it on a build
 * without ext-posix; descendants can only be reached with `posix_kill`. So a
 * system with no posix extension still stops the worker and reports that it
 * could not account for what the worker started, rather than quietly stopping
 * one process and calling the tree dealt with.
 */
final class Terminator
{
    /**
     * Seconds a process is given to act on a termination signal.
     */
    public const float GRACE_SECONDS = 2.0;

    /**
     * Seconds spent confirming that a kill took effect.
     *
     * A kill cannot be refused, so this is only the time the kernel needs to
     * tear the process down. Short, and the point of it is that "confirmed
     * stopped" means somebody looked.
     */
    public const float CONFIRMATION_SECONDS = 2.0;

    private const int SIGTERM = Interruption::SIGTERM;
    private const int SIGKILL = 9;

    /**
     * How often liveness is re-read while waiting.
     */
    private const int POLL_MICROSECONDS = 10_000;

    /**
     * @param string $procfs where process relationships are read from, which
     *        only a test has reason to change
     */
    public function __construct(private readonly string $procfs = '/proc')
    {
    }

    /**
     * Stop a process this package started, and everything it started.
     *
     * @param ManagedProcess $process the command that was started, which is
     *        how the direct child is stopped and how its exit is confirmed
     */
    public function stop(ManagedProcess $process, float $grace = self::GRACE_SECONDS): Termination
    {
        $pid = $process->pid();
        $running = $process->isRunning();

        // Read while the child is still alive: once it has gone, nothing
        // connects its children to this run any more.
        $tree = ProcessTree::rootedAt($pid, $this->procfs);

        if ($running === false) {
            // The child has exited. Its descendants have not necessarily: an
            // agent that spawned a server and returned leaves one behind, and
            // it is holding the workspace open either way.
            return $tree->isOnlyRoot()
                ? Termination::nothingToStop()
                : $this->signal($tree, $process, $grace, rootHasExited: true);
        }

        return $this->signal($tree, $process, $grace, rootHasExited: false);
    }

    /**
     * Ask the tree to stop, then kill what is left of it.
     */
    private function signal(ProcessTree $tree, ManagedProcess $process, float $grace, bool $rootHasExited): Termination
    {
        $targets = $tree->deepestFirst();
        $signalled = [];
        $accountable = true;

        foreach ($targets as $pid) {
            $sent = $pid === $tree->root() && !$rootHasExited
                ? $process->signal(self::SIGTERM)
                : $this->send($pid, self::SIGTERM);

            if ($sent === null) {
                // No way to signal this process at all, which on a build
                // without ext-posix is every descendant.
                $accountable = false;

                continue;
            }

            $signalled[] = $pid;
        }

        if ($signalled === []) {
            return Termination::unconfirmable([]);
        }

        $alive = $this->waitFor($signalled, $process, $grace, $rootHasExited);

        if ($alive === []) {
            return $accountable
                ? Termination::confirmed($signalled)
                : Termination::unconfirmable($signalled);
        }

        if ($alive === null) {
            return Termination::unconfirmable($signalled);
        }

        foreach ($alive as $pid) {
            if ($pid === $tree->root() && !$rootHasExited) {
                $process->signal(self::SIGKILL);

                continue;
            }

            $this->send($pid, self::SIGKILL);
        }

        $survivors = $this->waitFor($alive, $process, self::CONFIRMATION_SECONDS, $rootHasExited);

        if ($survivors === null) {
            return Termination::unconfirmable($signalled, killed: true);
        }

        if ($survivors !== []) {
            return Termination::survived($signalled, $survivors);
        }

        return $accountable
            ? Termination::confirmed($signalled, killed: true)
            : Termination::unconfirmable($signalled, killed: true);
    }

    /**
     * Wait for processes to go, and report which are still there.
     *
     * Returns an empty list once nothing is left, the survivors when the wait
     * runs out, and null when this system cannot tell either way — which is a
     * third answer and not a failure: see {@see Termination}.
     *
     * @param list<int> $pids
     *
     * @return list<int>|null
     */
    private function waitFor(array $pids, ManagedProcess $process, float $seconds, bool $rootHasExited): ?array
    {
        $deadline = microtime(true) + max(0.0, $seconds);
        $remaining = $pids;

        while (true) {
            $still = [];

            foreach ($remaining as $pid) {
                $alive = $this->isAlive($pid, $process, $rootHasExited);

                if ($alive === null) {
                    return null;
                }

                if ($alive) {
                    $still[] = $pid;
                }
            }

            if ($still === [] || microtime(true) >= $deadline) {
                return $still;
            }

            $remaining = $still;
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Whether one process of the tree is still running.
     *
     * The direct child is asked through the handle that started it, which is
     * both authoritative and the only reading that reaps it: an unreaped child
     * stays in `/proc` as a zombie, and the library's status read is what
     * collects its status. Descendants are read from the process filesystem.
     */
    private function isAlive(int $pid, ManagedProcess $process, bool $rootHasExited): ?bool
    {
        if (!$rootHasExited && $pid === $process->pid()) {
            return $process->isRunning();
        }

        return ProcessTree::isProcessAlive($pid, $this->procfs);
    }

    /**
     * Send a signal, or report that there is no way to.
     *
     * Null means this build cannot signal a process it does not hold a handle
     * for; false means the signal was refused, which for a process this package
     * started is a process that has already gone.
     */
    private function send(int $pid, int $signal): ?bool
    {
        if ($pid < 1) {
            return null;
        }

        if (!function_exists('posix_kill')) {
            return null;
        }

        return posix_kill($pid, $signal);
    }
}
