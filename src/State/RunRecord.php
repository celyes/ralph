<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSource;
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
        private readonly int $iterations,
        private readonly int $activeSeconds,
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
            (int) ($row['iterations_used'] ?? 0),
            (int) ($row['active_seconds'] ?? 0),
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
     * @return list<array<string, mixed>>
     */
    public function checks(): array
    {
        return $this->checks;
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
     * The run this one is continuing, when it is a resume.
     */
    public function resumedFrom(): ?int
    {
        return $this->resumedFrom;
    }

    public function isResume(): bool
    {
        return $this->resumedFrom !== null;
    }

    /**
     * How many tasks the loop has taken on in this run.
     */
    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * How long the run has spent working, in seconds.
     */
    public function activeSeconds(): int
    {
        return $this->activeSeconds;
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
     */
    public function describe(): string
    {
        $described = sprintf(
            '%s %s with %s, %s from %s',
            $this->runId,
            $this->state->value,
            $this->agent->describe(),
            $this->iterations === 1 ? '1 task' : $this->iterations . ' tasks',
            $this->baseReference,
        );

        if ($this->stopReason !== null) {
            $described .= sprintf(' (%s)', $this->stopReason);
        }

        return $described;
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
            'agent' => $this->agent->name(),
            'adapter' => $this->agent->adapter(),
            'agent_source' => $this->agentSource->value,
            'checks' => $this->checkNames(),
            'base_reference' => $this->baseReference,
            'base_sha' => $this->baseSha,
            'iterations' => $this->iterations,
            'active_seconds' => $this->activeSeconds,
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
