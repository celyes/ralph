<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * The tasks the runner has carried through verification itself.
 *
 * The one input needed to tell a checkbox the user ticked apart from one Ralph
 * PHP earned ({@see Completion}). It is a set of identifiers and nothing more:
 * the state store of PROJECT.md section D owns the evidence — attempts,
 * verification output, commit SHAs — and answering "has this task been verified
 * here before?" is all the checklist side of the question needs.
 *
 * Deliberately a small value object rather than an interface onto the database.
 * Task selection is then pure and testable against literal sets, the state
 * store decides for itself what counts as verified instead of having that rule
 * re-stated here, and nothing about reading a checklist requires SQLite to be
 * open.
 *
 * Immutable: {@see self::with()} returns a new set, so a runner that has just
 * finished a task can move on without the list it is iterating changing
 * underneath it.
 */
final class VerifiedTasks
{
    /**
     * @param array<string, true> $ids keyed by canonical identifier, so lookup
     *                                 is a hash hit and duplicates collapse
     */
    private function __construct(private readonly array $ids)
    {
    }

    /**
     * Nothing has been verified: a first run, or a project whose state store is
     * empty.
     *
     * The default on purpose. A checklist read without the state store must
     * under-claim rather than over-claim, so every ticked item it carries is
     * reported as the user's completion.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * A set of the given identifiers.
     */
    public static function of(TaskId ...$ids): self
    {
        $set = [];

        foreach ($ids as $id) {
            $set[$id->value()] = true;
        }

        return new self($set);
    }

    /**
     * A set read back from stored identifiers.
     *
     * @param list<string> $ids
     *
     * @throws InvalidArgumentException when a stored value is not an
     *                                 identifier, which means the state store
     *                                 disagrees with this package about what a
     *                                 task is named and should not be guessed
     *                                 at
     */
    public static function fromStrings(array $ids): self
    {
        return self::of(...array_map(
            static fn (string $id): TaskId => TaskId::fromString($id),
            $ids,
        ));
    }

    /**
     * Whether the runner has verified this task.
     */
    public function contains(TaskId $id): bool
    {
        return isset($this->ids[$id->value()]);
    }

    /**
     * The same set with one more task in it.
     */
    public function with(TaskId $id): self
    {
        if ($this->contains($id)) {
            return $this;
        }

        return new self([...$this->ids, $id->value() => true]);
    }

    /**
     * How many tasks the set names.
     */
    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * Whether the set names nothing.
     */
    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /**
     * The identifiers as canonical strings, in insertion order.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return array_keys($this->ids);
    }
}
