<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * One uncommitted difference Git reported in a working tree.
 *
 * Git says this in two characters: the first for the difference between the
 * last commit and the index, the second for the difference between the index
 * and the file on disk. Both are kept rather than collapsed into a single
 * "dirty" flag, for two reasons. The run has to tell a user *which* of their
 * changes is in the way, and "modified" and "staged deletion of" call for
 * different remedies; and the pair is the only place the distinction exists at
 * all, so a type that threw it away would make a clearer message impossible
 * later.
 *
 * The path is as Git printed it under `-z`: relative to the repository root,
 * with forward slashes, and verbatim — that output format is never quoted or
 * escaped, so a file whose name contains a quote, a backslash or a newline is
 * reported as what it is rather than as Git's rendering of it. An untracked
 * directory whose whole contents are untracked arrives as a single entry with
 * a trailing slash, which is Git's own summary and is kept as one: expanding it
 * would list files to a user who already knows what is in their own directory.
 */
final class WorkingTreeChange
{
    /**
     * The status character for "no difference on this side".
     */
    public const string UNCHANGED = ' ';

    /**
     * The index status Git uses for a path it knows nothing about.
     */
    public const string UNTRACKED = '?';

    /**
     * The status character for a path with a conflict still to be resolved.
     */
    public const string UNMERGED = 'U';

    /**
     * What each status character means, as a word for a message.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'M' => 'modified',
        'A' => 'added',
        'D' => 'deleted',
        'R' => 'renamed',
        'C' => 'copied',
        'T' => 'type changed',
    ];

    private function __construct(
        private readonly string $indexStatus,
        private readonly string $worktreeStatus,
        private readonly string $path,
        private readonly ?string $originalPath,
    ) {
    }

    /**
     * @param string $indexStatus the first status character Git printed
     * @param string $worktreeStatus the second status character Git printed
     * @param string $path the path Git named, relative to the repository root
     * @param string|null $originalPath where a renamed or copied path came from
     *
     * @throws InvalidArgumentException when a status is not a single character
     *                                 or the path is empty
     */
    public static function of(
        string $indexStatus,
        string $worktreeStatus,
        string $path,
        ?string $originalPath = null,
    ): self {
        foreach ([$indexStatus, $worktreeStatus] as $status) {
            if (strlen($status) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'A Git status is one character; "%s" is %d.',
                    $status,
                    strlen($status),
                ));
            }
        }

        if ($path === '') {
            throw new InvalidArgumentException('A working tree change must name a path.');
        }

        return new self($indexStatus, $worktreeStatus, $path, $originalPath === '' ? null : $originalPath);
    }

    /**
     * The path Git named, relative to the repository root.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Where a renamed or copied path came from, or null for everything else.
     */
    public function originalPath(): ?string
    {
        return $this->originalPath;
    }

    /**
     * Every path this change is about.
     *
     * A rename is one change concerning two paths, and whoever asks whether a
     * change belongs to Ralph PHP's own files has to be able to see both: a
     * file moved *out of* `.ralph/` is a change to the project, however the
     * destination is spelled.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->originalPath === null ? [$this->path] : [$this->path, $this->originalPath];
    }

    /**
     * The two status characters, as Git prints them.
     */
    public function code(): string
    {
        return $this->indexStatus . $this->worktreeStatus;
    }

    /**
     * Whether the repository has never heard of this path.
     */
    public function isUntracked(): bool
    {
        return $this->indexStatus === self::UNTRACKED;
    }

    /**
     * Whether this path has a conflict still to be resolved.
     *
     * Both halves are consulted, and so are the two same-letter pairs Git uses
     * for a conflict in which neither side has a plain state: `AA` for a file
     * both sides added, `DD` for one both sides deleted.
     */
    public function isUnmerged(): bool
    {
        if ($this->isUntracked()) {
            return false;
        }

        if ($this->indexStatus === self::UNMERGED || $this->worktreeStatus === self::UNMERGED) {
            return true;
        }

        return $this->indexStatus === $this->worktreeStatus
            && ($this->indexStatus === 'A' || $this->indexStatus === 'D');
    }

    /**
     * Whether part of this change is already in the index.
     */
    public function isStaged(): bool
    {
        return !$this->isUntracked() && $this->indexStatus !== self::UNCHANGED;
    }

    /**
     * One line naming the change and the path it concerns.
     *
     * Written so that the remedy is implied: a user who reads "modified
     * (staged)" knows the file is in the index, and a user who reads "untracked
     * directory" knows that nothing of theirs is being tracked yet. The two
     * status characters are not printed, because the point of the line is to be
     * readable by someone who has not memorised them.
     */
    public function describe(): string
    {
        $description = sprintf('%s: %s', $this->label(), $this->path);

        if ($this->originalPath === null) {
            return $description;
        }

        return sprintf('%s (was %s)', $description, $this->originalPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code(),
            'path' => $this->path,
            'original_path' => $this->originalPath,
            'description' => $this->describe(),
        ];
    }

    private function label(): string
    {
        if ($this->isUntracked()) {
            return str_ends_with($this->path, '/') ? 'untracked directory' : 'untracked file';
        }

        if ($this->isUnmerged()) {
            return 'unmerged';
        }

        $staged = $this->indexStatus !== self::UNCHANGED;
        $unstaged = $this->worktreeStatus !== self::UNCHANGED;

        if ($staged && $unstaged) {
            return sprintf(
                '%s (staged), %s (unstaged)',
                self::nameOf($this->indexStatus),
                self::nameOf($this->worktreeStatus),
            );
        }

        if ($staged) {
            return sprintf('%s (staged)', self::nameOf($this->indexStatus));
        }

        return self::nameOf($this->worktreeStatus);
    }

    /**
     * A status character as a word.
     *
     * A character Git adds later is reported as "changed" rather than as
     * itself: an unfamiliar letter in the middle of a sentence says less than
     * the honest general word, and the path beside it is what the user needs.
     */
    private static function nameOf(string $status): string
    {
        return self::NAMES[$status] ?? 'changed';
    }
}
