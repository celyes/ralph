<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A task's workspace was created but could not be made usable, and the run
 * stopped before the agent was started.
 *
 * Preparation is what turns a bare checkout into a directory the project can be
 * built and tested in: dependencies installed, local configuration copied in
 * (PROJECT.md section F, item 5). Every way that can fail — an install that
 * cannot reach the network, a listed file that is not on this machine, a
 * `composer` that is not installed — leaves a workspace in which the
 * configured verification commands cannot pass.
 *
 * Which is exactly why this is raised rather than reported as a failed attempt.
 * An agent sent into an unprepared workspace sees the project's own tests
 * failing for reasons that have nothing to do with its task, and would spend a
 * run's worth of tokens on the wrong problem; the failure would then be
 * recorded against the task, where the next attempt reads it as evidence about
 * work nobody has done yet.
 *
 * Nothing has been implemented when this is raised, and the workspace is left
 * exactly as preparation found it: the remedy is to fix what the message names
 * and run the command again.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, like {@see WorktreeException},
 * and for the same reason — the status for "put something right and run the
 * command again" is accurate about the remedy.
 */
final class PreparationException extends RalphException
{
    /**
     * @param list<string> $hints
     */
    public function __construct(string $message, private readonly array $hints = [])
    {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return ExitCode::CONFIGURATION;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
