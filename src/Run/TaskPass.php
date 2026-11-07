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
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskPublisher;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Log\AttemptLog;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\Memory\AttemptSummary;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\ChecklistUpdate;
use Celyes\RalphPhp\State\EvidenceTrail;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\WorktreeOwnership;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\ChecklistFile;
use Celyes\RalphPhp\Tasks\ChecklistSnapshot;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Verification\ChecksAudit;
use Celyes\RalphPhp\Verification\Satisfaction;
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
 * **Nor do the checks, entirely.** They pass against the suite the diff leaves
 * behind, so between staging and the commit the diff itself is read for changes
 * that weaken that suite, and a pass whose work would delete a test, empty one
 * or exclude a path from a tool stops with the patch kept for review rather
 * than committing on the strength of a green run (section G, item 5).
 *
 * **An empty diff decides nothing either.** A task can be asked for and
 * already be done, and that is a verified outcome rather than an empty commit
 * (section G, item 6) — but only once the facts are collected and read: an
 * empty index with green checks is equally what an agent that ticked the
 * checklist in its own workspace leaves behind. So the case is made, written
 * down beside the attempt's logs, and refused where something contradicts it.
 *
 * **Every exit records what it found.** A pass that throws its way out of a
 * phase still ends with a row saying which phase that was, a blocker naming the
 * next action, the workspace kept for inspection, and the agent's claim stored
 * as a claim. What it must never do is leave a pass recorded as `running` by a
 * process that has stopped, which is why the body sits inside a `try` whose
 * `catch` records the refusal and whose `finally` writes the attempt up and
 * sweeps the workspace.
 *
 * **A failing check is tried again; nothing else is.** While the configured
 * checks decide against the work and the task's budget still has room, the pass
 * makes another attempt, and that attempt is told what the last one ran into
 * (section G, item 4). The box stays unticked for every one of them. Every
 * other way a pass can go wrong is left alone — see
 * {@see TaskResult::invitesAnotherAttempt()} for which, and why.
 *
 * **A pass that owes only the checklist write is not attempted again at all.**
 * The write to the user's file is the last thing a pass does and the only one
 * that can fail with everything else already durable, so it is recorded as a
 * plan before it is made ({@see \Celyes\RalphPhp\State\ChecklistStore}). A pass
 * found with that plan still outstanding goes straight to
 * {@see self::finishTheChecklist()}: no workspace, no agent, no checks, no
 * second commit, and no attempt spent — section G, item 8.
 */
