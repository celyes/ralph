<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Preflight\Check\GitCheck;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;

/**
 * Asks the project's repository a question.
 *
 * A {@see Repository} bound to the project under inspection: the executable the
 * {@see GitCheck} found, the project root as the working directory rather than
 * the PHP process's own, and the inherited `GIT_DIR` and `GIT_WORK_TREE`
 * dropped so that preflight cannot be redirected at a repository the user never
 * named. The handle is built per call because the executable is only known once
 * the {@see GitCheck} has run, and the checks share one probe.
 *
 * Every command issued through this is read-only, which is what makes preflight
 * safe to run at any point, including during an interrupted run.
 */
final class GitProbe
{
    public function __construct(private readonly ProcessRunner $processes = new ProcessRunner())
    {
    }

    public function run(PreflightContext $context, string ...$arguments): ProcessResult
    {
        return Repository::at(
            $context->projectRoot(),
            $context->recall(GitCheck::EXECUTABLE) ?? 'git',
            $this->processes,
        )->run(...$arguments);
    }
}
