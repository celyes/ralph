<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskList;

/**
 * `ralph status`: what this project's tasks and its last run are.
 *
 * The command for the two questions a user has between runs — "where did it
 * get to" and "what is waiting for me" — and the reason the answers outlive
 * the terminal that first saw them. Everything printed is read back from the
 * checklist on disk and the state store; nothing is recomputed, nothing is
 * guessed, and nothing is written.
 *
 * **It reports in the project's own order.** The tasks are listed as the
 * checklist states them, because that is the order they are worked in and the
 * order the user is reading them in. Each row carries what the state store has
 * against that task: the pass's state and phase, how many attempts it has had,
 * the branch it owns and the commit it produced.
 *
 * **A checked box and a verified commit are not the same claim.** An item the
 * user ticked by hand is shown as theirs and an item a run carried through is
 * shown as the runner's (PROJECT.md section C, item 5), because "done" with no
 * commit behind it is exactly the thing this package must not pass off as its
 * own work.
 *
 * **It works in a project that has never been run**, and in one that was never
 * initialised: the configuration falls back to the defaults, a missing state
 * store means no runs rather than an error, and a checklist that cannot be read
 * is reported as the problem it is instead of taking the command down with it.
 * A diagnostic that only works once everything is already working is not worth
 * having.
 *
 * `status --json` and the structured event stream are PROJECT.md section I,
 * item 3, and are not here yet.
 */
final class StatusCommand extends ProjectCommand
{
    /**
     * Characters of a task description shown in the table.
     */
    private const int DESCRIPTION_WIDTH = 46;

    public function __construct(
        private readonly ChecklistReader $checklists = new ChecklistReader(),
        private readonly StateBootstrap $state = new StateBootstrap(),
        ConfigurationLoader $loader = new ConfigurationLoader(),
    ) {
        parent::__construct($loader);
    }

    public function name(): string
    {
        return 'status';
    }

    public function summary(): string
    {
        return 'Display tasks, IDs, state, attempts, branches, and commit references.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph status',
            '',
            'Prints, for the project in the current directory:',
            '',
            '  the configuration in force, the checklist it reads, and the selected agent',
            '  the latest run: its identifier, state, agent, limits used, and why it stopped',
            '  every task in checklist order, with its identifier, state, attempts, branch',
            '  and commit',
            '',
            'A task checked off by a verified run is reported as the runner\'s; one ticked by',
            'hand is reported as yours, because a box with no commit behind it is not work',
            'this package can vouch for.',
            '',
            'Nothing is written and no agent is started, so this is safe to run at any time,',
            'including while a run is going on in another terminal.',
            '',
            'Exits 0 when nothing is waiting for a person, and 75 when something is: a task',
            'blocked on a decision, a failed pass, or an interrupted run to resume.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $configuration = $this->configurationOrDefaults();
        $journal = $this->journal($configuration);
        $tasks = $this->tasks($configuration, $journal, $output);

        $this->reportProject($configuration, $tasks, $output);

        $run = $journal?->runs()->latest();

        if ($run !== null) {
            $output->line();
            $this->reportRun($run, $output);
        }

        $output->line();
        $passes = $this->reportTasks($tasks, $journal, $output);

        return $this->status($run, $passes);
    }

    /**
     * The state store, when the project has one.
     *
     * Opened rather than created: `status` is a question, and a command that
     * answered "no runs yet" by writing a database and a migration row into a
     * directory would have changed the thing it was asked about.
     */
    private function journal(Configuration $configuration): ?Journal
    {
        $file = StateBootstrap::fileIn($configuration->projectRoot());

        if (!is_file($file)) {
            return null;
        }

        return Journal::of($this->state->open($configuration));
    }

    /**
     * The checklist, as a work list, with what the store has verified.
     *
     * A checklist that cannot be read is reported and the command carries on
     * with no tasks: the run section below is still worth printing, and it is
     * often what explains the missing file.
     */
    private function tasks(Configuration $configuration, ?Journal $journal, Output $output): TaskList
    {
        try {
            return TaskList::fromChecklist(
                $this->checklists->read($configuration),
                $journal?->tasks()->verified(),
            );
        } catch (ChecklistException $failure) {
            $output->diagnosticLine('ralph: ' . $failure->getMessage());

            foreach ($failure->hints() as $hint) {
                $output->diagnosticLine('  ' . $hint);
            }

            return TaskList::of([]);
        }
    }

    /**
     * The project, its checklist and the agent a run here would use.
     */
    private function reportProject(Configuration $configuration, TaskList $tasks, Output $output): void
    {
        $output->line(sprintf('Ralph PHP status for %s', $configuration->projectRoot()));
        $output->line(sprintf(
            '  configuration  %s',
            $configuration->source() ?? 'defaults (no configuration file)',
        ));
        $output->line(sprintf(
            '  checklist      %s — %d task%s, %d checked, %d pending',
            $configuration->tasksPath(),
            $tasks->count(),
            $tasks->count() === 1 ? '' : 's',
            $tasks->doneCount(),
            $tasks->pendingCount(),
        ));
        $output->line(sprintf('  agent          %s (project default)', $configuration->defaultAgent()));
    }

