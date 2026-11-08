<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\Migration;
use Celyes\RalphPhp\State\MigrationResult;
use Celyes\RalphPhp\State\Migrations;
use Celyes\RalphPhp\State\Migrator;
use Celyes\RalphPhp\State\Schema;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\StateStore;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Opening the state store: the first time, every time after, and the times it
 * must refuse.
 *
 * Written against real files rather than an in-memory database, because most of
 * what is under test is filesystem behaviour — a runtime directory that was
 * cleaned out, a database file left behind by a run that was killed before it
 * could migrate, a path that is a directory. The one thing a state store cannot
 * do is start over against an empty database and repeat work that is already
 * committed, so the interesting cases are all the ones where it would be
 * tempting to.
 */
#[CoversClass(StateBootstrap::class)]
#[CoversClass(StateStore::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(MigrationResult::class)]
#[CoversClass(Database::class)]
#[CoversClass(Migration::class)]
final class StateBootstrapTest extends TestCase
{
    private TemporaryProject $project;

    private StateBootstrap $bootstrap;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->bootstrap = new StateBootstrap();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_first_open_creates_the_database_and_its_schema(): void
    {
        $store = $this->bootstrap->openAt($this->stateFile());

        self::assertTrue($store->wasCreated());
        self::assertTrue($store->migrations()->isInitial());
        self::assertSame(0, $store->migrations()->from());
        self::assertSame(Schema::version(), $store->version());
        self::assertFileExists($this->stateFile());
        self::assertSame(
            [],
            array_values(array_diff(Schema::TABLES, $store->database()->tables())),
        );
    }

    /**
     * The reason commands can open the store without first asking whether it
     * exists.
     */
    #[Test]
    public function opening_again_changes_nothing(): void
    {
        $this->bootstrap->openAt($this->stateFile());

        $reopened = $this->bootstrap->openAt($this->stateFile());

        self::assertFalse($reopened->wasCreated());
        self::assertTrue($reopened->migrations()->isUnchanged());
        self::assertSame(Schema::version(), $reopened->version());
        self::assertStringContainsString('already up to date', $reopened->describe());
    }

    #[Test]
    public function the_runtime_directory_is_created_when_it_has_been_cleaned_out(): void
    {
        $runtime = $this->project->path(Defaults::RUNTIME_DIRECTORY);
        self::assertDirectoryDoesNotExist($runtime);

        $this->bootstrap->openAt($this->stateFile());

        self::assertDirectoryExists($runtime);
        self::assertSame('700', sprintf('%o', fileperms($runtime) & 0o777));
    }

    /**
     * It must not make a project look initialised. `ralph init` writes the
     * configuration; this only ever adds the generated directory under it.
     */
    #[Test]
    public function the_project_is_not_initialised_as_a_side_effect(): void
    {
        $this->bootstrap->openAt($this->stateFile());

        self::assertFileDoesNotExist($this->project->path(Defaults::CONFIG_FILE));
        self::assertFileDoesNotExist($this->project->path(Defaults::TASKS_FILE));
        self::assertFileDoesNotExist($this->project->path(Defaults::CONTEXT_FILE));
    }

    /**
     * A run killed between creating the file and migrating it leaves an empty
     * database. That is a schema to create, not a corrupt store.
     */
    #[Test]
    public function an_empty_database_file_is_migrated_rather_than_rejected(): void
    {
        $file = $this->stateFile();
        mkdir(dirname($file), 0o700, true);
        Database::openFile($file);

        $store = $this->bootstrap->openAt($file);

        self::assertFalse($store->wasCreated(), 'The file was already there.');
        self::assertTrue($store->migrations()->isInitial(), 'The schema still had to be built.');
        self::assertSame(Schema::version(), $store->version());
    }

    #[Test]
    public function the_ledger_records_what_was_applied(): void
    {
        $store = $this->bootstrap->openAt($this->stateFile());

        $rows = $store->database()->query(
            sprintf('SELECT version, name, applied_at FROM %s ORDER BY version', Schema::LEDGER),
        );

        self::assertCount(count(Migrations::all()), $rows);
        self::assertSame(1, (int) $rows[0]['version']);
        self::assertSame('initial schema', $rows[0]['name']);
        self::assertNotSame('', (string) $rows[0]['applied_at']);
    }

