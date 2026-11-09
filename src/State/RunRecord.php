<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\ConfigurationException;
use InvalidArgumentException;

/**
 * One `runs` row: an invocation of the loop, and what it was configured to be.
 *
 * A run is the unit the limits of PROJECT.md section H are counted against and
 * the unit `resume` reconciles. It exists mainly so that the things a run must
 * not change halfway through — the agent, the checks, the base reference, the
 * task file — are written down once, at the start, where a later phase can read
 * them instead of asking the configuration again.
 *
 * **The snapshot is the point.** `configuration` and `checks` are the values as
 * they were read at the run's start. An agent that edits `.ralph/config.json`
 * mid-run changes what the *next* run requires and nothing about this one
 * (PROJECT.md section G, item 3).
 *
 * **No secrets are in here.** The agent's settings are
 * {@see AgentRecord::settings()}, which is the non-secret subset by
 * construction; authentication is named by environment variable and read at
 * invocation time.
 */
final class RunRecord
{
    /**
     * @param array<string, mixed> $configuration the frozen project settings
     * @param list<array<string, mixed>> $checks the frozen verification commands
     */
    private function __construct(
        private readonly int $rowId,
        private readonly string $runId,
        private readonly LifecycleState $state,
        private readonly ?string $stopReason,
        private readonly ?string $stopAction,
        private readonly ?StopKind $stopKind,
        private readonly AgentRecord $agent,
        private readonly AgentSource $agentSource,
        private readonly array $configuration,
        private readonly array $checks,
        private readonly string $tasksPath,
        private readonly string $baseReference,
        private readonly ?string $baseSha,
        private readonly ?string $host,
        private readonly ?int $pid,
        private readonly ?int $resumedFrom,
        private readonly ?string $resumedFromRunId,
        private readonly int $iterations,
        private readonly int $activeSeconds,
        private readonly int $carriedIterations,
        private readonly int $carriedSeconds,
        private readonly Timestamp $startedAt,
        private readonly Timestamp $updatedAt,
        private readonly ?Timestamp $finishedAt,
    ) {
    }

    /**
     * Read a stored row.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the row does not describe a run
     */
    public static function fromRow(array $row): self
    {
        $state = LifecycleState::from(self::text($row, 'state'));

        return new self(
            (int) ($row['id'] ?? 0),
            self::text($row, 'run_id'),
            $state,
            self::nullableText($row, 'stop_reason'),
            self::nullableText($row, 'stop_action'),
            self::recordedKind($row),
            AgentRecord::fromStorage(
                self::text($row, 'agent'),
                self::text($row, 'adapter'),
                self::text($row, 'agent_settings'),
            ),
            AgentSource::from(self::text($row, 'agent_source')),
            self::decodeMap(self::text($row, 'configuration')),
            self::decodeList(self::text($row, 'checks')),
            self::text($row, 'tasks_path'),
            self::text($row, 'base_reference'),
            self::nullableText($row, 'base_sha'),
            self::nullableText($row, 'host'),
            isset($row['pid']) ? (int) $row['pid'] : null,
            isset($row['resumed_from']) ? (int) $row['resumed_from'] : null,
            self::nullableText($row, 'resumed_from_run_id'),
            (int) ($row['iterations_used'] ?? 0),
            (int) ($row['active_seconds'] ?? 0),
            (int) ($row['carried_iterations'] ?? 0),
            (int) ($row['carried_seconds'] ?? 0),
            Timestamp::fromStorage(self::text($row, 'started_at')),
            Timestamp::fromStorage(self::text($row, 'updated_at')),
            Timestamp::recorded(self::nullableText($row, 'finished_at')),
        );
    }

    /**
     * The row's primary key, which the generation and attempt rows reference.
     */
    public function rowId(): int
    {
        return $this->rowId;
    }

    /**
     * The identifier a person reads and a commit trailer carries.
     */
    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * How the run stands.
     *
     * Only four of the seven lifecycle states can appear here, and the schema
     * enforces that: a run is `running`, or it ended `completed`, `failed` or
     * `interrupted`.
     */
    public function state(): LifecycleState
    {
        return $this->state;
    }

    public function isRunning(): bool
    {
        return $this->state->isRunning();
    }

    public function isFinished(): bool
    {
        return !$this->state->isRunning();
    }

