<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

/**
 * What one preflight check concluded.
 *
 * Only {@see self::Failed} stops a run. The distinction from {@see self::Warning}
 * is whether the work could proceed at all: a check that cannot confirm
 * something, but has no evidence against it either, must not refuse to start a
 * run the user can see no problem with — while a check that knows the next step
 * is impossible must refuse before an agent is paid for its tokens.
 */
enum CheckOutcome: string
{
    /**
     * The requirement is satisfied.
     */
    case Passed = 'pass';

    /**
     * The requirement is probably satisfied, but something about it could not
     * be established. The run may start.
     */
    case Warning = 'warn';

    /**
     * The requirement is not satisfied. No run may start.
     */
    case Failed = 'fail';

    /**
     * The check could not be answered because one it depends on failed. Not a
     * verdict about the requirement itself — but, since whatever blocked it has
     * already failed, it does not need to be one.
     */
    case Skipped = 'skip';

    /**
     * Label used in the report's status column.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Whether this outcome stops a run from starting.
     */
    public function blocks(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Whether a check that depends on this one can be answered.
     */
    public function allowsDependents(): bool
    {
        return $this === self::Passed || $this === self::Warning;
    }
}