    #[Test]
    public function the_configured_project_root_decides_where_the_database_goes(): void
    {
        $this->project->writeConfigArray(Defaults::toArray());
        $configuration = (new ConfigurationLoader())->load($this->project->root());

        $store = $this->bootstrap->open($configuration);

        self::assertSame(StateBootstrap::fileIn($this->project->root()), $store->path());
        self::assertSame($this->stateFile(), $store->path());
        self::assertStringEndsWith(Defaults::STATE_FILE, $store->path());
    }

    #[Test]
    public function an_in_memory_store_has_the_same_schema(): void
    {
        $store = $this->bootstrap->openInMemory();

        self::assertSame(Schema::version(), $store->version());
        self::assertSame(
            [],
            array_values(array_diff(Schema::TABLES, $store->database()->tables())),
        );
        self::assertNull($store->database()->path());
        self::assertFileDoesNotExist($this->stateFile());
    }

    #[Test]
    public function a_connection_enforces_the_schemas_foreign_keys(): void
    {
        $database = $this->bootstrap->openAt($this->stateFile())->database();

        self::assertTrue($database->enforcesForeignKeys());
        self::assertSame('wal', $database->journalMode());
        self::assertNotSame('', $database->sqliteVersion());
    }

    /**
     * The refusal that protects a history written by a newer package: its
     * records may mean something this build would misread.
     */
    #[Test]
    public function a_database_from_a_newer_package_is_refused_rather_than_used(): void
    {
        $file = $this->stateFile();
        $ahead = new Migrator([
            ...Migrations::all(),
            new Migration(99, 'a future step', ['CREATE TABLE future (id INTEGER PRIMARY KEY)']),
        ]);
        (new StateBootstrap($ahead))->openAt($file);

        try {
            $this->bootstrap->openAt($file);
            self::fail('A database from a newer package was opened.');
        } catch (StateException $refused) {
            self::assertStringContainsString('schema version 99', $refused->getMessage());
            self::assertStringContainsString(
                sprintf('understands version %d', Schema::version()),
                $refused->getMessage(),
            );
            self::assertNotSame([], $refused->hints());
        }
    }

    /**
     * The guarantee that makes a half-applied upgrade impossible rather than
     * merely unlikely: the recorded version always describes tables that exist.
     */
    #[Test]
    public function a_migration_that_fails_halfway_leaves_none_of_itself_behind(): void
    {
        $file = $this->stateFile();
        $this->bootstrap->openAt($file);

        $halfway = new Migrator([
            ...Migrations::all(),
            new Migration($this->nextVersion(), 'a step that fails after its first statement', [
                'CREATE TABLE first_half (id INTEGER PRIMARY KEY)',
                'CREATE TABLE runs (id INTEGER PRIMARY KEY)',
            ]),
        ]);

        $reported = null;

        try {
            $halfway->migrate($this->bootstrap->openAt($file)->database());
        } catch (StateException $failure) {
            $reported = $failure->getMessage();
        }

        self::assertNotNull($reported, 'A migration whose second statement failed was accepted.');

        $reopened = $this->bootstrap->openAt($file);

        self::assertFalse(
            $reopened->database()->hasTable('first_half'),
            'The first statement of a failed migration was left behind.',
        );
        self::assertSame($this->shippedVersions(), (new Migrator())->appliedVersions($reopened->database()));
    }

    #[Test]
    public function a_migration_that_fails_leaves_the_recorded_version_alone(): void
    {
        $file = $this->stateFile();
        $this->bootstrap->openAt($file);

        $broken = new Migrator([
            ...Migrations::all(),
            new Migration(
                $this->nextVersion(),
                'a step that cannot work',
                ['CREATE TABLE runs (id INTEGER PRIMARY KEY)'],
            ),
        ]);

        try {
            (new StateBootstrap($broken))->openAt($file);
            self::fail('A migration that cannot be applied was reported as applied.');
        } catch (StateException $failure) {
            self::assertStringContainsString(
                sprintf('%d: a step that cannot work', $this->nextVersion()),
                $failure->getMessage(),
            );
        }

        $reopened = $this->bootstrap->openAt($file);

        self::assertSame(Schema::version(), $reopened->version());
        self::assertSame($this->shippedVersions(), (new Migrator())->appliedVersions($reopened->database()));
    }

