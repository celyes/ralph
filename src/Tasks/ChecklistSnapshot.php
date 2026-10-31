<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;

/**
 * The whole checklist as it read when a task was picked up.
 *
 * One {@see TaskSnapshot} would be enough to notice that the running task
 * changed, but not enough to say anything useful about it: when a description
 * is edited the old task simply stops existing, and a message that says so and
 * stops there leaves the user to work out which of the lines in front of them
 * used to be it. Keeping every task's identity from the same reading lets
 * {@see ChecklistReconciler} point at the item that is new since the run
 * started — which is almost always the rewording — instead of reporting a
 * deletion.
 *
 * It is a snapshot and nothing more: no I/O, no state store, immutable. The
 * runner takes one when it selects a task and compares a fresh reading against
 * it when the work comes back.
 */
final class ChecklistSnapshot
{
    /**
     * @param list<TaskSnapshot> $tasks in file order
     * @param array<string, TaskSnapshot> $byId keyed by canonical identifier
     * @param string|null $source absolute path read, null when parsed from a string
     */
    private function __construct(
        private readonly array $tasks,
        private readonly array $byId,
        private readonly ?string $source,
    ) {
    }

    /**
     * Record a work list as it stands.
     *
     * @param string|null $source the file it was read from, for the messages
     *                            that will name it later
     */
    public static function of(TaskList $tasks, ?string $source = null): self
    {
        $snapshots = [];
        $byId = [];

        foreach ($tasks->all() as $task) {
            $snapshot = TaskSnapshot::of($task);

            $snapshots[] = $snapshot;
            $byId[$snapshot->id()->value()] = $snapshot;
        }

        return new self($snapshots, $byId, $source);
    }

    /**
     * Record a parsed checklist as it stands, identifying its items first.
     *
     * @throws ChecklistException when two items state the same task
     */
    public static function fromChecklist(Checklist $checklist): self
    {
        return self::of(TaskList::fromChecklist($checklist), $checklist->source());
    }

    /**
     * Every task the checklist stated, in file order.
     *
     * @return list<TaskSnapshot>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * The named task as it read, or null when the snapshot does not have it.
     */
    public function find(TaskId $id): ?TaskSnapshot
    {
        return $this->byId[$id->value()] ?? null;
    }

    /**
     * Whether the checklist stated this task when the snapshot was taken.
     */
    public function knows(TaskId $id): bool
    {
        return isset($this->byId[$id->value()]);
    }

    /**
     * The file the snapshot was read from, or null when it came from a string.
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * How to name this checklist at the start of a message about it.
     */
    public function subject(): string
    {
        return Checklist::describe($this->source);
    }

    /**
     * How many tasks the checklist stated.
     */
    public function count(): int
    {
        return count($this->tasks);
    }
}
