<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Migration;
use Celyes\RalphPhp\State\Migrations;
use Celyes\RalphPhp\State\Migrator;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\Schema;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the schema refuses.
 *
 * The tables are only worth having if the database enforces the things the
 * design promised — a branch belonging to one generation, a task having one
 * pass that can still be continued, evidence that cannot outlive the attempt it
 * belongs to. Those are tested by trying to write the forbidden row and
 * expecting SQLite to say no, because the alternative is an application layer
 * that is the only thing standing between a careless update and a reset branch.
 *
 * The rows here are written with raw SQL on purpose. The stores that will write
 * them for real are later items, and a schema test that went through them would
 * stop testing the schema the moment they gained a guard of their own.
 */
#[CoversClass(Schema::class)]
#[CoversClass(Migrations::class)]
#[CoversClass(Migration::class)]
final class SchemaTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
    }

    #[Test]
    public function the_schema_has_every_declared_table(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(Schema::TABLES, $this->database->tables())),
            'A table the package declares is missing from the migrated schema.',
        );
    }

    #[Test]
    public function the_schema_has_no_table_it_does_not_declare(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->database->tables(), Schema::TABLES)),
            'The migrated schema has a table the package does not declare.',
        );
    }

    /**
     * The storage half of "a separately owned branch".
     *
     * Reopening a finished task must not be able to land on the branch the
     * earlier pass left behind, and this is the guarantee that holds even if
     * the branch name is computed wrongly.
     */
    #[Test]
    public function two_generations_cannot_own_one_branch(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/TASK-A-add-a-flag', state: 'completed');

        $this->refuses(
            'UNIQUE constraint failed: generations.branch',
            fn (): int => $this->insertGeneration($task, 2, 'ralph/TASK-A-add-a-flag'),
        );
    }

    #[Test]
    public function generations_that_own_no_branch_yet_do_not_collide(): void
    {
        $first = $this->insertTask('add a flag');
        $second = $this->insertTask('remove a flag');

        $this->insertGeneration($first, 1, null, state: 'completed');
        $this->insertGeneration($first, 2, null, state: 'completed');
        $this->insertGeneration($second, 1, null);

        self::assertSame(3, $this->rowsIn('generations'));
    }

    /**
     * The invariant GenerationHistory refuses to guess at.
     */
    #[Test]
    public function a_task_cannot_have_two_passes_that_are_still_open(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', state: 'interrupted');

        $this->refuses(
            'UNIQUE constraint failed: generations.task_id',
            fn (): int => $this->insertGeneration($task, 2, 'ralph/second', state: 'running'),
        );
    }

    #[Test]
    public function a_finished_pass_lets_the_next_one_start(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', state: 'completed');
        $this->insertGeneration($task, 2, 'ralph/second', state: 'running');

        self::assertSame(2, $this->rowsIn('generations'));
    }

    #[Test]
    public function every_failure_state_still_counts_as_open(): void
    {
        foreach (['pending', 'running', 'blocked', 'failed', 'interrupted'] as $state) {
            $task = $this->insertTask('a task that ends ' . $state);
            $this->insertGeneration($task, 1, 'ralph/' . $state . '-1', state: $state);

            $this->refuses(
                'UNIQUE constraint failed: generations.task_id',
                fn (): int => $this->insertGeneration($task, 2, 'ralph/' . $state . '-2', state: 'running'),
                sprintf('A second open pass was allowed beside a %s one.', $state),
            );

            self::assertTrue(Schema::isOpen($state));
        }
    }

    /**
     * The drift guard between the enum and the DDL: the `CHECK` constraint is
     * built from these cases, so a case the column refuses would be a state the
     * runner could compute and never store.
     */
    #[Test]
    public function every_state_the_enum_names_is_a_state_the_column_takes(): void
    {
        foreach (LifecycleState::cases() as $state) {
            $task = $this->insertTask('a task recorded as ' . $state->value);
            $this->insertGeneration($task, 1, 'ralph/takes-' . $state->value, state: $state->value);
        }

        self::assertSame(count(LifecycleState::cases()), $this->rowsIn('generations'));
    }

    #[Test]
    public function a_state_the_enum_does_not_name_is_refused(): void
    {
        $task = $this->insertTask('add a flag');

        $this->refuses(
            'CHECK constraint failed: state IN',
            fn (): int => $this->insertGeneration($task, 1, 'ralph/first', state: 'paused'),
        );
    }

    #[Test]
    public function a_skipped_pass_is_closed_like_a_completed_one(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', state: 'skipped');
        $this->insertGeneration($task, 2, 'ralph/second', state: 'running');

        self::assertSame(2, $this->rowsIn('generations'));
        self::assertFalse(Schema::isOpen('skipped'));
        self::assertFalse(Schema::isOpen('completed'));
    }

    #[Test]
    public function one_generation_number_is_recorded_once(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', state: 'completed');

        $this->refuses(
            'UNIQUE constraint failed: generations.task_id, generations.number',
            fn (): int => $this->insertGeneration($task, 1, 'ralph/again', state: 'completed'),
        );
    }

    #[Test]
    public function a_generation_is_counted_from_one(): void
    {
        $task = $this->insertTask('add a flag');

        $this->refuses(
            'CHECK constraint failed: number >= 1',
            fn (): int => $this->insertGeneration($task, 0, 'ralph/zero'),
        );
    }

    #[Test]
    public function the_slug_column_takes_what_a_generation_is_named(): void
    {
        $id = TaskId::fromDescription('add a flag');
        $task = $this->insertTask('add a flag');

        foreach ([1, 2, 3] as $number) {
            $generation = TaskGeneration::of($id, $number);

            $this->insertGeneration(
                $task,
                $number,
                'ralph/' . $generation->slug(),
                state: $number === 3 ? 'running' : 'completed',
                slug: $generation->slug(),
            );
        }

        self::assertSame(
            [$id->value(), $id->value() . '-g2', $id->value() . '-g3'],
            array_map(
                static fn (array $row): string => (string) $row['slug'],
                $this->database->query('SELECT slug FROM generations ORDER BY number'),
            ),
        );
    }

    #[Test]
    public function a_state_the_package_does_not_know_is_refused(): void
    {
        $task = $this->insertTask('add a flag');

        $this->refuses(
            'CHECK constraint failed: state IN',
            fn (): int => $this->insertGeneration($task, 1, 'ralph/first', state: 'nearly-done'),
        );
    }

    /**
     * The drift guard between {@see Phase} and the DDL: the `CHECK` constraint
     * is built from these cases, so a case the column refuses would be a phase
     * the runner could record and never store.
     */
    #[Test]
    public function every_phase_the_enum_names_is_a_phase_the_column_takes(): void
    {
        foreach (Phase::cases() as $phase) {
            $task = $this->insertTask('a task stopped while ' . $phase->value);
            $this->insertGeneration($task, 1, 'ralph/takes-' . $phase->value, phase: $phase->value);
        }

        self::assertSame(count(Phase::cases()), $this->rowsIn('generations'));
    }

    #[Test]
    public function a_phase_the_package_does_not_know_is_refused(): void
    {
        $task = $this->insertTask('add a flag');

        $this->refuses(
            'CHECK constraint failed: phase IS NULL OR phase IN',
            fn (): int => $this->insertGeneration($task, 1, 'ralph/first', phase: 'thinking'),
        );
    }

    #[Test]
    public function a_generation_that_is_not_running_records_no_phase(): void
    {
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', state: 'completed', phase: null);

        self::assertNull($this->database->value('SELECT phase FROM generations'));
    }

    #[Test]
    public function the_completion_column_matches_the_completion_enum(): void
    {
        self::assertSame(
            array_map(static fn (Completion $case): string => $case->value, Completion::cases()),
            Schema::completions(),
        );

        foreach (Schema::completions() as $completion) {
            $this->insertTask('a task that is ' . $completion, completion: $completion);
        }

        self::assertSame(count(Completion::cases()), $this->rowsIn('tasks'));
    }

    #[Test]
    public function a_completion_the_enum_does_not_have_is_refused(): void
    {
        $this->refuses(
            'CHECK constraint failed: completion IN',
            fn (): int => $this->insertTask('add a flag', completion: 'probably_done'),
        );
    }

    #[Test]
    public function a_task_identifier_is_recorded_once(): void
    {
        $this->insertTask('add a flag');

        $this->refuses(
            'UNIQUE constraint failed: tasks.task_id',
            fn (): int => $this->insertTask('add a flag'),
        );
    }

    /**
     * Foreign keys are off by default in SQLite, so this is as much a test of
     * the connection as of the schema.
     */
    #[Test]
    public function evidence_cannot_point_at_an_attempt_that_does_not_exist(): void
    {
        self::assertTrue($this->database->enforcesForeignKeys());

        $this->refuses(
            'FOREIGN KEY constraint failed',
            fn (): int => $this->database->execute(
                "INSERT INTO verification_results
                    (attempt_id, position, name, command, outcome, timeout_seconds, started_at)
                 VALUES (9999, 1, 'tests', '[\"composer\",\"test\"]', 'passed', 600, '2026-11-01T00:00:00+00:00')",
            ),
        );
    }

    #[Test]
    public function deleting_a_task_takes_its_evidence_with_it(): void
    {
        $task = $this->insertTask('add a flag');
        $run = $this->insertRun();
        $generation = $this->insertGeneration($task, 1, 'ralph/first', run: $run);
        $attempt = $this->insertAttempt($generation, $run);
        $this->insertVerificationResult($attempt);
        $this->insertGitReference($generation);
        $this->insertChecklistUpdate($generation, $run);

        $this->database->execute('DELETE FROM tasks WHERE id = ?', [$task]);

        self::assertSame(0, $this->rowsIn('generations'));
        self::assertSame(0, $this->rowsIn('attempts'));
        self::assertSame(0, $this->rowsIn('verification_results'));
        self::assertSame(0, $this->rowsIn('git_references'));
        self::assertSame(0, $this->rowsIn('checklist_updates'));
    }

    /**
     * A finished run must not take the history of what it did with it: the
     * commits it made are still on branches, and `status` has to keep being
     * able to say which task they belong to.
     */
    #[Test]
    public function deleting_a_run_leaves_the_generations_it_produced(): void
    {
        $run = $this->insertRun();
        $task = $this->insertTask('add a flag');
        $this->insertGeneration($task, 1, 'ralph/first', run: $run, state: 'completed');

        $this->database->execute('DELETE FROM runs WHERE id = ?', [$run]);

        self::assertSame(1, $this->rowsIn('generations'));
        self::assertNull($this->database->value('SELECT run_id FROM generations'));
    }

    #[Test]
    public function one_check_position_is_recorded_once_per_attempt(): void
    {
        $task = $this->insertTask('add a flag');
        $run = $this->insertRun();
        $attempt = $this->insertAttempt($this->insertGeneration($task, 1, 'ralph/first', run: $run), $run);

        $this->insertVerificationResult($attempt, position: 1);

        $this->refuses(
            'UNIQUE constraint failed: verification_results.attempt_id, verification_results.position',
            fn (): int => $this->insertVerificationResult($attempt, position: 1),
        );
    }

    #[Test]
    public function a_check_outcome_the_package_does_not_know_is_refused(): void
    {
        $task = $this->insertTask('add a flag');
        $run = $this->insertRun();
        $attempt = $this->insertAttempt($this->insertGeneration($task, 1, 'ralph/first', run: $run), $run);

        $this->refuses(
            'CHECK constraint failed: outcome IN',
            fn (): int => $this->insertVerificationResult($attempt, outcome: 'mostly_passed'),
        );
    }

    #[Test]
    public function a_push_state_the_package_does_not_know_is_refused(): void
    {
        $task = $this->insertTask('add a flag');

        $this->refuses(
            'CHECK constraint failed: push_state IN',
            fn (): int => $this->insertGeneration($task, 1, 'ralph/first', pushState: 'probably'),
        );
    }

    /**
     * The conservative readings a column falls back to, written with those
     * columns left out of the insert so that it is the schema's default being
     * tested and not the helper's.
     *
     * Each one is the answer that cannot claim something the runner has not
     * done: nothing is awaiting publication, no commit has been skipped as
     * unnecessary, no attempt has been made, and no worktree is owned.
     */
    #[Test]
    public function a_new_generation_claims_nothing_by_default(): void
    {
        $task = $this->insertTask('add a flag');

        $this->database->execute(
            'INSERT INTO generations
                (task_id, number, slug, state, content_hash, agent, adapter, agent_settings,
                 base_reference, base_sha, started_at, updated_at)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $task,
                'TASK-00000001',
                'pending',
                hash('sha256', 'content'),
                'codex',
                'codex',
                '{}',
                'HEAD',
                str_repeat('a', 40),
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );

        $row = $this->database->query(
            'SELECT push_state, no_change, attempts, owns_worktree, phase, branch, commit_sha
             FROM generations',
        )[0];

        self::assertSame('not_required', $row['push_state'], 'A new pass was recorded as awaiting a push.');
        self::assertSame(0, (int) $row['no_change'], 'A new pass claimed the task was already satisfied.');
        self::assertSame(0, (int) $row['attempts'], 'A new pass started with an attempt already counted.');
        self::assertSame(0, (int) $row['owns_worktree'], 'A new pass claimed a worktree it had not made.');
        self::assertNull($row['phase']);
        self::assertNull($row['branch']);
        self::assertNull($row['commit_sha']);
    }

    #[Test]
    public function the_vocabularies_are_lowercase_identifiers(): void
    {
        $vocabularies = [
            Schema::states(),
            Schema::openStates(),
            Schema::closedStates(),
            Schema::phases(),
            Schema::RUN_STATES,
            Schema::attemptOutcomes(),
            Schema::checkOutcomes(),
            Schema::pushStates(),
            Schema::GIT_REFERENCE_KINDS,
            Schema::GIT_REFERENCE_OUTCOMES,
            Schema::checklistMarkers(),
            Schema::checklistUpdateStates(),
            Schema::completions(),
            Schema::agentSources(),
        ];

        foreach ($vocabularies as $values) {
            self::assertNotSame('', Schema::literals($values));
        }
    }

    #[Test]
    public function open_and_closed_states_partition_the_lifecycle(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(
                Schema::states(),
                [...Schema::openStates(), ...Schema::closedStates()],
            )),
            'A lifecycle state is neither open nor closed, so the one-open-pass index cannot classify it.',
        );

        self::assertSame(
            [],
            array_values(array_intersect(Schema::openStates(), Schema::closedStates())),
            'A lifecycle state is both open and closed.',
        );
    }

    #[Test]
    public function a_value_that_is_not_an_identifier_is_refused_rather_than_quoted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::literals(["it's complicated"]);
    }

    #[Test]
    public function the_schema_version_is_the_newest_migration(): void
    {
        self::assertSame(Migrations::latestVersion(), Schema::version());
        self::assertSame(Schema::version(), (new Migrator())->latestVersion());
    }

    private function insertTask(string $description, string $completion = 'pending'): int
    {
        $now = '2026-11-01T00:00:00+00:00';

        $this->database->execute(
            'INSERT INTO tasks
                (task_id, description, details, content_hash, completion, first_seen_at, last_seen_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                TaskId::fromDescription($description)->value(),
                $description,
                '',
                hash('sha256', $description),
                $completion,
                $now,
                $now,
                $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    private function insertRun(): int
    {
        $this->database->execute(
            'INSERT INTO runs
                (run_id, state, agent, adapter, agent_source, agent_settings, configuration, checks,
                 tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-' . bin2hex(random_bytes(4)),
                'running',
                'codex',
                'codex',
                'project_default',
                '{"adapter":"codex","executable":"codex"}',
                '{}',
                '[]',
                '.ralph/tasks.md',
                'HEAD',
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    private function insertGeneration(
        int $task,
        int $number,
        ?string $branch = null,
        ?int $run = null,
        string $state = 'running',
        ?string $phase = 'executing',
        string $pushState = 'not_required',
        ?string $slug = null,
    ): int {
        $this->database->execute(
            'INSERT INTO generations
                (task_id, number, slug, run_id, state, phase, content_hash, agent, adapter, agent_settings,
                 base_reference, base_sha, branch, push_state, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $task,
                $number,
                $slug ?? sprintf('TASK-%08X-g%d', $task, $number),
                $run,
                $state,
                $phase,
                hash('sha256', 'content'),
                'codex',
                'codex',
                '{}',
                'HEAD',
                str_repeat('a', 40),
                $branch,
                $pushState,
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    private function insertAttempt(int $generation, int $run, int $number = 1): int
    {
        $this->database->execute(
            'INSERT INTO attempts
                (generation_id, run_id, number, state, phase, agent, adapter, agent_settings,
                 started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $generation,
                $run,
                $number,
                'running',
                'executing',
                'codex',
                'codex',
                '{}',
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    private function insertVerificationResult(
        int $attempt,
        int $position = 1,
        string $outcome = 'passed',
    ): int {
        $this->database->execute(
            'INSERT INTO verification_results
                (attempt_id, position, name, command, outcome, exit_code, timeout_seconds, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $attempt,
                $position,
                'tests',
                '["composer","test"]',
                $outcome,
                0,
                600,
                '2026-11-01T00:00:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    private function insertGitReference(int $generation): int
    {
        $this->database->execute(
            'INSERT INTO git_references (generation_id, kind, name, sha, outcome, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$generation, 'commit', null, str_repeat('b', 40), 'created', '2026-11-01T00:00:00+00:00'],
        );

        return $this->database->lastInsertId();
    }

    private function insertChecklistUpdate(int $generation, int $run): int
    {
        $this->database->execute(
            'INSERT INTO checklist_updates
                (generation_id, run_id, path, marker, state, expected_hash, task_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $generation,
                $run,
                '.ralph/tasks.md',
                'checked',
                'pending',
                hash('sha256', 'file'),
                hash('sha256', 'task'),
                '2026-11-01T00:00:00+00:00',
                '2026-11-01T00:00:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    /**
     * Assert that the database refuses a write, and that it is the named
     * constraint that refuses it.
     *
     * Naming the constraint is the point. "Something threw" would also pass if
     * the row were rejected for an unrelated reason — a typo in a column name,
     * a foreign key standing in for the index under test — and the test would
     * then keep passing after the constraint it is about was dropped.
     *
     * {@see Database} reports a constraint violation as a
     * {@see StateException} with SQLite's own message inside it, which is why
     * the message is where the constraint is looked for.
     *
     * @param callable(): int $write
     */
    private function refuses(string $constraint, callable $write, ?string $because = null): void
    {
        try {
            $write();
        } catch (StateException $refused) {
            self::assertStringContainsString($constraint, $refused->getMessage(), $because ?? '');

            return;
        }

        self::fail($because ?? sprintf('The database accepted a row "%s" should refuse.', $constraint));
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->database->value(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
