<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

/**
 * What reconciling an interrupted pass against the repository concluded.
 *
 * The three answers PROJECT.md section H, item 5 allows, and the whole decision
 * a resume makes about one pass before it does anything else. Reconciliation
 * reads three things — the pass's workspace, its branch, and the commit its row
 * records — and the repository is the authority on all of them: a row is only
 * ever a copy, written at a moment that may be an instant before the process
 * stopped.
 *
 * The line between {@see self::Reconciled} and {@see self::Blocked} is the one
 * {@see \Celyes\RalphPhp\Git\DriftKind} draws for a worker's changes, asked
 * about a crash instead: **can the row be made to tell the truth without
 * guessing what somebody meant?** Adopting a commit that names the pass in its
 * own trailers is a reading, not a guess. Deciding which of two commits on a
 * branch is the pass's, or what happened to a commit the repository no longer
 * has, is a guess, and the convenient guesses are the ones that either
 * implement a task twice or publish work nothing verified.
 */
enum RecoveryVerdict: string
{
    /**
     * The repository and the row already agree.
     *
     * The ordinary interruption: a pass stopped during preparation, while the
     * agent was working, or during the checks has nothing recorded that the
     * repository could contradict. Resume continues from the recorded
     * {@see \Celyes\RalphPhp\State\Phase}, which is what decides whether that
     * means another attempt or picking this one up.
     */
    case Consistent = 'consistent';

    /**
     * Something was put right, and the row now says what the repository holds.
     *
     * The named case of section H, item 5: a crash between a commit and the
     * write that records it leaves a commit in the repository that the row does
     * not name, and the commit's own trailers are what identify it
     * ({@see \Celyes\RalphPhp\Git\CommitIdentity}). Adopting it is what keeps
     * resume from implementing and committing the same task a second time
     * (acceptance criterion 6). A branch reference re-created at a commit the
     * row already records is the other repair.
     */
    case Reconciled = 'reconciled';

    /**
     * The state cannot be reconciled, and a person has to say what it means.
     *
     * Ambiguous ownership, in the sense section H, item 9 uses it: a branch
     * holding a commit no pass recorded, a commit the row names that the
     * repository does not have, a workspace registered somewhere the
     * configuration no longer places it. Nothing is undone, nothing is created
     * and nothing is deleted — the pass keeps its branch, its worktree and its
     * logs, so that whatever the person decides, the evidence is still there.
     */
    case Blocked = 'blocked';

    /**
     * Whether resume may carry on with this pass.
     */
    public function allowsResume(): bool
    {
        return $this !== self::Blocked;
    }

    /**
     * Whether anything had to be put right.
     */
    public function neededWork(): bool
    {
        return $this === self::Reconciled;
    }

    /**
     * The verdict as a line in a report.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Consistent => 'the repository matches what the pass recorded',
            self::Reconciled => 'the pass was reconciled with the repository',
            self::Blocked => 'the pass cannot be reconciled without a decision',
        };
    }
}
