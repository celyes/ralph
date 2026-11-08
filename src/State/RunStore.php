<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\ConfigurationException;
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

    /**
     * How a run row is read: with the run it continues beside it.
     *
     * The left join is what lets a {@see RunRecord} say what it is without a
     * second query — whether it is a deliberately new run or a continuation,
     * which run it continues, and how much of its counters it inherited rather
     * than spent (PROJECT.md section H, item 3). Nothing is joined for a fresh
     * start, and the three columns come back null, which is the same answer.
     */
    private const string SELECT = 'SELECT runs.*,
                previous.run_id AS resumed_from_run_id,
                previous.iterations_used AS carried_iterations,
                previous.active_seconds AS carried_seconds
            FROM runs LEFT JOIN runs previous ON previous.id = runs.resumed_from';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Open a run: a deliberately new one, with its limits whole.
     *
     * The fresh start of PROJECT.md section H, item 3. Its counters begin at
     * zero, which is the point of it: a user who starts a run after one that
     * stopped at a limit is asking for the limits again, not for the leftovers
     * of the run before. {@see resume()} is the other case, and the two are
     * told apart by `resumed_from` being null here.
     *
     * **Nothing of what earlier runs finished is lost by starting one.** A
     * task's identity, its completion and its generations belong to the task
     * and not to the run that worked on it, so a new run reads the same
     * verified history ({@see TaskStore::verified()}) and takes on only what
     * is still pending. The run row is new; the checklist's past is not.
     *
     * @param AgentSelection $agent         the resolved agent, with where it was
     *        selected from — recorded so that `resume` can refuse an implicit
     *        change of agent (PROJECT.md section H, item 6)
     * @param Configuration  $configuration the settings as read, frozen here —
     *        the verification commands among them, as a
     *        {@see VerificationSnapshot}
     * @param string|null    $baseSha       the resolved base commit, when the
     *        reference has already been resolved
     *
     * @throws StateException         when the row cannot be written
     * @throws ConfigurationException when the configuration states no usable
     *         set of checks to freeze
     */
    public function open(
        AgentSelection $agent,
        Configuration $configuration,
        ?string $baseSha = null,
    ): RunRecord {
        return $this->insert($agent, $configuration, $baseSha, null);
    }

    /**
     * Open a run that continues an interrupted one, counters and all.
     *
     * The preserved counters of PROJECT.md section H, item 3. The new row
     * starts at the iteration count and the active seconds the run being
     * continued had reached, so the limits of section H, item 2 bound the work
     * rather than the process: a run interrupted three tasks into a limit of
     * five is resumed with two tasks left, and resuming it again does not buy
     * five more. Resume exists to continue work that was interrupted, not to
     * reset what that work had already spent.
     *
     * The counters are carried rather than summed over the chain at reading
     * time, because the figure a continuation inherits is already a total: each
     * resume carries forward what its predecessor had, so a row holds what the
     * whole chain has spent and {@see RunBudget} can be measured from one row.
     * What this run spent by itself is still legible — see
     * {@see RunRecord::ownIterations()}.
     *
     * The agent is taken from the caller rather than from `$previous`, because
     * it is the caller — {@see \Celyes\RalphPhp\Run\AgentContinuity}, for
     * `ralph resume` — that has to *explain* a refused change of agent, naming
     * what to type instead (section H, item 6). What is checked here is only
     * that the two agree: a continuation recording an agent other than the one
     * its predecessor used would attribute that run's commits and its cost to a
     * tool that did not produce them, and the column is the only record of
     * which did. The settings are not compared, only the agent and its adapter
     * — see {@see AgentRecord::isSameAgent()}.
     *
     * Also refused is what the store can see on its own: a run that ended in
     * the ordinary way has nothing left to continue, and continuing it would
     * be a fresh run wearing a finished run's budget.
     *
     * @param RunRecord $previous the run being continued, which may still claim
     *        to be running: a crashed runner never closed its row, and that is
     *        the commonest thing there is to resume
     *
     * @throws StateException         when the previous run completed, when the
     *                                agent is not the one it recorded, or the
     *                                row cannot be written
     * @throws ConfigurationException when the configuration states no usable
     *         set of checks to freeze
     */
    public function resume(
        RunRecord $previous,
        AgentSelection $agent,
        Configuration $configuration,
        ?string $baseSha = null,
    ): RunRecord {
        if ($previous->state() === LifecycleState::Completed) {
            throw new StateException(
                sprintf(
                    'Run %s ended as completed; there is nothing in it to resume.',
                    $previous->runId(),
                ),
                [
                    sprintf('It stopped because: %s.', $previous->stopReason() ?? 'no reason recorded'),
                    'Start a new run with `ralph run`, which begins with the configured limits whole.',
                    'Tasks completed by earlier runs stay completed and are not taken on again.',
                ],
            );
        }

        if (!$previous->agent()->isSameAgent(AgentRecord::of($agent))) {
            throw new StateException(
                sprintf(
                    'Run %s was carried out with the agent "%s"; a run continuing it cannot record "%s".',
                    $previous->runId(),
                    $previous->agent()->name(),
                    $agent->name(),
                ),
                [
                    'A resume continues interrupted work with the agent that produced it (PROJECT.md section H, item 6).',
                    sprintf(
                        'To use "%s", start a new run instead: `ralph run --agent %s`, which begins with the configured limits whole.',
                        $agent->name(),
                        $agent->name(),
                    ),
                ],
            );
        }

        return $this->insert($agent, $configuration, $baseSha ?? $previous->baseSha(), $previous);
    }

    /**
     * Write a run row, fresh or continuing.
     *
     * @throws StateException         when the row cannot be written
     * @throws ConfigurationException when the configuration states no usable
     *         set of checks to freeze
     */
    private function insert(
        AgentSelection $agent,
        Configuration $configuration,
        ?string $baseSha,
        ?RunRecord $previous,
    ): RunRecord {
        $record = AgentRecord::of($agent);
        $columns = $record->toColumns();
        $now = Timestamp::now($this->clock);

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, base_sha, host, pid, resumed_from,
                  iterations_used, active_seconds, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->identifier($now),
                LifecycleState::Running->value,
                $columns['agent'],
                $columns['adapter'],
                $agent->source()->value,
                $columns['settings'],
                $this->encode($configuration->toArray()),
                // The one place a run's checks are frozen (section G, item 3).
                // Everything that later runs or reports a check reads this
                // column back, not the configuration, so an edit to
                // `.ralph/config.json` cannot reach a run already under way.
                $this->encode(VerificationSnapshot::of($configuration)->toStorage()),
                $configuration->tasksFile(),
                $configuration->git()->baseReference(),
                $baseSha,
                $this->host(),
                getmypid() === false ? null : getmypid(),
                $previous?->rowId(),
                // Zero for a fresh start, the predecessor's totals for a
                // continuation: section H, item 3, in two columns.
                $previous?->iterations() ?? 0,
                $previous?->activeSeconds() ?? 0,
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
        $rows = $this->database->query(self::SELECT . ' WHERE runs.run_id = ?', [$runId]);

        return $rows === [] ? null : RunRecord::fromRow($rows[0]);
    }

    /**
     * The most recent run, or null when none has ever started.
     *
     * @throws StateException when the row cannot be read
     */
    public function latest(): ?RunRecord
    {
        $rows = $this->database->query(self::SELECT . ' ORDER BY runs.id DESC LIMIT 1');

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
            self::SELECT . ' WHERE runs.state = ? ORDER BY runs.id DESC LIMIT 1',
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
            $this->database->query(self::SELECT . ' ORDER BY runs.id DESC'),
        );
    }

    /**
     * The run this one continues, or null when it is a fresh start.
     *
     * @throws StateException when the row cannot be read
     */
    public function predecessor(RunRecord $run): ?RunRecord
    {
        $rowId = $run->resumedFrom();

        return $rowId === null ? null : $this->read($rowId);
    }

    /**
     * A run and everything it continues, newest first.
     *
     * What a resumed run's history is: a fresh start is one row, and each
     * resume adds one in front of it. `status` and `logs` read it to show the
     * work as the one continued effort it is rather than as a series of
     * unrelated runs that happen to share a checklist.
     *
     * A row whose predecessor has been deleted ends the chain where the
     * deletion did — `resumed_from` is `ON DELETE SET NULL`, so the lineage is
     * as much of the history as still exists, which is the honest answer. The
     * loop over it is bounded by the ids already seen as well: a cycle cannot
     * be written through this store, and a database that somehow held one must
     * not hang the command that reads it.
     *
     * @return list<RunRecord>
     *
     * @throws StateException when a row cannot be read
     */
    public function lineage(RunRecord $run): array
    {
        $chain = [$run];
        $seen = [$run->rowId() => true];
        $rowId = $run->resumedFrom();

        while ($rowId !== null && !isset($seen[$rowId])) {
            $seen[$rowId] = true;
            $previous = $this->read($rowId);
            $chain[] = $previous;
            $rowId = $previous->resumedFrom();
        }

        return $chain;
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
        $rows = $this->database->query(self::SELECT . ' WHERE runs.id = ?', [$rowId]);

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
