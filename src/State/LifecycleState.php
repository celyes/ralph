<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\GenerationSelector;

/**
 * Where one pass at a task stands.
 *
 * The seven states of PROJECT.md section D, item 2. They are recorded on an
 * execution generation rather than on the task, because a reopened task has a
 * finished pass and a running one at the same time and a single column could
 * not say both; a task's own state is its latest generation's, or
 * {@see self::Pending} when it has never been run. {@see Schema} builds the
 * `CHECK` constraints on `generations.state` and `attempts.state` from these
 * cases, so the database and this enum cannot drift apart.
 *
 * Two things make this more than a list of words.
 *
 * The first is the **open/closed partition**. A pass in an open state is the
 * task's current pass and is continued — same generation, same branch, another
 * attempt; a pass in a closed state is finished with, and work on that task
 * starting again means a new generation with a branch of its own. That is the
 * whole of {@see GenerationSelector}'s decision, and it is also what the
 * one-open-pass-per-task index in {@see Migrations} enforces, which is why the
 * two lists here must stay a partition rather than merely overlap-free.
 *
 * The second is the **transition table** in {@see self::successors()}. Its job
 * is to refuse the writes that would quietly lose evidence: a pass jumping
 * straight from `pending` to `completed` without ever having run, a `completed`
 * pass being reopened in place instead of superseded by a new generation, a
 * `failed` pass being overwritten as though it had never failed. The runner
 * goes through {@see self::transitionTo()} so that a sequencing mistake fails
 * where it is made, with the recorded state still readable, instead of being
 * stored and puzzled over three runs later.
 *
 * Not to be confused with {@see Completion}, which answers the different
 * question of where a *checked box* came from — the user's checklist or a
 * verified run. {@see self::Completed} is the state that earns
 * {@see Completion::RunnerVerified}, and it is used in the strict sense
 * PROJECT.md puts on ticking a box: verification passed, a commit or a
 * justified no-change result was recorded, and any required push succeeded.
 */
enum LifecycleState: string
{
    /**
     * Known, not started.
     *
     * Where a generation row begins: the runner writes it when it selects the
     * task, before preparing a workspace, so that an interruption in that gap
     * has somewhere to be recorded.
     */
    case Pending = 'pending';

    /**
     * Being worked on now, by this runner.
     *
     * The phase column says what part of it — preparing, executing, verifying,
     * committing, publishing, finalizing. Only a pass in this state holds a
     * worktree the runner considers its own.
     */
    case Running = 'running';

    /**
     * Carried all the way through, in the strict sense of the class note.
     *
     * Terminal. A finished task that the user unticks is not reopened in
     * place: it gets a new generation, so the commit and the verification
     * output behind this state stay readable (PROJECT.md section C, item 8).
     */
    case Completed = 'completed';

    /**
     * Deliberately not carried out.
     *
     * Terminal, and distinct from every failure: nothing went wrong, the runner
     * passed the task over. Kept apart from `completed` because a skipped pass
     * has no commit and no verification output, and reporting it as done would
     * be a claim the package cannot defend.
     */
    case Skipped = 'skipped';

    /**
     * Stopped, waiting on a human.
     *
     * The state behind every "stop and tell the user why": an agent that asked
     * for input rather than producing a candidate, a branch whose ownership is
     * ambiguous, changes that weaken existing verification. Open, because the
     * decision can be made and the same pass continued — but never by the
     * runner on its own.
     */
    case Blocked = 'blocked';

    /**
     * Stopped because something did not work.
     *
     * Verification that kept failing, attempts exhausted, an agent that exited
     * with an error. Open: `ralph retry TASK-ID` continues this pass with its
     * history intact. The task stays unchecked while a pass is here.
     */
    case Failed = 'failed';

    /**
     * Stopped mid-flight, with the work preserved.
     *
     * Ctrl+C, a killed process, a crashed machine. The one state resume is
     * meant to pick up: the recorded phase says how far the pass got, which is
     * the difference between invoking the agent again and only retrying a push.
     */
    case Interrupted = 'interrupted';

    /**
     * The state every pass starts in.
     */
    public static function initial(): self
    {
        return self::Pending;
    }

