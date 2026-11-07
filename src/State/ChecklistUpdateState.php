<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * Where a planned write to the user's checklist stands.
 *
 * PROJECT.md section G, item 8 is the reason this enum exists: a checkbox write
 * that fails must leave the task in *pending finalization* — the implementation
 * and the commit are already durable, so what is owed is one line in a Markdown
 * file and nothing else. That is a fact about the write, not about the pass, and
 * the generation's own columns cannot hold it: a pass blocked on an unwritable
 * checklist and a pass blocked because the agent asked a question look identical
 * from `generations`, and only one of them is finished by making a file writable.
 *
 * So the plan is recorded before the file is touched and these four states say
 * what became of it:
 *
 * - {@see self::Pending} — planned and not landed. The state resume looks for,
 *   and the state a failed write stays in: the write is still owed and still
 *   the only thing owed.
 * - {@see self::Applied} — the file says what the run meant it to say.
 * - {@see self::Failed} — the write cannot be retried as planned, because what
 *   it was planned against has changed. A reconciliation, not a retry.
 * - {@see self::Superseded} — a later generation of the same task replaced the
 *   plan before it was applied, so nobody should act on it.
 *
 * The distinction between `pending` and `failed` is the one that earns its
 * keep. Both describe a write that did not happen, and they call for opposite
 * things: one is retried unchanged, the other must not be, because the line it
 * would be applied to is no longer the line it was planned for.
 *
 * Spellings are pinned: they are written into `checklist_updates.state`, which
 * the database constrains with {@see Schema::checklistUpdateStates()}, built
 * from these cases so the two cannot drift.
 */
enum ChecklistUpdateState: string
{
    /**
     * The write is planned and has not landed.
     *
     * The state a row is created in, and the state it stays in when the write
     * is attempted and refused by the filesystem — an unwritable file, a full
     * disk, a directory the rename cannot happen in. Nothing about the work is
     * in doubt; the file is.
     */
    case Pending = 'pending';

    /**
     * The checklist on disk says what the run meant it to say.
     *
     * Reached by a write that changed the file, and by one that found the
     * marker already as planned — a run that crashed between ticking the box
     * and recording the tick must be able to ask again and be told it is done
     * ({@see \Celyes\RalphPhp\Tasks\CheckboxEdit::isChanged()}).
     */
    case Applied = 'applied';

    /**
     * The write cannot be retried as planned.
     *
     * What the plan was made against has changed: the task was reworded or
     * deleted, or the file no longer parses. Retrying would tick a line nobody
     * asked about, so the plan is closed and the pass stops for reconciliation
     * (PROJECT.md section C, item 7).
     */
    case Failed = 'failed';

    /**
     * A later generation of the same task replaced this plan before it landed.
     *
     * Reopening a task starts a new pass with its own branch and its own write;
     * the old plan is kept as history rather than deleted, and is not something
     * resume should act on.
     */
    case Superseded = 'superseded';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The stored spellings, in this order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Read a state back from a column.
     *
     * @throws InvalidArgumentException when the stored value is not one of
     *                                  these, which means the database and this
     *                                  build disagree about checklist writes
     *                                  and should not be guessed at
     */
    public static function fromStorage(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a checklist update state; the stored value must be one of %s.',
            $value,
            implode(', ', self::values()),
        ));
    }

    /**
     * Whether the write is still owed, and still the only thing owed.
     *
     * What resume scans for: a row in this state is a pass whose work is done
     * and whose checklist is not written.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the file says what the run meant it to say.
     */
    public function isApplied(): bool
    {
        return $this === self::Applied;
    }

    /**
     * Whether the plan was closed without landing.
     */
    public function hasFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Whether a later pass took the plan over.
     */
    public function isSuperseded(): bool
    {
        return $this === self::Superseded;
    }

    /**
     * Whether nothing further will be done about this row.
     *
     * The complement of {@see self::isOutstanding()}, so a row is always either
     * waiting or settled.
     */
    public function isSettled(): bool
    {
        return !$this->isOutstanding();
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'checklist write pending',
            self::Applied => 'checklist written',
            self::Failed => 'checklist write failed',
            self::Superseded => 'checklist write superseded',
        };
    }

    /**
     * The same thing phrased for a sentence.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Pending => 'the checklist has not been written yet',
            self::Applied => 'the checklist was written',
            self::Failed => 'the checklist write was abandoned because what it was planned against changed',
            self::Superseded => 'a later pass at this task replaced the planned write',
        };
    }

    /**
     * What is left to do about a row in this state.
     */
    public function nextAction(): string
    {
        return match ($this) {
            self::Pending => 'Make the checklist writable, then run `ralph resume`: only the box is written, '
                . 'and the implementation is not repeated.',
            self::Applied => 'Nothing: the box is as the run left it.',
            self::Failed => 'Reconcile the checklist against the task the pass was given, then retry the task.',
            self::Superseded => 'Nothing: a later pass at this task owns its checklist write.',
        };
    }
}
