<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * Where the package asks whether a process it did not start is still alive.
 *
 * One question, asked for one reason: a lock record left behind by a runner
 * that never released it says which process wrote it, and
 * {@see \Celyes\RalphPhp\Run\RepositoryLock} has to decide whether that runner
 * has stopped before a resume is allowed to continue its work (PROJECT.md
 * section H, item 1).
 *
 * An interface rather than a call to `/proc`, because the answer decides
 * whether a command refuses to start, and a test that cannot say "that process
 * is still there" cannot cover the case the refusal exists for.
 *
 * The three-valued answer is deliberate. "Running", "gone" and "this
 * environment cannot tell" are genuinely different, and a probe that reported
 * the third as either of the first two would make the lock either refuse every
 * takeover or wave through the one case it is meant to catch.
 */
interface ProcessProbe
{
    /**
     * Whether a process with this id exists.
     *
     * @return bool|null true when it exists, false when it certainly does not,
     *         null when this environment offers no way to find out
     */
    public function isRunning(int $pid): ?bool;
}
