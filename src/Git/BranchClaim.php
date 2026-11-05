<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * Whether a pass may put its commit on the branch it is named after
 * (PROJECT.md section F, item 4).
 *
 * Three answers, and the reason there are exactly three is that a branch name
 * is generated rather than chosen: `ralph/TASK-19E0214A-add-a-flag` is derived
 * from the task and the configured prefix, so the same name can be produced
 * twice — by a pass being resumed, by a second pass at a reopened task, or by a
 * person who happened to name a branch the same way. The name alone therefore
 * says nothing about who owns the branch, and the decision cannot be "create it
 * if it is missing, use it if it is there".
 *
 *  - {@see self::Available} — Git holds no such branch and no recorded pass has
 *    claimed the name. The pass may create it when it has a commit to put on
 *    it.
 *  - {@see self::Ours} — the branch exists and the pass's own row records it,
 *    with a history that still contains what the row says is on it. This is
 *    resume: the branch is continued, never reset.
 *  - {@see self::Unrelated} — the branch exists and is somebody else's, or
 *    another pass's row has reserved the name. The run stops. The three
 *    convenient things to do here — `git branch --force`, `git reset --hard`,
 *    `git branch -D` — each destroy commits that nobody asked to lose, which is
 *    what this case exists to refuse.
 *
 * Ownership is read from the state store and from the repository, never from
 * the spelling of the name: see {@see BranchNamer} on why a branch name is not
 * an identity.
 */
enum BranchClaim: string
{
    /**
     * Nothing holds the name, so the pass may create the branch.
     */
    case Available = 'available';

    /**
     * The pass's own branch, to be continued.
     */
    case Ours = 'ours';

    /**
     * Someone else's branch, or a name another pass has reserved.
     */
    case Unrelated = 'unrelated';

    /**
     * Whether a pass may commit on the branch.
     */
    public function isUsable(): bool
    {
        return $this !== self::Unrelated;
    }

    /**
     * Whether the branch has to be created before anything can be committed on
     * it.
     */
    public function needsCreating(): bool
    {
        return $this === self::Available;
    }

    /**
     * The answer as a phrase, for a status table or a log header.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Available => 'available',
            self::Ours => 'owned by this pass',
            self::Unrelated => 'owned by unrelated work',
        };
    }
}
