<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use PDO;
use PDOException;
use Throwable;

/**
 * An open connection to a state database, with the settings a recovery store
 * needs.
 *
 * Thin on purpose: it owns the connection and its pragmas, and nothing about
 * tasks or runs. What it does own is the handful of decisions that are wrong by
 * default for this use:
 *
 * - **`foreign_keys = ON`.** SQLite leaves foreign keys off per connection, so
 *   a schema full of `REFERENCES` clauses enforces nothing unless every
 *   connection turns them on. The invariants are the point of the schema, so
 *   they are enabled here and {@see self::enforcesForeignKeys()} reports it.
 * - **`synchronous = FULL`.** Slower, and chosen deliberately. The hard case in
 *   recovery is a crash immediately after a commit: if the row recording that
 *   commit can be lost by the operating system after the transaction returned,
 *   the next run reasons from a database that is behind the repository.
 * - **`journal_mode = WAL`** where the filesystem allows it, so a reader —
 *   `ralph status` in another terminal — does not block the run, and so a
 *   crashed process leaves a journal that rolls back cleanly. Network and some
 *   container filesystems refuse WAL; the mode that results is read back rather
 *   than assumed, and a refusal is not fatal.
 * - **`busy_timeout`.** Two Ralph PHP processes in one repository are prevented
 *   by the run lock, not by this; the timeout is for the short overlaps that
 *   are legitimate, like a status command reading while a run writes.
 *
 * Every `PDOException` is translated into a {@see StateException} with the
 * database's path in it. A raw PDO message names a file the user did not
 * knowingly open and reads as a bug in the package.
 */
final class Database
{
    /**
     * Milliseconds a statement waits for a lock before giving up.
     *
     * Long enough to cover a status command's read, short enough that a genuine
     * deadlock is reported within a run's patience rather than hanging.
     */
    private const int BUSY_TIMEOUT_MS = 5000;

    /**
     * @param string|null $path absolute path of the database file, null for an
     *                          in-memory database
     */
    private function __construct(
        private readonly PDO $pdo,
        private readonly ?string $path,
    ) {
    }

    /**
     * Open, creating the file if it does not exist.
     *
     * The directory must already exist: creating it is
     * {@see StateBootstrap}'s job, which is also where the question of whether
     * the project has been initialised is answered.
     *
     * @throws StateException when SQLite will not open the file, which includes
     *                        the case of a file at that path that is not a
     *                        database
     */
    public static function openFile(string $path): self
    {
        $database = new self(self::connect('sqlite:' . $path, $path), $path);
        $database->configure(wal: true);

        return $database;
    }

    /**
     * A private in-memory database, for tests and for checks that must not
     * touch the project.
     *
     * @throws StateException when SQLite is unavailable
     */
    public static function inMemory(): self
    {
        $database = new self(self::connect('sqlite::memory:', ':memory:'), null);
        $database->configure(wal: false);

        return $database;
    }

    /**
     * The connection, for the stores that read and write records.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * The file this is, or null for an in-memory database.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * How the path reads in a message.
     */
    public function describe(): string
    {
        return $this->path ?? 'an in-memory database';
    }

    /**
     * The SQLite library version PHP is linked against.
     */
    public function sqliteVersion(): string
    {
        return (string) $this->value('SELECT sqlite_version()');
    }

    /**
     * The journal mode in effect, which is `wal` when the filesystem allowed
     * it and otherwise whatever SQLite fell back to.
     */
    public function journalMode(): string
    {
        return strtolower((string) $this->value('PRAGMA journal_mode'));
    }

    /**
     * Whether this connection enforces the schema's foreign keys.
     */
    public function enforcesForeignKeys(): bool
    {
        return (int) $this->value('PRAGMA foreign_keys') === 1;
    }

    /**
     * The tables the database actually has, alphabetically.
     *
     * SQLite's own `sqlite_*` tables are left out: the question callers are
     * asking is what Ralph PHP put there.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name",
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Whether a table exists.
     */
    public function hasTable(string $name): bool
    {
        return in_array($name, $this->tables(), true);
    }

    /**
     * Run a statement with bound parameters.
     *
     * @param array<string|int, scalar|null> $parameters
     *
     * @return int rows affected
     *
     * @throws StateException when the statement fails
     */
    public function execute(string $sql, array $parameters = []): int
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);

            return $statement->rowCount();
        } catch (PDOException $failure) {
            throw $this->failure($failure, 'run a statement against');
        }
    }

    /**
     * Fetch rows as associative arrays.
     *
     * @param array<string|int, scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     *
     * @throws StateException when the query fails
     */
    public function query(string $sql, array $parameters = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $failure) {
            throw $this->failure($failure, 'query');
        }
    }

    /**
     * The first column of the first row, or null when there is no row.
     *
     * @param array<string|int, scalar|null> $parameters
     *
     * @throws StateException when the query fails
     */
    public function value(string $sql, array $parameters = []): mixed
    {
        $first = $this->query($sql, $parameters)[0] ?? null;

        if ($first === null || $first === []) {
            return null;
        }

        return reset($first);
    }

    /**
     * The id SQLite assigned to the last inserted row.
     */
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Run work in a transaction, committing it or rolling it back whole.
     *
     * Nested calls join the transaction already in progress rather than opening
     * a second one SQLite would refuse, so a store method that writes
     * atomically on its own stays correct when a caller wraps several of them
     * together.
     *
     * Any throwable rolls back, including one that is not a database failure:
     * a store that computes something wrong halfway through writing must not
     * leave the half behind.
     *
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     *
     * @throws StateException when the transaction cannot be opened or committed
     */
    public function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work($this);
        }

        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $failure) {
            throw $this->failure($failure, 'begin a transaction in');
        }

        try {
            $result = $work($this);
        } catch (Throwable $failure) {
            $this->rollBack();

            throw $failure;
        }

        try {
            $this->pdo->commit();
        } catch (PDOException $failure) {
            $this->rollBack();

            throw $this->failure($failure, 'commit a transaction to');
        }

        return $result;
    }

    /**
     * Whether a transaction is open.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @throws StateException when the connection cannot be made
     */
    private static function connect(string $dsn, string $subject): PDO
    {
        try {
            return new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $failure) {
            throw new StateException(
                sprintf('Unable to open the state database %s: %s', $subject, $failure->getMessage()),
                [
                    'Check that the file is a SQLite database and that its directory is writable.',
                    'The database is generated data: it can be deleted to start a fresh history, '
                    . 'which discards the record of which commits belong to which task.',
                ],
            );
        }
    }

    /**
     * Apply the connection pragmas.
     *
     * The first statement executed is also the first time SQLite reads the file
     * header, so this is where "that path is not a database" surfaces.
     *
     * @throws StateException when the file is not a usable database
     */
    private function configure(bool $wal): void
    {
        try {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
            $this->pdo->exec('PRAGMA synchronous = FULL');
        } catch (PDOException $failure) {
            throw $this->failure($failure, 'open');
        }

        if (!$wal) {
            return;
        }

        // Best effort: a filesystem that cannot do WAL leaves the default
        // journal, which is correct, only less concurrent.
        try {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException) {
            // Reported by journalMode(), not treated as a failure.
        }
    }

    private function rollBack(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {
            // The original failure is the one worth reporting; a rollback that
            // cannot run has already lost the transaction either way.
        }
    }

    private function failure(PDOException $failure, string $action): StateException
    {
        return new StateException(sprintf(
            'Unable to %s the state database %s: %s',
            $action,
            $this->describe(),
            $failure->getMessage(),
        ));
    }
}
