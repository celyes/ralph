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
use Celyes\RalphPhp\Support\Json;
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
 * the branch it owns, the commit it produced and how its checks came out.
 *
 * **The counts come before the detail, and the checks come from the store.**
 * How much of the checklist is done and how much is left is the first thing
 * anyone wants, so it is one line near the top; and a task's check results are
 * read back from `verification_results` rather than from the configured check
 * list, because what was meant to run and what was decided are different
 * claims. The run section names the frozen checks; the task rows say how they
 * came out.
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
 * **`--json` is the same report for a program** (PROJECT.md section I, item 3).
 * The table above is prose: it abbreviates a commit to twelve characters, cuts a
 * long description, and is reworded whenever a clearer wording is found. The
 * document `--json` prints is the same facts unabbreviated, with a schema
 * version on it, for a wrapper script or an editor extension that has to act on
 * them. The run's own progress as it happens is the event stream
 * ({@see \Celyes\RalphPhp\Event\EventStream}); this is the state it left behind.
 */
final class StatusCommand extends ProjectCommand
{
    /**
     * Version of the `--json` document this build prints.
     *
     * Its own number, moved independently of the event stream's
     * ({@see \Celyes\RalphPhp\Event\Event::SCHEMA}): the two are different
     * documents read by different code, and tying them together would mean
     * every change to one announcing a change to the other. Bumped when a field
     * is removed or its meaning changes; a field being added is not something a
     * reader has to be told about.
     */
    private const int SCHEMA = 1;

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
    protected function options(): array
    {
        return ['json'];
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph status [--json]',
            '',
            'Prints, for the project in the current directory:',
            '',
            '  the configuration in force, the checklist it reads, and the selected agent',
            '  how many tasks are completed and how many remain',
            '  the latest run: its identifier, state, agent, limits used, and why it stopped',
            '  every task in checklist order, with its identifier, state, phase, attempts,',
            '  branch, commit, and what its verification commands decided',
            '',
            'A task checked off by a verified run is reported as the runner\'s; one ticked by',
            'hand is reported as yours, because a box with no commit behind it is not work',
            'this package can vouch for.',
            '',
            'Options:',
            '',
            '  --json  Print the same report as one JSON document, with a schema version,',
            '          unabbreviated commit SHAs and full task descriptions, for a script',
            '          to read. Problems still go to standard error, so the document on',
            '          standard output stays machine-readable.',
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
        $reading = $this->tasks($configuration, $journal, $output);
        $tasks = $reading['tasks'];
        $run = $journal?->runs()->latest();

        // The same facts, printed for whoever asked. Both renderings read the
        // store the same way and both return the passes they read, so the exit
        // status cannot depend on which one was asked for.
        $passes = $input->hasOption('json')
            ? $this->printDocument($configuration, $tasks, $reading['problem'], $run, $journal, $output)
            : $this->printReport($configuration, $tasks, $run, $journal, $output);

        return $this->status($run, $passes);
    }

    /**
     * The report as a person reads it.
     *
     * @return list<GenerationRecord> the passes behind the rows
     */
    private function printReport(
        Configuration $configuration,
        TaskList $tasks,
        ?RunRecord $run,
        ?Journal $journal,
        Output $output,
    ): array {
        $this->reportProject($configuration, $tasks, $output);

        if ($run !== null) {
            $output->line();
            $this->reportRun($run, $output);
        }

        $output->line();

        return $this->reportTasks($tasks, $journal, $output);
    }

    /**
     * The report as a program reads it: one JSON document, on standard output.
     *
     * Everything the table shows, unabbreviated — the whole commit SHA, the
     * whole task description, the phase and completion as the stored words
     * rather than as the sentences they are printed as — plus the run's row and
     * the verification verdicts behind the rows. A consumer that wants the
     * sentences can write its own; one that wants the twelve-character SHA
     * cannot get the other forty-eight back.
     *
     * Problems still go to standard error, and the document carries the
     * checklist's problem as a field as well. `ralph status --json | jq` has to
     * work on a project whose checklist is missing, which is exactly the project
     * someone is most likely to be asking about.
     *
     * @return list<GenerationRecord> the passes behind the rows
     */
    private function printDocument(
        Configuration $configuration,
        TaskList $tasks,
        ?string $problem,
        ?RunRecord $run,
        ?Journal $journal,
        Output $output,
    ): array {
        $rows = [];
        $passes = [];

        foreach ($tasks->all() as $task) {
            $record = $journal?->generations()->latestFor($task->id());

            if ($record !== null) {
                $passes[] = $record;
            }

            $rows[] = $this->taskDocument($task, $tasks, $record, $journal);
        }

        $output->line(Json::document([
            'schema' => self::SCHEMA,
            'project' => [
                'root' => $configuration->projectRoot(),
                'configuration' => $configuration->source(),
                'checklist' => $configuration->tasksPath(),
                'agent' => $configuration->defaultAgent(),
                'checklist_problem' => $problem,
            ],
            'progress' => [
                'total' => $tasks->count(),
                'completed' => $tasks->doneCount(),
                'remaining' => $tasks->pendingCount(),
                // The split that makes the count honest, as two numbers rather
                // than as the clause the table prints: a consumer deciding
                // whether this project has been verified should not have to
                // match on a sentence.
                'verified_here' => count($tasks->runnerVerified()),
                'ticked_by_hand' => count($tasks->userCompleted()),
            ],
            'run' => $run?->toArray(),
            'tasks' => $rows,
            // Read off the same arithmetic the exit status uses, so the field
            // and the status cannot disagree about whether anything is waiting.
            'needs_attention' => $this->status($run, $passes) === ExitCode::ATTENTION,
        ]));

        return $passes;
    }