    /**
     * Every state, in the order a pass moves through them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The states a pass can still be picked up from, so it is continued rather
     * than superseded.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isOpen()));
    }

    /**
     * The states a pass does not come back from.
     *
     * @return list<self>
     */
    public static function closed(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isClosed()));
    }

    /**
     * Every state as the database spells it.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::open());
    }

    /**
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::closed());
    }

    /**
     * Read a state back out of the state store.
     *
     * The column has a `CHECK` constraint built from these cases, so a value
     * this cannot read did not come from a database this build wrote. The two
     * ways that happens are a newer Ralph PHP having been run against the
     * project and the file having been edited by hand, and both are reasons to
     * stop rather than to guess: the alternative is treating an unreadable
     * state as pending and running work that is already committed.
     *
     * @throws StateException when the value is not one of these states
     */
    public static function fromStorage(string $value): self
    {
        $state = self::tryFrom($value);

        if ($state instanceof self) {
            return $state;
        }

        throw new StateException(
            sprintf('The state store holds the task state "%s", which this version does not know.', $value),
            [
                'The states it knows are: ' . implode(', ', self::values()) . '.',
                sprintf(
                    'A state store written by a newer %s can hold states this build cannot read; check which version wrote it.',
                    Ralph::NAME,
                ),
            ],
        );
    }

    /**
     * Whether a pass in this state is the task's current one, and so continued
     * rather than superseded.
     */
    public function isOpen(): bool
    {
        return !$this->isClosed();
    }

    /**
     * Whether a pass in this state is finished with, whatever its verdict.
     *
     * The complement of {@see self::isOpen()}: between them they cover every
     * state, which is what lets the one-open-pass index classify any row.
     */
    public function isClosed(): bool
    {
        return match ($this) {
            self::Completed, self::Skipped => true,
            self::Pending, self::Running, self::Blocked, self::Failed, self::Interrupted => false,
        };
    }

    /**
     * Whether this pass earned the task's checkbox.
     */
    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether a runner is working on this pass now.
     */
    public function isRunning(): bool
    {
        return $this === self::Running;
    }

    /**
     * Whether `ralph resume` is what continues this pass.
     *
     * Only an interruption is resumed. A failed or blocked pass stopped for a
     * reason that resuming would walk straight back into, so it waits for
     * `ralph retry TASK-ID` — or, when it is blocked, for the user to resolve
     * what stopped it.
     */
    public function isResumable(): bool
    {
        return $this === self::Interrupted;
    }

    /**
     * Whether the pass is waiting on a decision only the user can make.
     */
    public function requiresHumanInput(): bool
    {
        return $this === self::Blocked;
    }

    /**
     * Whether the pass stopped because the work did not come out.
     *
     * Blocked is not a failure: nothing went wrong with the implementation, the
     * runner needs an answer. Keeping them apart is what lets `status` say
     * which tasks need a person and which need another attempt.
     */
    public function isFailure(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Whether another attempt at this same pass is allowed.
     *
     * Open and not already running. Says nothing about whether an attempt is
     * *available*: the attempt limit is counted on the generation and checked
     * separately.
     */
    public function isRetryable(): bool
    {
        return $this->isOpen() && !$this->isRunning();
    }

    /**
     * The states this one is allowed to move to.
     *
     * Read as a sentence each case at a time:
     *
     * - A **pending** pass is started, deliberately passed over, stopped by
     *   something preparation found, or interrupted in the gap between its row
     *   being written and the agent being invoked. It cannot become completed:
     *   nothing is complete that never ran.
     * - A **running** pass ends in exactly one of the four ways a pass can end
     *   while a runner holds it. It cannot be skipped, because skipping is a
     *   decision taken before the work starts.
     * - **Blocked**, **failed** and **interrupted** passes lead back to
     *   `running`, which is the only state anything is worked on in and so the
     *   only route to `completed`. A reconciliation that finds a pass
     *   unrecoverable records that directly; it does not have to pretend to run
     *   it first.
     * - **Completed** and **skipped** lead nowhere. Reopening is a new
     *   generation, which is what keeps the old pass's branch, commit and
     *   evidence intact.
     *
     * @return list<self>
     */
    public function successors(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Skipped, self::Blocked, self::Failed, self::Interrupted],
            self::Running => [self::Completed, self::Blocked, self::Failed, self::Interrupted],
            self::Blocked => [self::Running, self::Failed],
            self::Failed => [self::Running, self::Blocked],
            self::Interrupted => [self::Running, self::Blocked, self::Failed],
            self::Completed, self::Skipped => [],
        };
    }

    /**
     * Whether moving from this state to the given one is a thing that can
     * happen.
     *
     * A state cannot move to itself. Re-recording the state a pass is already
     * in is not a transition and does not go through here: a second attempt at
     * a running pass is a new row in `attempts`, not a new state.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->successors(), true);
    }

    /**
     * The given state, once it has been checked that a pass can get there from
     * here.
     *
     * Returns the new state rather than mutating, since an enum case is a
     * value: `$state = $state->transitionTo(LifecycleState::Running)`.
     *
     * @throws StateException when the move is not one the lifecycle allows
     */
    public function transitionTo(self $next): self
    {
        if (!$this->canTransitionTo($next)) {
            throw new StateException(
                sprintf(
                    'A task that is %s cannot become %s.',
                    $this->value,
                    $next->value,
                ),
                [$this->explainSuccessors()],
            );
        }

        return $next;
    }

    /**
     * The state phrased for a status line or an error message.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Pending => 'not started',
            self::Running => 'in progress',
            self::Completed => 'verified, committed and checked off',
            self::Skipped => 'deliberately passed over',
            self::Blocked => 'stopped, waiting on a decision',
            self::Failed => 'stopped after an unsuccessful attempt',
            self::Interrupted => 'stopped mid-run, can be resumed',
        };
    }

    /**
     * What the user is expected to do about a pass in this state.
     */
    public function nextAction(): string
    {
        return match ($this) {
            self::Pending => 'run `ralph run` to start it',
            self::Running => 'nothing: a runner is working on it',
            self::Completed => 'review the commit and merge it',
            self::Skipped => 'nothing: it was passed over on purpose',
            self::Blocked => 'resolve what stopped it, then run `ralph retry TASK-ID`',
            self::Failed => 'read `ralph logs TASK-ID`, then run `ralph retry TASK-ID`',
            self::Interrupted => 'run `ralph resume` to continue it',
        };
    }

    /**
     * The hint that goes with a refused transition.
     */
    private function explainSuccessors(): string
    {
        $successors = $this->successors();

        if ($successors === []) {
            return sprintf(
                'A %s pass is finished with; work on that task starting again is a new generation, not a change to this one.',
                $this->value,
            );
        }

        return sprintf(
            'From %s a pass can only become: %s.',
            $this->value,
            implode(', ', array_map(static fn (self $case): string => $case->value, $successors)),
        );
    }
}
