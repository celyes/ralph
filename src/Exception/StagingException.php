<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A verified task's changes could not be staged, so nothing was committed.
 *
 * This is the one failure in section F that arrives *after* the work has been
 * done. The agent has run, the verification commands have passed, and the
 * implementation is sitting in the task's workspace — so the message's job is
 * different from every other refusal in this package: it has to tell the user
 * where their work is, not what to put right before trying again.
 *
 * Nothing is undone when this is raised. The index may hold part of what was
 * going to be staged, and that is deliberate: resetting it would be the package
 * writing to a workspace it has just said it cannot reason about, and the
 * worktree is preserved for inspection either way (PROJECT.md section F,
 * item 10). What does *not* happen is a commit, which is the whole point —
 * {@see \Celyes\RalphPhp\Git\WorkspaceStager} is the step that decides what a
 * commit would contain, and a commit built on a reading it could not trust is
 * worse than no commit at all.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "put something
 * right and run the command again". The dedicated blocking status of PROJECT.md
 * section I, item 5 does not exist yet; this class adopts it when it does.
 */
final class StagingException extends RalphException
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
