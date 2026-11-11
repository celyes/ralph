<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessLibraryException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs a command and collects its result.
 *
 * Every command is an argument array executed with no shell, so neither a task
 * description, a branch name, nor a path with spaces in it can change what gets
 * executed or add a command to it. The working directory is always explicit:
 * the runner never depends on, and never changes, the process's own.
 *
 * It waits for the command, captures both streams, and enforces a timeout. It
 * is what preflight needs to ask `git` and an agent executable a question, and
 * — given an {@see OutputSink} — it is also what runs a coding agent for
 * twenty minutes with its output appearing as it is produced. Those are the
 * same code path on purpose: a separate streaming runner would be a second
 * implementation of the pipe draining, the timeout and the termination, and the
 * one that ran agents would be the one tested least.
 *
 * **The starting, the pipes and the input are symfony/process's.** Draining two
 * pipes while filling a third without deadlocking, writing a briefing larger
 * than a pipe buffer, reaping the child and reading its status are solved
 * problems, and the package that solves them is maintained by people who do
 * that and little else. What stays here is what is specific to this loop: when
 * a command may be started, when it has run too long, what counts as its
 * verdict — and the cancellation below, which no process library does.
 *
 * **It stops when the run is asked to stop.** While the command runs it asks
 * {@see Interruptions} whether a stop has been requested, and on the first one
 * it terminates the command's whole process tree through {@see Terminator},
 * keeps whatever the command had printed, and reports a cancelled result
 * (PROJECT.md section H, item 4). Polling rather than acting in the signal
 * handler is the point: killing a process tree and writing to SQLite are not
 * things to start halfway through an arbitrary opcode. The terminator's verdict
 * is recorded process-wide, because whether the worker is confirmed gone is
 * what decides if the run may release its lock, and that decision is made in a
 * `finally` several layers above this one.
 *
 * **Streaming does not replace collecting.** A chunk goes to the sink and into
 * the result both. The sink is for the user and for the log; the result is what
 * an adapter reads to decide what the agent did, and an adapter cannot read a
 * file it has already streamed past. The cost is that a worker's whole output
 * is held in memory for the length of the command, which for a coding agent's
 * progress chatter is a few hundred kilobytes.
 */
final class ProcessRunner
{
    /**
     * Seconds a command may run when the caller does not say.
     *
     * Short on purpose: the callers that use the default are asking a local
     * program for a fact — a version, a commit SHA — and one that has not
     * answered in half a minute is not going to.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Seconds a terminated command is given to exit before it is killed.
     */
    private const float TERMINATION_GRACE_SECONDS = 2.0;

    /**
     * How long a running command is left alone between turns, and when.
     *
     * Each turn reads the command's pipes, hands whatever arrived to the sink,
     * and asks whether the run has been stopped — so the interval is both how
     * quickly an agent's output reaches the terminal and how quickly a Ctrl+C
     * is acted on. One interval cannot serve both kinds of command this runner
     * is given: a `git rev-parse` answers in two milliseconds and there are
     * dozens of them per task, while a coding agent is watched for twenty
     * minutes. So the wait starts near-immediate and relaxes as the command
     * turns out to be a long one, by the elapsed seconds below.
     *
     * The slowest setting is still fifty turns a second, which is under what a
     * person notices in either direction, and a turn costs a status read and a
     * `stream_select` that returns at once: a twenty-minute agent spends about
     * a second of processor time being watched.
     *
     * @var list<array{float, int}> elapsed seconds, and microseconds to wait
     *      from then on
     */
    private const array POLL_SCHEDULE = [
        [0.05, 200],
        [1.0, 5_000],
        [INF, 20_000],
    ];

    /**
     * @param Interruptions $interruptions where a stop requested from outside
     *        the process is noticed. Process-wide by nature, and defaulted
     *        rather than required for that reason: an adapter builds its own
     *        runner ({@see \Celyes\RalphPhp\Agent\ProcessAdapter}) and would
     *        otherwise run an agent nothing could stop.
     * @param Terminator $terminator what stops a command and its children
     * @param ExecutableFinder $executables how a command that cannot be run at
     *        all is recognised before anything is started
     */
    public function __construct(
        private readonly Interruptions $interruptions = new Interruptions(),
        private readonly Terminator $terminator = new Terminator(),
        private readonly ExecutableFinder $executables = new ExecutableFinder(),
    ) {
    }

