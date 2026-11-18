<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Tasks\RecordedGeneration;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use InvalidArgumentException;

/**
 * One row of `generations`, read back as a value.
 *
 * Everything PROJECT.md section D, item 4 persists about a pass at a task: what
 * the task said when it started, how many attempts it has had, when it started
 * and was last touched, which agent and non-secret settings it is using,
 * whether its logs have been wiped, and why it stopped if it did.
 *
 * Read-only. The store is the only thing that writes, and every write returns a
 * fresh record read back out of the database rather than a modified copy of the
 * one that went in — so a record in a caller's hands is always what a row
 * actually says, never what a caller hoped it would say.
 *
 * {@see self::rowId()} is the integer primary key, and it is exposed for one
 * reason: the stores of attempts, verification results and checklist updates
 * point at a generation with a foreign key, and the row has to be nameable for
 * them to do that. Nothing user-facing uses it — {@see self::slug()} is the name a
 * person sees.
 */
final class GenerationRecord
{
    /**
     * @param int|null $runId the run that last worked on this pass, or null for
     *                        a pass recorded outside a run
     */
    private function __construct(
        private readonly int $rowId,
        private readonly TaskGeneration $generation,
        private readonly ?int $runId,
        private readonly LifecycleState $state,
        private readonly ?Phase $phase,
        private readonly int $attempts,
        private readonly string $contentHash,
        private readonly AgentRecord $agent,
        private readonly ?Timestamp $logsWipedAt,
        private readonly ?Blocker $blocker,
        private readonly Timestamp $startedAt,
        private readonly Timestamp $updatedAt,
        private readonly ?Timestamp $finishedAt,
    ) {
    }

    /**
     * Read a row.
     *
     * The generation is rebuilt from `slug` rather than from the task's row
     * and the generation number separately, because the slug is the one value
     * that is also written into a log path: reading it back through
     * {@see TaskGeneration::fromSlug()} means a row whose slug disagrees with
     * what the runner would produce is caught here rather than in the
     * filesystem.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when a column holds something the
     *                                 package never wrote
     */
    public static function fromRow(array $row): self
    {
        $slug = self::string($row, 'slug');

        return new self(
            self::integer($row, 'id'),
            TaskGeneration::fromSlug($slug),
            self::nullableInteger($row, 'run_id'),
            LifecycleState::fromStorage(self::string($row, 'state')),
            Phase::recorded(self::nullableString($row, 'phase')),
            self::integer($row, 'attempts'),
            self::string($row, 'content_hash'),
            AgentRecord::fromStorage(
                self::string($row, 'agent'),
                self::string($row, 'adapter'),
                self::string($row, 'agent_settings'),
            ),
            Timestamp::recorded(self::nullableString($row, 'logs_wiped_at')),
            Blocker::recorded(self::nullableString($row, 'blocker')),
            Timestamp::fromStorage(self::string($row, 'started_at')),
            Timestamp::fromStorage(self::string($row, 'updated_at')),
            Timestamp::recorded(self::nullableString($row, 'finished_at')),
        );
    }

    /**
     * The integer primary key, for the tables that reference a generation.
     */
    public function rowId(): int
    {
        return $this->rowId;
    }

    /**
     * Which pass at which task this is.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    public function task(): TaskId
    {
        return $this->generation->task();
    }

    /**
     * The generation number, counted from one.
     */
    public function number(): int
    {
        return $this->generation->number();
    }

    /**
     * The name the pass is written down under, and what its log path is named
     * after.
     */
    public function slug(): string
    {
        return $this->generation->slug();
    }

    /**
     * The run that last worked on this pass.
     */
    public function runId(): ?int
    {
        return $this->runId;
    }

    public function state(): LifecycleState
    {
        return $this->state;
    }

    /**
     * What the pass is in the middle of, when it is in the middle of something.
     */
    public function phase(): ?Phase
    {
        return $this->phase;
    }

