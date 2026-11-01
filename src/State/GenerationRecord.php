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
 * and was last touched, which agent and non-secret settings it is using, which
 * workspace it has and whether it owns it, the base commit it branched from,
 * its branch, its commit, how publication went, and why it stopped if it did.
 *
 * Read-only. The store is the only thing that writes, and every write returns a
 * fresh record read back out of the database rather than a modified copy of the
 * one that went in — so a record in a caller's hands is always what a row
 * actually says, never what a caller hoped it would say.
 *
 * {@see self::rowId()} is the integer primary key, and it is exposed for one
 * reason: the stores of attempts, verification results and Git references point
 * at a generation with a foreign key, and the row has to be nameable for them
 * to do that. Nothing user-facing uses it — {@see self::slug()} is the name a
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
        private readonly string $baseReference,
        private readonly string $baseSha,
        private readonly ?string $branch,
        private readonly ?string $commitSha,
        private readonly bool $noChange,
        private readonly PushResult $push,
        private readonly WorktreeOwnership $worktree,
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
     * that is also written into a branch name and a log path: reading it back
     * through {@see TaskGeneration::fromSlug()} means a row whose slug disagrees
     * with what the runner would produce is caught here instead of in Git.
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
            self::string($row, 'base_reference'),
            self::string($row, 'base_sha'),
            self::nullableString($row, 'branch'),
            self::nullableString($row, 'commit_sha'),
            self::boolean($row, 'no_change'),
            PushResult::fromStorage(
                self::string($row, 'push_state'),
                self::nullableString($row, 'push_remote'),
                self::nullableString($row, 'push_error'),
            ),
            WorktreeOwnership::fromStorage(
                self::nullableString($row, 'worktree_path'),
                self::boolean($row, 'owns_worktree'),
            ),
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
     * The name the pass is written down under: its branch and its log path.
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
     * The configured reference the branch was created from, as written.
     */
    public function baseReference(): string
    {
        return $this->baseReference;
    }

    /**
     * The commit that reference resolved to when the pass started.
     *
     * Recorded rather than re-resolved, which is the whole point: the base
     * moving on under a running task must not change what the task was built.
     */
    public function baseSha(): string
    {
        return $this->baseSha;
    }

    /**
     * The branch this pass owns, once one has been assigned.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * Whether a branch has been assigned to this pass.
     */
    public function hasBranch(): bool
    {
        return $this->branch !== null;
    }

    /**
     * Whether this pass owns the named branch.
     *
     * Compared exactly: Git refs are case-sensitive, and a near-match is
     * someone else's branch.
     */
    public function ownsBranch(string $branch): bool
    {
        return $this->branch !== null && $this->branch === $branch;
    }

    /**
     * The commit this pass produced, when it produced one.
     *
     * Null covers two different things, and {@see self::isNoChange()} is what
     * tells them apart: a pass that never got as far as committing, and a pass
     * whose task was already satisfied.
     */
    public function commitSha(): ?string
    {
        return $this->commitSha;
    }

    public function hasCommit(): bool
    {
        return $this->commitSha !== null;
    }

    /**
     * Whether the task was already satisfied, so there was nothing to commit.
     *
     * A complete outcome, not a failure: PROJECT.md section G, item 6 accepts a
     * verified no-change result rather than an empty commit.
     */
    public function isNoChange(): bool
    {
        return $this->noChange;
    }

    /**
     * Where publication stands.
     */
    public function push(): PushResult
    {
        return $this->push;
    }

    /**
     * The workspace and whether this pass may remove it.
     */
    public function worktree(): WorktreeOwnership
    {
        return $this->worktree;
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
     * Whether a commit or a justified no-change result has been recorded.
     *
     * The Git half of the conditions PROJECT.md puts on ticking a box.
     */
    public function hasImplementation(): bool
    {
        return $this->commitSha !== null || $this->noChange;
    }

    /**
     * Whether everything a tick depends on has happened: an implementation
     * recorded and publication settled.
     *
     * Verification is deliberately not part of this. It is evidence the
     * verification-results rows carry, and asking a single row to summarise it
     * would mean two places deciding what passing means.
     */
    public function isFinalizable(): bool
    {
        return $this->hasImplementation() && $this->push->isSatisfied();
    }

    /**
     * Whether only the push is outstanding, so resume retries publication and
     * nothing before it.
     */
    public function awaitsPublication(): bool
    {
        return $this->hasImplementation() && $this->push->isOutstanding();
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
            return RecordedGeneration::completed(
                $this->generation,
                $this->branch,
                $this->commitSha,
                $this->contentHash,
            );
        }

        return RecordedGeneration::open($this->generation, $this->branch, $this->contentHash);
    }

    /**
     * One line for a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %s%s, attempt %d, %s',
            $this->generation->label(),
            $this->state->value,
            $this->phase === null ? '' : ' (' . $this->phase->value . ')',
            $this->attempts,
            $this->branch ?? 'no branch yet',
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

    /**
     * @param array<string, mixed> $row
     */
    private static function boolean(array $row, string $column): bool
    {
        return self::integer($row, $column) === 1;
    }
}
