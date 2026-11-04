<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * One worktree Git knows about, as `git worktree list` reported it.
 *
 * The question this answers is narrow and the reason for it is not: before a
 * task's workspace is created, the run has to know whether the path it is about
 * to use is already a worktree. The two answers lead opposite ways. A worktree
 * that is there is adopted — it is almost always the one an interrupted run
 * left behind, and continuing in it is how resume avoids reimplementing work —
 * while a path Git has never heard of is created fresh. Getting that backwards
 * in the adopting direction would start a task on top of a stranger's files;
 * getting it backwards the other way would discard an interrupted task's
 * changes, which PROJECT.md section F, item 10 forbids.
 *
 * A record is read-only and says nothing about the filesystem. Whether the
 * directory is actually there is a separate question with a separate answer —
 * Git keeps the registration when the directory is deleted by hand, and
 * {@see self::prunableReason()} is how it says so — and asking the filesystem
 * belongs to {@see TaskWorktrees}, which can act on the answer.
 */
final class RegisteredWorktree
{
    /**
     * The prefix Git puts on a branch it reports here.
     *
     * `git worktree list --porcelain` names branches in full — `refs/heads/x`
     * rather than `x` — which is unambiguous and not what a branch is called
     * anywhere else in this package.
     */
    private const string BRANCH_PREFIX = 'refs/heads/';

    /**
     * @param string      $path            as Git printed it
     * @param string|null $head            the commit checked out, null for a bare repository
     * @param string|null $branch          the short branch name, null when detached or bare
     * @param bool        $main            whether this is the repository's main working tree
     * @param bool        $bare            whether it has no working tree at all
     * @param string|null $lockReason      why it is locked, an empty string when locked without a reason, null when unlocked
     * @param string|null $prunableReason  why Git considers the registration stale, null when it does not
     */
    private function __construct(
        private readonly string $path,
        private readonly ?string $head,
        private readonly ?string $branch,
        private readonly bool $main,
        private readonly bool $bare,
        private readonly ?string $lockReason,
        private readonly ?string $prunableReason,
    ) {
    }

    /**
     * Build a record from one porcelain paragraph.
     *
     * @param array<string, string> $attributes labels to values, as
     *        {@see WorktreeList} read them; a valueless label maps to the empty
     *        string
     *
     * @throws InvalidArgumentException when the paragraph names no path, which
     *                                 is the one line every record has
     */
    public static function fromAttributes(array $attributes, bool $main): self
    {
        $path = trim($attributes['worktree'] ?? '');

        if ($path === '') {
            throw new InvalidArgumentException(
                'Git reported a worktree without a path, which is the one line every record has.',
            );
        }

        $branch = $attributes['branch'] ?? null;

        return new self(
            $path,
            self::nullIfBlank($attributes['HEAD'] ?? null),
            $branch === null ? null : self::shortBranch($branch),
            $main,
            array_key_exists('bare', $attributes),
            $attributes['locked'] ?? null,
            self::nullIfBlank($attributes['prunable'] ?? null),
        );
    }

    /**
     * Where the worktree is, spelled as Git spells it.
     *
     * Not canonicalised, for the same reason {@see \Celyes\RalphPhp\Agent\Workspace}
     * is not: this text is compared against a path the runner recorded, and
     * resolving symlinks on one side of that comparison is how the database and
     * `git worktree list` stop agreeing.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The commit checked out there, when there is one.
     */
    public function head(): ?string
    {
        return $this->head;
    }

    /**
     * The branch checked out there, short, or null when the worktree is
     * detached or bare.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * Whether a branch is checked out at all.
     *
     * Read as the absence of a `branch` line rather than from Git's own
     * `detached` line, which says the same thing. The two cannot disagree —
     * Git prints exactly one of them for a non-bare worktree — and deriving it
     * means a record built by hand cannot claim to be both.
     */
    public function isDetached(): bool
    {
        return !$this->bare && $this->branch === null;
    }

    /**
     * Whether this is the repository's main working tree, which is the
     * controlling checkout rather than any task's workspace.
     *
     * Worth a method because it is the one worktree that must never be removed,
     * reset or adopted as a task workspace, and it is identified by its
     * position in Git's output rather than by anything in the record.
     */
    public function isMain(): bool
    {
        return $this->main;
    }

    public function isBare(): bool
    {
        return $this->bare;
    }

    /**
     * Whether the worktree is locked against removal.
     */
    public function isLocked(): bool
    {
        return $this->lockReason !== null;
    }

    /**
     * Why it is locked: the reason given, or null when it is not locked.
     *
     * An empty string is a real answer — `git worktree lock` without
     * `--reason` — and is deliberately not collapsed into null, because "locked
     * for no stated reason" and "not locked" lead to different messages.
     */
    public function lockReason(): ?string
    {
        return $this->lockReason;
    }

    /**
     * Whether Git considers the registration stale, which it does when the
     * directory has been deleted or its administrative files are unreadable.
     */
    public function isPrunable(): bool
    {
        return $this->prunableReason !== null;
    }

    /**
     * Git's own words for why the registration is stale, or null when it is
     * not — and also null on a Git too old to say, which is why
     * {@see TaskWorktrees} asks the filesystem rather than relying on this.
     */
    public function prunableReason(): ?string
    {
        return $this->prunableReason;
    }

    /**
     * Whether the worktree is at this exact path.
     */
    public function isAt(string $path): bool
    {
        return $this->path === $path;
    }

    /**
     * One line for a refusal or a status table.
     */
    public function describe(): string
    {
        $parts = [$this->path];

        $parts[] = match (true) {
            $this->bare => 'bare',
            $this->branch !== null => 'on ' . $this->branch,
            default => 'detached at ' . substr($this->head ?? 'an unknown commit', 0, BaseCommit::SHORT_LENGTH),
        };

        if ($this->main) {
            $parts[] = 'main working tree';
        }

        if ($this->isLocked()) {
            $parts[] = $this->lockReason === '' ? 'locked' : 'locked: ' . $this->lockReason;
        }

        if ($this->prunableReason !== null) {
            $parts[] = 'prunable: ' . $this->prunableReason;
        }

        return sprintf('%s (%s)', $parts[0], implode(', ', array_slice($parts, 1)));
    }

    private static function shortBranch(string $reference): string
    {
        $name = trim($reference);

        return str_starts_with($name, self::BRANCH_PREFIX)
            ? substr($name, strlen(self::BRANCH_PREFIX))
            : $name;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
