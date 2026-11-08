<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Git\CommitIdentity;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\GenerationRecord;

/**
 * What reconciling one interrupted pass found, did, and left for the caller to
 * do.
 *
 * The result of {@see PassRecovery}, and deliberately a record rather than a
 * verdict alone. A resume has to tell a user what it is about to do with work
 * they cannot see — a commit nothing named, a workspace that has gone, a branch
 * somebody else moved — and "resuming TASK-19E0214A" with none of that said is
 * the silent acceptance PROJECT.md forbids about a worker's commits, arriving
 * one crash later.
 *
 * Three kinds of line, kept apart because they answer different questions:
 *
 *  - **Actions** are what was changed, in the order it happened. Only
 *    {@see RecoveryVerdict::Reconciled} has any.
 *  - **Findings** are what was observed and needed no change — a workspace
 *    still in place, an implementation lost with a worktree that is gone, a
 *    branch that was never created. They are what makes a `consistent` verdict
 *    worth printing.
 *  - **The blocker** is the one thing a person has to decide, with the next
 *    action named, and it is carried rather than recorded: whether a pass is
 *    moved to `blocked` is the caller's to decide, since the same reading means
 *    "stop" to `ralph resume` and "this is what you are retrying over" to
 *    `ralph retry`.
 *
 * The row is carried because reconciling can write to it. A commit adopted into
 * a pass's row makes every copy of that row taken earlier stale, including the
 * one the caller passed in, and a caller that kept using its own copy would ask
 * for the implementation again — the exact duplicate this exists to prevent.
 */
final class Recovery
{
    /**
     * @param list<string> $actions  what was changed, in the order it happened
     * @param list<string> $findings what was observed and left alone
     */
    private function __construct(
        private readonly GenerationRecord $record,
        private readonly RecoveryVerdict $verdict,
        private readonly array $actions,
        private readonly array $findings,
        private readonly ?CommitIdentity $commit,
        private readonly bool $adopted,
        private readonly ?Blocker $blocker,
    ) {
    }

    /**
     * The repository already agreed with the row.
     *
     * @param list<string> $findings
     */
    public static function consistent(
        GenerationRecord $record,
        array $findings = [],
        ?CommitIdentity $commit = null,
    ): self {
        return new self($record, RecoveryVerdict::Consistent, [], $findings, $commit, false, null);
    }

    /**
     * Something was put right, and the row now says what the repository holds.
     *
     * @param list<string> $actions  at least one, or this would be
     *        {@see self::consistent()}
     * @param list<string> $findings
     * @param bool         $adopted  whether one of the actions was taking on a
     *        commit the row did not know about
     */
    public static function reconciled(
        GenerationRecord $record,
        array $actions,
        array $findings = [],
        ?CommitIdentity $commit = null,
        bool $adopted = false,
    ): self {
        return new self($record, RecoveryVerdict::Reconciled, $actions, $findings, $commit, $adopted, null);
    }

    /**
     * The state needs a person, and nothing was changed.
     *
     * @param list<string> $findings
     */
    public static function blocked(
        GenerationRecord $record,
        Blocker $blocker,
        array $findings = [],
        ?CommitIdentity $commit = null,
    ): self {
        return new self($record, RecoveryVerdict::Blocked, [], $findings, $commit, false, $blocker);
    }

    /**
     * The pass's row as it stands after reconciliation.
     */
    public function record(): GenerationRecord
    {
        return $this->record;
    }

    public function verdict(): RecoveryVerdict
    {
        return $this->verdict;
    }

    /**
     * What was changed, in the order it happened.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * What was observed and left alone.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * Every line, as a reader wants them: what was done first, then what was
     * seen.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return [...$this->actions, ...$this->findings];
    }

    /**
     * The commit the repository holds for this pass, when it holds one.
     *
     * Either the commit the row already recorded, read back and confirmed, or
     * the one that was adopted. Null covers a pass that never committed and one
     * whose outcome was a verified no change.
     */
    public function commit(): ?CommitIdentity
    {
        return $this->commit;
    }

    /**
     * Whether a commit the row did not know about was taken on.
     */
    public function adoptedCommit(): bool
    {
        return $this->adopted;
    }

    /**
     * What a person has to decide, when something does.
     */
    public function blocker(): ?Blocker
    {
        return $this->blocker;
    }

    public function isBlocked(): bool
    {
        return $this->verdict === RecoveryVerdict::Blocked;
    }

    public function wasReconciled(): bool
    {
        return $this->verdict->neededWork();
    }

    /**
     * Whether resume may carry on with this pass.
     */
    public function allowsResume(): bool
    {
        return $this->verdict->allowsResume();
    }

    /**
     * What resume does next about this pass.
     *
     * The blocker's next action when there is one; otherwise the recorded
     * phase's, which is where the answer has always been — {@see
     * \Celyes\RalphPhp\State\Phase::resumeAction()} is written as what will and
     * will not be repeated. A pass with no recorded phase never got far enough
     * to have one and is started again.
     */
    public function nextAction(): string
    {
        if ($this->blocker !== null) {
            return $this->blocker->nextAction();
        }

        return $this->record->phase()?->resumeAction()
            ?? 'start the task again: nothing of this pass had been produced yet';
    }

    /**
     * One line for a log or a terminal.
     */
    public function describe(): string
    {
        $summary = $this->blocker?->reason() ?? $this->verdict->describe();
        $lines = $this->lines();

        return sprintf(
            '%s: %s%s',
            $this->record->slug(),
            $summary,
            $lines === [] ? '' : ' (' . implode('; ', $lines) . ')',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->record->slug(),
            'verdict' => $this->verdict->value,
            'actions' => $this->actions,
            'findings' => $this->findings,
            'commit' => $this->commit?->toArray(),
            'adopted_commit' => $this->adopted,
            'blocker' => $this->blocker?->toStorage(),
            'next_action' => $this->nextAction(),
        ];
    }
}
