<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Preflight\Check\GitCheck;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;

/**
 * Asks the project's repository a question.
 *
 * Shared by the Git-dependent checks so they agree on three things: the
 * executable the {@see GitCheck} found, the working directory — always the
 * project root, never the process's own — and that two redirection variables
 * are dropped from the environment.
 *
 * `GIT_DIR` and `GIT_WORK_TREE` are removed because Ralph PHP locates the
 * repository from the project directory it was pointed at. Inheriting them
 * would make preflight answer about a different repository than the one whose
 * configuration is being checked, and report it in terms of paths the user
 * never mentioned.
 *
 * Every command issued through this is read-only, which is what makes preflight
 * safe to run at any point, including during an interrupted run.
 */
final class GitProbe
{
    /**
     * Seconds a local query may take. Generous for a `rev-parse`, short enough
     * that a repository on an unresponsive network mount reports a timeout
     * rather than hanging preflight.
     */
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(private readonly ProcessRunner $processes = new ProcessRunner())
    {
    }

    public function run(PreflightContext $context, string ...$arguments): ProcessResult
    {
        $command = [$context->recall(GitCheck::EXECUTABLE) ?? 'git'];

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        return $this->processes->run(
            $command,
            $context->projectRoot(),
            self::TIMEOUT_SECONDS,
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false],
        );
    }
}
