<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\TaskPublisher;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Log\AttemptLog;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\Memory\AttemptSummary;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\Tasks\ChecklistSnapshot;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Verification\VerificationReport;
use Celyes\RalphPhp\Verification\Verifier;
use InvalidArgumentException;

/**
 * One task, from an empty workspace to a ticked box.
 *
 * The six phases of PROJECT.md section D, item 3 carried out in order —
 * preparing, executing, verifying, committing, publishing, finalizing — each
 * one a step that already exists and was built and tested on its own in
 * sections C to F. What this class owns is the order, the state written between
 * the steps, and the decisions. Nothing here implements Git, runs a check, or
 * talks to an agent.
 *
 * **The agent does not decide anything.** A zero exit code gets the work
 * verified, and that is all it buys. The task's box is ticked only once the
 * configured checks have passed, a commit or a justified no-change result has
 * been recorded, and any required push has succeeded — PROJECT.md section 4
 * read as a sequence, and section G, item 7 read as a condition.
 *
 * **Every exit records what it found.** A pass that throws its way out of a
 * phase still ends with a row saying which phase that was, a blocker naming the
 * next action, the workspace kept for inspection, and the agent's claim stored
 * as a claim. What it must never do is leave a pass recorded as `running` by a
 * process that has stopped, which is why the body sits inside a `try` whose
 * `catch` records the refusal and whose `finally` writes the attempt up and
 * sweeps the workspace.
 */
final class TaskPass
{
    /**
     * @param VerificationSnapshot $checks the run's frozen checks, read off the
     *        run's row rather than out of the configuration, so that a worker's
     *        edit cannot replace them mid-run (section G, item 3)
     */
    public function __construct(
        private readonly Configuration $configuration,
        private readonly Journal $journal,
        private readonly Workbench $workbench,
        private readonly RunRecord $run,
        private readonly AgentSelection $agent,
        private readonly ExecutionAdapter $adapter,
        private readonly Verifier $verifier,
        private readonly BaseCommit $base,
        private readonly VerificationSnapshot $checks,
        private readonly ?Output $terminal = null,
    ) {
    }

    /**
     * Carry out one task.
     *
     * Returns rather than throws for everything a pass can record: a refused
     * branch, drift the auditor will not put back, a failing check, a rejected
     * push, and an agent that asked a question are all outcomes, and what to do
     * about them is the loop's decision. What does come out as an exception is a
     * failure to *record* — a state database that cannot be written — because a
     * pass whose outcome was not stored is one nothing can reason about later.
     *
     * @param ChecklistSnapshot $snapshot the reading the run started from,
     *        which is what an edit made while the agent worked is compared
     *        against
     *
     * @throws RalphException        when the pass's own state could not be recorded
     * @throws InvalidArgumentException when the task is not in the snapshot
     */
    public function execute(Task $task, ChecklistSnapshot $snapshot): TaskResult
    {
        $recorded = $snapshot->find($task->id())
            ?? throw new InvalidArgumentException(sprintf(
                '%s is not in the checklist reading this run started from.',
                $task->id()->value(),
            ));

        $record = $this->open($task, $recorded);
        $budget = ExecutionBudget::of(
            $this->configuration->limits(),
            $record->attempts(),
            (float) $this->run->activeSeconds(),
        );

        // Checked before the log is opened and before an attempt is spent: a
        // task with nothing left to spend on it has not failed again, it was
        // not tried again, and the two must not read the same afterwards.
        if ($budget->isExhausted()) {
            return $this->spent($task, $record, $budget);
        }

        $log = AttemptLog::open(
            $this->workbench->logs(),
            $record->generation(),
            $record->attempts() + 1,
            $this->terminal,
        );

        try {
            return $this->carryOut($task, $recorded, $snapshot, $record, $budget, $log);
        } finally {
            $log->close();
        }
    }

