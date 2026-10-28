<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;

/**
 * Creates the project files `ralph` needs: the configuration, the task
 * checklist, the context file, and the runtime directories.
 *
 * Free of console output so the same behaviour can be asserted directly: it
 * returns an {@see InitializationReport} and the command renders it.
 *
 * An existing configuration file is read before anything is written, so the
 * checklist and context file are created where *that* file says they belong
 * rather than at the package defaults. Nothing that already exists is written
 * to: initialisation is additive. The explicit option that allows overwriting a
 * user's file is the next checklist item (PROJECT.md section B, item 5), as are
 * the ignore rules (item 6), so this build has no way to destroy user content.
 */
final class ProjectInitializer
{
    /**
     * Mode for the project directory: readable by other users, like the rest of
     * a checkout, since the configuration and checklist are meant to be shared.
     */
    private const int PROJECT_MODE = 0o755;

    /**
     * Mode for the runtime directories: owner-only. They collect raw worker
     * output, memory summaries and task worktrees, which can contain project
     * content and whatever an agent printed.
     */
    private const int RUNTIME_MODE = 0o700;

    public function __construct(private readonly ConfigurationLoader $loader = new ConfigurationLoader())
    {
    }

    /**
     * Initialise the project rooted at the given directory.
     *
     * @throws FilesystemException when the directory is unusable or a path
     *                             cannot be created
     * @throws \Celyes\RalphPhp\Exception\ConfigurationException when an existing
     *         configuration file cannot be used, which decides where the
     *         checklist and context file belong
     */
    public function initialize(string $projectRoot): InitializationReport
    {
        $root = $this->resolveRoot($projectRoot);
        $configuration = $this->loader->loadOrDefaults($root);

        $configurationFile = $this->createFile($root, ConfigurationLoader::fileIn($root), Templates::config());
        $tasksFile = $this->createFile($root, $configuration->tasksFile(), Templates::tasks());
        $contextFile = $this->createFile($root, $configuration->contextFile(), Templates::context());

        $directories = [];
        foreach (self::runtimeDirectories() as $relative) {
            $directories[] = $this->createDirectory(
                $root,
                Path::normalize($root . '/' . $relative),
                self::RUNTIME_MODE,
            );
        }

        return new InitializationReport($root, $configurationFile, $tasksFile, $contextFile, $directories);
    }

    /**
     * The runtime directories a run writes into.
     *
     * The state database itself is created by the state store when a run starts
     * (PROJECT.md section D), so initialisation only has to make the places it
     * and the logs go.
     *
     * @return list<string>
     */
    public static function runtimeDirectories(): array
    {
        return [
            Defaults::RUNTIME_DIRECTORY,
            Defaults::LOGS_DIRECTORY,
            Defaults::MEMORY_DIRECTORY,
            Defaults::WORKTREES_DIRECTORY,
        ];
    }

    /**
     * Write a file unless something is already there.
     */
    private function createFile(string $root, string $path, string $contents): InitializedPath
    {
        if (is_dir($path)) {
            throw new FilesystemException(
                sprintf('A directory exists at %s, where Ralph PHP needs a file.', $path),
                ['Move the directory out of the way, then run "ralph init" again.'],
            );
        }

        // is_link() is checked separately: a symlink whose target is missing is
        // not reported by file_exists(), and writing through it would create a
        // file somewhere the user did not ask for.
        if (is_link($path) || file_exists($path)) {
            return new InitializedPath($path, $this->relative($root, $path), InitializationOutcome::Kept, false);
        }

        $this->createDirectory($root, dirname($path), self::PROJECT_MODE);

        if (@file_put_contents($path, $contents) === false) {
            throw new FilesystemException(
                sprintf('Unable to write %s.', $path),
                ['Check the permissions of ' . dirname($path) . '.'],
            );
        }

        return new InitializedPath($path, $this->relative($root, $path), InitializationOutcome::Created, false);
    }

    /**
     * Create a directory, and any parent it needs, unless it already exists.
     */
    private function createDirectory(string $root, string $path, int $mode): InitializedPath
    {
        if (is_dir($path)) {
            return new InitializedPath($path, $this->relative($root, $path), InitializationOutcome::Kept, true);
        }

        if (file_exists($path) || is_link($path)) {
            throw new FilesystemException(
                sprintf('A file exists at %s, where Ralph PHP needs a directory.', $path),
                ['Move the file out of the way, then run "ralph init" again.'],
            );
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new FilesystemException(
                sprintf('Unable to create the directory %s.', $path),
                ['Check the permissions of ' . dirname($path) . '.'],
            );
        }

        return new InitializedPath($path, $this->relative($root, $path), InitializationOutcome::Created, true);
    }

    /**
     * The directory being initialised, as an absolute, symlink-free path.
     */
    private function resolveRoot(string $projectRoot): string
    {
        if ($projectRoot === '') {
            throw new FilesystemException('No project directory was given.');
        }

        $resolved = realpath($projectRoot);

        if ($resolved === false || !is_dir($resolved)) {
            throw new FilesystemException(
                sprintf('The directory %s does not exist.', $projectRoot),
                ['Run "ralph init" from the project you want to initialise.'],
            );
        }

        if (!is_writable($resolved)) {
            throw new FilesystemException(
                sprintf('The directory %s is not writable.', $resolved),
                ['Check its permissions, and that you are running as the user that owns the project.'],
            );
        }

        return Path::normalize($resolved);
    }

    /**
     * A path as shown to the user: relative to the project when it is inside
     * it, absolute when a configured path points elsewhere.
     */
    private function relative(string $root, string $path): string
    {
        if (!Path::isInside($root, $path) || $path === $root) {
            return $path;
        }

        return substr($path, strlen(rtrim($root, '/')) + 1);
    }
}
