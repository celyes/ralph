<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\WorkspaceAudit;

/**
 * A worker changed the repository's commits or branches in a way this package
 * will not put back, and the pass stopped (PROJECT.md section F, item 9).
 *
 * The *block* half of "reconcile or block". The reconcilable half never reaches
 * here: a worker's commits on top of the commit the workspace was handed, or
 * the pass's own branch created under the pass's own name, are repaired and the
 * pass carries on. What is left for this is every difference whose repair would
 * mean forcing a branch, resetting a workspace or deleting a reference —
 * destroying work somebody may have and this run has never seen.
 *
 * Raised *instead of* staging and committing, which is what makes it safe to
 * raise at all. At the point this is thrown the agent has run and the
 * verification commands have not; nothing has been staged, no commit has been
 * made, and the task is left unchecked. Everything the worker did is still
 * there — the files, the commits, the references — so the whole remedy is for a
 * person to put the repository the way they want it and run the command again.
 *
 * It carries the {@see WorkspaceAudit} rather than only a message, because a
 * caller that records a blocker needs the findings, the two states and the
 * SHAs, and because the commits a blocked pass leaves behind may have no other
 * name anywhere.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "put something
 * right and run the command again". The dedicated blocking status of PROJECT.md
 * section I, item 5 does not exist yet; this class adopts it when it does.
 */
final class DriftException extends RalphException
{
    /**
     * @param list<string> $hints
     */
    public function __construct(
        string $message,
        private readonly WorkspaceAudit $audit,
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
     * What was found, for a caller that records the findings rather than only
     * their message.
     */
    public function audit(): WorkspaceAudit
    {
        return $this->audit;
    }
}
