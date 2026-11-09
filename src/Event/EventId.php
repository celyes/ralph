<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Event;

use Celyes\RalphPhp\Tasks\TaskId;

/**
 * The name of a thing that happened, which is how a consumer deduplicates.
 *
 * PROJECT.md section I, item 4's second half. `(run, seq)` orders a run's
 * events and tells two lines apart, but it is a pair a consumer has to assemble
 * and, more to the point, it names a *line of the stream* rather than an
 * occurrence: the same ending reported twice would arrive under two sequences
 * and read as two endings. An identifier derived from what happened rather than
 * from where it landed makes the stream idempotent — a consumer that has acted
 * on an id can skip it, whether it is re-reading the file from the start,
 * replaying it into something with at-least-once delivery, or being handed a
 * line a second time by a run that reported it again.
 *
 * **It is derived from the occurrence, and from nothing else.** The run, the
 * kind, the task and one discriminator that says *which* occurrence of that
 * kind this is ({@see EventStream::append()}'s `$occurrence`). Not the
 * sequence, not the timestamp, not the payload: all three vary between two
 * reports of one occurrence, and including any of them would give a key that
 * only ever matches itself. Compute it twice from the same four facts, in two
 * processes, and it is the same string.
 *
 * **The run is part of it, deliberately.** A pass that earned its tick and
 * could not write it finishes one run `blocked` and a later run `completed` —
 * the same task, the same generation, two genuinely different events. Leaving
 * the run out would collapse them, and a consumer would act on whichever it saw
 * first and ignore the one that mattered.
 *
 * **It is opaque, and short.** A readable composite would invite consumers to
 * parse it, and then the separator and the order of the parts would be
 * interface too; every field it would carry is already its own field in the
 * envelope. A hash prefix also gives one fixed-width column whatever a task
 * identifier or a discriminator happens to look like. The parts are
 * length-prefixed before hashing so that no value can be arranged to read as
 * another arrangement of parts.
 */
final class EventId
{
    /**
     * What every identifier starts with, so that one found loose in a log is
     * recognisable as this package's.
     */
    public const string PREFIX = 'ev_';

    /**
     * Hex characters kept from the digest.
     *
     * 128 bits. The stream of a long-lived project holds thousands of events,
     * not billions, so this is far more than collision resistance needs; it is
     * chosen to be short enough to read in a terminal and long enough that
     * nobody has to think about it.
     */
    private const int LENGTH = 32;

    private function __construct(private readonly string $value)
    {
    }

    /**
     * The identifier of one occurrence.
     *
     * @param string $occurrence which occurrence of this kind, within this run,
     *        this is — a generation number for a finished pass, an iteration
     *        for a started one, and empty for the two events a run has exactly
     *        one of
     */
    public static function of(
        string $runId,
        EventKind $kind,
        ?TaskId $task = null,
        string $occurrence = '',
    ): self {
        $canonical = '';

        foreach ([$runId, $kind->value, $task?->value() ?? '', $occurrence] as $part) {
            $canonical .= strlen($part) . ':' . $part . "\x1f";
        }

        return new self(self::PREFIX . substr(hash('sha256', $canonical), 0, self::LENGTH));
    }

    /**
     * The identifier as it is written and compared.
     */
    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