    /**
     * Why the loop stopped, in the words `status` prints.
     */
    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * What the user is expected to do about the way it stopped.
     *
     * The other half of PROJECT.md section H, item 9, and null for two
     * different reasons: a run that is still going has not stopped, and a run
     * recorded by a build older than schema 2 stopped without anybody writing
     * an action down. Neither is invented here — {@see \Celyes\RalphPhp\Run\RunStop}
     * is where the two halves are produced together.
     */
    public function stopAction(): ?string
    {
        return $this->stopAction;
    }

    /**
     * Which kind of thing ended the run, where it was recorded.
     *
     * Not a second spelling of {@see self::state()}: a run that ended
     * `completed` may have worked through its checklist, reached a limit, or
     * left a task that ran out of attempts, and those want different words and
     * different exit codes —
     * {@see \Celyes\RalphPhp\Console\ExitCode::forStop()} is the mapping
     * (section I, item 5), and this column is what it reads. A `failed` run
     * whose kind is `decision` is the same distinction from the other side: the
     * run could not carry on, and what it is waiting for is a person.
     */
    public function stopKind(): ?StopKind
    {
        return $this->stopKind;
    }

    /**
     * The agent this run resolved, with its non-secret settings.
     */
    public function agent(): AgentRecord
    {
        return $this->agent;
    }

    /**
     * Whether the agent came from configuration or from `--agent`.
     */
    public function agentSource(): AgentSource
    {
        return $this->agentSource;
    }

    /**
     * The project settings as the run read them.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->configuration;
    }

    /**
     * The verification commands the run froze at its start.
     *
     * The stored rows. {@see verification()} is what a caller that intends to
     * *run* them should ask for.
     *
     * @return list<array<string, mixed>>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * The frozen checks, read back as the set the run is held to.
     *
     * This is where the loop gets its checks from, rather than from the
     * configuration it was handed: a worker that edits `.ralph/config.json`
     * while the run is going changes what the next run requires and nothing
     * about this one (PROJECT.md section G, item 3). A resumed run reads the
     * same column and is therefore held to the same set it started under.
     *
     * Built on demand rather than in {@see fromRow()}, so that a row whose
     * checks cannot be read back refuses only the caller that was about to
     * verify something with them — `status` can still list the run.
     *
     * @throws ConfigurationException when the recorded checks cannot be read
     *         back, which is not a reason to carry on with a different set
     */
    public function verification(): VerificationSnapshot
    {
        return VerificationSnapshot::fromStorage(
            $this->checks,
            sprintf('the checks run %s froze', $this->runId),
        );
    }

    /**
     * The names of the frozen checks, for status output.
     *
     * @return list<string>
     */
    public function checkNames(): array
    {
        return array_map(
            static fn (array $check): string => is_string($check['name'] ?? null) ? $check['name'] : '',
            $this->checks,
        );
    }

    /**
     * The task file the run read, as an absolute path.
     */
    public function tasksPath(): string
    {
        return $this->tasksPath;
    }

    public function baseReference(): string
    {
        return $this->baseReference;
    }

