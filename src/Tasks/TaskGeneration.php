<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * One pass at a task: its identifier together with which attempt generation the
 * runner is on.
 *
 * A {@see TaskId} is deliberately stable. It comes from the task's wording and
 * nothing else, so a task that was finished months ago and is unticked today
 * comes back with the identifier, the history and the log directory it had
 * before. That is what the user wants from `status` and `ralph logs`, and it is
 * also the problem this class exists to solve: the earlier pass left a branch
 * and a commit behind, and the new one must not land on top of them.
 *
 * So the unit of *execution* is the task plus a generation number, counted from
 * one. Generation 1 is the first time Ralph PHP ran the task; generation 2 is
 * the pass that started when the user reopened it. Attempts live inside a
 * generation — a retry after a failed verification is another attempt at the
 * same generation, on the same branch — while reopening starts a new one
 * (PROJECT.md section C, item 8).
 *
 * {@see self::slug()} is the part that gets written down. The first generation
 * is named by the bare identifier, because in a project where nothing has been
 * reopened every branch and log path reads plainly; later generations carry a
 * `-g2`, `-g3` suffix, and that suffix is the whole mechanism behind "a
 * separately owned branch": two generations of one task cannot name the same
 * branch, so the runner can never mistake the old pass's branch for its own and
 * reset it. The spelling is a promise rather than an implementation detail,
 * since it ends up in Git refs and in a SQLite database that outlive the
 * process, and it is pinned by a test.
 *
 * Immutable. {@see self::next()} returns the following generation rather than
 * advancing this one, so the record of the pass that just ended cannot change
 * under a caller that is still reporting on it.
 */
final class TaskGeneration
{
    /**
     * The generation every task starts at.
     *
     * One rather than zero: it is counted out loud — "generation 2" in a status
     * table, `-g2` in a branch name — and a user reading that expects the first
     * pass to have been the first.
     */
    public const int FIRST = 1;

    /**
     * What separates the identifier from the generation number in a slug.
     *
     * A letter is part of it on purpose. `TASK-19E0214A-2` reads like part of
     * the identifier; `TASK-19E0214A-g2` reads like a second go at it. Both
     * characters are safe in a Git branch name and in a directory name, which
     * is where slugs are used.
     */
    private const string SEPARATOR = '-g';

    /**
     * A slug, with the optional generation suffix captured.
     *
     * Case-insensitive, and the identifier half is canonicalised by
     * {@see TaskId::fromString()} afterwards, because a slug is read back from
     * places that do not preserve case faithfully — a user retyping a branch
     * name, a shell completion, a copy out of a status table. What is produced
     * is always the canonical spelling.
     */
    private const string SLUG_PATTERN = '/^(TASK-[0-9A-Fa-f]{8})(?:-g([1-9][0-9]*))?$/i';

    private function __construct(
        private readonly TaskId $task,
        private readonly int $number,
    ) {
    }

    /**
     * The first pass at a task.
     */
    public static function first(TaskId $task): self
    {
        return new self($task, self::FIRST);
    }

    /**
     * A named generation of a task.
     *
     * @throws InvalidArgumentException when the number is below the first
     *                                 generation, which is not a pass that can
     *                                 have happened and would produce a slug no
     *                                 later run could read back
     */
    public static function of(TaskId $task, int $number): self
    {
        if ($number < self::FIRST) {
            throw new InvalidArgumentException(sprintf(
                'A task generation is counted from %d; %d is not a generation.',
                self::FIRST,
                $number,
            ));
        }

        return new self($task, $number);
    }

    /**
     * Read a generation back from a slug.
     *
     * The inverse of {@see self::slug()}, for the callers that find a slug
     * written down rather than passed to them — a branch name, a log directory,
     * a stored column. A bare identifier is the first generation, which is how
     * it was written.
     *
     * @throws InvalidArgumentException when the text is not a slug this class
     *                                 produces, because guessing at a branch's
     *                                 owner is how unrelated work gets reset
     */
    public static function fromSlug(string $slug): self
    {
        $candidate = trim($slug);

        if (preg_match(self::SLUG_PATTERN, $candidate, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a task generation; expected a value like "TASK-19E0214A" or "TASK-19E0214A-g2".',
                $slug,
            ));
        }

        return new self(
            TaskId::fromString($matches[1]),
            isset($matches[2]) ? (int) $matches[2] : self::FIRST,
        );
    }

    /**
     * Whether text can be read as a generation slug.
     */
    public static function isSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, trim($slug)) === 1;
    }

    /**
     * The task this is a pass at.
     */
    public function task(): TaskId
    {
        return $this->task;
    }

    /**
     * Which pass this is, counted from {@see self::FIRST}.
     */
    public function number(): int
    {
        return $this->number;
    }

    /**
     * Whether this is the first time the task has been run.
     */
    public function isFirst(): bool
    {
        return $this->number === self::FIRST;
    }

    /**
     * Whether this generation exists because a finished task was reopened.
     *
     * Any generation after the first does: nothing else creates one.
     */
    public function isReopening(): bool
    {
        return $this->number > self::FIRST;
    }

    /**
     * The generation that follows this one.
     */
    public function next(): self
    {
        return new self($this->task, $this->number + 1);
    }

    /**
     * The name this generation is written down under.
     *
     * Used for the task branch, the log directory and anywhere else a pass
     * needs a name that cannot collide with another pass at the same task. The
     * suffix on the generation number is deliberately absent for the first
     * generation: see the class note.
     */
    public function slug(): string
    {
        return $this->isFirst()
            ? $this->task->value()
            : $this->task->value() . self::SEPARATOR . $this->number;
    }

    /**
     * The same thing phrased for a status table or a log line.
     */
    public function label(): string
    {
        return $this->isFirst()
            ? $this->task->value()
            : sprintf('%s (generation %d)', $this->task->value(), $this->number);
    }

    /**
     * Whether two generations name the same pass at the same task.
     */
    public function equals(self $other): bool
    {
        return $this->number === $other->number && $this->task->equals($other->task);
    }

    /**
     * Whether this is a pass at the given task.
     */
    public function belongsTo(TaskId $task): bool
    {
        return $this->task->equals($task);
    }

    public function __toString(): string
    {
        return $this->slug();
    }
}
