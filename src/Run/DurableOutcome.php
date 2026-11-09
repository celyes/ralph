<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\State\RunRecord;

/**
 * The outcome an event is allowed to announce: the one the store already holds.
 *
 * PROJECT.md section I, item 4's first half. An event stream is acted on by
 * other programs — a wrapper that merges a branch, a CI job that reports a
 * task done, an editor that moves a card — and the one thing none of them can
 * recover from is being told a task finished when the next process to open the
 * database will not agree. So a finishing event's outcome is not read off the
 * object the loop is holding; it is read back out of the rows the pass wrote,
 * after it wrote them.
 *
 * **Which makes the ordering structural rather than incidental.** The loop
 * already emitted its finishing events in the right place, but "in the right
 * place" is a property of the order the lines of {@see Runner} happen to be in,
 * and nothing about it would notice being reordered. Reading the outcome out of
 * the store cannot be reordered into announcing something undurable: moved
 * earlier, it would report the row as it stood earlier, which is `running` and
 * a phase — not a completion.
 *
 * **The reading is published, not just consulted.** `durable` carries the rest
 * of what the finalization rests on — the phase, the commit, whether a
 * checklist write is still owed, when the row was closed — so a consumer that
 * needs more than the single word can check it without opening the database,
 * and nobody has to take this object's word for the gate having been applied.
 *
 * **A row that cannot be read back is reported, not dressed up.** The pass
 * wrote it moments earlier, so there is no ordinary way for it to be missing;
 * if it is, the line says `confirmed: false` and carries the pass's own claim
 * under `claimed`. Inventing an outcome for it, or silently writing the claim
 * as though the store had agreed, are the two things that would make the stream
 * untrustworthy for everything else.
 */
final class DurableOutcome
{
    /**
     * @param array<string, mixed> $durable what the store says, for the payload
     */
    private function __construct(
        private readonly string $outcome,
        private readonly bool $confirmed,
        private readonly array $durable,
    ) {
    }

    /**
     * What the store says about a pass that has just ended.
     *
     * The generation row is the pass's authority for everything durable
     * ({@see TaskResult}), so it is the row this reads: its state is the
     * outcome the event announces, and the checklist plan beside it is what
     * says whether the tick the state implies actually landed. A completed row
     * always has its write settled — {@see TaskPass::finalize()} ticks the box
     * before it completes the row, exactly so that the two cannot come apart —
     * and the field is published rather than assumed.
     *
     * Store failures are left to propagate: the caller has just written to the
     * same database to bill the pass's time, so a read that cannot be made here
     * is not a new way for a run to end.
     */
    public static function ofTask(Journal $journal, TaskResult $result): self
    {
        $claimed = $result->state()->value;
        $record = $journal->generations()->find($result->generation());

        if ($record === null) {
            return new self($claimed, false, [
                'confirmed' => false,
                'claimed' => $claimed,
                'reason' => 'the pass\'s own row could not be read back',
            ]);
        }

        $durable = [
            'confirmed' => true,
            'state' => $record->state()->value,
            'phase' => $record->phase()?->value,
            'commit' => $record->commitSha(),
            'checklist_write_owed' => $journal->checklists()->outstandingFor($record) !== null,
            'finished_at' => $record->finishedAt()?->value(),
        ];

        // Only when they differ, because a field that is nearly always a copy
        // of `outcome` would be read as part of the envelope rather than as the
        // disagreement it is there to report.
        if ($record->state()->value !== $claimed) {
            $durable['claimed'] = $claimed;
        }

        return new self($record->state()->value, true, $durable);
    }

    /**
     * What the store says about a run that has just been closed.
     *
     * A run's outcome is its stop kind rather than a lifecycle state, and the
     * row carries it, so the same rule applies: the kind the event announces is
     * the one a later `ralph status` will read back. A row still open has
     * nothing to announce — the caller is about to close it, or failed to — so
     * the stop the caller decided on is written with `confirmed: false` beside
     * it, since a consumer that saw `run.started` and nothing after it cannot
     * tell a crashed run from one still going.
     */
    public static function ofRun(Journal $journal, RunRecord $run, RunStop $stop): self
    {
        $decided = $stop->kind()->value;
        $current = $journal->runs()->find($run->runId());

        if ($current === null) {
            return new self($decided, false, [
                'confirmed' => false,
                'claimed' => $decided,
                'reason' => 'the run\'s own row could not be read back',
            ]);
        }

        $recorded = $current->stopKind()?->value;
        $confirmed = $current->isFinished() && $recorded !== null;

        $durable = [
            'confirmed' => $confirmed,
            'state' => $current->state()->value,
            'finished_at' => $current->finishedAt()?->value(),
        ];

        if (!$confirmed) {
            $durable['reason'] = 'the run\'s row is not closed';
        }

        if ($recorded !== null && $recorded !== $decided) {
            $durable['claimed'] = $decided;
        }

        return new self($recorded ?? $decided, $confirmed, $durable);
    }

    /**
     * The word the event carries.
     */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * Whether the store confirmed it.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * The reading, for the event's payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->durable;
    }
}