    /**
     * The latest run: what it was, what it used, and why it stopped.
     */
    private function reportRun(RunRecord $run, Output $output): void
    {
        $output->line(sprintf('Latest run %s — %s', $run->runId(), $this->describe($run)));
        $output->line(sprintf('  agent        %s', $run->agent()->describe()));
        $output->line(sprintf(
            '  base         %s at %s',
            $run->baseReference(),
            $run->baseSha() === null ? 'no commit recorded' : substr($run->baseSha(), 0, 12),
        ));
        $output->line(sprintf('  checks       %s', implode(', ', $run->checkNames())));
        $output->line(sprintf(
            '  iterations   %d%s',
            $run->iterations(),
            $run->isResume()
                ? sprintf(' (%d of them inherited from %s)', $run->carriedIterations(), $run->resumedFromRunId() ?? 'an earlier run')
                : '',
        ));
        $output->line(sprintf('  active time  %ds', $run->activeSeconds()));
        $output->line(sprintf('  started      %s', $run->startedAt()->value()));

        $reason = $run->stopReason();

        if ($reason !== null) {
            $output->line(sprintf('  stopped      %s', $reason));
        }

        $action = $run->stopAction();

        if ($action !== null) {
            $output->line(sprintf('  next         %s', $action));
        }
    }

    /**
     * How the latest run reads in a heading.
     *
     * Its stop kind, not its lifecycle state. A run's row holds both, and only
     * one of them is about a run: {@see \Celyes\RalphPhp\State\LifecycleState::describe()}
     * says what a *task* in that state is, so a run that worked through its
     * checklist would be headed "verified, committed and checked off" whether
     * or not any of its tasks passed. A run still going has no kind yet, and
     * the state is exactly right for it.
     */
    private function describe(RunRecord $run): string
    {
        return $run->stopKind()?->describe() ?? $run->state()->describe();
    }

    /**
     * Every task the checklist states, in the order it states them.
     *
     * @return list<GenerationRecord> the passes behind the rows, so the caller
     *         can decide the exit status without reading them again
     */
    private function reportTasks(TaskList $tasks, ?Journal $journal, Output $output): array
    {
        if ($tasks->count() === 0) {
            $output->line('No tasks.');

            return [];
        }

        $output->line('Tasks');
        $output->line(sprintf(
            '  %-11s  %-13s  %-8s  %-12s  %s',
            'state',
            'id',
            'attempts',
            'commit',
            'task',
        ));

        $passes = [];

        foreach ($tasks->all() as $task) {
            $record = $journal?->generations()->latestFor($task->id());

            if ($record !== null) {
                $passes[] = $record;
            }

            $output->line(sprintf(
                '  %-11s  %-13s  %-8s  %-12s  %s',
                $this->stateOf($task, $tasks, $record),
                $task->id()->value(),
                $record === null ? '-' : (string) $record->attempts(),
                $record?->commitSha() === null ? '-' : substr($record->commitSha(), 0, 12),
                $this->shorten($task->description()),
            ));

            foreach ($this->notes($task, $tasks, $record) as $note) {
                $output->line('                 ' . $note);
            }
        }

        return $passes;
    }

    /**
     * What to show in a task's state column.
     *
     * The recorded pass's state when there is one, because it is the specific
     * answer: `failed`, `blocked`, `interrupted` and `running` are all things a
     * checkbox cannot say. A task with no pass recorded has only its checkbox,
     * and that is reported as what it is — the user's completion, or pending.
     */
    private function stateOf(Task $task, TaskList $tasks, ?GenerationRecord $record): string
    {
        if ($record !== null) {
            return $record->state()->value;
        }

        return $tasks->completionOf($task->id())->label();
    }

    /**
     * The lines under a task's row: the phase it is in, the branch it owns,
     * what it is waiting for, and what to do about it.
     *
     * @return list<string>
     */
    private function notes(Task $task, TaskList $tasks, ?GenerationRecord $record): array
    {
        $notes = [];

        if ($record === null) {
            // The one thing a checkbox alone can be wrong about, so it is said
            // rather than left to be assumed: this package did not do this.
            if ($task->isChecked()) {
                $notes[] = $tasks->completionOf($task->id())->describe();
            }

            return $notes;
        }

        $phase = $record->phase();

        if ($phase !== null) {
            // The phase reads as what is happening, so a pass that is not
            // running is said to have stopped there: "waiting for the agent" of
            // a failed pass would describe a worker that is not there.
            $where = $record->state()->isRunning()
                ? $phase->describe()
                : 'stopped while ' . $phase->describe();

            // The generation only when it is not the first, whose label is the
            // task identifier the row already carries.
            $notes[] = $record->generation()->isFirst()
                ? $where
                : sprintf('%s: %s', $record->generation()->label(), $where);
        }

        if ($record->hasBranch()) {
            $notes[] = 'branch ' . (string) $record->branch();
        }

        if ($record->isNoChange()) {
            $notes[] = 'verified with no change needed';
        }

        if (!$record->push()->isSatisfied()) {
            $notes[] = 'push ' . $record->push()->describe();
        }

        $blocker = $record->blocker();

        if ($blocker !== null) {
            $notes[] = 'waiting: ' . $blocker->reason();
            $notes[] = 'next: ' . $blocker->nextAction();

            return $notes;
        }

        if (!$record->state()->isCompleted() && !$record->state()->isRunning()) {
            $notes[] = 'next: ' . str_replace('TASK-ID', $task->id()->value(), $record->state()->nextAction());
        }

        return $notes;
    }

    /**
     * Whether anything in the project is waiting for a person.
     *
     * @param list<GenerationRecord> $passes
     */
    private function status(?RunRecord $run, array $passes): int
    {
        foreach ($passes as $record) {
            if ($record->blocker() !== null || $record->state()->isFailure() || $record->state()->isResumable()) {
                return ExitCode::ATTENTION;
            }
        }

        if ($run !== null && $run->stopKind()?->needsAttention() === true) {
            return ExitCode::ATTENTION;
        }

        return ExitCode::SUCCESS;
    }

    /**
     * A task's description, cut to the table's width.
     */
    private function shorten(string $description): string
    {
        if (mb_strlen($description) <= self::DESCRIPTION_WIDTH) {
            return $description;
        }

        return mb_substr($description, 0, self::DESCRIPTION_WIDTH - 1) . '…';
    }
}
