<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Exception\VerificationException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Support\SystemClock;

/**
 * The checks, run as ordinary processes against the task's workspace.
 *
 * The real {@see Verifier}, and the thing a task's completion is actually
 * decided by. PROJECT.md section G, item 2 asks for the configured commands to
 * be run *independently of the worker*, capturing each command, its output, its
 * exit code and its timeout — and every word of that is load-bearing:
 *
 * **Independently.** Ralph PHP starts these processes itself. Nothing an agent
 * printed, claimed, or wrote to a file is consulted; the only thing the agent
 * contributes is the state of the workspace the commands are pointed at. An
 * agent that says the suite passes and an agent that says nothing get the same
 * treatment here.
 *
 * **The commands are the ones passed in.** They were frozen when the run
 * started (section G, item 3), and this class never reaches for
 * `.ralph/config.json` — a worker that edits the `checks` setting mid-run
 * changes what the *next* run requires and nothing about this one.
 *
 * **Capturing is per command.** Each check becomes one {@see CheckRun} with its
 * argument array, where it ran, its status, how long it took, the timeout it
 * was held to, and an excerpt of what it printed. The sequence stops at the
 * first failure and the checks behind it are reported as not run, because
 * "check 3 failed" and "check 3 was never reached" are different facts and the
 * next attempt is told a different thing by each.
 *
 * **A verdict is returned; a setup failure is raised.** A command that ran and
 * exited non-zero, or was killed at its timeout, is a failing report — the task
 * stays unchecked and is retried. A command that could not be started at all
 * raises {@see VerificationException}, which the runner records as a refusal
 * rather than as evidence against work nobody examined.
 *
 * **The excerpt is redacted; the streamed copy is not.** A check runs with the
 * environment the user started `ralph` in, so a suite that prints what it was
 * configured with can print a credential — and the excerpt this class keeps is
 * stored, shown and handed to the next attempt. The run's known secrets are
 * taken out of it (PROJECT.md section I, item 6). What went to the sink while
 * the check was running is a verbatim copy of the command's output and stays
 * one, for the reasons {@see \Celyes\RalphPhp\Log\LogFile} gives.
 *
 * **What is not here.** The commands run one after another, never in parallel:
 * the first failure is supposed to stop the sequence, and two suites writing to
 * one workspace's cache directories at once is a flake nobody can reproduce.
 * Nor is a check's full output written to a file of its own — it is streamed to
 * the sink the caller owns, which for a run is the attempt's log, and the
 * attempt's row already records that path.
 */
final class CommandVerifier implements Verifier
{
    public function __construct(
        private readonly ProcessRunner $processes = new ProcessRunner(),
        private readonly Clock $clock = new SystemClock(),
        private readonly ExecutableFinder $executables = new ExecutableFinder(),
    ) {
    }

    /**
     * Run the checks in order and report what each one did.
     *
     * @param list<VerificationCommand> $checks
     *
     * @throws VerificationException when there is nothing to verify with, the
     *         workspace cannot be entered, or a command could not be started
     */
    public function verify(
        array $checks,
        string $directory,
        ?OutputSink $sink = null,
        ?Secrets $secrets = null,
    ): VerificationReport {
        $secrets ??= Secrets::none();

        if ($checks === []) {
            // Configuration validation rejects an empty `checks` list long
            // before a run starts, so this is a caller that built its own list.
            // Still refused rather than reported as a vacuous pass: a report
            // standing behind nothing would commit on the strength of no
            // evidence at all.
            throw new VerificationException(
                'There are no verification commands to run, so nothing about this work can be established.',
                ['Configure at least one command under "checks".'],
            );
        }

        $workspace = $this->requireWorkspace($directory);
        $runs = [];
        $stopped = false;

        foreach ($checks as $check) {
            if ($stopped) {
                $sink?->note(sprintf('check %s: not run, because an earlier check failed', $check->name()));
                $runs[] = CheckRun::notRun($check, $workspace);

                continue;
            }

            $run = $this->runCheck($check, $workspace, $sink, $secrets);
            $runs[] = $run;
            $stopped = $run->outcome()->stopsTheSequence();
        }

        return VerificationReport::of(...$runs);
    }

