<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tasks\TaskChange;

/**
 * The task checklist changed while the task it describes was being worked on,
 * and the run stopped for a human to say what should happen.
 *
 * Kept apart from {@see ChecklistException}, which reports a checklist that
 * cannot be read or understood before anything has run. This one is raised
 * *after* work has happened: an agent has been invoked, there may be a commit
 * on a task branch, and the message has to say so, because a user who reads
 * "the checklist no longer states this task" and assumes their work was thrown
 * away will go looking for it in the wrong place.
 *
 * Nothing is undone when this is raised. The branch, the worktree and the logs
 * are left as they are, which is what makes restoring the old wording and
 * running again a complete remedy rather than a retry from scratch.
 *
 * Reported with {@see ExitCode::CONFIGURATION} for now, which is the status for
 * "correct a file and run the command again" and is accurate about the remedy.
 * The dedicated blocking status of PROJECT.md section I, item 5 does not exist
 * yet; this class adopts it when it does.
 */
final class ReconciliationException extends RalphException
{
    /**
     * @param list<string> $hints
     */
    public function __construct(
        string $message,
        private readonly TaskChange $change,
        private readonly array $hints = [],
    ) {
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

    /**
     * What was found, for a caller that reports or records the kind of change
     * rather than only its message.
     */
    public function change(): TaskChange
    {
        return $this->change;
    }
}