    /**
     * Run a command and wait for it.
     *
     * @param list<string> $command the program and its arguments
     * @param string $workingDirectory absolute path the command runs in
     * @param int $timeout seconds before the command is terminated
     * @param array<string, string|false> $environment variables to add to, or
     *        with `false` to remove from, the inherited environment
     * @param string|null $input what the command reads from standard input, or
     *        null for nothing at all
     * @param OutputSink|null $sink where output is forwarded as it arrives, or
     *        null to only collect it. Never closed here: an attempt's log spans
     *        several commands, and the caller that opened it owns it.
     *
     * @throws ProcessException when the command cannot be started
     */
    public function run(
        array $command,
        string $workingDirectory,
        int $timeout = self::DEFAULT_TIMEOUT_SECONDS,
        array $environment = [],
        ?string $input = null,
        ?OutputSink $sink = null,
    ): ProcessResult {
        if ($command === []) {
            throw new InvalidArgumentException('A command must have at least an executable.');
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException('A command timeout must be at least 1 second.');
        }

        // Nothing is started after a stop has been asked for. The loop winds
        // itself up a phase at a time (PROJECT.md section H, item 4) and this
        // is the backstop under all of it: an interruption noticed between two
        // commands must not be answered by starting the second one.
        $pending = $this->interruptions->requested();

        if ($pending !== null) {
            return new ProcessResult(
                $command,
                null,
                '',
                '',
                false,
                0.0,
                $pending,
                Termination::nothingToStop(),
            );
        }

        $this->requireExecutable($command, $workingDirectory);

        $standardOutput = '';
        $errorOutput = '';

        $process = new Process(
            $command,
            $workingDirectory,
            self::environment($environment),
            $input,
            // The timeout is enforced below instead, because the library's own
            // stops the command by signalling it alone: a coding agent's test
            // runner would be left behind, which is the whole subject of
            // {@see Terminator}.
            timeout: null,
        );

        $started = microtime(true);

        try {
            $process->start(function (string $stream, string $chunk) use (&$standardOutput, &$errorOutput, &$sink): void {
                // Handed on before it is appended to anything: the whole value
                // of streaming is that a user sees an agent working, and
                // holding a chunk back until the command's next turn would
                // delay the last line of a worker that then goes quiet.
                $sink = $this->forward($sink, $stream === Process::ERR ? OutputStream::Error : OutputStream::Standard, $chunk);

                if ($stream === Process::ERR) {
                    $errorOutput .= $chunk;

                    return;
                }

                $standardOutput .= $chunk;
            });
        } catch (ProcessLibraryException $exception) {
            throw $this->cannotStart($command, $workingDirectory, [trim($exception->getMessage())]);
        }

        [$timedOut, $cancelledBy, $termination] = $this->wait($process, new StartedCommand($process), $timeout);

        return new ProcessResult(
            $command,
            // A stopped command reported no status of its own: the exit code of
            // a process that was killed would say which signal ended it, which
            // is not the command's verdict on its work and must not be read as
            // one.
            $timedOut || $cancelledBy !== null ? null : self::statusOf($process),
            $standardOutput,
            $errorOutput,
            $timedOut,
            microtime(true) - $started,
            $cancelledBy,
            $termination,
        );
    }

    /**
     * Watch a running command until it ends, overruns, or is cancelled.
     *
     * `Process::isRunning()` is what does the work each turn: it re-reads the
     * command's status, drains both pipes into the callback that was given to
     * `start()`, and reaps the command once it has exited. So a command that
     * finishes has had everything it printed collected by the time this
     * returns, including the part written between the last two turns.
     *
     * @param StartedCommand $managed the same command as {@see Terminator}
     *        needs it, built while it is certainly running: the library stops
     *        reporting a process id once the process has gone, and the children
     *        a worker left behind are found by walking down from that id.
     *
     * @return array{bool, Interruption|null, Termination|null} whether it
     *         overran, the stop that cancelled it, and what stopping its
     *         process tree achieved
     */
    private function wait(Process $process, StartedCommand $managed, int $timeout): array
    {
        $started = microtime(true);
        $deadline = $started + $timeout;

        while ($process->isRunning()) {
            $cancelledBy = $this->interruptions->requested();

            if ($cancelledBy !== null) {
                return [false, $cancelledBy, $this->terminate($managed, ownership: true)];
            }

            $now = microtime(true);

            if ($now >= $deadline) {
                return [true, null, $this->terminate($managed, ownership: false)];
            }

            usleep(self::pause($now - $started));
        }

        return [false, null, null];
    }

    /**
     * How long to leave a command that has been running this long alone for.
     */
    private static function pause(float $elapsed): int
    {
        foreach (self::POLL_SCHEDULE as [$until, $microseconds]) {
            if ($elapsed < $until) {
                return $microseconds;
            }
        }

        return 20_000;
    }

