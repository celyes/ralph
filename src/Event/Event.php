<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Event;

use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Json;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;

/**
 * One line of the structured event stream.
 *
 * PROJECT.md section I, item 3's envelope: a schema version, a sequence, a
 * timestamp, the run, the task and the outcome. Everything a consumer needs to
 * order the stream, correlate it and act on it is in those six fields, so that
 * reading an event never means reaching into the payload and never means
 * knowing which kind of event it is.
 *
 * **The schema version leads, because it is what makes the rest readable.** A
 * consumer that finds a number it does not know should stop rather than guess,
 * and it can only do that if the number is in every line. It is bumped when a
 * field is removed or its meaning changes; adding one is not a change a reader
 * has to be told about, and bumping for it would make every addition a breaking
 * release.
 *
 * **The sequence is the order, and the timestamp is not.** Timestamps are
 * written to the second ({@see Timestamp}), and several events of one run
 * routinely share one — a task that failed its preparation starts and finishes
 * inside the same second. So the sequence is what orders a run's events, it
 * starts at 1, and it has no gaps; the timestamp says when, which is a
 * different question and the one a person asks.
 *
 * **Sequences are per run, not per file.** The stream is one file a project
 * appends to, so numbering it from the file would mean reading the whole of it
 * back before the first event of a run could be written, and a truncated file
 * would silently renumber. Every line carries the run it belongs to, and
 * `(run, seq)` is what identifies an event.
 *
 * **The outcome is a word from a vocabulary this package already has.** A
 * finished task's is its {@see \Celyes\RalphPhp\State\LifecycleState}, a
 * finished run's is its {@see \Celyes\RalphPhp\State\StopKind}, and the two
 * events that report something beginning say {@see self::STARTED}. Reusing
 * those words rather than inventing a parallel set is what keeps an event
 * agreeing with the row `ralph status` reads back.
 *
 * **The payload is advisory.** `data` holds what this package happened to know
 * at the time, in whatever shape the thing it describes records itself in. It is
 * there to save a consumer a database read, not to be depended on: a field may
 * appear in it without the schema moving, and nothing in it is a promise.
 */
final class Event
{
    /**
     * Version of the envelope this build writes.
     */
    public const int SCHEMA = 1;

    /**
     * The outcome of an event that reports something beginning.
     *
     * A started run and a started task have no outcome yet, and the field is
     * not left out for them: a consumer switching on `outcome` should not have
     * to handle an absent one, and "it has not come out any way yet" is itself
     * an outcome worth being able to match on.
     */
    public const string STARTED = 'started';

    /**
     * What an outcome may be spelled like.
     *
     * The shape the enums this package stores already use — lowercase words
     * joined by underscores. Held to deliberately: an outcome is matched on by
     * other programs, and one arriving with a capital or a space would be a
     * field that cannot be switched on.
     */
    private const string OUTCOME = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly int $sequence,
        private readonly Timestamp $at,
        private readonly string $runId,
        private readonly EventKind $kind,
        private readonly ?TaskId $task,
        private readonly string $outcome,
        private readonly array $data,
    ) {
    }

    /**
     * An event, with its envelope checked.
     *
     * Every refusal here is a mistake in this package rather than anything a
     * user did, which is why they are `InvalidArgumentException`s: an event with
     * no run to correlate it by, or a task result naming no task, would be a
     * line a consumer cannot use, and writing one is worse than noticing it.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the envelope would not be usable
     */
    public static function of(
        int $sequence,
        Timestamp $at,
        string $runId,
        EventKind $kind,
        string $outcome = self::STARTED,
        ?TaskId $task = null,
        array $data = [],
    ): self {
        if ($sequence < 1) {
            throw new InvalidArgumentException('An event sequence starts at 1.');
        }

        if (trim($runId) === '') {
            throw new InvalidArgumentException('An event belongs to a run, so it needs the run\'s identifier.');
        }

        if (preg_match(self::OUTCOME, $outcome) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'An event outcome is lowercase words joined by underscores; "%s" is not.',
                $outcome,
            ));
        }

        if ($kind->concernsATask() && $task === null) {
            throw new InvalidArgumentException(sprintf('A "%s" event is about a task.', $kind->value));
        }

        if (!$kind->concernsATask() && $task !== null) {
            throw new InvalidArgumentException(sprintf(
                'A "%s" event is about the run, so it cannot name one task.',
                $kind->value,
            ));
        }

        return new self($sequence, $at, $runId, $kind, $task, $outcome, $data);
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function at(): Timestamp
    {
        return $this->at;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function kind(): EventKind
    {
        return $this->kind;
    }

    public function task(): ?TaskId
    {
        return $this->task;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The event as a consumer reads it.
     *
     * `task` is present and null for a run-level event rather than left out:
     * one shape for every line is what lets a consumer read the field without
     * asking what kind of event it has.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'seq' => $this->sequence,
            'at' => $this->at->value(),
            'run' => $this->runId,
            'task' => $this->task?->value(),
            'event' => $this->kind->value,
            'outcome' => $this->outcome,
            'data' => $this->data,
        ];
    }

    /**
     * The one line this event is written as, without its line ending.
     */
    public function encode(): string
    {
        return Json::line($this->toArray());
    }
}
