<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * What happened to a task in the checklist while it was being worked on.
 *
 * Every case but {@see self::None} stops the run for a human decision
 * (PROJECT.md section C, item 7). That is the deliberate bias: the alternatives
 * are to apply the finished work to a task that now asks for something else, or
 * to throw the work away, and both are worse than stopping with the branch
 * intact and saying what moved. The commit and the agent's output survive a
 * pause; the user resolves it by restoring the wording or by accepting the work
 * against the new one.
 *
 * The cases are about the *running* task alone. Tasks being added, removed,
 * reordered or edited elsewhere in the file is ordinary editing: the runner
 * re-reads the checklist before it selects the next task, so the file's own
 * order decides what happens next without anything having to be reconciled.
 */
enum TaskChange: string
{
    /**
     * The checklist still states the task, with the same details and the same
     * marker. Progress may be recorded against it.
     *
     * The task may have moved; position is not identity.
     */
    case None = 'none';

    /**
     * The description is untouched but the details under it were edited.
     *
     * The same task, asking for something different: the indented bullets are
     * the implementation notes and acceptance criteria the agent was given, so
     * work finished against the old ones cannot be called verified against the
     * new ones without a human looking.
     */
    case DetailsEdited = 'details_edited';

    /**
     * The description was edited, so the checklist now states a different task.
     *
     * Identity comes from the description ({@see TaskId}), which makes a
     * rewording indistinguishable from a deletion plus an addition. This is the
     * case where the reconciler could name the item that appeared in its place.
     */
    case Reworded = 'reworded';

    /**
     * The task is gone from the checklist, with nothing in its place.
     */
    case Removed = 'removed';

    /**
     * Someone ticked the item while the runner was working on it.
     *
     * Not the runner's own tick: that is written after reconciliation, not
     * before it. Recording a verified completion over a box the user had
     * already ticked would overwrite their statement with ours and lose the
     * fact that they considered the work done for their own reasons.
     */
    case Ticked = 'ticked';

    /**
     * The item was unticked while the runner was working on it.
     *
     * Reachable only for a task that was already ticked when it was picked up,
     * which is what retrying a prematurely ticked task does. Kept distinct from
     * {@see self::Ticked} so the message can say which direction the marker
     * moved rather than that it "changed".
     */
    case Unticked = 'unticked';

    /**
     * Whether this finding stops the run until a human resolves it.
     */
    public function pausesRun(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether the checklist still states the task at all.
     */
    public function isStillStated(): bool
    {
        return $this !== self::Reworded && $this !== self::Removed;
    }

    /**
     * A short label for a status table or a log line.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'unchanged',
            self::DetailsEdited => 'details edited',
            self::Reworded => 'reworded',
            self::Removed => 'removed',
            self::Ticked => 'ticked by hand',
            self::Unticked => 'unticked by hand',
        };
    }
}