    /**
     * Pass a chunk on, and stop using a sink that throws.
     *
     * A sink is contractually not allowed to throw ({@see OutputSink}), and
     * this exists for when one does anyway. The command is alive and its pipes
     * are half-drained at this point, so letting the exception out would leave
     * a coding agent running with nothing waiting for it — the exception would
     * come out of `isRunning()`, past the timeout and the cancellation check
     * both. Dropping the sink loses the rest of the streamed copy; the result
     * still carries the whole output.
     *
     * Returns the sink to keep using, or null once it has misbehaved.
     */
    private function forward(?OutputSink $sink, OutputStream $stream, string $chunk): ?OutputSink
    {
        if ($sink === null || $chunk === '') {
            return $sink;
        }

        try {
            $sink->write($stream, $chunk);
        } catch (Throwable) {
            return null;
        }

        return $sink;
    }

    /**
     * The command's own verdict on its work, where it reported one.
     *
     * Null for a command that was ended by a signal: the library reports those
     * by the shell's convention, as 128 plus the signal number, and that is a
     * statement about how the process died rather than about the work.
     */
    private static function statusOf(Process $process): ?int
    {
        $status = $process->getExitCode();

        return $status === null || $status < 0 ? null : $status;
    }

    /**
     * Stop a command that overran its timeout or that the run no longer wants.
     *
     * The whole process tree, asked first and killed second
     * ({@see Terminator}). A timeout and a cancellation stop a command in
     * exactly the same way, and the one difference — what the result is called
     * — belongs to the caller.
     *
     * The verdict is returned either way, so that an adapter's log note can say
     * an agent had to be killed. It is recorded process-wide only for a
     * cancellation, and that distinction is the point: what the record decides
     * is whether the run may release the repository lock on its way out
     * (PROJECT.md section H, item 4), and a run that merely timed a task out is
     * not on its way out. It carries on to the next task and gives the lock
     * back when it finishes, as it should.
     *
     * @param bool $ownership whether this stop decides what the run may
     *        release — true when it is a cancellation
     */
    private function terminate(StartedCommand $managed, bool $ownership): Termination
    {
        $termination = $this->terminator->stop($managed, self::TERMINATION_GRACE_SECONDS);

        if ($ownership) {
            Interruptions::recordTermination($termination);
        }

        return $termination;
    }

    /**
     * That the command names a program this machine can run.
     *
     * Asked here rather than left to the library, which answers a command it
     * could not spawn directly by running it through a shell instead — the same
     * answer a shell gives for anything it cannot find, an exit status of 127
     * on a command that never ran. A caller cannot tell that from a program
     * that ran and exited 127, and this package's whole handling of a missing
     * agent or a missing check depends on telling those apart: one is a
     * configuration error naming what to install, the other is a failed check.
     *
     * {@see ExecutableFinder} is the same resolution preflight and the verifier
     * report against, so a command rejected here is rejected for a reason a
     * user has already been shown how to fix.
     *
     * @param list<string> $command
     *
     * @throws ProcessException when nothing executable answers to the name
     */
    private function requireExecutable(array $command, string $workingDirectory): void
    {
        $executable = $command[0];

        if ($this->executables->find($executable, $workingDirectory) !== null) {
            return;
        }

        throw $this->cannotStart($command, $workingDirectory, [
            ExecutableFinder::isSearched($executable)
                ? sprintf('"%s" was not found in %s.', $executable, $this->executables->describeSearchPath())
                : sprintf('"%s" is not an executable file.', $executable),
        ]);
    }

    /**
     * The environment for the child process.
     *
     * The caller's environment is inherited, because that is where an agent's
     * credentials and the user's PATH live, and overridden only where the
     * caller says. Values are never logged.
     *
     * Passed in full, and with a removal for everything else the library would
     * have filled in: left to itself it completes an environment from `$_ENV`
     * and `$_SERVER`, and `$_SERVER` in a command-line process holds PHP's own
     * entries — `PHP_SELF`, `SCRIPT_NAME`, `REQUEST_TIME` — which are not
     * environment variables and have no business in a coding agent's.
     *
     * @param array<string, string|false> $overrides
     * @return array<string, string|false>
     */
    private static function environment(array $overrides): array
    {
        $environment = array_filter(getenv(), 'is_string');

        foreach ($overrides as $name => $value) {
            if ($value === false) {
                unset($environment[$name]);

                continue;
            }

            $environment[$name] = $value;
        }

        /** @var array<string, string|false> $resolved */
        $resolved = $environment;

        foreach (array_keys($_ENV + $_SERVER) as $name) {
            if (!array_key_exists($name, $resolved)) {
                $resolved[(string) $name] = false;
            }
        }

        return $resolved;
    }

    /**
     * @param list<string> $command
     * @param list<string> $hints
     */
    private function cannotStart(array $command, string $workingDirectory, array $hints = []): ProcessException
    {
        if (!is_dir($workingDirectory)) {
            $hints[] = sprintf('Its working directory %s does not exist.', $workingDirectory);
        } else {
            $hints[] = sprintf('Working directory: %s.', $workingDirectory);
        }

        return new ProcessException(
            sprintf('Unable to start "%s".', implode(' ', $command)),
            $hints,
        );
    }
}
