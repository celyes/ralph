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
 * before the repository, the repository before what is read out of it, and the
 * agent last, with the two things a run cannot substitute for — the workspace a
 * task is implemented in, and the commands that decide whether it worked.
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
     * The `git` executable and its version.
     */
    case Git = 'git';

    /**
     * That the project directory is the root of a usable, non-bare Git
     * working tree Ralph PHP may write to.
     */
    case Repository = 'repository';

    /**
     * That the configured `git.base` names a commit task branches can start
     * from.
     */
    case BaseReference = 'base';

    /**
     * That Git can determine an author and committer identity, without which
     * no task commit can be created.
     */
    case Identity = 'identity';

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
     * That a new task worktree could be prepared: the `workspace.prepare`
     * commands name programs that exist, and the files `workspace.copy` lists
     * are on this machine to be copied.
     */
    case Workspace = 'workspace';

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
            self::Repository, self::Identity => [self::Git],
            self::BaseReference => [self::Git, self::Repository],
            self::AgentVersion => [self::Agent],
            default => [],
        };
    }
}
