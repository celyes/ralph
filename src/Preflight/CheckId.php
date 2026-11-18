<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

/**
 * The preflight checks, in the order they run.
 *
 * Each check has exactly one of these, so a report can be addressed by name —
 * by the checks that depend on an earlier one, and by tests that assert on a
 * single outcome rather than on rendered text.
 *
 * The order is the order of dependency: the runtime before the tools, the tools
 * before what is asked of them, and last the commands that decide whether a
 * task worked.
 */
enum CheckId: string
{
    /**
     * The PHP build running Ralph PHP.
     */
    case Php = 'php';

    /**
     * The PHP extensions the package declares it needs.
     */
    case Extensions = 'extensions';

    /**
     * That the selected agent's executable exists and its declared
     * authentication variables are present.
     */
    case Agent = 'agent';

    /**
     * That the installed CLI is a release the selected adapter's invocation
     * was written for.
     */
    case AgentVersion = 'agent-version';

    /**
     * That every configured verification command names a program that could
     * run.
     */
    case Checks = 'checks';

    /**
     * Label used in the report's left column.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * The checks whose failure makes this one unanswerable.
     *
     * Declared here rather than inside each check so the whole dependency graph
     * is readable in one place, and so a check never has to decide whether it
     * is allowed to run.
     *
     * @return list<self>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::AgentVersion => [self::Agent],
            default => [],
        };
    }
}
