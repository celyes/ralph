<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Init\ProjectInitializer;
use Celyes\RalphPhp\Ralph;

/**
 * The paths in the controlling checkout that belong to Ralph PHP.
 *
 * A run has to tell its own files apart from the project's, because the two are
 * uncommitted for opposite reasons. PROJECT.md section F, item 1 rejects
 * unrelated uncommitted changes; section 4 requires the runner to distinguish
 * its own checkbox changes from unrelated edits and never to commit them. One
 * answer serves both: these paths are read and written in the controlling
 * checkout *by design*, so an uncommitted change to them is something the run
 * uses rather than something it would miss.
 *
 * That is the test for membership, and it is why the list is short:
 *
 *  - `.ralph/`, the directory this package creates and owns. The configuration
 *    it reads, the checkbox state it writes, the state database, the logs, the
 *    memory and the task worktrees are all inside it, all of them generated or
 *    local by nature, and all of them read from this checkout rather than from
 *    a task's worktree.
 *  - The configured task checklist and context file, which default to inside
 *    `.ralph/` and may be configured anywhere in the project. The checklist is
 *    the one file a successful task edits here.
 *  - The `.bak` copies `ralph init --force` leaves beside a file it replaced,
 *    which exist because the user asked to keep their old version.
 *
 * Everything else in the repository is the project's, including anything a user
 * happens to have put inside `.ralph/`: a change there is reported as
 * unrelated, which is a readable complaint rather than a silent inclusion.
 *
 * The same list is approximately what {@see \Celyes\RalphPhp\Init\IgnoreRules}
 * writes, and deliberately computed separately. Ignore rules state what *should
 * not be committed* and a user may legitimately override them — tracking their
 * checklist is a documented choice. This states what *belongs to the package*,
 * which the user's ignore file cannot change.
 */
final class ManagedPaths
{
    /**
     * @param list<string> $paths relative to the project root, with forward
     *        slashes and no trailing slash
     */
    private function __construct(private readonly array $paths)
    {
    }

    /**
     * The package's paths in the project this configuration describes.
     */
    public static function forProject(Configuration $configuration): self
    {
        $root = Path::normalize($configuration->projectRoot());
        $paths = [Ralph::PROJECT_DIRECTORY];

        // Configuration validation already requires both of these to resolve
        // inside the project. The check is repeated because the consequence of
        // being wrong here is the reverse of the usual one: a path from outside
        // the project could not be turned into a rule about an unrelated file,
        // it would be turned into a claim that some unrelated file is ours.
        foreach ([$configuration->tasksFile(), $configuration->contextFile()] as $file) {
            $relative = self::relative($root, Path::normalize($file));

            if ($relative !== null) {
                $paths[] = $relative;
            }
        }

        return new self(array_values(array_unique($paths)));
    }

    /**
     * The paths themselves, relative to the project root.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Whether one path Git named belongs to the package.
     *
     * Compared against what `git status` produces and nothing else: a path
     * relative to the repository root, with forward slashes — Git uses them on
     * every platform — and with a trailing slash when Git has summarised a
     * wholly untracked directory. A managed directory covers everything below
     * it, and a managed file covers the backups named after it.
     *
     * Deliberately a plain textual comparison, with no normalisation of
     * separators or of leading and trailing whitespace. A backslash and a
     * leading space are both legal in a filename, and a path arriving here has
     * already been through Git: translating either would let a file called
     * `.ralph\notes.md` in the project root be claimed as the package's.
     *
     * Every managed path is itself non-empty, so a path Git could not have
     * named — an empty one — matches nothing and is not the package's, which is
     * the answer that keeps a whole dirty checkout from being absorbed.
     */
    public function covers(string $path): bool
    {
        foreach ($this->paths as $managed) {
            // The managed path itself — a file Git named, or the directory
            // reported with its trailing slash — and then anything inside it.
            if ($path === $managed || str_starts_with($path, $managed . '/')) {
                return true;
            }

            if (str_starts_with($path, $managed . ProjectInitializer::BACKUP_SUFFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One absolute path as a project-relative one, or null when it is not
     * inside the project or is the project root itself.
     */
    private static function relative(string $root, string $path): ?string
    {
        if (!Path::isInside($root, $path) || $path === $root) {
            return null;
        }

        return substr($path, strlen(rtrim($root, '/')) + 1);
    }
}