    /**
     * The pass itself, with the attempt's log open around it.
     */
    private function carryOut(
        Task $task,
        TaskSnapshot $recorded,
        ChecklistSnapshot $snapshot,
        GenerationRecord $record,
        ExecutionBudget $budget,
        AttemptLog $log,
    ): TaskResult {
        $attempt = null;
        $verification = null;

        try {
            // Preparing. The branch is read before the workspace is created: a
            // name unrelated work holds is a refusal, and finding that out
            // costs nothing before a checkout and a dependency install.
            $branch = $this->workbench->namer()->forRecord($record, $task->description());
            $ownership = $this->workbench->branches()->require(
                $branch,
                $record,
                $this->journal->generations()->history(),
            );

            $worktree = $this->workbench->worktrees()->prepare($record->generation(), $this->base);
            $record = $this->journal->generations()->recordWorktree($record, $worktree->ownership());
            $log->note('workspace ' . $worktree->describe());

            $preparation = $this->workbench->preparer()->prepare($worktree, $log);

            if (!$preparation->isEmpty()) {
                $log->note('prepared: ' . $preparation->describe());
            }

            $context = $this->workbench->context()->load($this->configuration, $task);

            // The attempt is spent here and not before. Everything above can
            // fail with the agent never having been invoked, and an attempt
            // counted against a task whose workspace could not be made would
            // shorten that task's life for a reason that was not its own.
            $record = $this->journal->generations()->beginAttempt($record);
            $attempt = $this->journal->attempts()->begin($record, $this->run->rowId(), $log->path());

            // Executing.
            $record = $this->journal->generations()->enterPhase($record, Phase::Executing);
            $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Executing);

            $before = $this->workbench->auditor()->observe($worktree);
            $result = $this->invoke($task, $record, $attempt, $worktree, $context, $budget, $log);
            $attempt = $this->journal->attempts()->recordClaim($attempt, $result->claim());

            // What the worker did to the repository, put back or refused,
            // before anything is verified against it.
            $this->workbench->auditor()->require($record, $worktree, $branch, $before, $log);
            $record = $this->journal->generations()->get($record->generation());

            if (!$result->hasCandidate()) {
                return $this->settled($task, $record, $attempt, $result, $log);
            }

            // Verifying: the configured checks, run against the workspace by
            // something that is not the worker.
            $record = $this->journal->generations()->enterPhase($record, Phase::Verifying);
            $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Verifying);

            $verification = $this->verifier->verify($this->checks->commands(), $worktree->path(), $log);
            $this->journal->verifications()->record($attempt, $verification);
            $log->note('verification: ' . $verification->describe());

            if (!$verification->passed()) {
                return $this->unverified($task, $record, $attempt, $result, $verification, $log);
            }

            // Committing. Staging is a reading and happens either way; the
            // phase is only entered when there is something to commit, because
            // a task found already satisfied is a verified no-change result
            // rather than an empty commit.
            $staged = $this->workbench->stager()->stage($worktree, $log);

            if ($staged->isEmpty()) {
                $record = $this->journal->generations()->recordNoChange($record);
                $log->note('nothing to commit: the task was already satisfied, and the checks agree');
            } else {
                $record = $this->journal->generations()->enterPhase($record, Phase::Committing);
                $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Committing);

