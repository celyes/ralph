<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationDrift;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Event\EventStream;
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\InterruptionException;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\ControllingCheckout;
use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\Process\Interruptions;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\ChecklistSnapshot;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskList;
use Celyes\RalphPhp\Verification\Verifier;
use Throwable;

/**
 * The loop: pending tasks, one at a time, until there is a reason to stop.
 *
 * What `ralph run` is. The sequence is the one PROJECT.md section G, item 1
 * names — select a pending task, prepare its workspace and context, invoke the
 * adapter, verify the result, commit, optionally push, finalize progress — and
 * each task's half of it is {@see TaskPass}. This class owns what happens
 * *between* tasks: the order, the run row, and the decision to carry on or
 * stop.
 *
 * **It is also what `ralph resume` and `ralph retry TASK-ID` are**, because all
 * three are this loop with a different opening: which agent, whether the run
 * row is opened or continued, and which tasks are taken on. {@see RunIntent}
 * holds those three differences and nothing else does, so a resume cannot drift
 * away from a run in how it locks the repository, freezes its checks, carries
 * out a task or reports why it stopped. What a resume adds is the
 * reconciliation of section H, item 5, performed before any agent is invoked;
 * what a retry adds is a fresh attempt allowance for the one task it names.
 *
 * **Everything that must hold still is settled before the first task.** The
 * user's checkout is inspected, the agent is resolved, the base reference is
 * resolved to a commit, the checklist is read, and the verification commands
 * are frozen — all of it recorded on the run's row. A failure in any of that is
 * a setup failure and is raised: there is nothing to record a per-task result
 * against yet, and a run that started half-configured would be worse than one
 * that did not start.
 *
 * **The checks come off the row, not out of the configuration.** The frozen set
 * is read back from the run that was just opened and handed to every pass, so a
 * worker that edits `.ralph/config.json` changes what the next run requires and
 * nothing about this one. The edit is still noticed and printed, because a run
 * silently disregarding the settings a user is reading would be its own problem
 * (section G, item 3).
 *
 * **One agent, one run, no parallelism.** The tasks are taken in file order and
 * finished one at a time. Selecting an agent does not enable simultaneous
 * execution (PROJECT.md section 4), and nothing here starts a second worker.
 * The repository lock ({@see RepositoryLock}) is taken before any of it, so
 * nothing *else* starts a second one either: two runners over one state
 * database, one set of task branches and one checklist would hand the same task
 * to two agents and each tick a box for work only one of them did.
 *
 * **A stop asked for from outside ends it too.** Ctrl+C installs nothing more
 * than a flag ({@see Interruptions}); the pass under way gives way at its next
 * phase boundary, the loop takes no further task on, the run's row is closed as
 * `interrupted` with the signal as its reason, and the repository lock goes
 * back only once the worker is confirmed stopped — section H, item 4, in the
 * order those four things have to happen in.
 *
 * **A failed task does not end the run; a blocked one does.** A task whose
 * checks failed stays unchecked and retriable, and the tasks after it are
 * independent work. A task that stopped to ask a question is waiting on a
 * person, and handing the next task to the same agent would be answering it by
 * ignoring it.
 *
 * **Retrying is the pass's business, not the loop's.** A task whose checks fail
 * is attempted again inside {@see TaskPass} while its attempt budget holds
 * (section G, item 4), so a result arriving here has already been tried as often
 * as the configuration allows. The loop counts one iteration per task however
 * many attempts that took: the iteration limit bounds how much of the checklist
 * a run works through, and the attempt limit bounds how long it stays on one
 * item.
 *
 * **However it ends, it ends with a next action** ({@see RunStop}, section H,
 * item 9). Each of the six endings — the checklist worked through, a task given
 * up on, a decision nobody but a person can make, a limit, a stop asked for,
 * and a failure — is recorded as a reason together with what the user is
 * expected to do about it, printed on the last line and written to the run's
 * row so that the answer outlives the terminal that saw it. The three endings
 * that wait on somebody are the three that item asks about, and the actions are
 * the pass's own wherever the pass recorded one: the judgement was made there,
 * and a second description of it here is one more thing that could disagree
 * with the row.
 *
 * **The run's own limits stop it between tasks** ({@see RunBudget}, section H,
 * item 2). The iteration limit and the total active run-time budget are read
 * before each task is taken on, off the run's row, and a run with neither left
 * ends with its untaken tasks untouched and still pending. The other two limits
 * are a task's and are enforced inside the pass: the attempt limit by
 * {@see TaskPass}, and the task timeout by the adapter, as the seconds
 * {@see \Celyes\RalphPhp\Agent\ExecutionBudget} gives the process it starts.
 * That one is the lesser of the task timeout and the run's remaining time, so
 * no single attempt can overrun the run's budget and leave it to be noticed at
 * the boundary afterwards.
 *
 * **Everything it prints for a person it also writes for a program**
 * ({@see EventStream}, section I, item 3): that the run started, each task as
 * it is taken on, how each one came out, and why the run ended. One JSON object
 * per line, all of them correlated by the run identifier, and the closing line
 * written whichever way the run ends — including the failure path, so that a
 * consumer following it can tell a run that crashed from one still going in the
 * same way the run's row can. A stream that cannot be
 * written is a note on the terminal and nothing more: the run is the work, and
 * the stream is a report about it.
 */
