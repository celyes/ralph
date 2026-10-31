<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * Every pass the project has recorded, by task.
 *
 * The state store's word about what has already happened, in the one shape
 * reopening needs: for each task, the generations that exist and whether the
 * last one finished. Like {@see VerifiedTasks}, it is a value object rather than
 * an interface onto the database, so deciding which generation a task should run
 * in is pure, testable against literal records, and possible without SQLite
 * being open — and so the state store keeps deciding for itself what counts as
 * a complete pass instead of having that rule restated here.
 *
 * It is also the place the invariant lives. A task has at most one open pass:
 * a failed or interrupted generation is continued, never superseded, and a new
 * generation is only ever created from a complete one. A history that breaks
 * that is refused on construction rather than resolved by guesswork, because
 * the guess decides which branch a run writes to.
 *
 * Immutable; {@see self::with()} returns a new history.
 */
final class GenerationHistory
{
    /**
     * @param array<string, list<RecordedGeneration>> $byTask keyed by canonical
     *                                                        identifier, each
     *                                                        list ordered by
     *                                                        generation number
     */
    private function __construct(private readonly array $byTask)
    {
    }

    /**
     * Nothing has been recorded: a first run, or a project whose state store is
     * empty.
     *
     * The default on purpose. A checklist read without the state store must
     * treat every task as never run, which is the reading that cannot
     * accidentally claim an earlier pass's branch.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * A history of the given passes, in any order.
     *
     * @throws InvalidArgumentException when one task has two records of the
     *                                 same generation, or an open generation
     *                                 that is not its latest — both of which
     *                                 make the task's current pass ambiguous
     */
    public static function of(RecordedGeneration ...$records): self
    {
        /** @var array<string, array<int, RecordedGeneration>> $byTask */
        $byTask = [];

        foreach ($records as $record) {
            $task = $record->task()->value();
            $number = $record->number();

            if (isset($byTask[$task][$number])) {
                throw new InvalidArgumentException(sprintf(
                    'Generation %d of %s is recorded twice.',
                    $number,
                    $task,
                ));
            }

            $byTask[$task][$number] = $record;
        }

        return new self(array_map(self::ordered(...), $byTask));
    }

    /**
     * Whether anything has been recorded for this task.
     */
    public function knows(TaskId $task): bool
    {
        return isset($this->byTask[$task->value()]);
    }

    /**
     * Every recorded pass at a task, oldest first.
     *
     * @return list<RecordedGeneration>
     */
    public function generationsOf(TaskId $task): array
    {
        return $this->byTask[$task->value()] ?? [];
    }

    /**
     * The task's most recent pass, or null when it has never been run.
     */
    public function latestOf(TaskId $task): ?RecordedGeneration
    {
        $generations = $this->generationsOf($task);

        return $generations === [] ? null : $generations[count($generations) - 1];
    }

    /**
     * How many passes a task has had.
     */
    public function countOf(TaskId $task): int
    {
        return count($this->generationsOf($task));
    }

    /**
     * How many of a task's passes finished.
     *
     * More than one means the task has been reopened and finished again, which
     * is ordinary: `status` reports it as history rather than as a problem.
     */
    public function completedCountOf(TaskId $task): int
    {
        return count(array_filter(
            $this->generationsOf($task),
            static fn (RecordedGeneration $pass): bool => $pass->isComplete(),
        ));
    }

    /**
     * Whether the task has a pass that stopped without finishing.
     */
    public function hasOpen(TaskId $task): bool
    {
        return $this->latestOf($task)?->isOpen() ?? false;
    }

    /**
     * Whether the task's latest pass finished, so running it again is a
     * reopening.
     */
    public function isComplete(TaskId $task): bool
    {
        return $this->latestOf($task)?->isComplete() ?? false;
    }

    /**
     * The branches the task's recorded passes own, oldest first.
     *
     * What a new generation must not be given. Passes that recorded no branch
     * are left out: there is nothing to protect.
     *
     * @return list<string>
     */
    public function branchesOf(TaskId $task): array
    {
        return array_values(array_filter(array_map(
            static fn (RecordedGeneration $pass): ?string => $pass->branch(),
            $this->generationsOf($task),
        )));
    }

    /**
     * The same history with one more pass in it.
     *
     * @throws InvalidArgumentException when the pass contradicts what is
     *                                 already recorded
     */
    public function with(RecordedGeneration $record): self
    {
        return self::of(...[...$this->records(), $record]);
    }

    /**
     * The tasks this history knows anything about, as canonical identifiers.
     *
     * @return list<string>
     */
    public function tasks(): array
    {
        return array_keys($this->byTask);
    }

    /**
     * Every recorded pass, task by task.
     *
     * @return list<RecordedGeneration>
     */
    public function records(): array
    {
        return array_merge(...array_values($this->byTask));
    }

    /**
     * Whether nothing at all has been recorded.
     */
    public function isEmpty(): bool
    {
        return $this->byTask === [];
    }

    /**
     * The tasks this project has carried through verification itself.
     *
     * The bridge to {@see TaskList}, which needs exactly this to tell a box the
     * user ticked from one Ralph PHP earned. A task counts once any of its
     * passes completed: reopening it does not retract the fact that an earlier
     * pass was verified, and if the item is unticked today the list reports it
     * as pending regardless of what is recorded here.
     */
    public function verified(): VerifiedTasks
    {
        $verified = VerifiedTasks::none();

        foreach ($this->byTask as $id => $generations) {
            foreach ($generations as $pass) {
                if ($pass->isComplete()) {
                    $verified = $verified->with(TaskId::fromString($id));

                    break;
                }
            }
        }

        return $verified;
    }

    /**
     * Order one task's passes by generation number and check the invariant.
     *
     * @param array<int, RecordedGeneration> $generations
     *
     * @return list<RecordedGeneration>
     *
     * @throws InvalidArgumentException when an open pass is not the latest
     */
    private static function ordered(array $generations): array
    {
        ksort($generations);

        $ordered = array_values($generations);
        $last = count($ordered) - 1;

        foreach ($ordered as $index => $pass) {
            if ($index !== $last && $pass->isOpen()) {
                throw new InvalidArgumentException(sprintf(
                    'Generation %d of %s is unfinished but is not its latest generation, '
                    . 'so which pass the task is on cannot be decided.',
                    $pass->number(),
                    $pass->task()->value(),
                ));
            }
        }

        return $ordered;
    }
}
