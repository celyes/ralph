<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use InvalidArgumentException;

/**
 * Where a task's workspace lives, and how to read a path back as the pass that
 * owns it.
 *
 * One directory per generation under `.ralph/runtime/worktrees/`, named by the
 * generation's slug. Both halves of that are load-bearing.
 *
 * The *name* is {@see TaskGeneration::slug()} and nothing else — not the task's
 * wording, not a counter, not the branch. It is derived from the task's content
 * and the pass number, so it is the same string on the next run, which is what
 * lets an interrupted pass be found again by asking Git about one path rather
 * than by searching for a directory that looks like the right one. Two
 * generations of one task get different slugs, so reopening a finished task
 * cannot land in the old pass's directory (PROJECT.md section C, item 8).
 *
 * The *place* is inside the runtime directory, which is git-ignored by the rules
 * `ralph init` writes and is the directory {@see ManagedPaths} counts as the
 * package's own. A worktree somewhere else — `/tmp`, a sibling of the
 * repository — would be invisible to the user looking for their task's files,
 * would not be cleaned up with the rest of the runtime data, and would appear
 * in the controlling checkout's `git status` as unrelated work if it landed
 * anywhere tracked.
 *
 * Textual throughout: a layout describes paths that mostly do not exist yet,
 * which is the point of asking it where to create one.
 */
final class WorktreeLayout
{
    private function __construct(private readonly string $root)
    {
    }

    /**
     * The layout for the project this configuration describes.
     */
    public static function forProject(Configuration $configuration): self
    {
        return self::under($configuration->projectRoot());
    }

    /**
     * The layout under a project root.
     *
     * @throws InvalidArgumentException when the root is not absolute, since
     *                                 every path derived from it is handed to
     *                                 Git and to {@see \Celyes\RalphPhp\Agent\Workspace}
     */
    public static function under(string $projectRoot): self
    {
        $root = trim($projectRoot);

        if ($root === '' || !Path::isAbsolute($root)) {
            throw new InvalidArgumentException(sprintf(
                'A worktree layout needs an absolute project root; "%s" would resolve against whatever directory the runner happens to be in.',
                $projectRoot,
            ));
        }

        return new self(Path::normalize($root . '/' . Defaults::WORKTREES_DIRECTORY));
    }

    /**
     * The directory every task workspace is created inside.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Where this pass works.
     */
    public function pathFor(TaskGeneration $generation): string
    {
        return $this->root . '/' . $generation->slug();
    }

    /**
     * Whether a path is one this layout hands out.
     *
     * The question cleanup and reconciliation ask about a worktree Git reports:
     * a directory below this root is the package's to reason about, and
     * anything else in the repository is not. Compared after normalisation so
     * that a trailing slash or a doubled separator is the same directory, and
     * strictly below the root, so the root itself is not mistaken for a
     * workspace.
     */
    public function contains(string $path): bool
    {
        $candidate = Path::normalize($path);

        return $candidate !== $this->root && Path::isInside($this->root, $candidate);
    }

    /**
     * The pass that owns a path, or null when the path is not one of this
     * layout's or does not name a generation.
     *
     * Deliberately strict. A directory under the worktrees root whose name is
     * not a slug — created by hand, left by a different tool, a nested
     * directory further down — is not attributed to a task, because the only
     * use for the answer is deciding what may be removed.
     */
    public function generationAt(string $path): ?TaskGeneration
    {
        if (!$this->contains($path)) {
            return null;
        }

        $relative = substr(Path::normalize($path), strlen($this->root) + 1);

        if (!TaskGeneration::isSlug($relative)) {
            return null;
        }

        return TaskGeneration::fromSlug($relative);
    }
}
