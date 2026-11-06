<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Random\RandomException;

/**
 * The `runs` rows: one per invocation of the loop.
 *
 * Opened before the first task is looked at, because everything the run must
 * hold still — the agent, the frozen checks, the base reference, the task file
 * — has to be written down before any of it is used, and because a generation
 * and an attempt both carry the run they belong to.
 *
 * **A run is closed once.** `completed`, `failed` and `interrupted` are the
 * three ways out, each with the reason the loop stopped, and a run already
 * closed is refused rather than reclosed: the first reason is the true one, and
 * a `finally` on the way out of a failed run must not be able to overwrite it
 * with something blander.
 */
final class RunStore
{
    /**
     * How many random characters a run identifier ends with.
     *
     * The timestamp makes identifiers sort and read sensibly; the suffix is
     * what keeps two runs started in the same second apart.
     */
    public const int SUFFIX_LENGTH = 6;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Open a run.
     *
     * @param AgentSelection $agent         the resolved agent, with where it was
     *        selected from — recorded so that `resume` can refuse an implicit
     *        change of agent (PROJECT.md section H, item 6)
     * @param Configuration  $configuration the settings as read, frozen here
     * @param string|null    $baseSha       the resolved base commit, when the
     *        reference has already been resolved
     * @param RunRecord|null $resumedFrom   the run this one continues
     *
     * @throws StateException when the row cannot be written
     */
    public function open(
        AgentSelection $agent,
        Configuration $configuration,
        ?string $baseSha = null,
        ?RunRecord $resumedFrom = null,
    ): RunRecord {
        $record = AgentRecord::of($agent);
        $columns = $record->toColumns();
        $now = Timestamp::now($this->clock);

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, base_sha, host, pid, resumed_from,
                  iterations_used, active_seconds, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)',
            [
                $this->identifier($now),
                LifecycleState::Running->value,
                $columns['agent'],
                $columns['adapter'],
                $agent->source()->value,
                $columns['settings'],
                $this->encode($configuration->toArray()),
                $this->encode(array_map(
                    static fn (VerificationCommand $check): array => $check->toArray(),
                    $configuration->checks(),
                )),
                $configuration->tasksFile(),
                $configuration->git()->baseReference(),
                $baseSha,
                $this->host(),
                getmypid() === false ? null : getmypid(),
                $resumedFrom?->rowId(),
                $now->value(),
                $now->value(),
            ],
        );

        return $this->read($this->database->lastInsertId());
    }

    /**
     * A run by its row id.
     *
     * @throws StateException when there is no such run
     */
    public function get(int $rowId): RunRecord
    {
        return $this->read($rowId);
    }

    /**
     * A run by the identifier a person sees, or null when there is none.
     *
     * @throws StateException when the row cannot be read
     */
    public function find(string $runId): ?RunRecord
    {
        $rows = $this->database->query('SELECT * FROM runs WHERE run_id = ?', [$runId]);

        return $rows === [] ? null : RunRecord::fromRow($rows[0]);
    }

    /**
     * The most recent run, or null when none has ever started.
     *
     * @throws StateException when the row cannot be read
     */
    public function latest(): ?RunRecord
    {
        $rows = $this->database->query('SELECT * FROM runs ORDER BY id DESC LIMIT 1');

        return $rows === [] ? null : RunRecord::fromRow($rows[0]);
    }

    /**
     * The run that still claims to be running, or null when none does.
     *
     * A claim and not a fact: the process that wrote it may be long gone, which
     * is what {@see RunRecord::pid()} is for and what the lock of PROJECT.md
     * section H, item 1 settles.
     *
     * @throws StateException when the row cannot be read
     */
    public function running(): ?RunRecord
    {
        $rows = $this->database->query(
            'SELECT * FROM runs WHERE state = ? ORDER BY id DESC LIMIT 1',
            [LifecycleState::Running->value],
        );

        return $rows === [] ? null : RunRecord::fromRow($rows[0]);
    }

    /**
     * Every run, newest first.
     *
     * @return list<RunRecord>
     *
     * @throws StateException when the rows cannot be read
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): RunRecord => RunRecord::fromRow($row),
            $this->database->query('SELECT * FROM runs ORDER BY id DESC'),
        );
    }

    /**
     * Record the base commit, once the reference has been resolved.
     *
     * @throws StateException when the SHA is blank or the row cannot be written
     */
    public function recordBase(RunRecord $run, string $baseSha): RunRecord
    {
        if (trim($baseSha) === '') {
            throw new StateException('A run cannot record a blank base commit.');
        }

        return $this->write($run, ['base_sha' => trim($baseSha)]);
    }

    /**
     * Count one task taken on.
     *
     * The iteration limit of PROJECT.md section H, item 2 is counted here and
     * nowhere else, so that a resumed run continues the count rather than
     * starting it again.
     *
     * @throws StateException when the row cannot be written
     */
    public function countIteration(RunRecord $run): RunRecord
    {
        return $this->write($run, ['iterations_used' => $run->iterations() + 1]);
    }

    /**
     * Add to the run's working time.
     *
     * @throws StateException when the seconds are negative, or the row cannot
     *                        be written
     */
    public function addActiveSeconds(RunRecord $run, int $seconds): RunRecord
    {
        if ($seconds < 0) {
            throw new StateException('A run cannot spend a negative amount of time working.');
        }

        return $this->write($run, ['active_seconds' => $run->activeSeconds() + $seconds]);
    }

    /**
     * Close the run as finished, with why the loop stopped.
     *
     * @throws StateException when the run is already closed
     */
    public function complete(RunRecord $run, string $stopReason): RunRecord
    {
        return $this->close($run, LifecycleState::Completed, $stopReason);
    }

    /**
     * Close the run as failed, with what it could not get past.
     *
     * @throws StateException when the run is already closed
     */
    public function fail(RunRecord $run, string $stopReason): RunRecord
    {
        return $this->close($run, LifecycleState::Failed, $stopReason);
    }

    /**
     * Close the run as interrupted.
     *
     * @throws StateException when the run is already closed
     */
    public function interrupt(RunRecord $run, string $stopReason = 'interrupted'): RunRecord
    {
        return $this->close($run, LifecycleState::Interrupted, $stopReason);
    }

    /**
     * Move a run to the state it ended in.
     *
     * @throws StateException when it has already ended
     */
    private function close(RunRecord $run, LifecycleState $state, string $stopReason): RunRecord
    {
        $current = $this->read($run->rowId());

        if ($current->isFinished()) {
            throw new StateException(
                sprintf(
                    'Run %s already ended as %s; it cannot also end as %s.',
                    $current->runId(),
                    $current->state()->value,
                    $state->value,
                ),
                [
                    sprintf('It stopped because: %s.', $current->stopReason() ?? 'no reason recorded'),
                    'The first reason a run stopped is the true one and is kept.',
                ],
            );
        }

        $now = Timestamp::now($this->clock);

        return $this->write($current, [
            'state' => $current->state()->transitionTo($state)->value,
            'stop_reason' => $this->reason($stopReason),
            'finished_at' => $now->value(),
        ], $now);
    }

    /**
     * Write columns to a run's row, moving `updated_at` with them.
     *
     * @param array<string, scalar|null> $columns
     *
     * @throws StateException when the row is gone, or the write is refused
     */
    private function write(RunRecord $run, array $columns, ?Timestamp $now = null): RunRecord
    {
        $columns['updated_at'] = ($now ?? Timestamp::now($this->clock))->value();

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($columns),
        ));

        $changed = $this->database->execute(
            sprintf('UPDATE runs SET %s WHERE id = ?', $assignments),
            [...array_values($columns), $run->rowId()],
        );

        if ($changed === 0) {
            throw new StateException(sprintf(
                'Run %s is no longer in the state database; it cannot be updated.',
                $run->runId(),
            ));
        }

        return $this->read($run->rowId());
    }

    /**
     * @throws StateException when there is no such run
     */
    private function read(int $rowId): RunRecord
    {
        $rows = $this->database->query('SELECT * FROM runs WHERE id = ?', [$rowId]);

        if ($rows === []) {
            throw new StateException(sprintf('There is no run with id %d in the state database.', $rowId));
        }

        return RunRecord::fromRow($rows[0]);
    }

    /**
     * A readable, sortable, unique identifier for a run.
     *
     * Readable because it goes in commit trailers, log directories and status
     * output, where a person has to be able to match one to a run they
     * remember starting.
     */
    private function identifier(Timestamp $at): string
    {
        return sprintf(
            'run-%s-%s',
            $at->moment()->format('Ymd-His'),
            $this->suffix(),
        );
    }

    /**
     * Random characters, from the system source where there is one.
     *
     * A run identifier is not a secret and nothing is authorised by it, so a
     * source of randomness being unavailable is not worth failing a run over:
     * the timestamp and the process id are already almost unique on their own.
     */
    private function suffix(): string
    {
        try {
            return substr(bin2hex(random_bytes(self::SUFFIX_LENGTH)), 0, self::SUFFIX_LENGTH);
        } catch (RandomException) {
            return substr(md5((string) getmypid() . microtime()), 0, self::SUFFIX_LENGTH);
        }
    }

    /**
     * @throws StateException when the reason says nothing
     */
    private function reason(string $reason): string
    {
        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new StateException(
                'A run that has stopped must record why.',
                ['`ralph status` prints this, and "finished" is not a reason.'],
            );
        }

        return $trimmed;
    }

    /**
     * The machine the run is on, for a state file shared through a network
     * drive or read after the fact.
     */
    private function host(): ?string
    {
        $host = gethostname();

        return $host === false ? null : $host;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @throws StateException when the value cannot be stored
     */
    private function encode(array $value): string
    {
        $json = json_encode($value);

        if ($json === false) {
            throw new StateException(
                'The run\'s configuration could not be recorded as JSON.',
                ['A setting holds a value that cannot be stored; check the configuration file.'],
            );
        }

        return $json;
    }
}