    /**
     * Chosen by set difference rather than "everything above the recorded
     * version", so a version that was somehow skipped is filled in instead of
     * passed over for good.
     */
    #[Test]
    public function a_version_that_was_skipped_is_still_applied(): void
    {
        $file = $this->stateFile();
        $store = $this->bootstrap->openAt($file);

        $later = new Migration($this->nextVersion(), 'a later step', [
            'CREATE TABLE later (id INTEGER PRIMARY KEY)',
        ]);
        $skipped = new Migration($this->nextVersion() + 1, 'a step applied out of order', [
            'CREATE TABLE skipped (id INTEGER PRIMARY KEY)',
        ]);

        (new Migrator([...Migrations::all(), $later, $skipped]))->migrate($store->database());
        $store->database()->execute(
            sprintf('DELETE FROM %s WHERE version = %d', Schema::LEDGER, $this->nextVersion()),
        );
        $store->database()->execute('DROP TABLE later');

        $migrator = new Migrator([...Migrations::all(), $later, $skipped]);

        self::assertSame(
            [$this->nextVersion()],
            array_map(
                static fn (Migration $migration): int => $migration->version(),
                $migrator->pending($store->database()),
            ),
        );

        $result = $migrator->migrate($store->database());

        self::assertTrue($store->database()->hasTable('later'));
        self::assertSame(
            [...$this->shippedVersions(), $this->nextVersion(), $this->nextVersion() + 1],
            $migrator->appliedVersions($store->database()),
        );
        self::assertCount(1, $result->applied());
    }

    #[Test]
    public function a_file_that_is_not_a_database_is_refused(): void
    {
        $file = $this->stateFile();
        mkdir(dirname($file), 0o700, true);
        file_put_contents($file, str_repeat('this is not a SQLite database. ', 200));

        $this->expectException(StateException::class);

        $this->bootstrap->openAt($file);
    }

    #[Test]
    public function a_directory_where_the_database_goes_is_refused(): void
    {
        $file = $this->stateFile();
        mkdir($file, 0o700, true);

        try {
            $this->bootstrap->openAt($file);
            self::fail('A directory was opened as a database.');
        } catch (FilesystemException $refused) {
            self::assertStringContainsString('A directory exists at', $refused->getMessage());
            self::assertNotSame([], $refused->hints());
        }
    }

    #[Test]
    public function a_file_where_the_runtime_directory_goes_is_refused(): void
    {
        $runtime = $this->project->path(Defaults::RUNTIME_DIRECTORY);
        mkdir(dirname($runtime), 0o755, true);
        file_put_contents($runtime, 'not a directory');

        try {
            $this->bootstrap->openAt($this->stateFile());
            self::fail('A file was used as the runtime directory.');
        } catch (FilesystemException $refused) {
            self::assertStringContainsString('where the runtime directory goes', $refused->getMessage());
        }
    }

    #[Test]
    public function an_unwritable_runtime_directory_is_reported_before_anything_is_opened(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permissions do not restrict root.');
        }

        $runtime = $this->project->path(Defaults::RUNTIME_DIRECTORY);
        mkdir(dirname($runtime), 0o700, true);
        mkdir($runtime, 0o500);

