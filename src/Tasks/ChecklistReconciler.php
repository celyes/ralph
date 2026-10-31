<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use InvalidArgumentException;

/**
 * Compares the checklist as it reads now against the reading a task started
 * from, and says whether its progress may be recorded.
 *
 * The checklist belongs to the user. They can reword a task, delete it, rewrite
 * the acceptance criteria under it or tick it by hand at any point, including
 * the minutes an agent spends working on it — and a loop that re-reads the file
 * and carries on would then tick a box for work nobody asked for, or record a
 * verified completion against criteria the result was never checked against.
 * PROJECT.md section C, item 7 asks for the opposite: notice, and pause.
 *
 * So the runner takes a {@see ChecklistSnapshot} when it selects a task, and
 * passes a fresh reading through here before it finalises anything. Everything
 * except {@see TaskChange::None} is a stop, and nothing is undone by the stop:
 * the branch, the commit and the logs stay where they are, which is what makes
 * "put the wording back and run again" a complete remedy.
 *
 * Free of I/O and of the state store. {@see ChecklistReader} supplies the fresh
 * reading; what the pause is recorded as is section D's business.
 *
 * What is deliberately *not* reconciled is the rest of the file. Tasks added,
 * deleted, reordered or edited elsewhere are ordinary editing, and the loop
 * picks up the next task from a fresh reading anyway, so the file's own order
 * decides what runs next without anyone having to resolve anything.
 */
final class ChecklistReconciler
{
    /**
     * How alike two descriptions must read before one is offered as the other's
     * rewording, as a percentage of matching characters.
     *
     * A hint, never a decision: the run stops either way, and the only
     * difference this makes is whether the message can point at a line. Set
     * where a corrected typo, an added clause or a retitled task still matches
     * while two unrelated tasks do not. Erring high would leave the user
     * hunting for what their edit did; erring low would point at the wrong
     * line, which is worse, so a near-miss is reported as a removal and the
     * user is told the task is gone.
     */
    private const int SIMILAR_ENOUGH = 60;

    public function __construct(private readonly TaskIdentifier $identifier = new TaskIdentifier())
    {
    }

    /**
     * Reconcile the running task against a freshly parsed checklist.
     *
     * @param TaskId $running the task the runner is working on, which must be
     *                        one of the tasks the snapshot recorded
     *
     * @throws InvalidArgumentException when the snapshot does not have the
     *                                 task, which means the caller is comparing
     *                                 against the wrong reading and no answer
     *                                 it gets could be trusted
     * @throws ChecklistException when the checklist as it now reads cannot be
     *                            identified at all — two items stating one task,
     *                            say — which is itself an edit the user has to
     *                            resolve, and is already reported in those words
     */
    public function reconcile(
        ChecklistSnapshot $snapshot,
        TaskId $running,
        Checklist $current,
    ): TaskReconciliation {
        return $this->compare(
            self::recorded($snapshot, $running),
            $snapshot,
            $this->identifier->identify($current),
            $current->source(),
        );
    }

    /**
     * Reconcile against tasks that have already been identified.
     *
     * For a caller that holds a {@see TaskList} from the fresh reading — the
     * runner does, since it needs the same reading to choose what to do next —
     * and should not pay to identify it twice.
     *
     * @throws InvalidArgumentException when the snapshot does not have the task
     */
    public function reconcileAgainst(
        ChecklistSnapshot $snapshot,
        TaskId $running,
        TaskList $current,
        ?string $source = null,
    ): TaskReconciliation {
        return $this->compare(
            self::recorded($snapshot, $running),
            $snapshot,
            $current->all(),
            $source ?? $snapshot->source(),
        );
    }

    /**
     * The running task as the snapshot recorded it.
     *
     * @throws InvalidArgumentException when it is not there
     */
    private static function recorded(ChecklistSnapshot $snapshot, TaskId $running): TaskSnapshot
    {
        return $snapshot->find($running)
            ?? throw new InvalidArgumentException(
                sprintf('%s is not a task in the recorded checklist snapshot.', $running->value()),
            );
    }

    /**
     * @param list<Task> $current the checklist as it now reads, in file order
     */
    private function compare(
        TaskSnapshot $task,
        ChecklistSnapshot $snapshot,
        array $current,
        ?string $source,
    ): TaskReconciliation {
        $source ??= $snapshot->source();
        $stated = self::findIn($current, $task->id());

        if ($stated === null) {
            $replacement = $this->replacementFor($task, $snapshot, $current);

            return $replacement === null
                ? TaskReconciliation::removed($task, $source)
                : TaskReconciliation::reworded($task, $replacement, $source);
        }

        // The marker is examined before the details on purpose: a user who
        // ticked the item by hand has said something about the whole task, and
        // that is the finding to report even if they also edited the notes.
        if ($stated->isChecked() !== $task->isChecked()) {
            return TaskReconciliation::changed(
                $stated->isChecked() ? TaskChange::Ticked : TaskChange::Unticked,
                $task,
                $stated,
                $source,
            );
        }

        if (!$task->statesSameWork($stated)) {
            return TaskReconciliation::changed(TaskChange::DetailsEdited, $task, $stated, $source);
        }

        return TaskReconciliation::unchanged($task, $stated, $source);
    }

    /**
     * The item that most likely used to be this task, or null when none does.
     *
     * Only items that were not in the file when the run started are considered:
     * a task that was already there is its own task and someone may well be
     * waiting for it, so offering it as the rewording of another would be a
     * confusing guess. Among those, the one whose description reads closest to
     * the task that disappeared wins, provided it is close enough to be worth
     * naming.
     *
     * @param list<Task> $current
     */
    private function replacementFor(
        TaskSnapshot $task,
        ChecklistSnapshot $snapshot,
        array $current,
    ): ?Task {
        $best = null;
        $bestScore = 0.0;

        foreach ($current as $candidate) {
            if ($snapshot->knows($candidate->id())) {
                continue;
            }

            $score = self::similarity($task->description(), $candidate->description());

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $bestScore >= self::SIMILAR_ENOUGH ? $best : null;
    }

    /**
     * How alike two descriptions read, as a percentage.
     *
     * Compared after the normalisation task identity uses, so the comparison is
     * not thrown off by capitalisation or by the spacing of a reflowed line.
     * `similar_text()` is not symmetric in its argument order, so the
     * description that disappeared is always passed first and the same two
     * descriptions always score the same.
     */
    private static function similarity(string $before, string $after): float
    {
        $percent = 0.0;

        similar_text(
            TaskBody::of($before)->normalized(),
            TaskBody::of($after)->normalized(),
            $percent,
        );

        return $percent;
    }

    /**
     * @param list<Task> $tasks
     */
    private static function findIn(array $tasks, TaskId $id): ?Task
    {
        foreach ($tasks as $task) {
            if ($task->id()->equals($id)) {
                return $task;
            }
        }

        return null;
    }
}
