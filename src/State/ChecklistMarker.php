<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * What a planned checklist write is aiming for.
 *
 * A boolean would do the job in PHP and does everywhere else in the package;
 * this exists because the write is *recorded* before it happens, and a column
 * holding `1` with no word beside it is unreadable to whoever opens the state
 * store to find out what a run was in the middle of. Two values, spelled as
 * `checklist_updates.marker` stores them and as {@see Schema::checklistMarkers()}
 * constrains them, built from these cases so the two cannot drift.
 */
enum ChecklistMarker: string
{
    /**
     * The box is to be ticked: `- [ ]` becomes `- [x]`.
     *
     * What finishing a verified task plans.
     */
    case Checked = 'checked';

    /**
     * The box is to be opened again: `- [x]` becomes `- [ ]`.
     *
     * What reopening a task that was ticked prematurely plans.
     */
    case Unchecked = 'unchecked';

    /**
     * The marker a write wants, from the boolean the editor takes.
     */
    public static function of(bool $checked): self
    {
        return $checked ? self::Checked : self::Unchecked;
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
     * Read a marker back from a column.
     *
     * @throws InvalidArgumentException when the stored value is not one of
     *                                  these, which means the database and this
     *                                  build disagree about what a checklist
     *                                  write is and should not be guessed at
     */
    public static function fromStorage(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a checklist marker; the stored value must be one of %s.',
            $value,
            implode(', ', self::values()),
        ));
    }

    /**
     * Whether this write ticks the box.
     */
    public function isChecked(): bool
    {
        return $this === self::Checked;
    }

    /**
     * The marker as it appears in the document.
     */
    public function literal(): string
    {
        return $this === self::Checked ? '- [x]' : '- [ ]';
    }

    /**
     * The write phrased for a sentence.
     */
    public function describe(): string
    {
        return $this === self::Checked ? 'tick the box' : 'open the box again';
    }
}
