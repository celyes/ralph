<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * Asks the operating system whether a process id is in use.
 *
 * `/proc/PID` first, because the supported platforms are Linux and WSL
 * (PROJECT.md section 2) and a directory test needs no extension, no signal and
 * no permission to the process it is asking about. `posix_kill($pid, 0)` is the
 * fallback for a system without a `/proc`, and the distinction it draws matters:
 * the signal being refused means the process exists and belongs to somebody
 * else, which is a different answer from no such process.
 *
 * Where neither is available the answer is null rather than a guess. The lock
 * treats that as "not confirmed running", because the flock it already holds is
 * the proof that no live process on this machine is holding the lock; the probe
 * only adds the case of a record naming a process that is somehow still there.
 */
final class SystemProbe implements ProcessProbe
{
    /**
     * `errno` for a signal refused: the process exists, and is not ours.
     *
     * The numeric literal is EPERM, which is 1 on Linux. PHP exposes no
     * constant for it that is present on every build, and the fallback is only
     * reached on a system with no `/proc` at all.
     */
    private const int EPERM = 1;

    /**
     * @param string $procfs where the process filesystem is mounted, which only
     *        a test has reason to change
     */
    public function __construct(private readonly string $procfs = '/proc')
    {
    }

    public function isRunning(int $pid): ?bool
    {
        // No process has an id below 1, so this is a record that was written
        // without one rather than a process to look for.
        if ($pid < 1) {
            return false;
        }

        if (is_dir($this->procfs)) {
            clearstatcache(true, $this->procfs . '/' . $pid);

            return is_dir($this->procfs . '/' . $pid);
        }

        if (!function_exists('posix_kill')) {
            return null;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        return posix_get_last_error() === self::EPERM;
    }
}