                $record = $this->workbench->committer()->commit(
                    $record,
                    $worktree,
                    $ownership,
                    $staged,
                    CommitMessage::forGeneration($record, $recorded),
                    $log,
                )->recorded();
            }

            // Publishing, when the configuration asks for it.
            if ($record->push()->isOutstanding()) {
                $record = $this->journal->generations()->enterPhase($record, Phase::Publishing);
                $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Publishing);

                $publication = $this->workbench->publisher()->publish($record, $log);
                $record = $publication->recorded();

                if ($publication->hasFailed()) {
                    return $this->unpublished(
                        $task,
                        $record,
                        $attempt,
                        $result,
                        $verification,
                        $publication->error(),
                        $log,
                    );
                }
            }

            return $this->finalize($task, $snapshot, $record, $attempt, $result, $verification, $log);
        } catch (RalphException $refusal) {
            return $this->refused($task, $record, $attempt, $verification, $refusal, $log);
        } finally {
            $this->remember($record, $attempt);
            $this->sweep($record, $log);
        }
    }

    /**
     * Open the pass: record the task as read, choose its generation, and get
     * the row it will be worked on through.
     *
     * A pass being continued keeps its history, its branch and its attempt
     * count; a reopened task gets a new generation with its own branch, which
     * is what keeps the completed one intact (PROJECT.md section C, item 8).
     *
     * @throws RalphException when the rows cannot be written
     */
    private function open(Task $task, TaskSnapshot $recorded): GenerationRecord
    {
        $this->journal->tasks()->record($recorded);

        $selection = $this->workbench->passes()->selectFor(
            $task,
            $this->journal->generations()->history(),
        );

        if ($selection->continues() === null) {
            return $this->journal->generations()->start(
                $selection->generation(),
                $recorded,
                AgentRecord::of($this->agent),
                $this->base->reference(),
                $this->base->sha(),
                TaskPublisher::expectationFor($this->configuration),
                $this->run->rowId(),
            );
        }

        $record = $this->journal->generations()->get($selection->generation());

        // The task's wording may have moved under a pass that is being
        // continued. It is the same work — identity comes from the description
        // — but what was asked for has changed, and the row should say so
        // before the agent is told it.
        $record = $this->journal->generations()->refreshContent($record, $recorded);

        return $this->journal->generations()->assignRun($record, $this->run->rowId());
    }

    /**
     * Invoke the agent for this attempt.
     *
     * The previous attempt's evidence goes with the request, which is the whole
     * point of having kept it: a second attempt that is told nothing about the
     * first is not a second attempt, it is the same one again.
     */
    private function invoke(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        TaskWorktree $worktree,
        AttemptContext $context,
        ExecutionBudget $budget,
        AttemptLog $log,
    ): ExecutionResult {
        $previous = $this->journal->attempts()->recallFor($record, 1)[0] ?? null;

        $request = new ExecutionRequest(
            $this->agent,
            $record->generation(),
            $attempt->number(),
            $task,
            $worktree->workspace(),
            $context,
            $budget,
            $previous?->evidence(),
            $log,
        );

        $log->note($this->agent->describe() . ': ' . $request->summary());

        return $this->adapter->execute($request);
    }

    /**
     * The end of a pass whose work was verified, committed and published.
     *
     * The order here is PROJECT.md section G, item 7, and it is not
     * interchangeable. The checklist is reconciled before it is written,
     * because an edit to the task under the pass means a tick would be applied
     * to work nobody asked for. The box is ticked before the pass is completed,
     * because a complete pass whose box could not be written is the one case
     * that must be recognisable afterwards as needing only the write.
     */
    private function finalize(
        Task $task,
        ChecklistSnapshot $snapshot,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        AttemptLog $log,
    ): TaskResult {
        $record = $this->journal->generations()->enterPhase($record, Phase::Finalizing);
        $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Finalizing);

        $blocker = $this->movedUnderUs($task, $snapshot) ?? $this->tick($task, $record);

        if ($blocker !== null) {
            $log->note('not checked off: ' . $blocker->reason());

            return $this->stopped(
                $task,
                $this->journal->generations()->block($record, $blocker),
                $this->journal->attempts()->finish(
                    $attempt,
                    AttemptOutcome::Candidate,
                    LifecycleState::Blocked,
                    $result->exitCode(),
                    $this->milliseconds($result),
                    blocker: $blocker,
                ),
                $verification,
                $blocker->reason(),
            );
        }

        $this->journal->tasks()->markVerified($task->id());
        $record = $this->journal->generations()->complete($record);
        $attempt = $this->journal->attempts()->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Completed,
            $result->exitCode(),
            $this->milliseconds($result),
        );

        $log->note(sprintf(
            'completed: %s, box ticked',
            $record->isNoChange() ? 'nothing to commit' : 'commit ' . substr((string) $record->commitSha(), 0, 8),
        ));

        return TaskResult::of(
            $task,
            $record,
            $attempt,
            $verification,
            true,
            $record->isNoChange() ? 'already satisfied, and the checks agree' : 'verified and committed',
        );
    }

    /**
     * Tick the task's box, and report a write that did not happen.
     *
     * The implementation and the commit are already durable by the time this
     * runs, so a checklist that cannot be written is a blocker and not a lost
     * pass: the next action is to make the file writable, and nothing is
     * implemented again.
     */
    private function tick(Task $task, GenerationRecord $record): ?Blocker
    {
        try {
            $this->workbench->checkboxes()->tick($this->configuration, $task->id());

            return null;
        } catch (RalphException $failure) {
            return Blocker::raised(
                'the task is done, but its box could not be ticked: ' . $failure->getMessage(),
                sprintf(
                    'The implementation is kept%s and is not repeated. Make %s writable, then run `ralph resume`.',
                    $record->commitSha() === null ? '' : ' as commit ' . substr((string) $record->commitSha(), 0, 8),
                    $this->configuration->tasksPath(),
                ),
            );
        }
    }

    /**
     * Whether the task is still the work this pass was given.
     *
     * Read fresh from the file rather than from the run's snapshot, which is
     * the point: the user may have reworded or deleted the task while the agent
     * was working, and a tick applied to a line that now says something else is
     * worse than no tick at all (PROJECT.md section C, item 7).
     */
    private function movedUnderUs(Task $task, ChecklistSnapshot $snapshot): ?Blocker
    {
        try {
            $current = $this->workbench->checklist()->read($this->configuration);
            $reconciliation = $this->workbench->reconciler()->reconcile($snapshot, $task->id(), $current);
        } catch (RalphException $unreadable) {
            return Blocker::raised(
                'the task file could not be read back before the box was ticked: ' . $unreadable->getMessage(),
                sprintf('Fix %s, then run `ralph resume`.', $this->configuration->tasksPath()),
            );
        }

        if (!$reconciliation->requiresPause()) {
            return null;
        }

        return Blocker::raised(
            $reconciliation->reason(),
            $reconciliation->hints()[0] ?? 'Reconcile the task file, then run `ralph resume`.',
        );
    }

    /**
     * An agent that blocked, errored, timed out, or was cancelled.
     *
     * The outcome decides the state — that is what
     * {@see AttemptOutcome::settles()} is — and the state is recorded with
     * whatever the agent left behind as evidence for the next attempt.
     */
    private function settled(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        AttemptLog $log,
    ): TaskResult {
        $state = $result->settles() ?? LifecycleState::Failed;
        $blocker = $result->blocker();

        $attempt = $this->journal->attempts()->finish(
            $attempt,
            $result->outcome(),
            $state,
            $result->exitCode(),
            $this->milliseconds($result),
            evidence: $result->evidence(),
            blocker: $blocker,
        );

        $record = match ($state) {
            LifecycleState::Blocked => $this->journal->generations()->block(
                $record,
                $blocker ?? Blocker::raised(
                    'the agent stopped and asked for human input',
                    sprintf('Read `ralph logs %s`, answer it, then run `ralph retry %s`.', $task->id()->value(), $task->id()->value()),
                ),
            ),
            LifecycleState::Interrupted => $this->journal->generations()->interrupt($record),
            default => $this->journal->generations()->fail($record, $blocker),
        };

        $log->note('the agent returned ' . $result->describe());

        return $this->stopped($task, $record, $attempt, null, $result->describe());
    }

    /**
     * A candidate implementation the checks decided against.
     *
     * The task stays unchecked and the evidence is stored for the next attempt
     * to read. The workspace is kept: whoever looks at why a check failed needs
     * the files that failed it.
     */
    private function unverified(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        AttemptLog $log,
    ): TaskResult {
        $blocker = Blocker::raised(
            'verification failed: ' . $verification->describe(),
            sprintf(
                'The work is kept in %s for inspection. Fix it, or run `ralph retry %s` to let the '
                . 'agent try again with the failure as evidence.',
                (string) $record->worktree()->path(),
                $task->id()->value(),
            ),
        );

        $attempt = $this->journal->attempts()->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Failed,
            $result->exitCode(),
            $this->milliseconds($result),
            evidence: $verification->evidence(),
            blocker: $blocker,
        );

        $log->note('not verified; the task stays unchecked');

        return $this->stopped(
            $task,
            $this->journal->generations()->fail($record, $blocker),
            $attempt,
            $verification,
            $blocker->reason(),
        );
    }

    /**
     * Verified and committed work the remote would not take.
     *
     * The local commit is kept and the task is left unchecked, because a push
     * the configuration requires is part of what completes a task. The phase on
     * the row stays `publishing` and the commit is recorded, which is what lets
     * the publication be retried without the implementation being repeated.
     */
    private function unpublished(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        ?string $error,
        AttemptLog $log,
    ): TaskResult {
        $blocker = Blocker::raised(
            sprintf(
                'the push to %s was refused: %s',
                (string) $record->push()->remote(),
                $error ?? 'no reason given',
            ),
            sprintf(
                'The commit is kept on %s and the implementation is not repeated. Resolve the '
                . 'remote, then run `ralph resume` to retry the push alone.',
                (string) $record->branch(),
            ),
        );

        $attempt = $this->journal->attempts()->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Failed,
            $result->exitCode(),
            $this->milliseconds($result),
            evidence: FailureEvidence::of('push refused', $error ?? 'no reason given'),
            blocker: $blocker,
        );

        $log->note('published: no — ' . $blocker->reason());

        return $this->stopped(
            $task,
            $this->journal->generations()->fail($record, $blocker),
            $attempt,
            $verification,
            $blocker->reason(),
        );
    }

    /**
     * A step that refused.
     *
     * Everything the Git steps and the stores raise comes here: a branch owned
     * by unrelated work, drift the auditor will not put back, an index that
     * cannot be staged, a commit Git rejected. The pass fails with the refusal
     * as the blocker's reason and the phase it happened in left on the row,
     * which together are what `ralph logs` prints and what tells a reviewer
     * where to look.
     *
     * @throws RalphException when the failure cannot itself be recorded, in
     *         which case there is nothing useful left to do with it
     */
    private function refused(
        Task $task,
        GenerationRecord $record,
        ?AttemptRecord $attempt,
        ?VerificationReport $verification,
        RalphException $refusal,
        AttemptLog $log,
    ): TaskResult {
        $log->note('stopped: ' . $refusal->getMessage());

        $current = $this->journal->generations()->find($record->generation()) ?? $record;

        $blocker = Blocker::raised(
            $refusal->getMessage(),
            $refusal->hints()[0] ?? sprintf('Run `ralph logs %s` for the whole attempt.', $task->id()->value()),
        );

        if ($attempt !== null) {
            $open = $this->journal->attempts()->get($current, $attempt->number());

            if ($open !== null && !$open->isFinished()) {
                $phase = $current->phase();

                $attempt = $this->journal->attempts()->finish(
                    $open,
                    AttemptOutcome::Error,
                    LifecycleState::Failed,
                    evidence: FailureEvidence::of(
                        sprintf('%s refused', $phase === null ? 'the pass' : $phase->value),
                        $refusal->getMessage(),
                    ),
                );
            }
        }

        return $this->stopped(
            $task,
            $current->state()->isOpen() ? $this->journal->generations()->fail($current, $blocker) : $current,
            $attempt,
            $verification,
            $refusal->getMessage(),
        );
    }

    /**
     * A task with nothing left to spend on it.
     *
     * Not a failure of this pass — it never started one — and not a new row:
     * the recorded pass keeps its history, and the loop reports why the task
     * was passed over. Which limit ran out is {@see ExecutionBudget::exhausted()},
     * and it is quoted rather than guessed at.
     */
    private function spent(Task $task, GenerationRecord $record, ExecutionBudget $budget): TaskResult
    {
        $blocker = Blocker::raised(
            $budget->exhausted() ?? 'there is nothing left in the budget for this task',
            sprintf(
                'Read `ralph logs %s`, then either fix the task\'s wording or run `ralph retry %s` '
                . 'to start a new pass at it.',
                $task->id()->value(),
                $task->id()->value(),
            ),
        );

        return $this->stopped(
            $task,
            $record->state()->isOpen() ? $this->journal->generations()->fail($record, $blocker) : $record,
            null,
            null,
            $blocker->reason(),
        );
    }

    /**
     * A pass that ended without the box being ticked.
     */
    private function stopped(
        Task $task,
        GenerationRecord $record,
        ?AttemptRecord $attempt,
        ?VerificationReport $verification,
        string $reason,
    ): TaskResult {
        return TaskResult::of($task, $record, $attempt, $verification, false, $reason);
    }

    /**
     * Write the attempt up for the next one to read.
     *
     * The row is read back rather than taken from the variable, because this
     * runs in a `finally` and the finished row is the one worth summarising.
     * Nothing here treats the note as state: the agent's claim stays marked as
     * a claim, which is {@see AttemptSummary}'s job and PROJECT.md section D,
     * item 6's requirement.
     */
    private function remember(GenerationRecord $record, ?AttemptRecord $attempt): void
    {
        if ($attempt === null) {
            return;
        }

        try {
            $finished = $this->journal->attempts()->get($record, $attempt->number()) ?? $attempt;
            $this->workbench->memory()->record(AttemptSummary::of($record->generation(), $finished));
        } catch (RalphException $failure) {
            // A memory note is an aid to the next attempt, not a record of what
            // happened — the state store already has that. Losing one must not
            // turn a finished pass into a failed one.
            $this->terminal?->diagnosticLine(
                'note: the attempt summary could not be written — ' . $failure->getMessage(),
            );
        }
    }

    /**
     * Remove the workspace, or say why it is being kept.
     *
     * The cleaner decides, and it is handed the pass's final row: a completed
     * pass's workspace goes, and a failed, blocked or interrupted one stays
     * with its reason noted, which is the documented half of PROJECT.md
     * section F, item 10.
     */
    private function sweep(GenerationRecord $record, AttemptLog $log): void
    {
        try {
            $final = $this->journal->generations()->find($record->generation());

            if ($final === null) {
                return;
            }

            $log->note('workspace: ' . $this->workbench->cleaner()->clean($final, $log)->describe());
        } catch (RalphException $failure) {
            $log->note('the workspace could not be cleaned up: ' . $failure->getMessage());
        }
    }

    /**
     * How long the agent took, in whole milliseconds.
     */
    private function milliseconds(ExecutionResult $result): int
    {
        return (int) round($result->seconds() * 1000);
    }
}
