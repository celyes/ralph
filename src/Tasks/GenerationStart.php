<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * Why the runner is working in the generation it is working in.
 *
 * Three answers, and they differ in exactly one consequential way: whether the
 * pass about to start owns a branch of its own or inherits the one an unfinished
 * pass already owns. Keeping that as a named finding rather than a boolean is
 * what lets `status` say "reopened" — the thing a user who has just unticked an
 * item is looking for — instead of reporting the task as though it had never
 * been done.
 */
enum GenerationStart: string
{
    /**
     * Nothing has ever been recorded for this task: generation 1.
     */
    case FirstRun = 'first_run';

    /**
     * A generation is already open, and this run picks it up where it stopped.
     *
     * A task that failed verification, exhausted its attempts, was blocked or
     * was interrupted has an open generation, and retrying or resuming it is
     * the same pass continuing: the same branch, the same accumulated attempts,
     * the same failure evidence fed back in. Creating a new generation here
     * would strand the branch the earlier attempt left behind.
     */
    case Continued = 'continued';

    /**
     * The task was finished and has been unticked, so a new pass begins.
     *
     * Everything the completed generation recorded — its attempts, its
     * verification output, its branch and its commit — is kept as it is, and
     * the new pass gets the next generation number and a branch of its own
     * (PROJECT.md section C, item 8). The old commit stays reachable from the
     * old branch, so reopening a task can never be what loses work that was
     * already approved.
     */
    case Reopened = 'reopened';

    /**
     * Whether a finished pass is being started again.
     */
    public function isReopening(): bool
    {
        return $this === self::Reopened;
    }

    /**
     * Whether this pass needs a branch created for it.
     *
     * False only for a continuation, which inherits the open generation's
     * branch; both of the others start from the configured base reference.
     */
    public function startsNewBranch(): bool
    {
        return $this !== self::Continued;
    }

    /**
     * Whether attempt counters carry over from what is already recorded.
     *
     * A continuation keeps counting towards the per-task attempt limit of
     * PROJECT.md section H; a reopening is new work and starts at zero, or a
     * task reopened after a limit was reached could never be run again.
     */
    public function keepsAttemptCount(): bool
    {
        return $this === self::Continued;
    }

    /**
     * A short label for a status table or a log line.
     */
    public function label(): string
    {
        return match ($this) {
            self::FirstRun => 'first run',
            self::Continued => 'continued',
            self::Reopened => 'reopened',
        };
    }
}
