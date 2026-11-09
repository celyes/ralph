<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\StateBootstrap;
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
 * What this cannot promise is that a worker's log holds no secret: it is the
 * agent's own output, captured as it arrived. Redaction of known configured
 * secrets from the package's own diagnostics, and the documented limits of raw
 * worker logs, are PROJECT.md section I, item 6.
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
            'Nothing is written and no agent is started. The logs are the agent\'s own',
            'output, captured as it arrived, so treat them as you would its terminal.',
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

        $logs = new LogDirectory(LogDirectory::directoryIn($configuration->projectRoot()));

        // Newest pass first: a task that was worked on again is being read
        // about because of what happened the last time.
        foreach (array_reverse($passes) as $index => $record) {
            if ($index > 0) {
                $output->line();
            }

            $this->reportPass($record, $journal, $logs, $output);
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
        Output $output,
    ): void {
        $output->line($record->describe());
        $output->line(sprintf(
            '  base %s, %s',
            substr($record->baseSha(), 0, 12),
            $record->hasCommit()
                ? 'commit ' . substr((string) $record->commitSha(), 0, 12)
                : ($record->isNoChange() ? 'verified with no change needed' : 'no commit'),
        ));

        $blocker = $record->blocker();

        if ($blocker !== null) {
            $output->line('  waiting: ' . $blocker->reason());
            $output->line('  next: ' . $blocker->nextAction());
        }

        $attempts = $journal->attempts()->forGeneration($record);

        if ($attempts === []) {
            $output->line('  no attempt was started.');

            return;
        }

        foreach ($attempts as $attempt) {
            $output->line();
            $this->reportAttempt($record, $attempt, $journal, $logs, $output);
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
        Output $output,
    ): void {
        $output->line(sprintf('  %s — %s', $record->slug(), $attempt->describe()));

        $summary = $attempt->summary();

        if ($summary !== null) {
            // The agent's own words, and labelled as the agent's: nothing here
            // has been verified by running anything.
            $output->line('    the agent said (unverified): ' . $summary->text());
        }

        $this->reportWorkerOutput($attempt, $output);
        $this->reportChecks($attempt, $journal, $output);
        $this->reportFiles($record, $attempt, $logs, $output);
    }

    /**
     * The worker's output, verbatim.
     */
    private function reportWorkerOutput(AttemptRecord $attempt, Output $output): void
    {
        $path = $attempt->logPath();

        if ($path === null) {
            $output->line('    no worker log was opened for this attempt.');

            return;
        }

        if (!is_file($path)) {
            $output->line(sprintf('    worker log %s is no longer there.', $path));

            return;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            $output->line(sprintf('    worker log %s could not be read.', $path));

            return;
        }

        $output->line(sprintf('    worker log %s (%d bytes):', $path, strlen($contents)));

        // As bytes, with nothing added: see the class note. A log that does not
        // end in a newline gets one, so the next line of this report starts
        // where it should.
        $output->text($contents);

        if ($contents !== '' && !str_ends_with($contents, "\n")) {
            $output->line();
        }
    }

    /**
     * What the checks decided about this attempt.
     */
    private function reportChecks(AttemptRecord $attempt, Journal $journal, Output $output): void
    {
        $runs = $journal->verifications()->forAttempt($attempt);

        if ($runs === []) {
            $output->line('    no verification command ran against this attempt.');

            return;
        }

        $output->line('    checks:');

        foreach ($runs as $run) {
            $output->line('      ' . $run->describe());

            foreach ($this->outputOf($run) as $line) {
                $output->line('        ' . $line);
            }
        }
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

        foreach ($candidates as $label => $path) {
            if (is_file($path)) {
                $output->line(sprintf('    %s: %s', $label, $path));
            }
        }
    }
}