final class Runner
{
    /**
     * What the repository lock records this command as.
     *
     * Written into the lock file so that a second runner's refusal can say
     * what it is refusing beside. A resume and a retry record their own
     * purposes; see {@see RunIntent}.
     */
    public const string PURPOSE = RunIntent::RUN;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly Verifier $verifier,
        private readonly AdapterRegistry $adapters,
        private readonly StateBootstrap $state = new StateBootstrap(),
        private readonly ?Output $terminal = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
        private readonly ?RepositoryLock $lock = null,
        private readonly Interruptions $interruptions = new Interruptions(),
    ) {
    }

    /**
     * A runner for a project, with the adapters the package ships.
     *
     * @param Verifier $verifier what runs the configured checks; supplied
     *        rather than constructed, because the loop's correctness does not
     *        depend on how a check is executed and its tests must not depend on
     *        a real test suite being installed
     */
    public static function forProject(
        Configuration $configuration,
        Verifier $verifier,
        ?Output $terminal = null,
        ?AdapterRegistry $adapters = null,
        ?Clock $clock = null,
        ?RepositoryLock $lock = null,
    ): self {
        return new self(
            $configuration,
            $verifier,
            $adapters ?? AdapterRegistry::default(),
            new StateBootstrap(),
            $terminal,
            $clock ?? new SystemClock(),
            'git',
            new ProcessRunner(),
            $lock,
        );
    }

    /**
     * Process pending tasks within the configured limits.
     *
     * @param string|null $requested the agent named on the command line, which
     *        overrides the project default for this run only
     *
     * @throws RalphException when the run cannot be set up, or when a task's
     *         outcome could not be recorded — the two cases where carrying on
     *         would mean guessing
     */
    public function run(?string $requested = null): RunReport
    {
        return $this->start(RunIntent::fresh($requested));
    }

    /**
     * Continue the run that was interrupted, from the phase it stopped in.
     *
     * What `ralph resume` is. The agent and its recorded settings come off the
     * interrupted run's row rather than out of the configuration, every pass it
     * left open is reconciled against the repository before it is worked on,
     * and the iteration and time counters carry over (PROJECT.md section H,
     * items 3, 5 and 6).
     *
     * @param string|null $requested the agent named on the command line, which
     *        a resume may only use to confirm the one already recorded
     *
     * @throws RalphException when there is no run to continue, when the agent
     *         cannot be the recorded one, when reconciliation needs a person,
     *         or when a task's outcome could not be recorded
     */
    public function resume(?string $requested = null): RunReport
    {
        return $this->start(RunIntent::resumed($requested));
    }

    /**
     * Try one task again, keeping everything the earlier attempts produced.
     *
     * What `ralph retry TASK-ID` is. The pass is continued rather than
     * replaced, so its attempts, logs, branch and blocker details stay where
     * they are, and the configured attempt allowance starts again — a task
     * whose attempts ran out is the commonest thing there is to retry, and a
     * retry that refused it before invoking anything would be no retry at all.
     *
     * @throws RalphException when the task is not one that can be retried, or
     *         when its outcome could not be recorded
     */
    public function retry(TaskId $task, ?string $requested = null): RunReport
    {
        return $this->start(RunIntent::retrying($task, $requested));
    }

    /**
     * The whole of a run, whichever command asked for it.
     *
     * @throws RalphException when the run cannot be set up, or when a task's
     *         outcome could not be recorded
     */
    public function start(RunIntent $intent): RunReport
    {
        // Before the lock, because a Ctrl+C between taking the lock and
        // installing the handlers would be the one window in which an
        // interruption left a run recorded as running with a lock nobody
        // released. Returns false where ext-pcntl is missing, which is
        // reported once the terminal is being written to (section H, item 4).
        $listening = Interruptions::listen();

        // Then: one runner per repository (section H, item 1). Taken before
        // anything is read because every step below writes somewhere two
        // runners would be sharing — the state database, the task branches,
        // the worktree directory, the user's one checklist — and a conflict
        // noticed after the second agent has started is a conflict that has
        // already done its damage.
        $lock = ($this->lock ?? RepositoryLock::forProject($this->configuration, $this->clock))
            ->acquire($intent->purpose());

        try {
            return $this->locked($intent, $lock, $listening);
        } finally {
            // The lock goes back only once the worker is accounted for, and the
            // handlers are put back after it: a second Ctrl+C arriving during
            // this is still this package's to notice rather than PHP's to die
            // of halfway through writing the lock record.
            $this->relinquish($lock);
            Interruptions::release();
        }
    }

    /**
     * Give the lock back, or keep claiming it.
     *
     * PROJECT.md section H, item 4's "releasing ownership only after
     * termination is confirmed". An ordinary run, and an interrupted run whose
     * worker was confirmed stopped, release it. A run that stopped a worker and
     * could not confirm it had gone leaves the record claiming the repository
     * and naming the survivors, so the next `ralph run` or `ralph resume`
     * refuses to start on top of an agent that may still be writing in a task
     * worktree.
     */
    private function relinquish(HeldLock $lock): void
    {
        $unconfirmed = Interruptions::unconfirmed();

        if ($unconfirmed === []) {
            $lock->release();

            return;
        }

        $lock->retain($unconfirmed);

        $this->terminal?->lines([
            sprintf(
                'worker process%s %s could not be confirmed stopped',
                count($unconfirmed) === 1 ? '' : 'es',
                implode(', ', $unconfirmed),
            ),
            sprintf('  %s still records this repository as in use, so nothing will start '
                . 'here until they have gone.', $lock->path()),
        ]);
    }

    /**
     * The run, with the repository lock held.
     *
     * @throws RalphException when the run cannot be set up, or when a task's
     *         outcome could not be recorded
     */
    private function locked(RunIntent $intent, HeldLock $lock, bool $listening): RunReport
    {
        // A lock left behind by a runner that stopped without releasing it has
        // been taken over rather than waited for, and that is worth a line: a
        // run that silently continued past an interrupted one would hide the
        // fact that there is interrupted work in the repository at all.
        if ($lock->wasReclaimed()) {
            $this->terminal?->line($lock->describe());
        }

        // A stop that arrived before the run got this far. Raised rather than
        // recorded, because there is nothing yet to record it against: no run
        // row, no worktree, nothing read and nothing written. A run that never
        // started is not a run that was interrupted (section H, item 4).
        //
        // It is asked again after each setup step below for a reason that is
        // not symmetry: those steps run `git`, a stop cancels every command the
        // package starts, and an interruption reported as "the uncommitted
        // changes could not be read" would send a user looking at their
        // checkout for a problem that is not there.
        $this->stopUnlessRunning();

        // Before anything is written: the user's own checkout must have nothing
        // of theirs in it that a run could be blamed for (section F, item 1).
        ControllingCheckout::forProject($this->configuration, $this->executable, $this->processes)
            ->requireRunnable();

        $this->stopUnlessRunning();

        $journal = Journal::of($this->state->open($this->configuration), $this->clock);

        // Read before the agent is resolved, because for a resume it is what
        // resolves the agent: the recorded one, with the settings it was
        // invoked through (section H, item 6). Null for every other intent.
        $previous = $intent->continuesARun() ? $this->resumable($journal) : null;

        $agent = $previous === null
            ? (new AgentSelector($this->adapters))->select($this->configuration, $intent->agent())
            : $this->recordedAgent($previous, $intent);

        $adapter = $this->adapters->create($agent);

        // A continuation keeps the base its predecessor recorded rather than
        // resolving `git.base` again. The reference routinely moves — it
        // defaults to `HEAD` — and a resumed pass whose worktree and branch
        // were made from one commit must not be finished against another.
        $base = $previous === null
            ? BaseResolver::forProject($this->configuration, $this->executable, $this->processes)->resolve()
            : $this->recordedBase($previous);

        $this->stopUnlessRunning();

        $workbench = Workbench::forProject(
            $this->configuration,
            $journal,
            $this->clock,
            $this->executable,
            $this->processes,
        );

        // A retry's task is named on a command line, and the name is checked
        // before a run row exists. An identifier that is a typo, or a task
        // nothing can be retried about, is a usage mistake rather than a run of
        // this project — and a row recording one would become the project's
        // latest run, which is what a later `ralph resume` continues: the typo
        // would hand back the iteration and time counters the interrupted run
        // had spent (section H, item 3).
        $tasks = null;

        if ($intent->isRetry()) {
            $tasks = $this->read($journal, $workbench);
            $this->selection($tasks, $journal, $intent);
        }

        $run = $previous === null
            ? $journal->runs()->open($agent, $this->configuration, $base->sha())
            : $journal->runs()->resume($previous, $agent, $this->configuration, $base->sha());

        // The lock was taken before there was a run to name in it; now there
        // is, so a conflicting runner can be told which run it is conflicting
        // with rather than only that something is running.
        $lock->record($run->runId());

        // The same run, written for another program (section I, item 3).
        // Opened here because this is the first point at which there is a run
        // identifier, and every line of the stream is correlated by that.
        $events = EventStream::forRun($this->configuration->projectRoot(), $run->runId(), $this->clock);

        // Whether the stream's own trouble has been mentioned already, so that
        // a stream that could not be opened is not reported twice.
        $mentioned = $events->hasFailed();

        $this->announce($run, $agent, $base, $run->verification(), $listening, $intent, $previous, $events);

        try {
            $events->runStarted([
                'intent' => $intent->purpose(),
                'agent' => $agent->toArray(),
                'base' => ['reference' => $base->reference(), 'sha' => $base->sha()],
                'checks' => $run->checkNames(),
                'resumed_from' => $run->resumedFromRunId(),
            ]);

            return $this->loop($run, $agent, $adapter, $base, $journal, $workbench, $intent, $events, $tasks);
        } catch (Throwable $failure) {
            // The run row is closed with the reason before the failure goes on
            // to the caller. A run left recorded as `running` by a process that
            // has gone is the state resume cannot tell from a live one.
            $this->close($journal, $run, $events, $failure);

            throw $failure;
        } finally {
            // A stream that stopped being written part-way through is said so
            // once, at the end: a consumer that followed this run would
            // otherwise be left with a truncated account of it and no way to
            // tell that from a run that ended there.
            if ($events->hasFailed() && !$mentioned) {
                $this->terminal?->line(sprintf(
                    'note: the event stream %s stopped being written — %s',
                    $events->path(),
                    (string) $events->failure(),
                ));
            }

            $events->close();
        }
    }

    /**
     * The run a resume continues.
     *
     * A row still claiming to be running first, because that is the commonest
     * thing there is to resume: a runner that crashed or was killed never got
     * to close its row. The lock is already held by this process, so such a row
     * cannot belong to a live runner — {@see RepositoryLock} refuses to start
     * beside one, and refuses to take over from one whose worker it cannot
     * confirm stopped (section H, items 1 and 4).
     *
     * Otherwise the latest run, whatever it says. A run that ended as
     * `completed` is refused by {@see RunStore::resume()} rather than here, so
     * that the refusal names the run and quotes why it stopped.
     *
     * @throws StateException when the project has never recorded a run
     */
    private function resumable(Journal $journal): RunRecord
    {
        $run = $journal->runs()->running() ?? $journal->runs()->latest();

        if ($run !== null) {
            return $run;
        }

        throw new StateException(
            'This project has no recorded run, so there is nothing to resume.',
            [
                'Run `ralph run` to start one.',
                '`ralph status` lists what the state store holds.',
            ],
        );
    }

    /**
     * The agent an interrupted run is continued with: its own.
     *
     * {@see AgentContinuity} owns the decision and every refusal around it;
     * this is where what it found reaches the terminal. The confirmation and
     * the configuration drift are both printed, because a resume using settings
     * other than the ones in the file the user is reading should say so.
     *
     * @throws RalphException when `--agent` names a different agent, or the
     *         recorded invocation cannot be carried out by this build
     */
    private function recordedAgent(RunRecord $previous, RunIntent $intent): AgentSelection
    {
        $continuation = (new AgentContinuity($this->adapters))
            ->continuation($previous, $this->configuration, $intent->agent());

        foreach ($continuation->lines() as $line) {
            $this->terminal?->line('  ' . $line);
        }

        return $continuation->selection();
    }

    /**
     * The base a continued run keeps: the commit its predecessor recorded.
     *
     * A run whose row holds no base SHA never got as far as resolving one, so
     * there is nothing of its to keep and the configured reference is resolved
     * afresh.
     *
     * @throws RalphException when the reference cannot be resolved
     */
    private function recordedBase(RunRecord $previous): BaseCommit
    {
        $sha = $previous->baseSha();

        if ($sha === null) {
            return BaseResolver::forProject($this->configuration, $this->executable, $this->processes)
                ->resolve();
        }

        return BaseCommit::of($previous->baseReference(), $sha);
    }

    /**
     * Put every pass the interrupted run left open back in step with the
     * repository, before any of it is worked on.
     *
     * PROJECT.md section H, item 5. Done up front rather than task by task for
     * one reason: a reconciliation that needs a person has to stop the resume
     * *before* an agent is invoked on some other task, and a run that invoked
     * one first would have to be stopped again afterwards.
     *
     * Only the two states an interrupted run leaves behind are reconciled. A
     * `blocked` or `failed` pass stopped for a reason of its own that its row
     * already records, and {@see LifecycleState::isResumable()} is where that
     * distinction is kept; a `pending` one never produced anything to be out of
     * step with.
     *
     * @return list<Recovery> in the order the passes were reconciled
     *
     * @throws RalphException when the repository cannot be read, or a row
     *         cannot be written
     */
    private function reconcile(Journal $journal, Workbench $workbench, TaskList $tasks): array
    {
        $recoveries = [];

        foreach ($tasks->all() as $task) {
            $record = $journal->generations()->openFor($task->id());

            if ($record === null || !$this->isResumable($record)) {
                continue;
            }

            $recovery = $workbench->recovery()->reconcile($record);
            $recoveries[] = $recovery;

            $this->terminal?->line($recovery->describe());
            $this->terminal?->line('  next: ' . $recovery->nextAction());

            // A reading that needs a person is recorded on the pass as the
            // blocker it is, so that `status` and a later retry read the same
            // reason this terminal was given. The run stops on it — that is the
            // caller's doing, since a resume that carried on to the next task
            // would be answering the question by ignoring it.
            $blocker = $recovery->blocker();

            if (!$recovery->allowsResume() && $blocker !== null) {
                $journal->generations()->block($recovery->record(), $blocker);
            }
        }

        return $recoveries;
    }

    /**
     * Whether a pass is one a resume reconciles.
     *
     * A pass recorded as `running` by a process that has gone is the crash
     * case; one recorded as `interrupted` is the Ctrl+C case. Nothing else is
     * out of step with the repository through no decision of its own.
     */
    private function isResumable(GenerationRecord $record): bool
    {
        return $record->state() === LifecycleState::Running || $record->state()->isResumable();
    }

    /**
     * Give way before a setup step, if a stop has already been asked for.
     *
     * The run's counterpart of {@see TaskPass::stopIfInterrupted()}, and it
     * throws for the same reason: the steps either side of it are the ones that
     * must not half-happen.
     *
     * @throws InterruptionException when the run has been asked to stop
     */
    private function stopUnlessRunning(): void
    {
        $interruption = $this->interruptions->requested();

        if ($interruption !== null) {
            throw InterruptionException::before($interruption, 'the run was set up');
        }
    }

    /**
     * The loop over the tasks this run is to take on.
     *
     * @throws RalphException when a task's outcome could not be recorded
     */
    private function loop(
        RunRecord $run,
        AgentSelection $agent,
        ExecutionAdapter $adapter,
        BaseCommit $base,
        Journal $journal,
        Workbench $workbench,
        RunIntent $intent,
        EventStream $events,
        ?TaskList $tasks = null,
    ): RunReport {
        // Read here unless the opening already had to read it: a retry checks
        // the task it names before a run row is opened, and reading the file
        // twice would record the same reading twice.
        $tasks ??= $this->read($journal, $workbench);
        $snapshot = ChecklistSnapshot::of($tasks, $this->configuration->tasksFile());

        // Read back off the run's row, not out of the configuration this
        // process was handed. The row is where the set was frozen, so this is
        // the set a resumed run is held to as well, and a worker's edit to
        // `.ralph/config.json` changes what the *next* run requires and nothing
        // about this one (section G, item 3).
        $checks = $run->verification();

        // A resume's first act, before a budget is measured or an agent is
        // invoked: what the interrupted run left in the repository is put back
        // in step with what its rows say (section H, item 5).
        $recoveries = $intent->continuesARun()
            ? $this->reconcile($journal, $workbench, $tasks)
            : [];

        $unreconciled = $this->unreconciled($recoveries);

        $selected = $unreconciled === null
            ? $this->selection($tasks, $journal, $intent)
            : [];

        $pass = new TaskPass(
            $this->configuration,
            $journal,
            $workbench,
            $run,
            $agent,
            $adapter,
            $this->verifier,
            $base,
            $checks,
            $this->terminal,
            $this->clock,
            $this->interruptions,
            // A retry grants the configured attempt allowance again; every
            // other intent spends the one the task has left.
            $intent->isRetry() ? $this->attemptsAlreadyMade($journal, $selected) : 0,
        );

        $limits = $this->configuration->limits();
        $results = [];
        $stopped = $unreconciled === null ? null : RunStop::unreconciled($unreconciled);
        $drift = VerificationDrift::none();

        foreach ($selected as $task) {
            // Before the budget and before the task: a run that has been asked
            // to stop takes nothing else on. The pass that was already under
            // way when the signal arrived has given way at its own phase
            // boundary (section H, item 4); this is the next task never being
            // started.
            $interruption = $this->interruptions->requested();

            if ($interruption !== null) {
                $stopped = $this->wasStopped($interruption, $tasks, $results);

                break;
            }

            // Measured from the run's row rather than from this loop's own
            // bookkeeping, and read before the task is taken on rather than
            // after: see {@see RunBudget}. A run with nothing left stops here
            // with its remaining tasks untouched and still pending.
            $budget = RunBudget::of($limits, $run->iterations(), $run->activeSeconds());

            if (!$budget->allowsAnotherTask()) {
                $stopped = RunStop::limitReached($budget, $this->untaken($tasks, $results));

                break;
            }

            $run = $journal->runs()->countIteration($run);
            $started = $this->clock->now();

            $events->taskStarted($task->id(), [
                'description' => $task->description(),
                'iteration' => $run->iterations(),
            ]);

            $result = $pass->execute($task, $snapshot);
            $results[] = $result;

            $run = $journal->runs()->addActiveSeconds(
                $run,
                max(0, $this->clock->now()->getTimestamp() - $started->getTimestamp()),
            );

            $this->report($result);

            // Emitted once the pass has returned, which is once it has written
            // its own rows: the outcome is the state the store now holds, so a
            // consumer acting on this line is acting on something durable
            // rather than on a claim that may yet be revised.
            $events->taskFinished($result->taskId(), $result->state()->value, $result->toArray());

            // The frozen set is what just ran either way; what a worker's edit
            // must not be is unmentioned.
            $drift = $this->noticeDrift($checks, $drift);

            if ($result->stopsTheRun()) {
                // Two different stops, and the difference is whose decision it
                // was: an interrupted pass is the user's, and a blocked one is
                // a question the pass recorded with its own next action.
                $stopped = $result->isInterrupted()
                    ? $this->wasStopped($this->interruptions->requested(), $tasks, $results)
                    : RunStop::decision($result);

                break;
            }
        }

        $stopped ??= $this->whyItEnded($tasks, $results);
        $run = $this->endOfRun($journal, $run, $stopped);

        $report = RunReport::of($run, $agent, $base, $tasks, $results, $stopped, $drift);

        // After the row was closed, so the run the stream says ended is a run
        // the store also says ended. The per-task lines above carry the task
        // detail, so this is the run's own figures and why it stopped.
        $events->runFinished($stopped->kind()->value, [
            'stop' => $stopped->toArray(),
            'completed' => $report->completedCount(),
            'remaining' => $report->remaining(),
            'iterations' => $run->iterations(),
            'active_seconds' => $run->activeSeconds(),
            'verification_drift' => $drift->toArray(),
        ]);

        $this->terminal?->lines([$report->describe(), '  next: ' . $stopped->nextAction()]);

        return $report;
    }

    /**
     * The tasks this run takes on, in the order it takes them.
     *
     * `run` and `resume` work through the checklist's pending items in file
     * order (PROJECT.md section C, item 5). `retry` takes on the one task named
     * and nothing else, so that a task left behind by a stopped run can be
     * tried again without the rest of the checklist being worked through first.
     *
     * @return list<Task>
     *
     * @throws RalphException when a retry names a task that cannot be retried
     */
    private function selection(TaskList $tasks, Journal $journal, RunIntent $intent): array
    {
        $named = $intent->task();

        if ($named === null) {
            return $tasks->pending();
        }

        return [$this->retriable($tasks, $journal, $named)];
    }

    /**
     * The task a retry names, once it is established that retrying it is a
     * thing that can happen.
     *
     * Four refusals, each with what to do instead:
     *
     *  - the checklist does not state the task, so there is nothing to retry
     *    and the identifier is probably a typo or from another project;
     *  - the checklist states it as checked, so retrying would be work nobody
     *    asked for — unticking the box is how a finished task is taken on
     *    again, as a new pass with its own branch (section C, item 8);
     *  - the task has never been run, so there are no attempts to preserve and
     *    `ralph run` is the command that starts one;
     *  - the pass was interrupted rather than failed, and resuming it finishes
     *    what it was doing instead of starting an attempt over the top.
     *
     * @throws ChecklistException when the checklist does not state the task
     * @throws StateException     when the task is not in a state a retry
     *                            continues
     */
    private function retriable(TaskList $tasks, Journal $journal, TaskId $named): Task
    {
        $task = $tasks->find($named);

        if ($task === null) {
            throw new ChecklistException(
                sprintf('%s states no task %s.', $this->configuration->tasksPath(), $named->value()),
                [
                    'Task identifiers are generated from the task\'s own words, so an edited task has a '
                        . 'different one.',
                    'Run `ralph status` to list the tasks this project has, with their identifiers.',
                ],
            );
        }

        if ($task->isChecked()) {
            throw new StateException(
                sprintf('%s is checked off in %s.', $named->value(), $this->configuration->tasksPath()),
                [
                    'A retry continues an unfinished pass; a checked task has nothing outstanding.',
                    sprintf(
                        'To have the work done again, untick the item in %s and run `ralph run`: it is '
                        . 'taken on as a new pass with its own branch, and the finished one keeps its '
                        . 'commit and its evidence.',
                        $this->configuration->tasksPath(),
                    ),
                ],
            );
        }

        $record = $journal->generations()->latestFor($named);

        if ($record === null) {
            throw new StateException(
                sprintf('%s has never been run, so there is no attempt at it to retry.', $named->value()),
                ['Run `ralph run` to take it on; it is the next pending task in file order.'],
            );
        }

        if ($record->state()->isResumable()) {
            throw new StateException(
                sprintf(
                    '%s was interrupted part-way through %s, not attempted unsuccessfully.',
                    $named->value(),
                    $record->phase()?->describe() ?? 'its pass',
                ),
                [
                    'Run `ralph resume` to continue it from where it stopped, with the agent that '
                        . 'started it: nothing earlier in the pass is repeated and no second commit is made.',
                    sprintf('`ralph logs %s` shows what the interrupted attempt had got to.', $named->value()),
                ],
            );
        }

        return $task;
    }

    /**
     * Attempts a retried pass has already recorded.
     *
     * What the retry forgives, so that the task gets the configured allowance
     * again: see {@see TaskPass}'s `$forgivenAttempts`. Zero when the task has
     * no open pass, which leaves the budget exactly as a fresh run would
     * measure it.
     *
     * @param list<Task> $selected the tasks the run is taking on, which for a
     *        retry is the one named
     */
    private function attemptsAlreadyMade(Journal $journal, array $selected): int
    {
        if (count($selected) !== 1) {
            return 0;
        }

        return $journal->generations()->openFor($selected[0]->id())?->attempts() ?? 0;
    }

    /**
     * The first reconciliation that will not let the resume go on, if any.
     *
     * @param list<Recovery> $recoveries
     */
    private function unreconciled(array $recoveries): ?Recovery
    {
        foreach ($recoveries as $recovery) {
            if (!$recovery->allowsResume()) {
                return $recovery;
            }
        }

        return null;
    }

    /**
     * Notice the configuration moving under the run, and say so.
     *
     * The frozen checks of section G, item 3 make a worker's edit unable to
     * replace what this run verifies against; this is what makes the edit
     * unable to pass unmentioned. The file is read again rather than trusted to
     * be what this process loaded, because the thing being looked for is
     * exactly a change made after that load — by the agent that has just been
     * given write access to the repository.
     *
     * Reporting only. A configuration that no longer parses is drift and not a
     * failure: the checks this run is held to were frozen before the file was
     * touched, the tasks after it are verified against that same set, and
     * ending the run would throw away work over a file it is no longer reading.
     *
     * @param VerificationDrift $reported what has already been said, so that an
     *        edit made during the first task is not announced again after each
     *        of the tasks behind it
     */
    private function noticeDrift(VerificationSnapshot $frozen, VerificationDrift $reported): VerificationDrift
    {
        $drift = $this->readDrift($frozen);

        // An edit already reported stays reported, and an unchanged reading
        // does not withdraw it: a file put back after the fact does not unmake
        // the edit, and the report is a record of what happened rather than a
        // last look at the file.
        if (!$drift->hasChanged() || $drift->toArray() === $reported->toArray()) {
            return $reported;
        }

        $this->terminal?->line($drift->describe());

        foreach ($drift->lines() as $line) {
            $this->terminal?->line('  ' . $line);
        }

        $this->terminal?->line('  checks in force: ' . $frozen->describe());

        return $drift;
    }

    /**
     * Compare the frozen checks against what the configuration file says now.
     */
    private function readDrift(VerificationSnapshot $frozen): VerificationDrift
    {
        $file = ConfigurationLoader::fileIn($this->configuration->projectRoot());

        if (!is_file($file)) {
            // A run configured from a file whose file has gone is drift; a run
            // that never had one and still has none is not.
            return $this->configuration->source() === null
                ? VerificationDrift::none()
                : VerificationDrift::unreadable($file . ' is no longer there');
        }

        try {
            return $frozen->compareTo(
                VerificationSnapshot::of((new ConfigurationLoader())->load($this->configuration->projectRoot())),
            );
        } catch (RalphException $failure) {
            return VerificationDrift::unreadable($failure->getMessage());
        }
    }

    /**
     * Read the checklist and record the reading.
     *
     * The reading is recorded before any of it is worked on, for two reasons:
     * a task the file no longer states can then be named
     * ({@see \Celyes\RalphPhp\State\TaskStore::absentSince()}), and a checked
     * item the runner never verified is recorded as the user's completion
     * rather than as the package's claim (section C, item 5).
     *
     * @throws RalphException when the file cannot be read or identified, or the
     *         reading cannot be recorded
     */
    private function read(Journal $journal, Workbench $workbench): TaskList
    {
        $checklist = $workbench->checklist()->read($this->configuration);
        $tasks = TaskList::fromChecklist($checklist, $journal->tasks()->verified());

        // The store decides each task's completion as it records it: an item
        // the checklist states as checked and no pass here verified is the
        // user's completion, not the package's claim.
        $journal->tasks()->recordReading(...ChecklistSnapshot::of($tasks, $checklist->source())->tasks());

        return $tasks;
    }

    /**
     * Close the run's row the way it ended.
     *
     * `interrupted` rather than `completed` when a stop was asked for, which is
     * the state `ralph resume` looks for and the state that keeps the run's
     * counters continuable (section H, items 3 and 4). Everything else is a run
     * that ended on its own terms, however much of the checklist it got
     * through.
     *
     * @throws RalphException when the row cannot be written
     */
    private function endOfRun(Journal $journal, RunRecord $run, RunStop $stopped): RunRecord
    {
        return $this->interruptions->requested() === null
            ? $journal->runs()->complete($run, $stopped->reason(), $stopped->nextAction(), $stopped->kind())
            : $journal->runs()->interrupt($run, $stopped->reason(), $stopped->nextAction(), $stopped->kind());
    }

    /**
     * Why the loop stopped because it was told to, and what it left.
     *
     * The pending count is the same arithmetic {@see self::untaken()} does for
     * a spent budget and for the same reason: "stopped by Ctrl+C" with one task
     * left and with nine left are different situations, and the user who
     * pressed the key is the one who has to decide what to do about them.
     *
     * @param list<TaskResult> $results
     */
    private function wasStopped(?Interruption $interruption, TaskList $tasks, array $results): RunStop
    {
        $pending = max(0, $tasks->pendingCount() - count(array_filter(
            $results,
            static fn (TaskResult $result): bool => $result->isCompleted(),
        )));

        return RunStop::interrupted($interruption, $pending);
    }

    /**
     * How much of the checklist the run never took on.
     *
     * Counted from the reading the run started with, less the tasks it worked
     * on, because that is what is still pending: a run stopped by a limit left
     * them untouched, which is the difference between a limit and a task that
     * was given up on.
     *
     * @param list<TaskResult> $results
     */
    private function untaken(TaskList $tasks, array $results): int
    {
        return max(0, $tasks->pendingCount() - count($results));
    }

    /**
     * Why the loop ran out of work.
     *
     * The three ordinary endings, and the one that is not: a run that reached
     * the end of the checklist having given up on something names it, because
     * section H, item 9's "exhausted attempts" is a stop whether or not it was
     * the loop that stopped. The attempt limit is read from the configuration
     * rather than from a result, since what makes a task given-up-on is the
     * limit it was measured against.
     *
     * @param list<TaskResult> $results
     */
    private function whyItEnded(TaskList $tasks, array $results): RunStop
    {
        if ($tasks->isEmpty()) {
            return RunStop::noTasks($this->configuration->tasksPath());
        }

        if ($results === []) {
            return RunStop::nothingPending($this->configuration->tasksPath());
        }

        $unfinished = array_values(array_filter(
            $results,
            static fn (TaskResult $result): bool => !$result->isCompleted(),
        ));

        if ($unfinished === []) {
            return RunStop::everythingCompleted(count($results), $this->configuration->tasksPath());
        }

        return RunStop::unfinished(
            $unfinished,
            count($results),
            $this->configuration->limits()->attemptsPerTask(),
        );
    }

    /**
     * Say what the run is, before it starts doing it.
     */
    private function announce(
        RunRecord $run,
        AgentSelection $agent,
        BaseCommit $base,
        VerificationSnapshot $checks,
        bool $listening,
        RunIntent $intent,
        ?RunRecord $previous,
        EventStream $events,
    ): void {
        $lines = [
            sprintf('%s: %s, %s', $run->runId(), $agent->describe(), $intent->describe()),
            sprintf('base %s', $base->describe()),
            sprintf('checks: %s', $checks->describe()),
        ];

        // What a continuation inherits, said at the start rather than left to
        // be deduced from a limit that stops the run sooner than the
        // configuration would suggest (section H, item 3).
        if ($previous !== null) {
            $lines[] = sprintf(
                'continuing %s: %d iteration%s and %ds of its budget already spent',
                $previous->runId(),
                $run->carriedIterations(),
                $run->carriedIterations() === 1 ? '' : 's',
                $run->carriedSeconds(),
            );
        }

        // Said once, at the start, and only when it is not true: a user who may
        // have to stop this run should know beforehand that Ctrl+C will end the
        // process where it stands rather than stop the agent and write the run
        // up. The run itself is unaffected, and `ralph resume` reconciles what
        // such a stop leaves behind.
        if (!$listening) {
            $lines[] = 'note: ext-pcntl is not loaded, so Ctrl+C cannot be handled — it will stop '
                . 'this process without stopping the agent or recording the interruption';
        }

        // Said here, not left to be discovered by whatever was going to read
        // the stream: a supervising process watching this run needs to know
        // before the run is under way that there will be nothing to watch. The
        // run itself is unaffected (section I, item 3; {@see EventStream}).
        if ($events->hasFailed()) {
            $lines[] = sprintf(
                'note: the event stream %s cannot be written — %s',
                $events->path(),
                (string) $events->failure(),
            );
        }

        $this->terminal?->lines($lines);
    }

    /**
     * Say what happened to one task.
     */
    private function report(TaskResult $result): void
    {
        $this->terminal?->line($result->describe());

        $blocker = $result->blocker();

        if ($blocker !== null) {
            $this->terminal?->line('  next: ' . $blocker->nextAction());
        }
    }

    /**
     * Close the run's row after a failure, without hiding the failure.
     *
     * A run that is already closed is left alone: the first reason is the true
     * one. A failure to close is swallowed, because it would otherwise replace
     * the failure the caller is about to be told about with a less useful one.
     */
    private function close(Journal $journal, RunRecord $run, EventStream $events, Throwable $failure): void
    {
        try {
            $current = $journal->runs()->get($run->rowId());

            // A stop the user asked for is not a failure of the run, wherever
            // it surfaced. It can escape from outside a pass — a checklist read
            // between tasks, the reconciliation after one — and the row has to
            // say `interrupted` for those too, because that is what resume
            // looks for (section H, item 4).
            if ($failure instanceof InterruptionException) {
                // No pending count: this stop surfaced outside the loop, which
                // is where the count is kept, and a figure guessed at here
                // would read as the checklist's own.
                $stop = RunStop::interrupted($failure->interruption());

                $journal->runs()->interrupt($current, $stop->reason(), $stop->nextAction(), $stop->kind());
                $events->runFinished($stop->kind()->value, ['stop' => $stop->toArray()]);

                return;
            }

            // The failure's own hints are the next action, which is where a
            // setup step that found ambiguous ownership — a branch unrelated
            // work owns, a checkout holding changes that are not the run's to
            // touch — has always said what to do about it (section H, item 9).
            // They are copied onto the row rather than left to the one terminal
            // that saw them.
            $stop = RunStop::failure(
                sprintf('%s: %s', $this->describe($failure), $failure->getMessage()),
                $failure instanceof RalphException ? $failure->hints() : [],
            );

            $journal->runs()->fail($current, $stop->reason(), $stop->nextAction(), $stop->kind());

            // However a run ended, the stream says it ended. A consumer that
            // saw `run.started` and nothing after it cannot tell a crashed run
            // from one still going, which is the state this whole method exists
            // to keep the database out of.
            $events->runFinished($stop->kind()->value, ['stop' => $stop->toArray()]);
        } catch (Throwable) {
            // Nothing useful can be done here, and the failure that matters is
            // the one on its way out.
        }
    }

    /**
     * What kind of thing went wrong, for the stop reason.
     */
    private function describe(Throwable $failure): string
    {
        $parts = explode('\\', $failure::class);

        return (string) end($parts);
    }
}
