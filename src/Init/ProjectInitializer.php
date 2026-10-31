<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Config\RawConfiguration;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\State\StateBootstrap;

/**
 * Creates the project files `ralph` needs: the configuration, the task
 * checklist, the context file, the runtime directories, and the Git ignore
 * rules that keep the generated ones out of commits.
 *
 * Free of console output so the same behaviour can be asserted directly: it
 * returns an {@see InitializationReport} and the command renders it.
 *
 * Initialisation is additive by default: a path that already exists is reported
 * and left byte for byte as it was, because the checklist and the context file
 * are written by the user and losing them to a command whose job is to create
 * files would be indefensible. {@see InitializationMode::Overwrite} is the
 * explicit request that changes that, and even then nothing is destroyed — each
 * replaced file is copied beside itself first.
 *
 * An existing configuration file is read before anything is written, so the
 * checklist and context file are created where *that* file says they belong
 * rather than at the package defaults. The same configuration decides which
 * paths the ignore rules name.
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

    /**
     * Suffix of the copy taken before a file is replaced.
     *
     * Public because the ignore rules are derived from it: the backups are
     * named after the files they copy, so {@see IgnoreRules} builds their
     * patterns from the same suffix rather than repeating the literal.
     */
    public const string BACKUP_SUFFIX = '.bak';

    /**
     * How many backups of one file may accumulate before initialisation asks
     * the user to clean up. A bound exists so that a loop calling
     * `init --force` cannot quietly fill the project directory.
     */
    private const int BACKUP_LIMIT = 20;

    public function __construct(
        private readonly ConfigurationLoader $loader = new ConfigurationLoader(),
        private readonly IgnoreFileWriter $ignoreFile = new IgnoreFileWriter(),
    ) {
    }

    /**
     * Initialise the project rooted at the given directory.
     *
     * @throws FilesystemException when the directory is unusable, or a path
     *                             cannot be created, backed up or replaced
     * @throws ConfigurationException when an existing configuration file cannot
     *         be used, which decides where the checklist and context file
     *         belong. Overwriting does not read it for that purpose, so a
     *         project whose configuration is broken can still be reset.
     */
    public function initialize(
        string $projectRoot,
        InitializationMode $mode = InitializationMode::Preserve,
    ): InitializationReport {
        $root = $this->resolveRoot($projectRoot);

        // Overwriting replaces the configuration, so the file it writes decides
        // where the checklist and context belong — which is the defaults.
        // Honouring the paths of the configuration being thrown away would
        // create files nothing then points at.
        $configuration = $mode->overwrites()
            ? $this->loader->defaults($root)
            : $this->loader->loadOrDefaults($root);

        $unreferenced = $mode->overwrites()
            ? $this->unreferencedPaths($root, $configuration)
            : [];

        $configurationFile = $this->writeFile($root, ConfigurationLoader::fileIn($root), Templates::config(), $mode);
        $tasksFile = $this->writeFile($root, $configuration->tasksFile(), Templates::tasks(), $mode);
        $contextFile = $this->writeFile($root, $configuration->contextFile(), Templates::context(), $mode);

        $directories = [];
        foreach (self::runtimeDirectories() as $relative) {
            $directories[] = $this->createDirectory(
                $root,
                Path::normalize($root . '/' . $relative),
                self::RUNTIME_MODE,
            );
        }

        // Last, and never fatal: the ignore rules are about the repository the
        // user still controls, and the file they go in is the one path here
        // that Ralph PHP does not own, so it is only ever appended to.
        $ignoreFile = $this->ignoreFile->update($root, $configuration);

        return new InitializationReport(
            $root,
            $mode,
            $configurationFile,
            $tasksFile,
            $contextFile,
            $directories,
            $ignoreFile,
            $unreferenced,
        );
    }

    /**
     * The runtime directories a run writes into.
     *
     * The state database itself is created by {@see StateBootstrap} when a
     * command first opens it, so initialisation only has to make the places it
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
     * Files the configuration being replaced pointed at, which the one being
     * written does not.
     *
     * Only the two path settings are read, and they are read straight from the
     * document rather than through a loaded {@see Configuration}. A project
     * whose settings are unusable is the main reason to reset one, and that is
     * exactly the project where the warning matters: an invalid limit says
     * nothing about whether `tasks` is legible. Anything that cannot be read is
     * passed over, because this only produces a warning — the reset itself must
     * not depend on it.
     *
     * @return list<string>
     */
    private function unreferencedPaths(string $root, Configuration $written): array
    {
        $file = ConfigurationLoader::fileIn($root);

        if (!is_file($file)) {
            return [];
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $keeping = [$written->tasksFile(), $written->contextFile()];

        $unreferenced = [];
        foreach (['tasks' => Defaults::TASKS_FILE, 'context' => Defaults::CONTEXT_FILE] as $setting => $default) {
            $path = $this->configuredPath($contents, $file, $root, $setting, $default);

            if ($path === null || in_array($path, $keeping, true) || in_array($path, $unreferenced, true)) {
                continue;
            }

            if (is_file($path)) {
                $unreferenced[] = $path;
            }
        }

        return array_map(fn (string $path): string => $this->relative($root, $path), $unreferenced);
    }

    /**
     * One path setting of the configuration document, resolved against the
     * project root, or null when the document or the setting is unreadable.
     */
    private function configuredPath(
        string $contents,
        string $file,
        string $root,
        string $setting,
        string $default,
    ): ?string {
        try {
            $configured = RawConfiguration::fromJson($contents, $file)->string($setting, $default);
        } catch (ConfigurationException) {
            return null;
        }

        if (trim($configured) === '') {
            return null;
        }

        return Path::isAbsolute($configured)
            ? Path::normalize($configured)
            : Path::normalize($root . '/' . $configured);
    }

    /**
     * Write a file from its template, according to what the mode allows.
     */
    private function writeFile(
        string $root,
        string $path,
        string $contents,
        InitializationMode $mode,
    ): InitializedPath {
        if (is_dir($path)) {
            throw new FilesystemException(
                sprintf('A directory exists at %s, where Ralph PHP needs a file.', $path),
                ['Move the directory out of the way, then run "ralph init" again.'],
            );
        }

        // is_link() is checked separately: a symlink whose target is missing is
        // not reported by file_exists(), and writing through it would create or
        // replace a file somewhere the user did not ask for.
        $exists = is_link($path) || file_exists($path);

        if (!$exists) {
            $this->createDirectory($root, dirname($path), self::PROJECT_MODE);
            $this->putContents($path, $contents);

            return InitializedPath::file($path, $this->relative($root, $path), InitializationOutcome::Created);
        }

        if (!$mode->overwrites()) {
            return InitializedPath::file($path, $this->relative($root, $path), InitializationOutcome::Kept);
        }

        if (is_link($path)) {
            throw new FilesystemException(
                sprintf('%s is a symbolic link, and Ralph PHP will not write through one.', $path),
                [
                    'Replacing it would rewrite its target, which may be outside this project.',
                    'Remove the link, then run "ralph init --force" again.',
                ],
            );
        }

        // Nothing to preserve and nothing to change: the file is already the
        // template. Reported as kept rather than replaced so that repeating
        // "init --force" does not accumulate identical backups.
        if (@file_get_contents($path) === $contents) {
            return InitializedPath::file($path, $this->relative($root, $path), InitializationOutcome::Kept);
        }

        $backup = $this->backUp($path);
        $this->putContents($path, $contents);

        return InitializedPath::file(
            $path,
            $this->relative($root, $path),
            InitializationOutcome::Replaced,
            $backup,
        );
    }

    /**
     * Write the template, reporting a refusal with the path that caused it.
     */
    private function putContents(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new FilesystemException(
                sprintf('Unable to write %s.', $path),
                ['Check the permissions of ' . dirname($path) . '.'],
            );
        }
    }

    /**
     * Copy a file beside itself before its content is written over.
     *
     * Overwriting is explicitly requested, but `ralph` is built to be driven by
     * an unattended loop, so the request is honoured without making the user's
     * previous checklist unrecoverable. The copy is made before the write, not
     * a rename, so a failed write still leaves the original in place.
     *
     * @return string the absolute path of the copy
     */
    private function backUp(string $path): string
    {
        $target = $path . self::BACKUP_SUFFIX;

        for ($suffix = 2; file_exists($target) || is_link($target); ++$suffix) {
            if ($suffix > self::BACKUP_LIMIT) {
                throw new FilesystemException(
                    sprintf('%d backups of %s already exist.', self::BACKUP_LIMIT, $path),
                    ['Remove the "' . basename($path) . self::BACKUP_SUFFIX . '" files you no longer need.'],
                );
            }

            $target = $path . self::BACKUP_SUFFIX . '.' . $suffix;
        }

        if (!@copy($path, $target)) {
            throw new FilesystemException(
                sprintf('Unable to back up %s to %s.', $path, $target),
                [
                    'Check the permissions of ' . dirname($path) . '.',
                    'Nothing was replaced: the file is still as it was.',
                ],
            );
        }

        // The backup inherits the original's permissions: a configuration file
        // the user restricted must not become a world-readable copy.
        $mode = @fileperms($path);
        if ($mode !== false) {
            @chmod($target, $mode & 0o7777);
        }

        return $target;
    }

    /**
     * Create a directory, and any parent it needs, unless it already exists.
     */
    private function createDirectory(string $root, string $path, int $mode): InitializedPath
    {
        if (is_dir($path)) {
            return InitializedPath::directory($path, $this->relative($root, $path), InitializationOutcome::Kept);
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

        return InitializedPath::directory($path, $this->relative($root, $path), InitializationOutcome::Created);
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
