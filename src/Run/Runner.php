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
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\ControllingCheckout;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\ChecklistSnapshot;
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
 */
final class Runner
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly Verifier $verifier,
        private readonly AdapterRegistry $adapters,
        private readonly StateBootstrap $state = new StateBootstrap(),
        private readonly ?Output $terminal = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
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
    ): self {
        return new self(
            $configuration,
            $verifier,
            $adapters ?? AdapterRegistry::default(),
            new StateBootstrap(),
            $terminal,
            $clock ?? new SystemClock(),
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
        // Before anything is written: the user's own checkout must have nothing
        // of theirs in it that a run could be blamed for (section F, item 1).
        ControllingCheckout::forProject($this->configuration, $this->executable, $this->processes)
            ->requireRunnable();

        $agent = (new AgentSelector($this->adapters))->select($this->configuration, $requested);
        $adapter = $this->adapters->create($agent);

        $base = BaseResolver::forProject($this->configuration, $this->executable, $this->processes)
            ->resolve();

        $journal = Journal::of($this->state->open($this->configuration), $this->clock);
        $workbench = Workbench::forProject(
            $this->configuration,
            $journal,
            $this->clock,
            $this->executable,
            $this->processes,
        );

        $run = $journal->runs()->open($agent, $this->configuration, $base->sha());

        $this->announce($run, $agent, $base, $run->verification());

        try {
            return $this->loop($run, $agent, $adapter, $base, $journal, $workbench);
        } catch (Throwable $failure) {
            // The run row is closed with the reason before the failure goes on
            // to the caller. A run left recorded as `running` by a process that
            // has gone is the state resume cannot tell from a live one.
            $this->close($journal, $run, $failure);

            throw $failure;
        }
    }

    /**
     * The loop over the checklist's pending tasks.
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
    ): RunReport {
        $tasks = $this->read($journal, $workbench);
        $snapshot = ChecklistSnapshot::of($tasks, $this->configuration->tasksFile());

        // Read back off the run's row, not out of the configuration this
        // process was handed. The row is where the set was frozen, so this is
        // the set a resumed run is held to as well, and a worker's edit to
        // `.ralph/config.json` changes what the *next* run requires and nothing
        // about this one (section G, item 3).
        $checks = $run->verification();

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
        );

        $limit = $this->configuration->limits()->iterations();
        $results = [];
        $stopped = null;
        $drift = VerificationDrift::none();

        foreach ($tasks->pending() as $task) {
            if (count($results) >= $limit) {
                $stopped = sprintf(
                    'the iteration limit of %d was reached with %d task%s still pending',
                    $limit,
                    $tasks->pendingCount() - count($results),
                    $tasks->pendingCount() - count($results) === 1 ? '' : 's',
                );

                break;
            }

            $run = $journal->runs()->countIteration($run);
            $started = $this->clock->now();

            $result = $pass->execute($task, $snapshot);
            $results[] = $result;

            $run = $journal->runs()->addActiveSeconds(
                $run,
                max(0, $this->clock->now()->getTimestamp() - $started->getTimestamp()),
            );

            $this->report($result);

            // The frozen set is what just ran either way; what a worker's edit
            // must not be is unmentioned.
            $drift = $this->noticeDrift($checks, $drift);

            if ($result->stopsTheRun()) {
                $stopped = sprintf('%s needs human input', $result->taskId()->value());

                break;
            }
        }

        $stopped ??= $this->whyItEnded($tasks, $results);
        $run = $journal->runs()->complete($run, $stopped);

        $report = RunReport::of($run, $agent, $base, $tasks, $results, $stopped, $drift);

        $this->terminal?->line($report->describe());

        return $report;
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
     * Why the loop ran out of work.
     *
     * @param list<TaskResult> $results
     */
    private function whyItEnded(TaskList $tasks, array $results): string
    {
        if ($tasks->isEmpty()) {
            return sprintf('%s states no tasks', $this->configuration->tasksPath());
        }

        if ($results === []) {
            return 'there was nothing pending: every task in the checklist is already checked';
        }

        $unfinished = count(array_filter($results, static fn (TaskResult $r): bool => !$r->isCompleted()));

        if ($unfinished > 0) {
            return sprintf(
                'every pending task was taken on; %d of %d did not pass',
                $unfinished,
                count($results),
            );
        }

        return 'every pending task was completed';
    }

    /**
     * Say what the run is, before it starts doing it.
     */
    private function announce(
        RunRecord $run,
        AgentSelection $agent,
        BaseCommit $base,
        VerificationSnapshot $checks,
    ): void {
        $this->terminal?->lines([
            sprintf('%s: %s', $run->runId(), $agent->describe()),
            sprintf('base %s', $base->describe()),
            sprintf('checks: %s', $checks->describe()),
        ]);
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
    private function close(Journal $journal, RunRecord $run, Throwable $failure): void
    {
        try {
            $journal->runs()->fail(
                $journal->runs()->get($run->rowId()),
                sprintf('%s: %s', $this->describe($failure), $failure->getMessage()),
            );
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
