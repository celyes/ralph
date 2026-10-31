<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * One numbered step from one schema version to the next.
 *
 * A version, a name a human can recognise in the ledger, and the statements
 * that make the change. {@see Migrator} applies the statements inside a single
 * transaction and records the version, so a migration either happened or did
 * not: SQLite makes schema changes transactional, which is what lets a
 * half-applied upgrade be impossible rather than merely unlikely.
 *
 * Migrations are append-only. Once a version has shipped, its statements are
 * frozen — editing them would leave a database that recorded version 1 and a
 * database freshly created at version 1 with different tables in them, and
 * nothing afterwards could tell the two apart.
 */
final class Migration
{
    /**
     * @param list<string> $statements executed in order, in one transaction
     *
     * @throws InvalidArgumentException when the version is not positive, the
     *                                 name is blank, or there is nothing to do
     */
    public function __construct(
        private readonly int $version,
        private readonly string $name,
        private readonly array $statements,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException(sprintf(
                'A schema version is counted from 1; %d is not a version.',
                $version,
            ));
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('A migration must have a name.');
        }

        if ($statements === []) {
            throw new InvalidArgumentException(sprintf(
                'Migration %d ("%s") has no statements.',
                $version,
                $name,
            ));
        }
    }

    /**
     * The version the database is at once this has been applied.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * What the step does, as written in the ledger.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * How the step reads in command output.
     */
    public function describe(): string
    {
        return sprintf('%d: %s', $this->version, $this->name);
    }
}
