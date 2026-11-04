<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * Every worktree attached to a repository, as Git last listed them.
 *
 * Read from `worktree list --porcelain`, which is the form with a stable
 * contract: the human output aligns columns, abbreviates SHAs to a width that
 * depends on the repository, and marks the current worktree, and none of that
 * is safe to parse. The porcelain form is a paragraph per worktree, one
 * `label value` line each, blank line between.
 *
 * Available since Git 2.7, comfortably below
 * {@see \Celyes\RalphPhp\Ralph::MINIMUM_GIT_VERSION}. The `-z` variant, which
 * would remove the one limitation below, arrived in 2.36 and is above the
 * floor.
 *
 * **Unknown labels are ignored.** Git has added lines to this format over the
 * years — `locked` and `prunable` are both newer than the oldest version this
 * package supports — and a parser that refused a label it did not recognise
 * would break on the next one for no benefit. Compare
 * {@see WorkingTreeStatus::parse()}, which refuses what it cannot read: there,
 * an unreadable record is a file that would be reported as clean, so silence is
 * the dangerous direction. Here it is the reverse, which is also the answer to
 * this format's one real flaw: paths are printed unquoted, so a worktree whose
 * path contains a newline is read as a record followed by a line this ignores.
 * That can only ever make this list *shorter* than the truth, and the authority
 * on whether a path is free is `git worktree add` itself, which refuses a path
 * it has registered. This list decides whether to adopt or to create; Git
 * decides whether creating is allowed.
 *
 * A paragraph with no `worktree` line is still refused, because that is not a
 * format change Git could make — it is output this cannot attribute to any
 * directory at all.
 */
final class WorktreeList
{
    /**
     * The arguments that produce the format this class parses.
     *
     * Declared beside the parser so the request and the reading of it cannot
     * drift apart.
     *
     * @var list<string>
     */
    public const array COMMAND = ['worktree', 'list', '--porcelain'];

    /**
     * @param list<RegisteredWorktree> $worktrees in the order Git reported
     *        them, which always begins with the main working tree
     */
    private function __construct(private readonly array $worktrees)
    {
    }

    /**
     * Read the output of {@see self::COMMAND}.
     *
     * @throws InvalidArgumentException when a paragraph names no path
     */
    public static function parse(string $output): self
    {
        $worktrees = [];
        $attributes = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $output)) as $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '') {
                if ($attributes !== []) {
                    $worktrees[] = RegisteredWorktree::fromAttributes($attributes, $worktrees === []);
                    $attributes = [];
                }

                continue;
            }

            $separator = strpos($line, ' ');
            $label = $separator === false ? $line : substr($line, 0, $separator);
            $value = $separator === false ? '' : substr($line, $separator + 1);

            // A repeated label would mean Git described one worktree twice;
            // keeping the first is the conservative reading, and the `worktree`
            // line is the one that matters — overwriting it would move a record
            // onto a path from a different paragraph.
            if (!array_key_exists($label, $attributes)) {
                $attributes[$label] = $value;
            }
        }

        if ($attributes !== []) {
            $worktrees[] = RegisteredWorktree::fromAttributes($attributes, $worktrees === []);
        }

        return new self($worktrees);
    }

    /**
     * An empty list, for a caller holding a repository it has not asked yet.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Every worktree, in Git's order.
     *
     * @return list<RegisteredWorktree>
     */
    public function all(): array
    {
        return $this->worktrees;
    }

    /**
     * The worktree registered at exactly this path, or null when none is.
     *
     * Compared as text. Git prints the path it recorded when the worktree was
     * created, and the runner derives the path it asks about from the same
     * layout, so the two agree by construction; resolving symlinks to make a
     * near-match count would mean adopting a directory the runner did not name.
     */
    public function at(string $path): ?RegisteredWorktree
    {
        foreach ($this->worktrees as $worktree) {
            if ($worktree->isAt($path)) {
                return $worktree;
            }
        }

        return null;
    }

    /**
     * The repository's main working tree, which Git lists first.
     */
    public function main(): ?RegisteredWorktree
    {
        return $this->worktrees[0] ?? null;
    }

    /**
     * The worktrees other than the main one, which are the linked ones.
     *
     * @return list<RegisteredWorktree>
     */
    public function linked(): array
    {
        return array_slice($this->worktrees, 1);
    }

    /**
     * The worktree with this branch checked out, or null when no worktree has.
     *
     * Git refuses to check a branch out in two worktrees at once, so this is
     * the question behind "is that branch somebody else's" — asked by branch
     * ownership in PROJECT.md section F, item 4.
     */
    public function forBranch(string $branch): ?RegisteredWorktree
    {
        foreach ($this->worktrees as $worktree) {
            if ($worktree->branch() === $branch) {
                return $worktree;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->worktrees);
    }

    public function isEmpty(): bool
    {
        return $this->worktrees === [];
    }
}
