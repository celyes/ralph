<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * How far cleanup of a pass's workspace got.
 *
 * Two values and a null, and the null is the common case: most passes have
 * never had a removal issued against them, either because they are still being
 * worked on or because their workspace is being kept on purpose. The column
 * exists for the one thing nothing else can answer — **was a removal already
 * started?** — which PROJECT.md section K, item 7 needs twice over:
 *
 *  - A removal is not complete until Git confirms it, and "confirmed but not
 *    yet written down" is the gap a crash falls into.
 *    {@see \Celyes\RalphPhp\Git\WorktreeCleaner} writes {@see self::Removing}
 *    *before* it issues the command and {@see self::Removed} only after Git and
 *    the filesystem both say the workspace has gone, so a resumed run can tell
 *    which side of the command it was interrupted on.
 *  - `git worktree remove` deletes the worktree directory and *then* Git's
 *    administrative record of it, so an interruption in the middle leaves a
 *    part-deleted directory that is indistinguishable, from the outside, from a
 *    workspace somebody has been editing. {@see self::Removing} is what tells
 *    them apart: a directory this package has already begun deleting is a
 *    husk to finish off, and one it has not is work to protect.
 *
 * Spellings are pinned: they are written into `generations.cleanup_state`,
 * which the database constrains with {@see Schema::cleanupStates()}, built from
 * these cases so the two cannot drift.
 */
enum CleanupState: string
{
    /**
     * A removal has been issued and has not been confirmed.
     *
     * The state the row sits in for as long as the Git command is running, and
     * the state an interruption leaves behind. It says nothing about how far
     * the deletion got — that is what the filesystem is for — only that this
     * package asked for it, having already judged the workspace removable.
     */
    case Removing = 'removing';

    /**
     * The workspace has gone, Git agrees, and the row no longer names it.
     *
     * Written in the same statement that clears the workspace columns, so the
     * two cannot disagree: a row marked removed never names a directory, and a
     * row that names a directory is never marked removed. That is what makes
     * cleanup safe to call again — there is no second pass for it to do.
     */
    case Removed = 'removed';

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
     * Read the column back, including the null that means "never attempted".
     *
     * @throws InvalidArgumentException when the stored value is not one of
     *                                 these, which means the database and this
     *                                 build disagree about cleanup and should
     *                                 not be guessed at — guessing "finished"
     *                                 would leak a worktree and guessing
     *                                 "started" would licence a forced removal
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a cleanup state; the stored value must be one of %s.',
            $value,
            implode(', ', self::values()),
        ));
    }

    /**
     * Whether a removal was issued and never confirmed.
     *
     * The question a resumed cleanup asks. True means the workspace at the
     * recorded path is one Git was already told to delete, so whatever is left
     * of it is the remains of that deletion rather than anybody's work.
     */
    public function isInProgress(): bool
    {
        return $this === self::Removing;
    }

    /**
     * Whether cleanup finished and was written down.
     */
    public function isFinished(): bool
    {
        return $this === self::Removed;
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Removing => 'cleanup unfinished',
            self::Removed => 'workspace removed',
        };
    }

    /**
     * The same thing phrased for a sentence.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Removing => 'a removal of the workspace was started and never confirmed',
            self::Removed => 'the workspace was removed and Git confirmed it',
        };
    }
}
