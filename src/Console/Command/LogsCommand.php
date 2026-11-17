<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Config\LogSettings;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;

/**
 * `ralph logs TASK-ID`: what was done to one task, and what the checks said.
 *
 * The command a user reaches for when a task did not come out, so it is
 * organised the way that question is asked: newest pass first, each attempt in
 * order, and for each attempt the worker's own output followed by the verdict
 * of the checks that ran against it. A task worked on twice has two passes and
 * both are shown, because the second one's branch is not the first one's and a
 * reader comparing them is doing something reasonable.
 *
 * **The worker's output is printed verbatim, as bytes.** It is not this
 * package's text: reformatting, re-wrapping or re-colouring it would hide
 * exactly the detail somebody is reading it for. The size is reported first, so
 * a reader can decide to open the file in a pager instead.
 *
 * **The checks are read from the state store, not from the log.** What a run
 * decided about an attempt is in `verification_results` — each command, its
 * exit code, its timeout and an excerpt — and that is the record a task's
 * completion rested on. A log says what was printed; the rows say what was
 * concluded.
 *
 * **Every file is named, including the ones that are not output.** The staged
 * diff a blocked pass preserved, the evidence behind a no-change completion and
 * the record behind a ticked box all live beside the logs, and a reader sent to
 * the logs without being told they are there would not find them.
 *
 * **A log that was wiped on purpose says so.** A project with
 * `logs.wipe_on_success` turned on deletes a task's own output once its
 * completion is durable ({@see \Celyes\RalphPhp\Log\LogWiper}), and from the
 * filesystem afterwards that is indistinguishable from a log somebody deleted
 * by hand or one a run never managed to write. The pass's row is what knows
 * which of the three it is — it holds the moment the wipe finished — so the
 * removal is read out of the row and reported as a removal rather than as a
 * loss, and the pass is labelled once at the top so a reader is not left to
 * infer it from a run of absent attempts. The logs of a failed, blocked or
 * interrupted pass are never wiped (PROJECT.md section K, item 12), so an
 * absent log on one of those is still the warning it has always been.
 *
 * **Everything this command composes is redacted; the worker's log is not.**
 * PROJECT.md section I, item 6 asks for two different things and this command
 * is where they meet. The lines it writes — a pass's description, a blocker's
 * question, the agent's claim, a check's excerpt — have the values of the
 * project's declared authentication variables replaced by the variables' names
 * before they reach the terminal ({@see \Celyes\RalphPhp\Console\Output}).
 * The log file's own bytes are printed through {@see
 * \Celyes\RalphPhp\Console\Output::text()} exactly as they were captured,
 * because a copy that rewrote itself would stop being the record it is read as
 * — and because a chunk off a pipe can end in the middle of a value, so the
 * rewriting would be partial anyway.
 *
 * So the honest statement, which {@see self::help()} makes to the user: a raw
 * worker log holds whatever the agent printed. The files are created 0600 under
 * `.ralph/runtime/logs/`, which `ralph init` adds to the project's ignore
 * rules, and they stay there until a successful task's wipe removes them or the
 * directory is deleted by hand. A secret the package was never told about — a
 * token in a remote URL, a key an agent read from a file — cannot be redacted
 * anywhere, by this command or by anything else in the package.
 */
final class LogsCommand extends ProjectCommand
{
    public function __construct(
        private readonly StateBootstrap $state = new StateBootstrap(),
        ConfigurationLoader $loader = new ConfigurationLoader(),
    ) {
        parent::__construct($loader);
    }

    public function name(): string
    {
        return 'logs';
    }

