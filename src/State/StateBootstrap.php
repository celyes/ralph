<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\StateException;

/**
 * Opens a project's state store, creating it when the project has not run
 * before.
 *
 * The one entry point commands use. It is idempotent by design: `run`,
 * `status`, `resume` and `retry` all call it without first asking whether the
 * database exists, because "create it" and "open it" differ only in what the
 * report says afterwards.
 *
 * The runtime directory is created if it is missing, which keeps a project
 * usable when `.ralph/runtime/` has been cleaned out — it holds nothing but
 * generated data, so a user deleting it is housekeeping and not a broken
 * project. `ralph init` still creates it, so an initialised project never
 * depends on this; what this must not do is create the *project* directory,
 * since a state store under a project with no configuration would be a project
 * that looks initialised and is not.
 */
final class StateBootstrap
{
    /**
     * Mode for a created runtime directory.
     *
     * The same as `ralph init` uses: the directory holds worker logs, which can
     * contain anything the agent printed, so it is kept to the owner.
     */
    private const int RUNTIME_MODE = 0o700;

    public function __construct(private readonly Migrator $migrator = new Migrator())
    {
    }

    /**
     * Where a project's state database lives.
     *
     * Derived from the project root, never configured: see
     * {@see Defaults::STATE_FILE}.
     */
    public static function fileIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . Defaults::STATE_FILE);
    }

    /**
     * Open the state store of a loaded project.
     *
     * @throws FilesystemException when the runtime directory cannot be created
     * @throws StateException when the database cannot be opened or migrated
     */
    public function open(Configuration $configuration): StateStore
    {
        return $this->openAt(self::fileIn($configuration->projectRoot()));
    }

    /**
     * Open the state store at an explicit path.
     *
     * @throws FilesystemException when the containing directory cannot be
     *                             created
     * @throws StateException when the database cannot be opened or migrated
     */
    public function openAt(string $file): StateStore
    {
        $path = Path::normalize($file);

        $this->refuseDirectory($path);
        $this->prepareDirectory(dirname($path));

        $created = !file_exists($path);

        $database = Database::openFile($path);
        $migrations = $this->migrator->migrate($database);

        return new StateStore($database, $path, $created, $migrations);
    }

    /**
     * An in-memory store with the schema applied.
     *
     * For tests, and for anything that needs to reason about the schema without
     * writing to the project.
     *
     * @throws StateException when the schema cannot be created
     */
    public function openInMemory(): StateStore
    {
        $database = Database::inMemory();

        return new StateStore(
            $database,
            ':memory:',
            true,
            $this->migrator->migrate($database),
        );
    }

    /**
     * @throws FilesystemException when a directory sits where the database goes
     */
    private function refuseDirectory(string $path): void
    {
        if (is_dir($path)) {
            throw new FilesystemException(
                sprintf('A directory exists at %s, where the state database goes.', $path),
                ['Move the directory out of the way, then run the command again.'],
            );
        }
    }

    /**
     * @throws FilesystemException when the directory is missing and cannot be
     *                             created, or exists and cannot be written to
     */
    private function prepareDirectory(string $directory): void
    {
        if (is_file($directory)) {
            throw new FilesystemException(
                sprintf('A file exists at %s, where the runtime directory goes.', $directory),
                ['Move the file out of the way, then run the command again.'],
            );
        }

        if (!is_dir($directory) && !@mkdir($directory, self::RUNTIME_MODE, true) && !is_dir($directory)) {
            throw new FilesystemException(
                sprintf('Unable to create the runtime directory %s.', $directory),
                ['Check the permissions of the project directory.'],
            );
        }

        if (!is_writable($directory)) {
            throw new FilesystemException(
                sprintf('The runtime directory %s is not writable.', $directory),
                ['The state database is created and updated there on every run.'],
            );
        }
    }
}
