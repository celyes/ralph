<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * Which workspace a pass is using, and whether it is the pass's to remove.
 *
 * The two columns exist together because the path alone cannot answer the only
 * question cleanup has to get right. A linked worktree at
 * `.ralph/runtime/worktrees/TASK-19E0214A` may have been created by this
 * generation, in which case removing it when the pass is finished with is
 * tidying up; or it may have been created by an earlier run, or by the user by
 * hand, in which case removing it destroys work nobody asked to lose.
 * PROJECT.md section F, item 10 requires cleanup to protect uncommitted work,
 * and this is the fact that makes that possible.
 *
 * Ownership is recorded at the moment the worktree is prepared and never
 * re-derived. There is no way to look at a directory later and tell who made
 * it, so a run that guessed would guess wrong exactly when it mattered — after
 * an interruption, which is when the directory has something in it.
 *
 * {@see self::none()} is the starting value: a pass that has not been prepared
 * yet runs in no workspace, which is different from running in the user's
 * checkout.
 */
final class WorktreeOwnership
{
    private function __construct(
        private readonly ?string $path,
        private readonly bool $owned,
    ) {
    }

    /**
     * No workspace yet.
     */
    public static function none(): self
    {
        return new self(null, false);
    }

    /**
     * A worktree this pass created, and may remove.
     *
     * @throws InvalidArgumentException when the path is blank
     */
    public static function created(string $path): self
    {
        return new self(self::location($path), true);
    }

    /**
     * A workspace that was already there.
     *
     * Covers both an isolated worktree left by an earlier run that this pass is
     * continuing in and a directory the user prepared. Either way it is not
     * this pass's to delete.
     *
     * @throws InvalidArgumentException when the path is blank
     */
    public static function adopted(string $path): self
    {
        return new self(self::location($path), false);
    }

    /**
     * Read the two columns back.
     *
     * A row with ownership set but no path is refused rather than read as
     * unowned: it means something wrote half of a pair that is only meaningful
     * whole, and quietly resolving it in the direction of "do not remove" would
     * hide the bug while leaking a worktree.
     *
     * @throws InvalidArgumentException when the row holds a combination this
     *                                 class never writes
     */
    public static function fromStorage(?string $path, bool $owned): self
    {
        if ($path === null || trim($path) === '') {
            if ($owned) {
                throw new InvalidArgumentException(
                    'A stored worktree claims ownership without naming a path.',
                );
            }

            return self::none();
        }

        return new self(trim($path), $owned);
    }

    /**
     * Where the pass works, when it has somewhere.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Whether a workspace has been prepared at all.
     */
    public function exists(): bool
    {
        return $this->path !== null;
    }

    /**
     * Whether this pass created the workspace, so cleanup may remove it.
     */
    public function isOwned(): bool
    {
        return $this->owned;
    }

    /**
     * Whether cleanup must leave the workspace alone.
     *
     * The question asked in the safe direction: true both for a workspace
     * somebody else made and for no workspace at all.
     */
    public function mustBePreserved(): bool
    {
        return !$this->owned;
    }

    /**
     * Whether this is the named workspace.
     *
     * Compared exactly. Resolving paths is the caller's job — it needs a
     * filesystem, and this is a record — and a near-match is a different
     * directory.
     */
    public function isAt(string $path): bool
    {
        return $this->path !== null && $this->path === $path;
    }

    /**
     * One line for a status table or a log header.
     */
    public function describe(): string
    {
        return match (true) {
            $this->path === null => 'no workspace prepared',
            $this->owned => sprintf('%s (created by this pass)', $this->path),
            default => sprintf('%s (pre-existing, left in place)', $this->path),
        };
    }

    /**
     * @throws InvalidArgumentException when the path names nothing
     */
    private static function location(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A recorded workspace must name a directory.');
        }

        return $trimmed;
    }

    /**
     * The two values a row holds, ready to bind.
     *
     * @return array{path: string|null, owned: int}
     */
    public function toColumns(): array
    {
        return [
            'path' => $this->path,
            'owned' => $this->owned ? 1 : 0,
        ];
    }
}