    public function summary(): string
    {
        return 'Display a task\'s execution and verification logs.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph logs TASK-ID',
            '',
            'Prints what was recorded for one task: each pass at it, newest first, and for',
            'each attempt within a pass the worker\'s own output and the result of every',
            'verification command that ran against it.',
            '',
            'The worker\'s output is printed exactly as it was captured. Files beside it are',
            'named rather than printed: the staged diff a stopped pass preserved, the',
            'evidence behind a completion with no change needed, and the record behind a',
            'ticked box.',
            '',
            'Run "ralph status" to list the tasks with their identifiers.',
            '',
            'Nothing is written and no agent is started.',
            '',
            'Where the logs live, and what is in them:',
            '',
            '  Worker output is written to .ralph/runtime/logs/TASK-ID[-N]/, one file per',
            '  attempt, created readable only by you. "ralph init" adds the runtime',
            '  directory to this project\'s ignore rules, so nothing here is committed or',
            '  pushed. Nothing trims or rotates them.',
            '',
            '  With "logs": {"wipe_on_success": true} in .ralph/config.json, a task\'s own',
            '  log files are deleted once its commit, its required push, its state and its',
            '  checklist write are all durable. A log removed that way is reported here as',
            '  wiped, with the moment it went, rather than as a file that is missing. The',
            '  logs of a failed, blocked or interrupted task are kept whatever that setting',
            '  says, and so is everything a completion rests on: the commit, the check',
            '  results above, a preserved diff and the completion evidence.',
            '',
            '  A log is a verbatim copy of what the agent printed, so it holds whatever the',
            '  agent chose to print — source code, file contents, and anything it echoed',
            '  from its own environment. Lines beginning "--- " are Ralph PHP\'s own notes',
            '  about the invocation; everything else is the agent\'s.',
            '',
            '  Values of the variables named in "agents.*.environment" are replaced with the',
            '  variable\'s name in what Ralph PHP writes about a run: its terminal output,',
            '  the notes in these logs, the event stream, the state store and the excerpts',
            '  shown above. They are NOT removed from the raw worker output in the log',
            '  files, because that is a copy of another process\'s bytes rather than text',
            '  this package composed.',
            '',
            '  A secret Ralph PHP was never told about cannot be redacted at all: a token',
            '  inside a Git remote URL, a credential an agent read out of a file, or a key',
            '  printed by your own test suite. Treat these logs as you would the agent\'s',
            '  terminal, and read one before pasting it into an issue.',
        ];
    }

    protected function maximumArguments(): int
    {
        return 1;
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $task = $this->taskArgument($input);
        $configuration = $this->configurationOrDefaults();

        // Before anything is printed, and before the logs are opened: the lines
        // this command composes are redacted, and the log files' own bytes are
        // not. See the class description.
        $secrets = Secrets::forProject($configuration);
        $output->redact($secrets);

        $journal = $this->journal($configuration);

        $passes = $journal->generations()->forTask($task);

        if ($passes === []) {
            throw new StateException(
                sprintf('Nothing has been recorded for %s.', $task->value()),
                [
                    'Only a task a run has taken on has logs; one that is still pending has none.',
                    'Run "ralph status" to list the tasks this project has, with their identifiers.',
                ],
            );
        }

        $logs = new LogDirectory(LogDirectory::directoryIn($configuration->projectRoot()), $secrets);
        $retention = $configuration->logs();

        // Newest pass first: a task that was worked on again is being read
        // about because of what happened the last time.
        foreach (array_reverse($passes) as $index => $record) {
            if ($index > 0) {
                $output->line();
            }

            $this->reportPass($record, $journal, $logs, $retention, $output);
        }

        return ExitCode::SUCCESS;
    }

    /**
     * The state store, which a logs command cannot do without.
     *
     * @throws StateException when the project has no state store
     */
    private function journal(Configuration $configuration): Journal
    {
        $file = StateBootstrap::fileIn($configuration->projectRoot());

        if (!is_file($file)) {
            throw new StateException(
                sprintf('This project has no state store at %s, so nothing has been recorded.', $file),
                ['Run "ralph run" to take a task on; it is written as the run goes.'],
            );
        }

        return Journal::of($this->state->open($configuration));
    }

    /**
     * One pass at the task: what it is, then each of its attempts.
     */
    private function reportPass(
        GenerationRecord $record,
        Journal $journal,
        LogDirectory $logs,
        LogSettings $retention,
        Output $output,
    ): void {
        $style = $output->style();

        $output->line($style->state($record->state(), $record->describe()));
        $output->line($style->muted(sprintf(
            '  base %s, %s',
            substr($record->baseSha(), 0, 12),
            $record->hasCommit()
                ? 'commit ' . substr((string) $record->commitSha(), 0, 12)
                : ($record->isNoChange() ? 'verified with no change needed' : 'no commit'),
        )));

        $wipedAt = $record->logsWipedAt();

        if ($wipedAt !== null) {
            // Muted, not warned: nothing went wrong here. The line is what
            // separates a log this package removed on purpose from one that
            // went missing, and it is said once rather than once an attempt.
            $output->line('  ' . $style->muted(sprintf(
                'logs: wiped %s, after this task succeeded (logs.wipe_on_success).',
                $wipedAt->describe(),
            )));
            $output->line('  ' . $style->muted(
                '  the commit, the checks below and the completion evidence were kept.',
            ));
        }

        $blocker = $record->blocker();

        if ($blocker !== null) {
            $output->line('  ' . $style->warn('waiting: ') . $blocker->reason());
            $output->line('  ' . $style->label('next: ') . $blocker->nextAction());
        }

        $attempts = $journal->attempts()->forGeneration($record);

        if ($attempts === []) {
            $output->line('  ' . $style->muted('no attempt was started.'));

            return;
        }

        foreach ($attempts as $attempt) {
            $output->line();
            $this->reportAttempt($record, $attempt, $journal, $logs, $retention, $output);
        }
    }

    /**
     * One attempt: its outcome, its worker output, its checks, its files.
     */
    private function reportAttempt(
        GenerationRecord $record,
        AttemptRecord $attempt,
        Journal $journal,
        LogDirectory $logs,
        LogSettings $retention,
        Output $output,
    ): void {
        $style = $output->style();

        $output->line(sprintf(
            '  %s — %s',
            $style->identifier($record->slug()),
            $style->state($attempt->state(), $attempt->describe()),
        ));

        $summary = $attempt->summary();

        if ($summary !== null) {
            // The agent's own words, and labelled as the agent's: nothing here
            // has been verified by running anything. The label is marked and
            // the words are not, which is the whole point of the line — the
            // reader has to see whose claim it is before they read it.
            $output->line('    ' . $style->warn('the agent said (unverified):') . ' ' . $summary->text());
        }

        $this->reportWorkerOutput($record, $attempt, $retention, $output);
        $this->reportChecks($attempt, $journal, $output);
        $this->reportFiles($record, $attempt, $logs, $output);
    }

    /**
     * The worker's output, verbatim.
     */
    private function reportWorkerOutput(
        GenerationRecord $record,
        AttemptRecord $attempt,
        LogSettings $retention,
        Output $output,
    ): void {
        $path = $attempt->logPath();

        $style = $output->style();

        if ($path === null) {
            $output->line('    ' . $style->muted('no worker log was opened for this attempt.'));

            return;
        }

        if (!is_file($path)) {
            $output->line('    ' . $this->absence($record, $retention, $path, $style));

            return;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            $output->line('    ' . $style->warn(sprintf('worker log %s could not be read.', $path)));

            return;
        }

        $output->line('    ' . $style->label(sprintf('worker log %s (%d bytes):', $path, strlen($contents))));

        // As bytes, with nothing added: see the class note. A log that does not
        // end in a newline gets one, so the next line of this report starts
        // where it should.
        $output->text($contents);

        if ($contents !== '' && !str_ends_with($contents, "\n")) {
            $output->line();
        }
    }

    /**
     * What to say about a worker log that is not on disk.
     *
     * Two different facts, and the row is the only thing that tells them apart
     * (PROJECT.md section K, item 12). A pass whose logs this package wiped
     * carries the moment it did, so the line names the removal and is dimmed
     * like the rest of what went to plan. A pass with no such moment — which is
     * every failed, blocked and interrupted one, because those are never
     * wiped — has lost a file it was supposed to still have, and that is a
     * warning.
     *
     * The wipe is attributed per attempt as well as per pass. It covers the
     * generation rather than one attempt, so an absent log under a wiped pass
     * went with it; claiming less than that would put a warning under a line
     * that has just explained the absence.
     */
    private function absence(
        GenerationRecord $record,
        LogSettings $retention,
        string $path,
        Style $style,
    ): string {
        $wipedAt = $record->logsWipedAt();

        if ($wipedAt !== null) {
            return $style->muted(sprintf('worker log %s was wiped %s.', $path, $wipedAt->describe()));
        }

        // A wipe writes the moment only once nothing eligible is left, so a
        // pass that succeeded, in a project that wipes, and missing a log is
        // one whose wipe was stopped part way — by a permission, usually — and
        // which the next run will finish. Still not a warning: the file went
        // where the user asked it to go, and the account of why it is absent
        // belongs beside it rather than in whatever they go on to check next.
        if ($retention->wipesOnSuccess() && $record->isComplete() && $record->isFinalizable()) {
            return $style->muted(sprintf(
                'worker log %s was wiped; this pass\'s wipe has not finished, and the next run completes it.',
                $path,
            ));
        }

        return $style->warn(sprintf('worker log %s is no longer there.', $path));
    }

    /**
     * What the checks decided about this attempt.
     */
    private function reportChecks(AttemptRecord $attempt, Journal $journal, Output $output): void
    {
        $runs = $journal->verifications()->forAttempt($attempt);

        $style = $output->style();

        if ($runs === []) {
            $output->line('    ' . $style->muted('no verification command ran against this attempt.'));

            return;
        }

        $output->line('    ' . $style->label('checks:'));

        foreach ($runs as $run) {
            $output->line('      ' . $this->verdict($run, $style));

            foreach ($this->outputOf($run) as $line) {
                $output->line('        ' . $style->muted($line));
            }
        }
    }

    /**
     * One check's line, in the colour of what it decided.
     *
     * A check that did not run is dimmed rather than coloured: it decided
     * nothing, and showing it in red would read as a failing check when the
     * verdict a reader is looking for is on another line.
     */
    private function verdict(CheckRun $run, Style $style): string
    {
        return match ($run->outcome()) {
            CheckOutcome::Passed => $style->pass($run->describe()),
            CheckOutcome::Failed, CheckOutcome::TimedOut => $style->fail($run->describe()),
            CheckOutcome::NotRun => $style->muted($run->describe()),
        };
    }

    /**
     * A check's captured output, as the excerpt the store holds.
     *
     * @return list<string>
     */
    private function outputOf(CheckRun $run): array
    {
        if (!$run->hasOutput()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", rtrim((string) $run->output(), "\n")),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /**
     * The files beside the log, named rather than printed.
     *
     * A diff and two Markdown records, each of which exists only in the
     * circumstance it documents, so an attempt usually has one of the three or
     * none.
     */
    private function reportFiles(
        GenerationRecord $record,
        AttemptRecord $attempt,
        LogDirectory $logs,
        Output $output,
    ): void {
        $candidates = [
            'staged diff' => $logs->stagedDiff($record->generation(), $attempt->number()),
            'no-change evidence' => $logs->satisfactionRecord($record->generation(), $attempt->number()),
            'completion evidence' => $logs->completionRecord($record->generation(), $attempt->number()),
        ];

        $style = $output->style();

        foreach ($candidates as $label => $path) {
            if (is_file($path)) {
                $output->line(sprintf(
                    '    %s %s',
                    $style->label($label . ':'),
                    $style->identifier($path),
                ));
            }
        }
    }
}
