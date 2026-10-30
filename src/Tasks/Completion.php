<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Ralph;

/**
 * Why a task counts as done — or that it does not yet.
 *
 * The checklist records one bit: ticked or not. That bit cannot say who ticked
 * it, and the difference matters. A checklist handed to Ralph PHP for the first
 * time usually arrives with items already marked `- [x]`, and those are the
 * user's word that the work exists; Ralph PHP has not run them, has no commit
 * for them and has no verification output to show. Reporting them as though it had
 * would be a lie in `status`, and in a tool whose only product is evidence that
 * is the one thing it cannot afford.
 *
 * So a ticked item is {@see self::UserCompleted} until the state store can show
 * that this run — or an earlier one — carried it through verification, a commit
 * and any required push, which is what {@see self::RunnerVerified} asserts.
 * Until section D exists there is nothing that can produce that verdict, and an
 * imported checklist is read exactly as what it is: the user's history.
 *
 * Not to be confused with the lifecycle states of PROJECT.md section D
 * (`pending`, `running`, `completed`, `blocked`, …), which track where a task
 * is inside a run. This says where its *completion* came from, and only ever
 * has these three answers.
 */
enum Completion: string
{
    /**
     * The checklist leaves the item unticked: work still to do.
     */
    case Pending = 'pending';

    /**
     * The item arrived ticked, with nothing on record to back it.
     *
     * Skipped rather than run, and reported as the user's claim rather than as
     * the runner's: no attempt, no commit and no verification output belong to
     * it.
     */
    case UserCompleted = 'user_completed';

    /**
     * Ralph PHP ran the task and recorded that it passed.
     *
     * Means the whole of it: verification passed, a commit or a justified
     * no-change result was recorded, and any required push succeeded — the
     * conditions PROJECT.md puts on ticking a box in the first place.
     */
    case RunnerVerified = 'runner_verified';

    /**
     * Whether the task needs no further work.
     */
    public function isDone(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether a run should pick this task up.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::UserCompleted => 'done (by you)',
            self::RunnerVerified => 'done (verified)',
        };
    }

    /**
     * The same thing phrased for a sentence.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Pending => 'not started',
            self::UserCompleted => sprintf(
                'marked done in the checklist, not verified by %s',
                Ralph::NAME,
            ),
            self::RunnerVerified => sprintf('completed and verified by %s', Ralph::NAME),
        };
    }
}