    /**
     * Run one check and record what it did.
     *
     * @throws VerificationException when the command could not be started
     */
    private function runCheck(
        VerificationCommand $check,
        string $directory,
        ?OutputSink $sink,
        Secrets $secrets,
    ): CheckRun {
        $sink?->note(sprintf(
            'check %s: %s (in %s, timeout %ds)',
            $check->name(),
            $check->describe(),
            $directory,
            $check->timeout(),
        ));

        $this->requireExecutable($check, $directory);

        $startedAt = Timestamp::now($this->clock);

        try {
            $result = $this->processes->run(
                $check->command(),
                $directory,
                $check->timeout(),
                // No environment overrides. A check inherits the environment the
                // user ran `ralph` in, which is where its PATH, its HOME and
                // whatever a project's test suite needs already live; inventing
                // a different one here would make a check that passes by hand
                // fail under the runner for reasons neither would explain.
                [],
                // Nothing on standard input: a check that stops to ask a
                // question would otherwise hold a run open until its timeout.
                null,
                $sink,
            );
        } catch (ProcessException $failure) {
            throw $this->cannotRun($check, $directory, $failure->getMessage(), $failure->hints());
        }

        $run = CheckRun::of(
            $check,
            self::outcomeOf($result),
            $directory,
            $result->exitCode(),
            $result->seconds(),
            self::outputOf($result->redactedWith($secrets)),
            null,
            $startedAt,
            Timestamp::now($this->clock),
        );

        $sink?->note('check ' . $run->summary());

        return $run;
    }

    /**
     * What one finished command says about the work.
     *
     * A timeout is kept apart from a failure, and neither is read off the exit
     * code alone: a command killed at its limit never reported one.
     */
    private static function outcomeOf(ProcessResult $result): CheckOutcome
    {
        if ($result->timedOut()) {
            return CheckOutcome::TimedOut;
        }

        return $result->succeeded() ? CheckOutcome::Passed : CheckOutcome::Failed;
    }

    /**
     * What the check printed, as one piece of text.
     *
     * Both streams, ordinary output first, because a failing test suite prints
     * its summary to standard output and a crashed one explains itself on
     * standard error — and whichever came last is the part
     * {@see CheckRun::OUTPUT_CHARACTERS} keeps. The interleaving the two streams
     * really had is preserved in the sink's copy, which is the one a person
     * reads; this is the excerpt a next attempt is told about.
     */
    private static function outputOf(ProcessResult $result): string
    {
        $parts = [];

        foreach ([$result->standardOutput(), $result->errorOutput()] as $stream) {
            $trimmed = trim($stream);

            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * The directory the checks will run in, or a refusal naming it.
     *
     * Asked before the first command rather than left to `proc_open`, so that a
     * workspace that was never created reads as the setup failure it is instead
     * of as every configured check failing at once.
     *
     * @throws VerificationException when it is not a directory the commands
     *         could run in
     */
    private function requireWorkspace(string $directory): string
    {
        if (trim($directory) === '') {
            throw new VerificationException('The checks were given no workspace to run in.');
        }

        if (!is_dir($directory)) {
            throw new VerificationException(
                sprintf('The checks cannot run in %s: it is not a directory.', $directory),
                ['The task workspace is created before verification; this one is missing.'],
            );
        }

        return $directory;
    }

    /**
     * That the program a check names exists before it is run.
     *
     * Preflight asked the same question of the project root
     * ({@see \Celyes\RalphPhp\Preflight\Check\VerificationCheck}); this asks it
     * of the workspace, which is a different answer for a check that runs a
     * project's own `vendor/bin` binary — the one case where "it worked in the
     * checkout" and "it works in the worktree" genuinely come apart, and the
     * reason section F, item 5 prepares a workspace at all.
     *
     * @throws VerificationException when nothing executable answers to the name
     */
    private function requireExecutable(VerificationCommand $check, string $directory): void
    {
        $executable = $check->command()[0];

        if ($this->executables->find($executable, $directory) !== null) {
            return;
        }

        throw $this->cannotRun(
            $check,
            $directory,
            ExecutableFinder::isSearched($executable)
                ? sprintf('"%s" was not found in PATH.', $executable)
                : sprintf('"%s" is not an executable file in the workspace.', $executable),
            [
                sprintf('Install "%s", or change "checks" to a command this project can run.', $executable),
                'Dependencies a check needs in a fresh workspace are installed by the "prepare" setting.',
            ],
        );
    }

    /**
     * The refusal for a check that never started.
     *
     * @param list<string> $hints
     */
    private function cannotRun(
        VerificationCommand $check,
        string $directory,
        string $reason,
        array $hints = [],
    ): VerificationException {
        return new VerificationException(
            sprintf(
                'The verification command "%s" (%s) could not be run in %s: %s',
                $check->name(),
                $check->describe(),
                $directory,
                $reason,
            ),
            [...$hints, 'Nothing was verified, so the task was not judged on this.'],
        );
    }
}
