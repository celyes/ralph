<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * One way a worker can have changed a repository's commits or branches, and
 * whether this package may put it back.
 *
 * PROJECT.md section F, item 9 allows two responses — reconcile, or block — and
 * this enum is where the choice between them is made, once, rather than at each
 * site that notices a difference. The line between the two cases is a single
 * question: **can the state be restored without losing anything a person
 * would want back?**
 *
 * Exactly two findings pass that test, and both for the same reason. A worker
 * that committed on top of the commit the workspace was handed moved the
 * implementation out of the working tree and into a commit object, and
 * `git reset --soft` moves it back: the index and every file on disk are left
 * untouched, the only thing that changes is which commit HEAD names, and the
 * commits themselves survive in the reflog. A worker that put HEAD on the
 * pass's own branch chose the name this pass was going to use anyway, so
 * recording the claim is the whole repair.
 *
 * Everything else blocks, and the enum is deliberately biased that way. The
 * convenient responses to an unexpected reference — `git branch --force`,
 * `git reset --hard`, `git branch -D`, committing onto whatever history is
 * there — each destroy work that somebody may have and that this package has
 * never seen. A branch that moved may be the user's `main`; a HEAD that no
 * longer descends from the commit the workspace started at may be a rebase that
 * took an hour. None of those is a decision a runner can make, which is what
 * makes blocking the correct outcome rather than a limitation.
 *
 * Reconcilability is a property of the *kind*, not of a particular finding, so
 * that a reading cannot be assembled with one finding claiming to be safe
 * because of how it was phrased. {@see WorkspaceAudit} is blocking as soon as
 * one finding is.
 */
enum DriftKind: string
{
    /**
     * HEAD moved forward: the worker committed in the workspace, on top of the
     * commit it was handed.
     *
     * Reconcilable. The commits sit on the commit the workspace started at —
     * nothing has been dropped or rewritten — so a soft reset restores the
     * state section F's staging and commit steps expect, with every change
     * still in the index and on disk.
     *
     * Putting the work back rather than adopting the worker's commits is the
     * requirement, not a preference: PROJECT.md section 4 says a zero exit code
     * is not enough to complete a task, and an adopted commit would be a commit
     * that no verification command had ever run against, carrying a message
     * with none of the identifiers section F, item 7 requires.
     */
    case CommitsAdded = 'commits_added';

    /**
     * HEAD is on a commit that does not descend from the one the workspace was
     * handed.
     *
     * Blocking. A checkout of some other commit, a reset backwards, an
     * amended or rebased history — this cannot tell which, and in each of them
     * the relationship between what is on disk and what is committed is
     * something only a person can establish.
     */
    case HeadMoved = 'head_moved';

    /**
     * HEAD was attached to the pass's own branch by something other than this
     * package.
     *
     * Reconcilable. The name is the one {@see BranchNamer} derives for this
     * pass and no other pass records it, so nothing is being taken from
     * anybody: the repair records the claim the pass was going to make anyway,
     * which is what keeps a later run from reading the reference as unrelated
     * work ({@see TaskBranches}).
     */
    case BranchAttached = 'branch_attached';

    /**
     * A branch under the pass's own name appeared, at the commit the workspace
     * was handed.
     *
     * Reconcilable, and the detached sibling of {@see self::BranchAttached}: a
     * worker that ran `git branch` without switching to it. The reference is
     * where this pass's commit was going to put it, so there is nothing in Git
     * to change and the repair is the recorded claim alone.
     */
    case BranchClaimed = 'branch_claimed';

    /**
     * HEAD is attached to a branch that is not the pass's.
     *
     * Blocking. The next commit would land on somebody else's branch, and a
     * soft reset — the repair for commits this package does know how to undo —
     * would move that branch off the commit it is on.
     */
    case ForeignBranch = 'foreign_branch';

    /**
     * HEAD was on the pass's branch and is now attached to nothing.
     *
     * Blocking. The branch still points where it did, so no work is lost, but
     * the workspace was detached by something whose intention is unknown, and
     * re-attaching it would be this package guessing at which of the two
     * positions is the one that matters.
     */
    case HeadDetached = 'head_detached';

    /**
     * A branch appeared that is not the pass's, or is the pass's at a commit
     * this cannot account for.
     *
     * Blocking. A worker that creates a reference is doing the one thing
     * section F reserves to the runner, and the reference may be the only name
     * on commits that are not in the workspace at all.
     */
    case BranchCreated = 'branch_created';

    /**
     * An existing branch's tip changed.
     *
     * Blocking, and the finding that catches a commit made *outside* the task's
     * workspace: references are shared by every worktree, so a worker that
     * committed onto `main` in the controlling checkout is seen here even
     * though nothing about the workspace changed.
     */
    case BranchMoved = 'branch_moved';

    /**
     * A branch that existed before the worker ran is gone.
     *
     * Blocking, and never repaired even though the SHA is recorded and
     * `git branch <name> <sha>` would restore it. A deleted reference may have
     * been the user's own cleanup; re-creating it would be this package
     * reversing somebody's deliberate act, and the recorded SHA in the message
     * is what lets *them* restore it in one command.
     */
    case BranchDeleted = 'branch_deleted';

    /**
     * The workspace is in the middle of a rebase, merge, cherry-pick, revert or
     * bisect.
     *
     * Blocking, and the first thing reported when it is found: a workspace in
     * this state has no resting answer to "what is HEAD" or "what is on the
     * branch", so every other finding beside it is a reading of an operation in
     * progress rather than of the work.
     */
    case OperationInProgress = 'operation_in_progress';

    /**
     * Whether this package may put the state back by itself.
     */
    public function isReconcilable(): bool
    {
        return match ($this) {
            self::CommitsAdded, self::BranchAttached, self::BranchClaimed => true,
            default => false,
        };
    }

    /**
     * Whether finding this stops the pass.
     */
    public function isBlocking(): bool
    {
        return !$this->isReconcilable();
    }

    /**
     * A short phrase naming the finding, for a status table or a log line.
     */
    public function label(): string
    {
        return match ($this) {
            self::CommitsAdded => 'commits made by the worker',
            self::HeadMoved => 'HEAD moved off the workspace\'s history',
            self::BranchAttached => 'the workspace attached to the pass\'s branch',
            self::BranchClaimed => 'the pass\'s branch created',
            self::ForeignBranch => 'the workspace attached to another branch',
            self::HeadDetached => 'the workspace detached',
            self::BranchCreated => 'a branch created',
            self::BranchMoved => 'a branch moved',
            self::BranchDeleted => 'a branch deleted',
            self::OperationInProgress => 'a Git operation left unfinished',
        };
    }
}
