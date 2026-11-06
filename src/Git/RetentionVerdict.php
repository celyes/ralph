<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * What cleanup decided about one task workspace.
 *
 * Three answers, because "do not remove it" and "there is nothing to remove"
 * are different facts and a user reading `ralph status` needs them apart: the
 * first means a directory is sitting there on purpose and may hold the only
 * copy of something, the second means the pass's workspace has already gone.
 * Collapsing them would make a finished run look like it had left work behind,
 * or — far worse in the other direction — make a preserved failure look
 * already tidied.
 *
 * @see WorkspaceRetention for the decision this labels, and
 *      {@see RetentionReason} for why a workspace is preserved
 */
enum RetentionVerdict: string
{
    /**
     * The workspace may be removed: this pass created it, the pass is finished
     * with, and nothing in it would be lost.
     *
     * Every one of {@see RetentionReason}'s cases was asked and none applied.
     */
    case Removable = 'removable';

    /**
     * The workspace stays, for at least one stated reason.
     *
     * The outcome PROJECT.md section F, item 10 is mostly about. It is never
     * reported without the reasons that produced it.
     */
    case Preserved = 'preserved';

    /**
     * There is no workspace to decide about.
     *
     * Either the pass never had one, or its directory and its registration have
     * both already gone. Not an error: a task that was skipped before
     * preparation, and a run whose workspaces were cleaned up last time, both
     * land here.
     */
    case Absent = 'absent';

    /**
     * The verdict as a status line says it.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Removable => 'can be removed',
            self::Preserved => 'kept',
            self::Absent => 'no workspace',
        };
    }
}