final class TaskPass
{
    /**
     * @param VerificationSnapshot $checks the run's frozen checks, read off the
     *        run's row rather than out of the configuration, so that a worker's
     *        edit cannot replace them mid-run (section G, item 3)
     * @param Clock $clock what times the gap between attempts within one pass,
     *        which is the part of the run's time budget no row has been written
     *        for yet
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
        private readonly Clock $clock = new SystemClock(),
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
     * Attempts are made here, in a loop, for as long as the checks fail and the
     * budget holds. The task's box is not ticked by any of them, which is the
     * second half of section G, item 4 and needs no code of its own: ticking it
     * is what {@see self::finalize()} does, and verification failing never
     * reaches it.
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

        // Before a budget is measured or a workspace is made: a pass whose work
        // is recorded and whose checklist write is still outstanding owes one
        // line in a Markdown file. Attempting the task again would re-invoke
        // the agent against work that is already committed (section G, item 8).
        $owed = $this->owedChecklistWrite($record);

        if ($owed !== null) {
            return $this->finishTheChecklist($task, $snapshot, $record, $owed);
        }

        $unverified = null;
        $spent = 0.0;

        while (true) {
            $budget = $this->budgetFor($record, $spent);

            // Checked before the log is opened and before an attempt is spent:
            // a task with nothing left to spend on it has not failed again, it
            // was not tried again, and the two must not read the same
            // afterwards. A budget that ran out *between* attempts keeps the
            // last failure as the outcome — the task failed its checks, and
            // reporting a spent limit instead would bury what it failed on.
            if ($budget->isExhausted()) {
                return $unverified ?? $this->spent($task, $record, $budget);
            }

            $log = AttemptLog::open(
                $this->workbench->logs(),
                $record->generation(),
                $record->attempts() + 1,
                $this->terminal,
            );

            $began = $this->clock->now()->getTimestamp();

            try {
                $result = $this->carryOut($task, $recorded, $snapshot, $record, $budget, $log);
            } finally {
                $log->close();
            }

            if (!$result->invitesAnotherAttempt()) {
                return $result;
            }

            $unverified = $result;
            $spent += (float) max(0, $this->clock->now()->getTimestamp() - $began);

            // Read back rather than carried: the attempt counter, the phase and
            // the blocker were all written by the attempt that just ended, and
            // the next one's budget is measured against them.
            $record = $this->journal->generations()->get($record->generation());

            $this->announceRetry($task, $record, $result);
        }
    }

    /**
     * What an attempt about to start has left to spend.
     *
     * The run's active seconds are read from its row rather than from the
     * record this pass was handed at construction: the loop adds each task's
     * time to that row as the run goes on, and a pass trusting its own copy
     * would offer the last task of a long run the whole budget again.
     *
     * `$spent` is this pass's own time since, which no row holds yet — the loop
     * writes the pass's elapsed time only once the pass returns, so between two
     * attempts of the same pass this is the only thing keeping the run's bound
     * true.
     */
    private function budgetFor(GenerationRecord $record, float $spent): ExecutionBudget
    {
        return ExecutionBudget::of(
            $this->configuration->limits(),
            $record->attempts(),
            $this->activeSeconds() + $spent,
        );
    }

    /**
     * Active seconds the run has recorded so far.
     *
     * A row that cannot be read falls back to the copy this pass was
     * constructed with, which is stale rather than wrong: a budget measured
     * from it is too generous, and refusing to attempt the task at all over a
     * reading of the clock would be the worse answer.
     */
    private function activeSeconds(): float
    {
        try {
            return (float) $this->journal->runs()->get($this->run->rowId())->activeSeconds();
        } catch (RalphException) {
            return (float) $this->run->activeSeconds();
        }
    }

