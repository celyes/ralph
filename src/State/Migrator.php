<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Ralph;

/**
 * Brings a database to the schema this build expects.
 *
 * Creating the schema and upgrading it are the same operation: an empty
 * database has every migration pending, so there is no separate install path
 * that could produce different tables from the migrations. Running it on a
 * database that is already current does nothing, which is why a run can simply
 * open the state store without first asking whether the project has one.
 *
 * Two refusals matter more than the happy path.
 *
 * A database recording a version this build does not have was written by a
 * newer Ralph PHP. It is not downgraded and it is not used: its tables may hold
 * columns this build would silently ignore while it reasons about which commits
 * belong to which task. The user is told to upgrade the package.
 *
 * A migration that fails is rolled back, version and all, so the recorded
 * version always describes tables that actually exist. SQLite makes schema
 * changes transactional, which is what makes that guarantee real rather than
 * merely likely.
 */
final class Migrator
{
    /**
     * @var list<Migration>
     */
    private readonly array $migrations;

    /**
     * @param list<Migration>|null $migrations defaults to the package's own;
     *                                         injected by tests that need a
     *                                         future version to exist
     */
    public function __construct(?array $migrations = null)
    {
        $this->migrations = $migrations ?? Migrations::all();
    }

    /**
     * Apply everything the database is missing.
     *
     * @throws StateException when the database is from a newer version of the
     *                        package, or a migration fails
     */
    public function migrate(Database $database): MigrationResult
    {
        $this->createLedger($database);

        $from = $this->version($database);
        $this->refuseNewerSchema($database, $from);

        $applied = [];
        foreach ($this->pending($database) as $migration) {
            $this->apply($database, $migration);
            $applied[] = $migration;
        }

        return new MigrationResult($from, $this->version($database), $applied);
    }

    /**
     * The version the database records, zero when none has been applied.
     *
     * @throws StateException when the ledger cannot be read
     */
    public function version(Database $database): int
    {
        if (!$database->hasTable(Schema::LEDGER)) {
            return 0;
        }

        return (int) $database->value(sprintf('SELECT MAX(version) FROM %s', Schema::LEDGER));
    }

    /**
     * The versions already applied, oldest first.
     *
     * @return list<int>
     */
    public function appliedVersions(Database $database): array
    {
        if (!$database->hasTable(Schema::LEDGER)) {
            return [];
        }

        return array_map(
            static fn (array $row): int => (int) $row['version'],
            $database->query(sprintf('SELECT version FROM %s ORDER BY version', Schema::LEDGER)),
        );
    }

    /**
     * The migrations this database has not had, oldest first.
     *
     * Selected by set difference rather than by "everything above the recorded
     * version", so a database that somehow skipped a version has it filled in
     * instead of passed over for good.
     *
     * @return list<Migration>
     */
    public function pending(Database $database): array
    {
        $applied = $this->appliedVersions($database);

        return array_values(array_filter(
            $this->ordered(),
            static fn (Migration $migration): bool => !in_array($migration->version(), $applied, true),
        ));
    }

    /**
     * The newest version this build can produce.
     */
    public function latestVersion(): int
    {
        $ordered = $this->ordered();

        return $ordered === [] ? 0 : $ordered[count($ordered) - 1]->version();
    }

    /**
     * Create the ledger if it is not there.
     *
     * Outside the migration list, because it is what records that a migration
     * ran. Its shape is fixed for all time: any future version has to be able
     * to read it as it was first written.
     *
     * @throws StateException when it cannot be created
     */
    private function createLedger(Database $database): void
    {
        $database->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )',
            Schema::LEDGER,
        ));
    }

    /**
     * @throws StateException when the database is ahead of this build
     */
    private function refuseNewerSchema(Database $database, int $recorded): void
    {
        $latest = $this->latestVersion();

        if ($recorded <= $latest) {
            return;
        }

        throw new StateException(
            sprintf(
                'The state database %s is at schema version %d, and this %s understands version %d.',
                $database->describe(),
                $recorded,
                Ralph::NAME,
                $latest,
            ),
            [
                sprintf('Upgrade %s to a version that knows schema %d.', Ralph::PACKAGE, $recorded),
                'The database is not downgraded: an older build could misread records a newer '
                . 'one wrote and repeat work that has already been committed.',
            ],
        );
    }

    /**
     * @throws StateException when a statement fails
     */
    private function apply(Database $database, Migration $migration): void
    {
        try {
            $database->transaction(function (Database $database) use ($migration): void {
                foreach ($migration->statements() as $statement) {
                    $database->execute($statement);
                }

                $database->execute(
                    sprintf('INSERT INTO %s (version, name, applied_at) VALUES (?, ?, ?)', Schema::LEDGER),
                    [$migration->version(), $migration->name(), gmdate('c')],
                );
            });
        } catch (StateException $failure) {
            throw new StateException(
                sprintf(
                    'Migration %s could not be applied to the state database %s: %s',
                    $migration->describe(),
                    $database->describe(),
                    $failure->getMessage(),
                ),
                [
                    'Nothing was changed: the migration and the version it records are applied '
                    . 'together, so the database is still at its previous version.',
                ],
            );
        }
    }

    /**
     * The migrations by version, oldest first.
     *
     * @return list<Migration>
     */
    private function ordered(): array
    {
        $migrations = $this->migrations;

        usort(
            $migrations,
            static fn (Migration $a, Migration $b): int => $a->version() <=> $b->version(),
        );

        return $migrations;
    }
}