        try {
            $this->bootstrap->openAt($this->stateFile());
            self::fail('An unwritable runtime directory was accepted.');
        } catch (FilesystemException $refused) {
            self::assertStringContainsString('is not writable', $refused->getMessage());
        } finally {
            chmod($runtime, 0o700);
        }
    }

    #[Test]
    public function a_transaction_is_rolled_back_whole(): void
    {
        $database = $this->bootstrap->openInMemory()->database();

        $reported = null;

        try {
            $database->transaction(function (Database $database): void {
                $this->insertRun($database, 'RUN-1');
                $this->insertRun($database, 'RUN-2');

                throw new RuntimeException('the work decided against itself');
            });
        } catch (RuntimeException $failure) {
            $reported = $failure->getMessage();
        }

        self::assertSame(
            'the work decided against itself',
            $reported,
            'The failure was swallowed instead of reaching the caller.',
        );
        self::assertSame(0, (int) $database->value('SELECT COUNT(*) FROM runs'));
        self::assertFalse($database->inTransaction());
    }

    /**
     * A store method that writes atomically on its own has to stay correct
     * when a caller wraps several of them together.
     */
    #[Test]
    public function a_nested_transaction_joins_the_one_already_open(): void
    {
        $database = $this->bootstrap->openInMemory()->database();

        $reported = null;

        try {
            $database->transaction(function (Database $database): void {
                $database->transaction(function (Database $database): void {
                    $this->insertRun($database, 'RUN-1');
                });

                self::assertTrue($database->inTransaction(), 'The inner call committed on its own.');

                throw new RuntimeException('the outer work failed');
            });
        } catch (RuntimeException $failure) {
            $reported = $failure->getMessage();
        }

        self::assertSame('the outer work failed', $reported);
        self::assertSame(
            0,
            (int) $database->value('SELECT COUNT(*) FROM runs'),
            'The inner write survived the outer failure.',
        );
    }

    #[Test]
    public function a_transaction_that_succeeds_keeps_its_writes(): void
    {
        $database = $this->bootstrap->openAt($this->stateFile())->database();

        $id = $database->transaction(function (Database $database): int {
            $this->insertRun($database, 'RUN-1');

            return $database->lastInsertId();
        });

        self::assertSame(
            'RUN-1',
            $database->value('SELECT run_id FROM runs WHERE id = ?', [$id]),
        );
    }

    /**
     * Named for what it reads rather than for what it returns, so a query that
     * happens to select more than one column cannot quietly hand back the
     * wrong one.
     */
    #[Test]
    public function a_single_value_is_the_first_column_of_the_first_row(): void
    {
        $database = $this->bootstrap->openInMemory()->database();
        $this->insertRun($database, 'RUN-1');
        $this->insertRun($database, 'RUN-2');

        self::assertSame(
            'RUN-1',
            $database->value('SELECT run_id, state, agent FROM runs ORDER BY run_id'),
        );
        self::assertNull(
            $database->value("SELECT run_id FROM runs WHERE run_id = 'RUN-3'"),
            'A query that matched nothing reported a value.',
        );
    }

    #[Test]
    public function a_migration_must_say_what_it_does(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Migration(1, 'does nothing', []);
    }

    #[Test]
    public function a_schema_version_is_counted_from_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Migration(0, 'before the beginning', ['SELECT 1']);
    }

    #[Test]
    public function a_migration_must_have_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Migration(1, '   ', ['SELECT 1']);
    }

    /**
     * A version beyond everything the package ships.
     *
     * The cases that need a migration to fail, to be skipped or to arrive out
     * of order invent one, and inventing it at a fixed number would collide
     * with the next version the package itself adds — which is how a test that
     * is supposed to watch a migration fail quietly stops running it at all.
     */
    private function nextVersion(): int
    {
        return Migrations::latestVersion() + 1;
    }

    /**
     * The versions a fresh database has, oldest first.
     *
     * @return list<int>
     */
    private function shippedVersions(): array
    {
        return array_map(
            static fn (Migration $migration): int => $migration->version(),
            Migrations::all(),
        );
    }

    private function stateFile(): string
    {
        return $this->project->path(Defaults::STATE_FILE);
    }

    private function insertRun(Database $database, string $runId): void
    {
        $database->execute(
            'INSERT INTO runs
                (run_id, state, agent, adapter, agent_source, agent_settings, configuration, checks,
                 tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $runId,
                'running',
                'codex',
                'codex',
                'project_default',
                '{}',
                '{}',
                '[]',
                Defaults::TASKS_FILE,
                'HEAD',
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );
    }
}
