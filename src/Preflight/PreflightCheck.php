<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

/**
 * One thing that must be true before a run starts.
 *
 * A check answers for exactly one {@see CheckId} and returns one
 * {@see CheckResult}. It never throws for the condition it is checking — a
 * missing executable is the answer, not an error — and it never changes
 * anything: preflight reads the environment and nothing else, so running it is
 * always safe, including on a project in the middle of an interrupted run.
 *
 * Whether a check is allowed to run at all is decided by {@see Preflight} from
 * {@see CheckId::dependsOn()}, so an implementation can assume what it depends
 * on is in place.
 */
interface PreflightCheck
{
    /**
     * The check this implementation answers for.
     */
    public function id(): CheckId;

    public function inspect(PreflightContext $context): CheckResult;
}
