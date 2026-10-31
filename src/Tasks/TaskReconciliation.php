<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ReconciliationException;
use Celyes\RalphPhp\Support\Excerpt;
use LogicException;

/**
 * What a fresh reading of the checklist says about the task being worked on.
 *
 * The answer to "may this task's progress be recorded?" together with the
 * evidence for it: what the task said when the run started, what the file says
 * now, and — when the description was edited — the item that appeared in its
 * place. Returned rather than thrown, because the runner's response to a
 * changed task is to pause and *record* the pause (PROJECT.md section C, item 7
 * and section H, item 9), not to crash; {@see self::toException()} is for the
 * callers that have nowhere to park the finding and must stop with a readable
 * message instead.
 *
 * A reconciliation never changes anything. It reads two snapshots and reports.
 */
final class TaskReconciliation
{
    private function __construct(
        private readonly TaskChange $change,
        private readonly TaskSnapshot $snapshot,
        private readonly ?Task $current,
        private readonly ?Task $replacement,
        private readonly ?string $source,
    ) {
    }

    /**
     * The task is still the task that was asked for.
     *
     * @param Task $current the task as the file states it now, which may be on
     *                      a different line than it was
     */
    public static function unchanged(TaskSnapshot $snapshot, Task $current, ?string $source = null): self
    {
        return new self(TaskChange::None, $snapshot, $current, null, $source);
    }

    /**
     * The checklist still states the task, but not as it was.
     */
    public static function changed(
        TaskChange $change,
        TaskSnapshot $snapshot,
        Task $current,
        ?string $source = null,
    ): self {
        return new self($change, $snapshot, $current, null, $source);
    }

    /**
     * The description was edited; this item appeared where the task was.
     */
    public static function reworded(TaskSnapshot $snapshot, Task $replacement, ?string $source = null): self
    {
        return new self(TaskChange::Reworded, $snapshot, null, $replacement, $source);
    }

    /**
     * The task is gone and nothing in the file reads like it.
     */
    public static function removed(TaskSnapshot $snapshot, ?string $source = null): self
    {
        return new self(TaskChange::Removed, $snapshot, null, null, $source);
    }

    /**
     * What was found.
     */
    public function change(): TaskChange
    {
        return $this->change;
    }

    /**
     * The task as it read when the run started.
     */
    public function snapshot(): TaskSnapshot
    {
        return $this->snapshot;
    }

    /**
     * The task as the checklist states it now, or null when it is gone.
     */
    public function current(): ?Task
    {
        return $this->current;
    }

    /**
     * The item that appeared where the task was, or null when there is none.
     *
     * A guess, and labelled as one in the message: it is the new item whose
     * wording is closest to the task that disappeared. Offered because a
     * rewording leaves no trace linking the two, and the user needs to see
     * which line the reconciler means.
     */
    public function replacement(): ?Task
    {
        return $this->replacement;
    }

    /**
     * Whether the run must stop for a human decision.
     */
    public function requiresPause(): bool
    {
        return $this->change->pausesRun();
    }

    /**
     * Whether the task may have its progress recorded.
     */
    public function isUnchanged(): bool
    {
        return !$this->change->pausesRun();
    }

    /**
     * One sentence naming the task, the file and what moved.
     */
    public function reason(): string
    {
        $subject = Checklist::describe($this->source);
        $id = $this->snapshot->id()->value();
        $quoted = Excerpt::of($this->snapshot->description());

        return match ($this->change) {
            TaskChange::None => sprintf('%s still states %s as it was.', $subject, $id),
            TaskChange::DetailsEdited => sprintf(
                '%s has edited details under %s since the task started: "%s".',
                $subject,
                $id,
                $quoted,
            ),
            TaskChange::Reworded => sprintf(
                '%s no longer states %s ("%s"); the closest item now reads "%s".',
                $subject,
                $id,
                $quoted,
                Excerpt::of($this->replacement?->description() ?? ''),
            ),
            TaskChange::Removed => sprintf(
                '%s no longer states %s ("%s").',
                $subject,
                $id,
                $quoted,
            ),
            TaskChange::Ticked => sprintf(
                '%s was marked done in %s by something other than this run: "%s".',
                $id,
                $subject,
                $quoted,
            ),
            TaskChange::Unticked => sprintf(
                '%s was marked as still to do in %s while this run was working on it: "%s".',
                $id,
                $subject,
                $quoted,
            ),
        };
    }

    /**
     * What the user can do about it.
     *
     * Every list ends by saying where the work is, because the first thing a
     * person wants to know when a run stops after an agent has run is whether
     * what it produced still exists.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        $preserved = 'Nothing was ticked and nothing was discarded: the work is still on its task branch, with its logs.';

        return match ($this->change) {
            TaskChange::None => [],
            TaskChange::DetailsEdited => [
                'The details are the notes and acceptance criteria the agent was given, so the finished work was checked against the previous ones.',
                sprintf('Run "ralph retry %s" to do the task again against the details as they now read.', $this->snapshot->id()->value()),
                $preserved,
            ],
            TaskChange::Reworded, TaskChange::Removed => [
                'A task is identified by its description, so editing or deleting the line makes it a different task.',
                'Restore the wording the run started with to have this result recorded against it.',
                $preserved,
            ],
            TaskChange::Ticked => [
                'Untick the item to have this run\'s result recorded against it, or leave it ticked to keep it as your own completion.',
                $preserved,
            ],
            TaskChange::Unticked => [
                'Leave the item unticked to have the task run again, or tick it to keep the earlier completion.',
                $preserved,
            ],
        };
    }

    /**
     * The same finding as a failure a command can stop on.
     *
     * @throws LogicException when nothing changed, which is not a failure and
     *                         must not be reported as one
     */
    public function toException(): ReconciliationException
    {
        if ($this->isUnchanged()) {
            throw new LogicException('An unchanged task is not a reconciliation failure.');
        }

        return new ReconciliationException($this->reason(), $this->change, $this->hints());
    }
}
