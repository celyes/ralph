<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;

/**
 * One row of `attempts`: one invocation of the agent, and what came back.
 *
 * Numbered within its generation, so attempt 2 of generation 2 is a second try
 * at reopened work rather than the fourth row of a flat list. The agent is
 * carried here as well as on the run, because `ralph logs` has to be able to
 * say which agent produced a given attempt long after the project's default has
 * changed.
 *
 * **Two fields hold text, and they are not the same kind of text.**
 * {@see self::summary()} is the agent's own account — defused and bounded by
 * {@see UnverifiedText} on the way in, and carrying no verdict whatever.
 * {@see self::evidence()} is what the runner observed. The row's `state`,
 * `outcome` and the rows in `verification_results` are the record of what
 * actually happened, and they are written by the runner from its own
 * observations; no part of either text field feeds them. That separation is
 * PROJECT.md section D, item 6, and it is the reason the two are stored in
 * separate columns rather than in one `notes` field that would be impossible to
 * tell apart afterwards.
 *
 * Read-only, like {@see GenerationRecord}: {@see AttemptStore} is the only
 * writer, and every write returns a record read back out of the database.
 */
final class AttemptRecord
{
    private function __construct(
        private readonly int $rowId,
        private readonly int $generationId,
        private readonly int $runId,
        private readonly int $number,
        private readonly LifecycleState $state,
        private readonly ?Phase $phase,
        private readonly AgentRecord $agent,
        private readonly ?AttemptOutcome $outcome,
        private readonly ?int $exitCode,
        private readonly ?int $durationMs,
        private readonly ?UnverifiedText $summary,
        private readonly ?FailureEvidence $evidence,
        private readonly ?Blocker $blocker,
        private readonly ?string $logPath,
        private readonly Timestamp $startedAt,
        private readonly Timestamp $updatedAt,
        private readonly ?Timestamp $finishedAt,
    ) {
    }

    /**
     * Read a row.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when a column holds something the
     *                                 package never wrote
     */
    public static function fromRow(array $row): self
    {
        return new self(
            self::integer($row, 'id'),
            self::integer($row, 'generation_id'),
            self::integer($row, 'run_id'),
            self::integer($row, 'number'),
            LifecycleState::fromStorage(self::string($row, 'state')),
            Phase::recorded(self::nullableString($row, 'phase')),
            AgentRecord::fromStorage(
                self::string($row, 'agent'),
                self::string($row, 'adapter'),
                self::string($row, 'agent_settings'),
            ),
            AttemptOutcome::recorded(self::nullableString($row, 'outcome')),
            self::nullableInteger($row, 'exit_code'),
            self::nullableInteger($row, 'duration_ms'),
            UnverifiedText::recorded(self::nullableString($row, 'summary')),
            FailureEvidence::recorded(self::nullableString($row, 'failure_evidence')),
            Blocker::recorded(self::nullableString($row, 'blocker')),
            self::nullableString($row, 'log_path'),
            Timestamp::fromStorage(self::string($row, 'started_at')),
            Timestamp::fromStorage(self::string($row, 'updated_at')),
            Timestamp::recorded(self::nullableString($row, 'finished_at')),
        );
    }

    /**
     * The integer primary key, which `verification_results` rows point at.
     */
    public function rowId(): int
    {
        return $this->rowId;
    }

    /**
     * The generation this is an attempt at.
     */
    public function generationId(): int
    {
        return $this->generationId;
    }

    /**
     * The run that made the attempt.
     */
    public function runId(): int
    {
        return $this->runId;
    }

    /**
     * Which attempt at the generation this is, counted from one.
     */
    public function number(): int
    {
        return $this->number;
    }

    public function state(): LifecycleState
    {
        return $this->state;
    }

    /**
     * What the attempt is in the middle of, or null once it is finished with.
     */
    public function phase(): ?Phase
    {
        return $this->phase;
    }

    /**
     * The agent that made the attempt, with its non-secret settings.
     */
    public function agent(): AgentRecord
    {
        return $this->agent;
    }

    /**
     * What the agent came back with, or null while it is still running.
     */
    public function outcome(): ?AttemptOutcome
    {
        return $this->outcome;
    }

    /**
     * The worker's exit status, or null when it has not exited.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * How long the invocation took, measured by the runner.
     */
    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    /**
     * The agent's own account of the attempt, or null when it gave none.
     *
     * Unverified by construction. Nothing downstream may read a claim here as
     * a result.
     */
    public function summary(): ?UnverifiedText
    {
        return $this->summary;
    }

    /**
     * What the runner observed when the attempt did not pass.
     */
    public function evidence(): ?FailureEvidence
    {
        return $this->evidence;
    }

    /**
     * Why the attempt stopped for a human, if it did.
     */
    public function blocker(): ?Blocker
    {
        return $this->blocker;
    }

    /**
     * The attempt's own log file, where the untrimmed worker output is.
     */
    public function logPath(): ?string
    {
        return $this->logPath;
    }

    public function startedAt(): Timestamp
    {
        return $this->startedAt;
    }

    public function updatedAt(): Timestamp
    {
        return $this->updatedAt;
    }

    /**
     * When the attempt reached a state it does not come back from.
     */
    public function finishedAt(): ?Timestamp
    {
        return $this->finishedAt;
    }

    /**
     * Whether the attempt is over.
     *
     * Read off `finished_at` rather than off the state, because an attempt
     * finishes in states a *pass* can still be continued from: a failed attempt
     * is over, and the generation it belongs to is retryable. The column is
     * written exactly when the attempt stops and never cleared.
     */
    public function isFinished(): bool
    {
        return $this->finishedAt !== null;
    }

    /**
     * Whether the agent said anything about this attempt.
     */
    public function hasSummary(): bool
    {
        return $this->summary !== null && !$this->summary->isEmpty();
    }

    /**
     * Whether the runner recorded anything it saw.
     */
    public function hasEvidence(): bool
    {
        return $this->evidence !== null && !$this->evidence->isEmpty();
    }

    /**
     * Whether this attempt is one the next one should be told about.
     *
     * An attempt with neither a claim nor an observation has nothing to pass
     * on, and a note saying only that it happened costs the next attempt's
     * budget without telling it anything.
     */
    public function isWorthRecalling(): bool
    {
        return $this->hasSummary() || $this->hasEvidence() || $this->blocker !== null;
    }

    /**
     * One line for a status table or a log index.
     */
    public function describe(): string
    {
        return sprintf(
            'attempt %d: %s%s, %s',
            $this->number,
            $this->state->value,
            $this->phase === null ? '' : ' (' . $this->phase->value . ')',
            $this->outcome === null ? 'no result yet' : $this->outcome->describe(),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the column is absent or not text
     */
    private static function string(array $row, string $column): string
    {
        $value = self::nullableString($row, $column);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf('A stored attempt has no %s.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'The %s of a stored attempt is not text.',
                $column,
            ));
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function integer(array $row, string $column): int
    {
        $value = self::nullableInteger($row, $column);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf('A stored attempt has no %s.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableInteger(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new InvalidArgumentException(sprintf(
                'The %s of a stored attempt is not a number.',
                $column,
            ));
        }

        return (int) $value;
    }
}