    /**
     * How many attempts this pass has had.
     *
     * A counter on the row rather than a count of `attempts` rows, so that a
     * crash between an attempt starting and its row being written still spends
     * the attempt. A task cannot escape its attempt limit by being killed at
     * the right moment.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * What the task said when this pass last read it.
     */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Whether the task still states the work this pass was started on.
     */
    public function statesSameWork(TaskSnapshot $snapshot): bool
    {
        return $this->contentHash === $snapshot->contentHash();
    }

    /**
     * The agent and non-secret settings this pass is being carried out with.
     */
    public function agent(): AgentRecord
    {
        return $this->agent;
    }

    /**
     * When this pass's own log files were deleted on purpose, or null when they
     * were not.
     *
     * Only a project with `logs.wipe_on_success` turned on ever writes it, and
     * only a pass that succeeded ({@see \Celyes\RalphPhp\Log\LogWiper}). It
     * is the fact the filesystem cannot supply afterwards: a log that is not
     * there because the package removed it and a log that is not there because
     * something went wrong look identical from outside, and this is what tells
     * a reader which they have.
     */
    public function logsWipedAt(): ?Timestamp
    {
        return $this->logsWipedAt;
    }

    /**
     * Whether this pass's eligible logs have been wiped and the wipe finished.
     *
     * False while a wipe is outstanding as well as when none was ever wanted,
     * because the column is written only once no eligible log is left: a wipe
     * an interruption cut short leaves this false and the remaining files on
     * disk, which is what makes the retry safe.
     */
    public function logsWereWiped(): bool
    {
        return $this->logsWipedAt !== null;
    }

    /**
     * Why the pass stopped and what to do about it, when it stopped.
     */
    public function blocker(): ?Blocker
    {
        return $this->blocker;
    }

    public function startedAt(): Timestamp
    {
        return $this->startedAt;
    }

    /**
     * When the row was last written.
     */
    public function updatedAt(): Timestamp
    {
        return $this->updatedAt;
    }

    /**
     * When the pass reached a state it does not come back from.
     */
    public function finishedAt(): ?Timestamp
    {
        return $this->finishedAt;
    }

    /**
     * Whether the pass can still be continued.
     */
    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * Whether the pass carried the task all the way through.
     */
    public function isComplete(): bool
    {
        return $this->state->isCompleted();
    }

    /**
     * How many attempts are left under a limit.
     */
    public function attemptsRemaining(int $limit): int
    {
        return max(0, $limit - $this->attempts);
    }

    /**
     * Whether a pass has used up its attempts.
     */
    public function hasExhausted(int $limit): bool
    {
        return $this->attempts >= $limit;
    }

    /**
     * The same facts as the checklist layer asks for them.
     *
     * {@see RecordedGeneration} is what {@see \Celyes\RalphPhp\Tasks\GenerationSelector}
     * reads to decide whether reopening a task continues an open pass or starts
     * a new one. It carries a deliberately small part of this record, and the
     * conversion lives here so the checklist layer never needs to know what a
     * lifecycle state is.
     */
    public function toRecordedGeneration(): RecordedGeneration
    {
        if ($this->state->isCompleted()) {
            return RecordedGeneration::completed($this->generation, $this->contentHash);
        }

        return RecordedGeneration::open($this->generation, $this->contentHash);
    }

    /**
     * One line for a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %s%s, attempt %d',
            $this->generation->label(),
            $this->state->value,
            $this->phase === null ? '' : ' (' . $this->phase->value . ')',
            $this->attempts,
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
            throw new InvalidArgumentException(sprintf(
                'A stored generation has no %s.',
                $column,
            ));
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
                'The %s of a stored generation is not text.',
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
            throw new InvalidArgumentException(sprintf(
                'A stored generation has no %s.',
                $column,
            ));
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
                'The %s of a stored generation is not a number.',
                $column,
            ));
        }

        return (int) $value;
    }
}
