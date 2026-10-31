<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

/**
 * An opened, up-to-date state database together with how it got that way.
 *
 * What {@see StateBootstrap} hands back. The records themselves are read and
 * written through {@see self::database()} by the stores of PROJECT.md section D,
 * items 2 to 6; this holds the connection and the two facts a caller needs
 * before using it — whether the file had to be created, and what the migrator
 * did.
 */
final class StateStore
{
    public function __construct(
        private readonly Database $database,
        private readonly string $path,
        private readonly bool $created,
        private readonly MigrationResult $migrations,
    ) {
    }

    /**
     * The open connection.
     */
    public function database(): Database
    {
        return $this->database;
    }

    /**
     * Absolute path of the database file.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether the file did not exist before this call.
     *
     * Distinct from {@see MigrationResult::isInitial()}, which is about the
     * schema: a database file can exist with no schema in it if a previous run
     * was killed between creating the file and migrating it, and that case is
     * handled rather than reported as corruption.
     */
    public function wasCreated(): bool
    {
        return $this->created;
    }

    /**
     * What bringing the schema up to date did.
     */
    public function migrations(): MigrationResult
    {
        return $this->migrations;
    }

    /**
     * The schema version in effect.
     */
    public function version(): int
    {
        return $this->migrations->to();
    }

    /**
     * How the whole thing reads in command output.
     */
    public function describe(): string
    {
        return sprintf(
            '%s (%s%s)',
            $this->path,
            $this->created ? 'created, ' : '',
            $this->migrations->describe(),
        );
    }
}