    /**
     * Say that the checks failed and another attempt follows.
     *
     * Printed between the attempts rather than left to the run's summary: the
     * user is watching a task being worked on, and a second worker starting
     * with no line to explain it reads as the loop having lost its place.
     */
    private function announceRetry(Task $task, GenerationRecord $record, TaskResult $result): void
    {
        $this->terminal?->line(sprintf(
            '  %s: %s; attempt %d follows, with the failure as evidence',
            $task->id()->value(),
            $result->verification()?->describe() ?? 'the checks decided against the work',
            $record->attempts() + 1,
        ));
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
        $satisfaction = null;

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
            $record = $this->journal->generations()->recordWorktree(
                $record,
                $this->ownershipOf($record, $worktree),
            );
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
                // An empty index is not evidence of anything on its own, so the
                // case for completing the task without a commit is collected,
                // written down beside the attempt's logs, and read for what
                // contradicts it (section G, item 6).
                $satisfaction = $this->workbench->satisfaction()->audit(
                    $worktree,
                    $attempt->number(),
                    $this->base,
                    $this->workbench->auditor()->observe($worktree),
                    $staged,
                    $verification,
                    $result->claim(),
                    $this->workbench->logs()->satisfactionRecord($record->generation(), $attempt->number()),
                    $log,
                );

                if ($satisfaction->isBlocking()) {
                    return $this->unsatisfied($task, $record, $attempt, $result, $verification, $satisfaction, $log);
                }

                $record = $this->journal->generations()->recordNoChange($record);
            } else {
                // What the checks passed against is the suite this diff leaves
                // behind, so the diff is read before it becomes a commit: a
                // change that weakens the project's own verification stops the
                // pass with the patch kept for review (section G, item 5).
                $audit = $this->workbench->checks()->audit(
                    $this->checks,
                    $worktree,
                    $staged,
                    $this->workbench->logs()->stagedDiff($record->generation(), $attempt->number()),
                    $log,
                );

                if ($audit->isBlocking()) {
                    return $this->weakened($task, $record, $attempt, $result, $verification, $audit, $log);
                }

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

            return $this->finalize(
                $task,
                $snapshot,
                $record,
                $attempt,
                $result,
                $verification,
                $staged,
                $log,
                $satisfaction,
            );
        } catch (RalphException $refusal) {
            return $this->refused($task, $record, $attempt, $verification, $refusal, $log);
        } finally {
            $this->remember($record, $attempt);
            $this->sweep($record, $log);
        }
    }

    /**
     * What the row should say about the workspace this attempt is using.
     *
     * Ordinarily whatever the preparation reported. The exception is a pass
     * attempting a task again: the directory its last attempt created is still
     * there — kept on purpose, as the evidence a failing check leaves behind —
     * so preparation can only report having adopted it, and recording that
     * would hand the pass's own workspace to nobody. Cleanup removes only what
     * a pass created ({@see \Celyes\RalphPhp\Git\WorktreeCleaner}), so a task
     * that passed on its second attempt would leave a checkout behind where one
     * that passed on its first did not.
     *
     * The row's own answer is kept when it already names this path as created,
     * which is not a guess about the directory: it is what this pass wrote when
     * it made it.
     */
    private function ownershipOf(GenerationRecord $record, TaskWorktree $worktree): WorktreeOwnership
    {
        $recorded = $record->worktree();

        return $recorded->isOwned() && $recorded->isAt($worktree->path())
            ? $recorded
            : $worktree->ownership();
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
     * The previous attempts' evidence goes with the request, which is the whole
     * point of having kept it: a second attempt that is told nothing about the
     * first is not a second attempt, it is the same one again.
     *
     * The trail rather than the newest row alone, and bounded by
     * {@see EvidenceTrail} rather than by anything assembled here: an attempt
     * that errored or was cancelled recorded nothing the runner observed, and
     * the failing check behind it is still why the task is not done
     * (section G, item 4).
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
        $evidence = EvidenceTrail::of($this->journal->attempts()->recallFor($record, EvidenceTrail::ROWS));

        $request = new ExecutionRequest(
            $this->agent,
            $record->generation(),
            $attempt->number(),
            $task,
            $worktree->workspace(),
            $context,
            $budget,
            $evidence,
            $log,
        );

        $log->note($this->agent->describe() . ': ' . $request->summary());

        return $this->adapter->execute($request);
    }

    /**
     * The end of a pass whose work was verified, committed and published.
     *
     * PROJECT.md section G, item 7 in both its halves. The condition first:
     * the checks, the Git requirements and any required publication are read
     * off the evidence as one reading ({@see CompletionEvidence::unmet()})
     * before the checklist is touched, so what permits the tick is a question
     * asked here rather than a property of how the pass happened to arrive. The
     * sequence above already guarantees every one of them, which is exactly why
     * the reading is cheap and worth having: the one write this package makes
     * to a file the user owns should not depend on nobody ever reordering the
     * code above it.
     *
     * Then the record: every fact the tick rests on is kept together beside the
     * attempt's logs, because by the end of a pass they are spread across the
     * verification rows, the generation's columns, the attempt's claim and a
     * line in a Markdown file, and none of those answers "why is this ticked
     * off" on its own.
     *
     * The rest of the order is not interchangeable either. The checklist is
     * reconciled before it is written, because an edit to the task under the
     * pass means a tick would be applied to work nobody asked for. The box is
     * ticked before the pass is completed, because a complete pass whose box
     * could not be written is the one case that must be recognisable
     * afterwards as needing only the write.
     */
    private function finalize(
        Task $task,
        ChecklistSnapshot $snapshot,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        StagedChanges $staged,
        AttemptLog $log,
        ?Satisfaction $satisfaction = null,
    ): TaskResult {
        $record = $this->journal->generations()->enterPhase($record, Phase::Finalizing);
        $attempt = $this->journal->attempts()->enterPhase($attempt, Phase::Finalizing);

        $evidence = CompletionEvidence::of(
            $record,
            $task->description(),
            $attempt->number(),
            $this->base,
            $verification,
            $staged,
            $satisfaction,
            $result->claim(),
            $this->configuration->tasksPath(),
        );

        // The conditions before the reconciliation, and both before the write:
        // whether the task moved under the pass does not matter about work that
        // did not earn a tick in the first place.
        $blocker = $this->unearned($evidence) ?? $this->movedUnderUs($task, $snapshot);

        if ($blocker === null) {
            $written = $this->tick($task, $record, $log);

            if ($written instanceof Blocker) {
                $blocker = $written;
            } else {
                $evidence = $evidence->ticked($written);
            }
        }

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
                    evidence: $evidence->evidence(),
                    blocker: $blocker,
                ),
                $verification,
                $blocker->reason(),
                $satisfaction,
                $this->keep($evidence, $record, $attempt, $log),
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

        return TaskResult::of(
            $task,
            $record,
            $attempt,
            $verification,
            true,
            $record->isNoChange() ? $this->describeNoChange($satisfaction) : 'verified and committed',
            $satisfaction,
            $this->keep($evidence, $record, $attempt, $log),
        );
    }

    /**
     * Refuse a tick the pass has not earned, with what is missing.
     *
     * Blocked rather than failed when it happens, like the other two readings
     * that stop a green run ({@see self::weakened()}, {@see self::unsatisfied()})
     * and for the same reason: nothing here is a failing check, so there is
     * nothing a further attempt would be told that it does not already know.
     */
    private function unearned(CompletionEvidence $evidence): ?Blocker
    {
        if ($evidence->isEarned()) {
            return null;
        }

        return Blocker::raised($evidence->summary(), implode(' ', $evidence->hints()));
    }

    /**
     * Keep the completion's evidence, and say on the log what it holds.
     *
     * Written for a refusal as well as for a tick: a pass that reached the end
     * and found a condition unmet is the case most worth being able to read
     * afterwards. The record is a copy of facts the state store already holds,
     * so a write that fails is noted and nothing about the outcome changes.
     */
    private function keep(
        CompletionEvidence $evidence,
        GenerationRecord $record,
        AttemptRecord $attempt,
        AttemptLog $log,
    ): CompletionEvidence {
        return $this->workbench->completions()->record(
            $evidence,
            $this->workbench->logs()->completionRecord($record->generation(), $attempt->number()),
            $log,
        );
    }

    /**
     * Tick the task's box, recording the write before making it.
     *
     * The plan goes in the state store first, which is what PROJECT.md
     * section G, item 8 turns on: the implementation and the commit are already
     * durable by the time this runs, so a checklist that cannot be written is a
     * pass pending its finalization rather than a lost pass, and the row is
     * what makes that recognisable afterwards. Without it, a pass blocked on an
     * unwritable file and a pass blocked on a question the agent asked are the
     * same row in `generations`.
     *
     * The edit is returned rather than discarded because the completion record
     * names the line it changed, which is the one part of the evidence that
     * exists nowhere else.
     *
     * @throws RalphException when the plan cannot be recorded, which is a
     *         failure to record and not a failed write: the box is left alone
     *         rather than ticked with nothing saying so
     */
    private function tick(Task $task, GenerationRecord $record, AttemptLog $log): CheckboxEdit|Blocker
    {
        return $this->apply($task, $record, $this->plan($task, $record), $log);
    }

    /**
     * Record what the run means to write, before the file is touched.
     *
     * The checklist is hashed as it reads now rather than as the run first read
     * it: this is the state the write is about to be applied to, and it is what
     * a later retry has to compare against to know the file has not moved under
     * it. The task's own hash goes with it for the same reason — a retry must
     * be able to tell "the same write, not yet made" from "a different task on
     * that line now".
     *
     * @throws RalphException when the checklist cannot be read, or the plan
     *         cannot be recorded
     */
    private function plan(Task $task, GenerationRecord $record): ChecklistUpdate
    {
        return $this->journal->checklists()->plan(
            $record,
            $this->run->rowId(),
            $this->configuration->tasksPath(),
            ChecklistFile::hash(ChecklistFile::read($this->configuration->tasksFile())),
            TaskSnapshot::hash($task->description(), $task->details()),
        );
    }

    /**
     * Make the planned write, and settle its row either way.
     *
     * The two failures are kept apart because they call for opposite things.
     * A {@see FilesystemException} is the file saying no — unwritable, full,
     * a rename that could not happen — and the plan stays outstanding, because
     * the work is done and retrying the write unchanged is exactly right.
     * A {@see ChecklistException} means what the plan was made against has
     * changed, and retrying it would tick a line nobody asked about, so the
     * plan is closed and the pass stops for reconciliation.
     *
     * @throws RalphException when the row cannot be settled
     */
    private function apply(
        Task $task,
        GenerationRecord $record,
        ChecklistUpdate $planned,
        AttemptLog $log,
    ): CheckboxEdit|Blocker {
        try {
            $edit = $this->workbench->checkboxes()->tick($this->configuration, $task->id());
        } catch (FilesystemException $refused) {
            $owed = $this->journal->checklists()->deferred($planned, $refused->getMessage());
            $log->note('pending finalization: ' . $owed->describe());

            return $this->pendingFinalization($record, $refused);
        } catch (ChecklistException $changed) {
            $this->journal->checklists()->abandoned($planned, $changed->getMessage());

            return Blocker::raised(
                'the task is done, but its box could not be ticked: ' . $changed->getMessage(),
                sprintf(
                    'The implementation is kept%s and is not repeated. Reconcile %s against the task '
                        . 'this pass was given, then run `ralph retry %s`.',
                    $this->keptCommit($record),
                    $this->configuration->tasksPath(),
                    $task->id()->value(),
                ),
            );
        }

        $this->journal->checklists()->applied($planned, ChecklistFile::hash($edit->markdown()));

        return $edit;
    }

    /**
     * A pass that earned its tick and could not write it.
     *
     * The next action is the whole point of the state: a person makes the file
     * writable and resumes, and what resumes is the write and nothing else.
     */
    private function pendingFinalization(GenerationRecord $record, RalphException $failure): Blocker
    {
        return Blocker::raised(
            'the task is done, but its box could not be ticked: ' . $failure->getMessage(),
            sprintf(
                'The implementation is kept%s and is not repeated: the pass is pending finalization, '
                    . 'and a resume writes only the box. Make %s writable, then run `ralph resume`.',
                $this->keptCommit($record),
                $this->configuration->tasksPath(),
            ),
        );
    }

    /**
     * The commit a refused tick leaves behind, phrased for a hint.
     */
    private function keptCommit(GenerationRecord $record): string
    {
        $sha = $record->commitSha();

        return $sha === null ? '' : ' as commit ' . substr($sha, 0, BaseCommit::SHORT_LENGTH);
    }

    /**
     * The checklist write this pass still owes, or null when it owes none.
     *
     * Both halves have to hold. The plan must still be outstanding — that is
     * the write not having landed — and the pass must be finalizable, which is
     * the implementation recorded and the publication settled
     * ({@see GenerationRecord::isFinalizable()}). A pass interrupted before
     * either of those has work to redo and is not this case; a pass that has
     * both owes a line in a file.
     *
     * @throws RalphException when the rows cannot be read
     */
    private function owedChecklistWrite(GenerationRecord $record): ?ChecklistUpdate
    {
        if (!$record->isFinalizable()) {
            return null;
        }

        return $this->journal->checklists()->outstandingFor($record);
    }

    /**
     * Finish a pass that owes nothing but the write to the user's checklist.
     *
     * PROJECT.md section G, item 8's second half. Everything an attempt
     * produces is already recorded, so nothing here prepares a workspace,
     * invokes the agent, runs a check or commits, and no attempt is spent: a
     * read-only file must not be able to exhaust the budget of work that has
     * already passed. The agent's log is reopened rather than replaced, so the
     * retry reads as the end of the attempt that earned it.
     *
     * The task is still reconciled first. A pass can sit pending finalization
     * for as long as it takes someone to notice, and in that time the task may
     * have been reworded or removed — in which case the planned write is closed
     * rather than applied to whatever is on that line now.
     *
     * @throws RalphException when the outcome cannot be recorded
     */
    private function finishTheChecklist(
        Task $task,
        ChecklistSnapshot $snapshot,
        GenerationRecord $record,
        ChecklistUpdate $owed,
    ): TaskResult {
        $attempt = $this->journal->attempts()->latestFor($record);
        $log = $attempt === null
            ? AttemptLog::toTerminal($this->terminal)
            : AttemptLog::open(
                $this->workbench->logs(),
                $record->generation(),
                $attempt->number(),
                $this->terminal,
            );

        try {
            $log->note('resuming finalization: ' . $owed->describe());
            $this->announceFinalization($task, $record, $owed);

            $record = $this->journal->generations()->resumeFinalizing($record);
            $verification = $attempt === null ? null : $this->journal->verifications()->reportFor($attempt);

            $blocker = $this->movedUnderUs($task, $snapshot);

            if ($blocker !== null) {
                $this->journal->checklists()->abandoned($owed, $blocker->reason());
            } else {
                // The same plan-then-write as a first-time finalization:
                // {@see self::plan()} reuses the outstanding row rather than
                // adding to it, so the retry refreshes what the write is about
                // to be applied against instead of recording a second claim on
                // the same line.
                $written = $this->tick($task, $record, $log);
                $blocker = $written instanceof Blocker ? $written : null;
            }

            if ($blocker !== null) {
                $log->note('still not checked off: ' . $blocker->reason());

                return $this->stopped(
                    $task,
                    $this->journal->generations()->block($record, $blocker),
                    $attempt,
                    $verification,
                    $blocker->reason(),
                );
            }

            $this->journal->tasks()->markVerified($task->id());
            $record = $this->journal->generations()->complete($record);

            return TaskResult::of(
                $task,
                $record,
                $attempt,
                $verification,
                true,
                'the box was written; the commit and the checks are the ones this pass already recorded',
            );
        } finally {
            $this->sweep($record, $log);
            $log->close();
        }
    }

    /**
     * Say that the pass is being finished rather than attempted.
     *
     * Worth a line of its own: a run that prints a task and then neither a
     * worker's output nor a check reads as the loop having skipped it, when in
     * fact it is doing the one thing the task still needed.
     */
    private function announceFinalization(Task $task, GenerationRecord $record, ChecklistUpdate $owed): void
    {
        $this->terminal?->line(sprintf(
            '  %s: %s; writing only the box%s',
            $task->id()->value(),
            $owed->state()->describe(),
            $record->commitSha() === null ? '' : ', the implementation is kept' . $this->keptCommit($record),
        ));
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
     * A verified diff that leaves the project's verification weaker than it
     * found it.
     *
     * Blocked rather than failed, which is the deliberate part. A failing check
     * is evidence of something wrong with the work, and the next attempt is
     * given it (section G, item 4); this is not — the checks passed. What is in
     * question is whether a diff that deletes a test, empties one or excludes a
     * path from a tool is the change the task asked for, and no number of
     * further attempts answers that. So the pass stops, the run stops with it,
     * and the patch is where {@see ChecksAudit::diffPath()} says.
     *
     * Nothing is committed and nothing is undone. The workspace is preserved
     * with the diff still staged, so the same patch can be read from Git as
     * well as from the file, and a user who decides the change is right can
     * commit it themselves.
     */
    private function weakened(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        ChecksAudit $audit,
        AttemptLog $log,
    ): TaskResult {
        $blocker = Blocker::raised(
            $audit->summary(),
            implode(' ', $audit->hints()),
        );

        $attempt = $this->journal->attempts()->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Blocked,
            $result->exitCode(),
            $this->milliseconds($result),
            evidence: $audit->evidence(),
            blocker: $blocker,
        );

        $log->note('not committed: ' . $audit->summary());

        return $this->stopped(
            $task,
            $this->journal->generations()->block($record, $blocker),
            $attempt,
            $verification,
            $audit->summary(),
        );
    }

    /**
     * An empty index whose no-change outcome the facts do not support.
     *
     * The counterpart of {@see self::weakened()} on the other side of staging,
     * and blocked for the same reason: nothing here is a failing check, so
     * there is nothing a further attempt would be told that it does not
     * already know. An agent that staged nothing because it ticked the
     * checklist inside its own workspace, or a workspace that is no longer at
     * the commit it was handed, is a question for a person — and the one answer
     * the package must not give is to complete the task because the checks were
     * green and the diff was empty.
     *
     * The evidence is where {@see Satisfaction::recordPath()} says, and the
     * workspace is kept, so what was collected can be read beside the files it
     * was collected from.
     */
    private function unsatisfied(
        Task $task,
        GenerationRecord $record,
        AttemptRecord $attempt,
        ExecutionResult $result,
        VerificationReport $verification,
        Satisfaction $satisfaction,
        AttemptLog $log,
    ): TaskResult {
        $blocker = Blocker::raised($satisfaction->summary(), implode(' ', $satisfaction->hints()));

        $attempt = $this->journal->attempts()->finish(
            $attempt,
            AttemptOutcome::Candidate,
            LifecycleState::Blocked,
            $result->exitCode(),
            $this->milliseconds($result),
            evidence: $satisfaction->evidence(),
            blocker: $blocker,
        );

        $log->note('not completed: ' . $satisfaction->summary());

        return $this->stopped(
            $task,
            $this->journal->generations()->block($record, $blocker),
            $attempt,
            $verification,
            $satisfaction->summary(),
            $satisfaction,
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
            $this->failing($current, $blocker),
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
            $this->failing($record, $blocker),
            null,
            null,
            $blocker->reason(),
        );
    }

    /**
     * Record the pass as failed, where the lifecycle has somewhere to go.
     *
     * `failed` is an open state — a failed task can be attempted again — but it
     * is not a state a pass can move to from itself, and a pass that ran out of
     * attempts after a failing check is exactly that: already failed, and now
     * being passed over. Its first blocker is the true one, so the row is left
     * saying what the checks said rather than that the runner declined to try
     * again; the reason the loop prints says the rest.
     *
     * @throws RalphException when the row cannot be written
     */
    private function failing(GenerationRecord $record, ?Blocker $blocker): GenerationRecord
    {
        return $record->state()->canTransitionTo(LifecycleState::Failed)
            ? $this->journal->generations()->fail($record, $blocker)
            : $record;
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
        ?Satisfaction $satisfaction = null,
        ?CompletionEvidence $completion = null,
    ): TaskResult {
        return TaskResult::of(
            $task,
            $record,
            $attempt,
            $verification,
            false,
            $reason,
            $satisfaction,
            $completion,
        );
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
     * What the terminal says about a task that needed no change.
     *
     * The audit's own words where there is an audit, so the line a user reads
     * names the base commit the checks passed against rather than only
     * asserting that they agreed. A pass completed without one — which the
     * loop has no path to today, and which a later caller could — says the
     * plain thing instead of claiming evidence that was never collected.
     */
    private function describeNoChange(?Satisfaction $satisfaction): string
    {
        return $satisfaction?->summary() ?? 'already satisfied, and the checks agree';
    }

    /**
     * How long the agent took, in whole milliseconds.
     */
    private function milliseconds(ExecutionResult $result): int
    {
        return (int) round($result->seconds() * 1000);
    }
}