    /**
     * One task, as the document states it.
     *
     * `state` is null for a task with no recorded pass, because there is no
     * lifecycle state for work that was never taken on, and `completion` is the
     * field that is always there: it says `pending`, `user_completed` or
     * `runner_verified`, which is the distinction a consumer must not lose
     * (PROJECT.md section C, item 5).
     *
     * @return array<string, mixed>
     */
    private function taskDocument(
        Task $task,
        TaskList $tasks,
        ?GenerationRecord $record,
        ?Journal $journal,
    ): array {
        $attempt = null;
        $report = null;

        if ($journal !== null && $record !== null) {
            $attempt = $journal->attempts()->latestFor($record);
            $report = $attempt === null ? null : $journal->verifications()->reportFor($attempt);
        }

        return [
            'task' => $task->id()->value(),
            'description' => $task->description(),
            'line' => $task->line(),
            'checked' => $task->isChecked(),
            'completion' => $tasks->completionOf($task->id())->value,
            'state' => $record?->state()->value,
            'phase' => $record?->phase()?->value,
            'generation' => $record?->generation()->number(),
            'attempts' => $record?->attempts() ?? 0,
            'branch' => $record?->branch(),
            'commit' => $record?->commitSha(),
            'no_change' => $record?->isNoChange(),
            'push' => $record?->push()->state()->value,
            'blocker' => $record?->blocker()?->toStorage(),
            // The attempt the verdicts below belong to, named rather than
            // implied: "the last one" is only useful if a reader can tell
            // which one that was.
            'attempt' => $attempt?->number(),
            'verification' => $report?->toArray(),
        ];
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
     *
     * The problem comes back beside the list as well as going to standard
     * error, because `--json` prints a document whose reader is not watching
     * the terminal.
     *
     * @return array{tasks: TaskList, problem: ?string}
     */
    private function tasks(Configuration $configuration, ?Journal $journal, Output $output): array
    {
        try {
            return [
                'tasks' => TaskList::fromChecklist(
                    $this->checklists->read($configuration),
                    $journal?->tasks()->verified(),
                ),
                'problem' => null,
            ];
        } catch (ChecklistException $failure) {
            $output->diagnosticLine('ralph: ' . $failure->getMessage());

            foreach ($failure->hints() as $hint) {
                $output->diagnosticLine('  ' . $hint);
            }

            return ['tasks' => TaskList::of([]), 'problem' => $failure->getMessage()];
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

        if ($tasks->count() > 0) {
            $output->line(sprintf('  progress       %s', $this->progressOf($tasks)));
        }
    }

    /**
     * How much of the checklist is done, how much is left, and on whose word.
     *
     * Two counts a reader wants before any of the detail — what is finished and
     * what is still coming (PROJECT.md section I, item 2) — and then the split
     * that makes the first of them honest. "3 of 5 completed" is only a useful
     * number if it says how many of the three this package stands behind: a
     * checklist whose boxes were all ticked by hand is at 100% and has had no
     * work done on it at all.
     *
     * The split is dropped when there is nothing to split, so a project that
     * has only ever been run by Ralph PHP, or only ever been ticked by hand,
     * reads as the one plain sentence it is.
     */
    private function progressOf(TaskList $tasks): string
    {
        $progress = sprintf(
            '%d of %d completed, %d remaining',
            $tasks->doneCount(),
            $tasks->count(),
            $tasks->pendingCount(),
        );

        $verified = count($tasks->runnerVerified());
        $ticked = count($tasks->userCompleted());

        if ($verified > 0 && $ticked > 0) {
            return sprintf('%s — %d verified here, %d ticked by hand', $progress, $verified, $ticked);
        }

        if ($ticked > 0) {
            return sprintf('%s — all ticked by hand, none verified here', $progress);
        }

        return $progress;
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

            foreach ($this->notes($task, $tasks, $record, $journal) as $note) {
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
     * how its checks came out, what it is waiting for, and what to do about it.
     *
     * @return list<string>
     */
    private function notes(
        Task $task,
        TaskList $tasks,
        ?GenerationRecord $record,
        ?Journal $journal,
    ): array {
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

        foreach ($this->checks($record, $journal) as $note) {
            $notes[] = $note;
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
     * How the checks came out for the last attempt at a pass.
     *
     * Read from `verification_results`, which is the record the pass's
     * completion rested on — not from the configured check list, which says
     * only what was meant to run. The run section above names the frozen
     * checks; this says what they decided (PROJECT.md section I, item 2), and
     * the two lines being separate is the point: a check that was configured
     * and never reached is a different thing from one that passed.
     *
     * The last attempt's, because that is the one that stands: a task that
     * failed twice and then passed is passing, and the earlier verdicts are
     * what `ralph logs TASK-ID` is for. Each check is named individually only
     * when there is more than one of them, since a single check's summary says
     * the same thing the headline already did.
     *
     * @return list<string>
     */
    private function checks(GenerationRecord $record, ?Journal $journal): array
    {
        if ($journal === null) {
            return [];
        }

        $attempt = $journal->attempts()->latestFor($record);

        if ($attempt === null) {
            return [];
        }

        $report = $journal->verifications()->reportFor($attempt);

        if ($report === null) {
            // Said rather than left out. A pass with no verdict behind it is
            // the case where an unchecked box is right and a reader would
            // otherwise go looking for the failing check that explains it.
            return [sprintf('checks: none ran against attempt %d', $attempt->number())];
        }

        $notes = [sprintf('checks: %s', $report->describe())];

        if ($report->count() > 1) {
            foreach ($report->details() as $detail) {
                $notes[] = '  ' . $detail;
            }
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
