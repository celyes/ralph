<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Verification\Satisfaction;
use Celyes\RalphPhp\Verification\VerificationReport;

/**
 * What one turn of the loop did with one task.
 *
 * The pass's own record is the authority for everything durable — the state,
 * the commit, the push, the blocker — so this holds that record rather than
 * copies of its columns. What it adds is the three things the database cannot
 * answer: the verification report the decision was made on, whether the
 * checklist's box was actually ticked, and — for a pass that staged nothing —
 * the case for or against completing it without a commit.
 *
 * **`wasChecked()` is deliberately separate from the pass being complete.** A
 * pass can be verified, committed, pushed and complete, and the box still not
 * be ticked, because ticking it is a write to a file a user may own and may
 * have changed underneath. Keeping them apart is what lets the loop report
 * "done, but the checklist still says otherwise" rather than silently implying
 * one from the other.
 */
final class TaskResult
{
    private function __construct(
        private readonly Task $task,
        private readonly GenerationRecord $generation,
        private readonly ?AttemptRecord $attempt,
        private readonly ?VerificationReport $verification,
        private readonly bool $checked,
        private readonly string $reason,
        private readonly ?Satisfaction $satisfaction,
    ) {
    }

    /**
     * The result of a pass, read off its final record.
     *
     * @param bool   $checked whether the checklist's box was ticked
     * @param string $reason  one line on why the pass ended as it did
     * @param Satisfaction|null $satisfaction the case for a pass that staged
     *        nothing, where one was made
     */
    public static function of(
        Task $task,
        GenerationRecord $generation,
        ?AttemptRecord $attempt = null,
        ?VerificationReport $verification = null,
        bool $checked = false,
        string $reason = '',
        ?Satisfaction $satisfaction = null,
    ): self {
        return new self($task, $generation, $attempt, $verification, $checked, $reason, $satisfaction);
    }

    public function task(): Task
    {
        return $this->task;
    }

    public function taskId(): TaskId
    {
        return $this->task->id();
    }

    public function description(): string
    {
        return $this->task->description();
    }

    /**
     * The pass's final record.
     */
    public function record(): GenerationRecord
    {
        return $this->generation;
    }

    public function generation(): TaskGeneration
    {
        return $this->generation->generation();
    }

    /**
     * The attempt that was made, or null when the pass stopped before one
     * could be — a workspace that could not be prepared, an exhausted attempt
     * limit.
     */
    public function attempt(): ?AttemptRecord
    {
        return $this->attempt;
    }

    /**
     * How many attempts this pass has had in total, across runs.
     */
    public function attempts(): int
    {
        return $this->generation->attempts();
    }

    public function state(): LifecycleState
    {
        return $this->generation->state();
    }

    public function isCompleted(): bool
    {
        return $this->state()->isCompleted();
    }

    /**
     * Whether the pass is waiting on a person.
     */
    public function isBlocked(): bool
    {
        return $this->state()->requiresHumanInput();
    }

    public function isFailure(): bool
    {
        return $this->state()->isFailure();
    }

    public function isInterrupted(): bool
    {
        return $this->state() === LifecycleState::Interrupted;
    }

    /**
     * Whether the loop should stop rather than take on the next task.
     *
     * A blocked pass stops the run: it is waiting for an answer only a person
     * can give, and the next task would be handed to the same agent that has
     * just said it needs one. A failed pass does not — the task stays
     * unchecked and retriable, and the tasks after it are independent work
     * (PROJECT.md section 2).
     */
    public function stopsTheRun(): bool
    {
        return $this->isBlocked();
    }

    /**
     * Whether another attempt at this task would have something to act on.
     *
     * True for exactly one outcome: a candidate implementation the configured
     * checks decided against. That is the case PROJECT.md section G, item 4
     * names, and it is the only one where the runner itself holds evidence of
     * what is wrong — a failing command, its status and its output — which is
     * what makes a second attempt a different attempt rather than the same one
     * again.
     *
     * Deliberately false for everything else. An agent that stopped to ask a
     * question is waiting on a person, and asking it again is not an answer. An
     * execution error, a refused branch or drift the auditor would not put back
     * left nothing to act on and will fail the same way. A commit the remote
     * would not take is already implemented, and repeating the implementation
     * is the one thing resume must not do (section H, item 7).
     */
    public function invitesAnotherAttempt(): bool
    {
        return $this->isFailure()
            && $this->verification !== null
            && $this->verification->failed()
            && $this->commitSha() === null;
    }

    /**
     * The checks as they decided this pass, or null when they never ran.
     */
    public function verification(): ?VerificationReport
    {
        return $this->verification;
    }

    public function wasVerified(): bool
    {
        return $this->verification !== null && $this->verification->passed();
    }

    public function commitSha(): ?string
    {
        return $this->generation->commitSha();
    }

    /**
     * Whether the task was found already satisfied, so there was nothing to
     * commit.
     */
    public function isNoChange(): bool
    {
        return $this->generation->isNoChange();
    }

    /**
     * The evidence behind a pass that staged nothing, or null when the pass
     * never reached that question.
     *
     * Present for both answers to it: a task completed as a verified no-change
     * outcome, and one stopped because the facts did not support completing it
     * (PROJECT.md section G, item 6). The row is the authority for the state
     * either way — this is what the database has no column for, and what
     * {@see Satisfaction::recordPath()} has already been written to a file.
     */
    public function satisfaction(): ?Satisfaction
    {
        return $this->satisfaction;
    }

    public function branch(): ?string
    {
        return $this->generation->branch();
    }

    public function push(): PushResult
    {
        return $this->generation->push();
    }

    public function blocker(): ?Blocker
    {
        return $this->generation->blocker();
    }

    /**
     * Whether the checklist's box was ticked for this task.
     */
    public function wasChecked(): bool
    {
        return $this->checked;
    }

    /**
     * Why the pass ended as it did, in one line.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * One line for the terminal: what happened to this task.
     */
    public function describe(): string
    {
        $described = sprintf(
            '%s %s: %s',
            $this->taskId()->value(),
            $this->state()->value,
            $this->reason === '' ? $this->state()->describe() : $this->reason,
        );

        $sha = $this->commitSha();

        if ($sha !== null) {
            $described .= sprintf(' (%s on %s)', substr($sha, 0, 8), (string) $this->branch());
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->taskId()->value(),
            'description' => $this->description(),
            'generation' => $this->generation->number(),
            'state' => $this->state()->value,
            'attempts' => $this->attempts(),
            'checked' => $this->checked,
            'commit' => $this->commitSha(),
            'branch' => $this->branch(),
            'no_change' => $this->isNoChange(),
            'satisfaction' => $this->satisfaction?->toArray(),
            'push' => $this->push()->state()->value,
            'verification' => $this->verification?->toArray(),
            'blocker' => $this->blocker()?->toStorage(),
            'reason' => $this->reason,
        ];
    }
}
