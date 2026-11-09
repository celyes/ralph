<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A task's isolated workspace could not be prepared, and the run stopped rather
 * than working somewhere else.
 *
 * Every task is implemented in a linked worktree created from the recorded base
 * commit, because that is what keeps a task's changes out of the checkout the
 * user is looking at (PROJECT.md section F, items 1 and 2). So the fallbacks
 * that would make this failure go away are all worse than the failure: running
 * in the controlling checkout would edit files the package promised to leave
 * alone, and clearing whatever occupies the workspace path would delete
 * something nobody asked to lose — which section F, item 10 forbids in as many
 * words.
 *
 * Nothing has been implemented when this is raised. The agent has not run, so
 * the remedy is always to deal with the directory named in the message and run
 * the command again.
 *
 * Reported with {@see ExitCode::CONFIGURATION} and settled there by PROJECT.md
 * section I, item 5: the agent has not run, so this is the project not being in
 * a state to run in rather than a block over work that exists. Deal with the
 * directory the message names and run the command again.
 */
final class WorktreeException extends RalphException
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
