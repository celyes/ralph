<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * Locates the program a configured command would actually run.
 *
 * Preflight needs this to say "the agent's executable is not installed" before
 * a task is selected, instead of after a worktree has been created. The runner
 * needs the same answer for every verification command.
 *
 * Resolution follows the two shapes a configuration can use:
 *
 *  - a path, recognised by a directory separator, which is checked where it
 *    points, relative paths being resolved against the directory the command
 *    would run in;
 *  - a bare name, which is searched for in PATH, exactly as the shell would.
 *
 * No shell is involved, so a name is never expanded, aliased, or run through a
 * function the user has defined in their profile: what is found here is what
 * {@see ProcessRunner} will execute.
 */
final class ExecutableFinder
{
    /**
     * Directories a search path is listed in full up to.
     */
    private const int DIRECTORIES_LISTED = 4;

    /**
     * The absolute path a command name resolves to, or null when nothing
     * executable answers to it.
     *
     * @param string $workingDirectory directory a relative path is resolved against
     */
    public function find(string $name, string $workingDirectory): ?string
    {
        if ($name === '') {
            return null;
        }

        if (self::looksLikePath($name)) {
            $path = self::isAbsolute($name) ? $name : rtrim($workingDirectory, '/\\') . DIRECTORY_SEPARATOR . $name;

            return $this->executableAt($path);
        }

        foreach ($this->searchPath() as $directory) {
            $found = $this->executableAt(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Whether a command name would be searched for in PATH rather than used as
     * a path. Reported separately so a failure can say where it looked.
     */
    public static function isSearched(string $name): bool
    {
        return !self::looksLikePath($name);
    }

    /**
     * The directories a bare name is searched in, in order.
     *
     * @return list<string>
     */
    public function searchPath(): array
    {
        $path = getenv('PATH');

        if (!is_string($path) || $path === '') {
            return [];
        }

        $directories = [];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            // An empty entry means the current directory to some shells. It is
            // not honoured here: running a program out of whichever directory
            // the user happened to be in is not something a configuration
            // should be able to ask for implicitly.
            if ($directory === '') {
                continue;
            }

            $directories[] = $directory;
        }

        return $directories;
    }

    /**
     * A readable description of where a bare name is searched.
     *
     * Short paths are listed, because the entry a user has to add is then
     * obvious. A long one is counted instead: a development machine's PATH runs
     * to forty directories, and printing all of them to say that a program is
     * in none of them buries the sentence that matters.
     */
    public function describeSearchPath(): string
    {
        $directories = $this->searchPath();
        $count = count($directories);

        if ($count === 0) {
            return 'PATH is empty or unset';
        }

        if ($count <= self::DIRECTORIES_LISTED) {
            return sprintf('PATH: %s', implode(', ', $directories));
        }

        return sprintf('PATH, which has %d directories', $count);
    }

    /**
     * The path, resolved, if something executable is there.
     */
    private function executableAt(string $path): ?string
    {
        foreach ($this->candidates($path) as $candidate) {
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);

            return $resolved === false ? $candidate : $resolved;
        }

        return null;
    }

    /**
     * The filenames to try for one candidate path.
     *
     * On Linux and WSL, the supported platforms, that is the path itself. On
     * Windows a program is named without its extension, so the extensions the
     * system considers executable are tried as well; this is a convenience for
     * a developer running the suite there, not a support claim.
     *
     * @return list<string>
     */
    private function candidates(string $path): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [$path];
        }

        $candidates = [$path];
        $extensions = getenv('PATHEXT');

        foreach (explode(';', is_string($extensions) && $extensions !== '' ? $extensions : '.EXE;.BAT;.CMD') as $extension) {
            if ($extension === '') {
                continue;
            }

            $candidates[] = $path . $extension;
        }

        return $candidates;
    }

    private static function looksLikePath(string $name): bool
    {
        return str_contains($name, '/') || str_contains($name, '\\');
    }

    private static function isAbsolute(string $name): bool
    {
        return str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $name) === 1;
    }
}
