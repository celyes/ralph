<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\State\LifecycleState;

/**
 * Who asked for a workspace to be cleaned up.
 *
 * PROJECT.md section K, item 7 asks for two behaviours that only differ in
 * this: a failed, blocked or interrupted pass's workspace is **preserved by
 * default**, and there is an **explicit action** that removes one anyway. Both
 * are {@see WorktreeCleaner}, and this is the whole of the difference between
 * them — one reason out of eleven, waived for one set of states, when a person
 * named the workspace.
 *
 * Writing it as a request rather than as a boolean is what keeps the asymmetry
 * legible at the call sites. `clean($pass)` is the run's own housekeeping and
 * cannot remove evidence; `clean($pass, $log, CleanupRequest::Explicit)` is
 * `ralph clean TASK-ID` and can. A `true` in that position would read as
 * "force", which is precisely what neither of them is.
 *
 * **What an explicit request does not buy.** Only
 * {@see RetentionReason::PassUnfinished} is waived, and only for a pass that
 * has actually stopped. Every other reason stands exactly as it does for a
 * run's own cleanup: uncommitted changes, a commit nothing recorded, a commit
 * its branch does not hold, a directory that is not this package's, a worktree
 * Git has locked. In particular there is **no option anywhere that removes a
 * workspace holding uncommitted work** — that is the "protects uncommitted
 * changes" half of the item, and the reason it is a property of the design
 * rather than of a default. A person who has decided those changes are
 * expendable discards them with their own Git and runs the command again;
 * {@see RetentionReason::UncommittedWork} says so.
 *
 * @see WorktreeCleaner::assess() for the decision this takes part in
 */
enum CleanupRequest: string
{
    /**
     * A run tidying up after a task it finished.
     *
     * The default everywhere, and the conservative one. An unfinished pass's
     * workspace is its evidence — the half-written implementation, the index as
     * the worker left it, what `ralph logs` sends a person to look at — so a
     * loop moving on to the next task never removes one, however clean it
     * looks.
     */
    case Finalization = 'finalization';

    /**
     * A person asked for this workspace, by name, with nothing else going on.
     *
     * `ralph clean TASK-ID` and `ralph clean --all`. The command takes the
     * repository lock first, so "nothing else going on" is checked rather than
     * assumed: a workspace a live runner is holding is not one anybody can ask
     * to have deleted from another terminal.
     */
    case Explicit = 'explicit';

    /**
     * Whether a pass in this state may have its workspace removed although the
     * pass never finished.
     *
     * True only for an explicit request about a pass that has stopped
     * ({@see LifecycleState::hasStopped()}). A *running* pass is excluded for
     * the obvious reason — a runner is using the directory — and a *pending*
     * one because a workspace recorded against work that never began is
     * something to reconcile rather than something to delete.
     */
    public function waivesPreservation(LifecycleState $state): bool
    {
        return $this === self::Explicit && $state->hasStopped();
    }

    /**
     * Whether a person asked for this.
     */
    public function isExplicit(): bool
    {
        return $this === self::Explicit;
    }

    /**
     * The request as a log line says it.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Finalization => 'cleanup after a finished task',
            self::Explicit => 'cleanup you asked for',
        };
    }
}
