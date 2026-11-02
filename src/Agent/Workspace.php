<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use InvalidArgumentException;

/**
 * The directory an attempt works in.
 *
 * A string would do the job, and that is the problem. An adapter runs another
 * program, and the two ways that goes wrong are both about this path: the
 * command inherits the PHP process's own working directory because nobody
 * passed one, or it is given a relative path that resolves against whatever
 * that happens to be. Either one lets a task edit the controlling checkout
 * instead of its own worktree — a repository the runner has promised not to
 * touch (PROJECT.md section F, item 1). Requiring this type is what makes "an
 * explicit working directory" (section E, item 2) something the signature says
 * rather than something each adapter remembers.
 *
 * So the invariants hold at construction: absolute, and a directory that is
 * there now. The isolated worktree per task is section F's job; this is only
 * the handle the adapter is given, which is also why an adapter cannot create
 * one out of a path it invented — it is handed the workspace the runner
 * prepared.
 *
 * The path is **not** canonicalised. Resolving symlinks would change the text
 * of a path the runner recorded with a worktree and a branch, and the state
 * store, the logs and `git worktree list` all have to keep agreeing about which
 * directory a task owns; a path that reads differently in the database than on
 * the command line is a reconciliation problem nobody needs. Trailing
 * separators are removed, because those are a formatting difference rather than
 * a different directory.
 */
final class Workspace
{
    private function __construct(private readonly string $path)
    {
    }

    /**
     * The workspace at this absolute path.
     *
     * @throws InvalidArgumentException when the path is relative, says nothing,
     *                                 or is not a directory that exists
     */
    public static function at(string $path): self
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A workspace must have a path.');
        }

        if (!self::isAbsolute($trimmed)) {
            throw new InvalidArgumentException(sprintf(
                'A workspace path must be absolute; "%s" is relative and would resolve against whatever directory the runner happens to be in.',
                $trimmed,
            ));
        }

        $directory = self::withoutTrailingSeparator($trimmed);

        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf(
                'The workspace %s is not a directory that exists; it has to be prepared before an attempt is given it.',
                $directory,
            ));
        }

        return new self($directory);
    }

    /**
     * The absolute directory the agent is to be run in.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * An absolute path to something inside the workspace.
     *
     * For an adapter that has to name a file in the workspace — a prompt the
     * agent is told to read, a result file it is told to write. Relative paths
     * only: joining an absolute one would silently escape the workspace, which
     * is the single thing this type exists to prevent.
     *
     * @throws InvalidArgumentException when the path is absolute, empty, or
     *                                 climbs out of the workspace
     */
    public function resolve(string $relative): string
    {
        $trimmed = trim($relative);

        if ($trimmed === '' || self::isAbsolute($trimmed)) {
            throw new InvalidArgumentException(sprintf(
                'A path inside a workspace must be relative and not empty; "%s" is neither.',
                $relative,
            ));
        }

        $normalized = str_replace('\\', '/', $trimmed);

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf(
                    '"%s" climbs out of the workspace %s.',
                    $relative,
                    $this->path,
                ));
            }
        }

        return $this->path . DIRECTORY_SEPARATOR . self::withoutTrailingSeparator($normalized);
    }

    /**
     * Whether two handles name the same directory.
     *
     * Compared as text, for the reason the class description gives: these are
     * paths the runner recorded, and two spellings of one directory are a
     * question for whoever prepared them.
     */
    public function equals(self $other): bool
    {
        return $this->path === $other->path;
    }

    public function __toString(): string
    {
        return $this->path;
    }

    /**
     * Absolute in the POSIX sense, or on Windows with a drive letter or a UNC
     * prefix. The package targets Linux and WSL, where only the first applies;
     * the others cost two lines and stop a Windows path being called relative.
     */
    private static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path);
    }

    /**
     * A path without the separators on its end, keeping a lone root.
     */
    private static function withoutTrailingSeparator(string $path): string
    {
        $trimmed = rtrim($path, '/\\');

        return $trimmed === '' ? $path[0] : $trimmed;
    }
}
