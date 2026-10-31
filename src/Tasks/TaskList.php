<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use InvalidArgumentException;

/**
 * A checklist's tasks, in file order, with what is left to do picked out.
 *
 * This is the shape the runner works from. {@see ChecklistParser} says what the
 * file contains, {@see TaskIdentifier} gives each item a name, and this answers
 * the two questions a loop and a status table actually ask: which task is next,
 * and what is the standing of every task that is not.
 *
 * Order is the file's order and nothing else. A checklist is a document a human
 * reads top to bottom, so the task they put first is the task they meant first;
 * no priority field, no sorting, no reordering by identifier. The runner takes
 * {@see self::next()} until there is none left.
 *
 * Ticked items are skipped, not run — and are *not* reported as Ralph PHP's
 * work unless the state store says so. A ticked item with no record behind it
 * is {@see Completion::UserCompleted}: the user's claim that the work exists,
 * kept in the list because it is the project's history, because `status` has to
 * show it, and because reopening it later must bring back the same identifier.
 * See {@see Completion} for why that distinction is kept rather than collapsed
 * into "done".
 *
 * Immutable, and a snapshot: it describes one reading of the file.
 * {@see ChecklistWriter} changes that file underneath it, and PROJECT.md
 * section C, item 7 makes a mid-run edit something to reconcile rather than
 * absorb, so a runner that has finished a task re-reads instead of mutating
 * this.
 */
final class TaskList
{
    /**
     * @param list<Task> $tasks in file order
     * @param array<string, Completion> $completion keyed by identifier
     */
    private function __construct(
        private readonly array $tasks,
        private readonly array $completion,
    ) {
    }

    /**
     * Read a checklist as a work list.
     *
     * @param VerifiedTasks|null $verified what the state store has carried
     *                                     through verification itself; null,
     *                                     the default, means nothing has been,
     *                                     so a caller without a state store
     *                                     under-claims rather than over-claims
     *
     * @throws ChecklistException when two items state the same task
     */
    public static function fromChecklist(
        Checklist $checklist,
        ?VerifiedTasks $verified = null,
        TaskIdentifier $identifier = new TaskIdentifier(),
    ): self {
        return self::of(
            $identifier->identify($checklist),
            $verified ?? VerifiedTasks::none(),
        );
    }

    /**
     * Build a list from tasks that have already been identified.
     *
     * @param array<Task> $tasks in file order; any array, because a caller
     *                            that filtered or sliced its tasks keeps the
     *                            order without having to reindex first
     * @param VerifiedTasks|null $verified null, the default, meaning nothing
     *                                     has been verified
     *
     * @throws InvalidArgumentException when two tasks share an identifier,
     *                                 which {@see TaskIdentifier} refuses with
     *                                 a readable error long before here
     */
    public static function of(array $tasks, ?VerifiedTasks $verified = null): self
    {
        $verified ??= VerifiedTasks::none();
        $completion = [];

        foreach ($tasks as $task) {
            $id = $task->id()->value();

            if (isset($completion[$id])) {
                throw new InvalidArgumentException(
                    sprintf('Two tasks share the identifier %s.', $id),
                );
            }

            $completion[$id] = self::completionFor($task, $verified);
        }

        return new self(array_values($tasks), $completion);
    }

    /**
     * Every task the checklist states, ticked or not, in file order.
     *
     * @return list<Task>
     */
    public function all(): array
    {
        return $this->tasks;
    }

    /**
     * The tasks still to do, in file order.
     *
     * @return list<Task>
     */
    public function pending(): array
    {
        return $this->matching(static fn (Completion $completion): bool => $completion->isPending());
    }

    /**
     * The tasks the checklist marks done, in file order, whoever finished them.
     *
     * @return list<Task>
     */
    public function done(): array
    {
        return $this->matching(static fn (Completion $completion): bool => $completion->isDone());
    }

    /**
     * The ticked tasks nothing on record backs, in file order.
     *
     * @return list<Task>
     */
    public function userCompleted(): array
    {
        return $this->withCompletion(Completion::UserCompleted);
    }

    /**
     * The tasks this project has carried through verification, in file order.
     *
     * @return list<Task>
     */
    public function runnerVerified(): array
    {
        return $this->withCompletion(Completion::RunnerVerified);
    }

    /**
     * The next task to work on, or null when nothing is pending.
     *
     * The first unticked item in the file, however many ticked ones precede it.
     */
    public function next(): ?Task
    {
        foreach ($this->tasks as $task) {
            if ($this->completionOf($task->id())->isPending()) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Whether there is anything to work on.
     */
    public function hasPending(): bool
    {
        return $this->next() !== null;
    }

    /**
     * Whether the checklist states no tasks at all.
     *
     * Not the same as having nothing pending: an empty checklist means there is
     * nothing to do yet, a fully ticked one means the work is finished, and
     * only the caller can decide how to say that.
     */
    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /**
     * How many tasks the checklist states.
     */
    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * How many tasks are still to do.
     */
    public function pendingCount(): int
    {
        return count($this->pending());
    }

    /**
     * How many tasks the checklist marks done.
     */
    public function doneCount(): int
    {
        return count($this->done());
    }

    /**
     * Where a task stands, by identifier.
     *
     * @throws InvalidArgumentException when the identifier is not in this list,
     *                                 because answering "pending" for a task
     *                                 that is not there would have a caller run
     *                                 work the checklist no longer states
     */
    public function completionOf(TaskId $id): Completion
    {
        return $this->completion[$id->value()]
            ?? throw new InvalidArgumentException(
                sprintf('%s is not a task in this checklist.', $id->value()),
            );
    }

    /**
     * Whether this list has the named task.
     */
    public function has(TaskId $id): bool
    {
        return isset($this->completion[$id->value()]);
    }

    /**
     * The named task, or null when the checklist does not state it.
     *
     * What `ralph logs TASK-ID` and `ralph retry TASK-ID` resolve their
     * argument with.
     */
    public function find(TaskId $id): ?Task
    {
        foreach ($this->tasks as $task) {
            if ($task->id()->equals($id)) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Where a task stands, given what the runner has verified.
     *
     * An unticked task is pending even when the state store has verified it
     * before: that is a completed item the user reopened, and the file is the
     * authority on what is being asked for. It keeps the identifier it had, so
     * its history is still findable; which execution generation the new pass
     * belongs to is {@see GenerationSelector}'s decision, taken from what the
     * state store recorded rather than from the marker. What matters here is
     * that it is not skipped.
     */
    private static function completionFor(Task $task, VerifiedTasks $verified): Completion
    {
        if (!$task->isChecked()) {
            return Completion::Pending;
        }

        return $verified->contains($task->id())
            ? Completion::RunnerVerified
            : Completion::UserCompleted;
    }

    /**
     * @param callable(Completion): bool $predicate
     *
     * @return list<Task>
     */
    private function matching(callable $predicate): array
    {
        return array_values(array_filter(
            $this->tasks,
            fn (Task $task): bool => $predicate($this->completionOf($task->id())),
        ));
    }

    /**
     * @return list<Task>
     */
    private function withCompletion(Completion $completion): array
    {
        return $this->matching(
            static fn (Completion $candidate): bool => $candidate === $completion,
        );
    }
}