    /**
     * The commit the reference resolved to, or null when the run stopped before
     * it could be resolved.
     */
    public function baseSha(): ?string
    {
        return $this->baseSha;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    /**
     * The process that holds the run.
     *
     * Recorded so that `resume` can say whether the old runner is still alive
     * before it takes anything over (PROJECT.md section H, item 1).
     */
    public function pid(): ?int
    {
        return $this->pid;
    }

    /**
     * The row id of the run this one is continuing, when it is a resume.
     */
    public function resumedFrom(): ?int
    {
        return $this->resumedFrom;
    }

    /**
     * The identifier of the run this one is continuing, as a person reads it.
     */
    public function resumedFromRunId(): ?string
    {
        return $this->resumedFromRunId;
    }

    /**
     * Whether this run continues an earlier one rather than starting over.
     */
    public function isResume(): bool
    {
        return $this->resumedFrom !== null;
    }

    /**
     * Whether this run was deliberately started anew.
     *
     * The other half of PROJECT.md section H, item 3, and the reason the
     * distinction is recorded at all: a fresh start gets the configured limits
     * whole, a resume gets what its predecessor left of them. Which of the two
     * a row is must be legible afterwards, because it is the difference
     * between a run that did two tasks and a run that did two tasks on top of
     * three — and the counters alone cannot say.
     *
     * Starting afresh costs nothing already finished: completed tasks belong
     * to the checklist and its task rows, not to the run that completed them.
     */
    public function isFreshStart(): bool
    {
        return $this->resumedFrom === null;
    }

    /**
     * How many tasks have been taken on, counting the runs this one continues.
     *
     * The figure {@see \Celyes\RalphPhp\Run\RunBudget} is measured against,
     * and a total rather than this run's own work on purpose: a limit that
     * only counted the current process would be reset by every interruption.
     */
    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * How long has been spent working, in seconds, counting the runs this one
     * continues.
     */
    public function activeSeconds(): int
    {
        return $this->activeSeconds;
    }

    /**
     * The iteration count this run began with, inherited from the run it
     * continues; zero for a fresh start.
     */
    public function carriedIterations(): int
    {
        return $this->carriedIterations;
    }

    /**
     * The active seconds this run began with, inherited from the run it
     * continues; zero for a fresh start.
     */
    public function carriedSeconds(): int
    {
        return $this->carriedSeconds;
    }

    /**
     * Tasks this run took on itself, leaving out what it inherited.
     *
     * What `status` should show against a resumed run: the totals are what the
     * limits are measured against, and this is what the run actually did. The
     * floor at zero is for a predecessor whose counters somehow exceed its
     * continuation's, which the store cannot write but a hand-edited database
     * could hold.
     */
    public function ownIterations(): int
    {
        return max(0, $this->iterations - $this->carriedIterations);
    }

    /**
     * Seconds this run spent working itself, leaving out what it inherited.
     */
    public function ownActiveSeconds(): int
    {
        return max(0, $this->activeSeconds - $this->carriedSeconds);
    }

    public function startedAt(): Timestamp
    {
        return $this->startedAt;
    }

    public function updatedAt(): Timestamp
    {
        return $this->updatedAt;
    }

    public function finishedAt(): ?Timestamp
    {
        return $this->finishedAt;
    }

    /**
     * One line for `status` and the run's log header.
     *
     * A continuation says both figures, because neither is the whole answer on
     * its own: the tasks it took on are what it did, and the total is what its
     * limits are measured against.
     */
    public function describe(): string
    {
        $described = sprintf(
            '%s %s with %s, %s from %s',
            $this->runId,
            $this->state->value,
            $this->agent->describe(),
            $this->tasksTaken(),
            $this->baseReference,
        );

        if ($this->stopReason !== null) {
            $described .= sprintf(' (%s)', $this->stopReason);
        }

        return $described;
    }

    /**
     * The tasks taken on, said so that a resume's inherited count cannot be
     * mistaken for work this run did.
     */
    private function tasksTaken(): string
    {
        $own = sprintf('%d task%s', $this->ownIterations(), $this->ownIterations() === 1 ? '' : 's');

        if ($this->isFreshStart()) {
            return $own;
        }

        return sprintf(
            '%s continuing %s (%d in all)',
            $own,
            $this->resumedFromRunId ?? 'an earlier run',
            $this->iterations,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'state' => $this->state->value,
            'stop_reason' => $this->stopReason,
            'stop_action' => $this->stopAction,
            'stop_kind' => $this->stopKind?->value,
            'agent' => $this->agent->name(),
            'adapter' => $this->agent->adapter(),
            'agent_source' => $this->agentSource->value,
            'checks' => $this->checkNames(),
            'base_reference' => $this->baseReference,
            'base_sha' => $this->baseSha,
            // Null for a deliberately new run, the predecessor's identifier
            // for a continuation: the one field that tells the two apart
            // (section H, item 3).
            'resumed_from' => $this->resumedFromRunId,
            'iterations' => $this->iterations,
            'iterations_here' => $this->ownIterations(),
            'active_seconds' => $this->activeSeconds,
            'active_seconds_here' => $this->ownActiveSeconds(),
            'started_at' => $this->startedAt->value(),
            'finished_at' => $this->finishedAt?->value(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the column is not a string
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('A run row needs a %s.', str_replace('_', ' ', $column)),
            );
        }

        return $value;
    }

    /**
     * The recorded stop kind, or null when the row has none.
     *
     * A value the enum does not know is read as null rather than refused: the
     * column is a description of a finished run, and a row written by a newer
     * build — or edited by hand — is not a reason to make the run unreadable.
     * The reason and the action are the parts a user needs, and they are plain
     * text.
     *
     * @param array<string, mixed> $row
     */
    private static function recordedKind(array $row): ?StopKind
    {
        $value = self::nullableText($row, 'stop_kind');

        return $value === null ? null : StopKind::tryFrom($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableText(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeMap(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
