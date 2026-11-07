<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
use InvalidArgumentException;
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
 * **It stops when the run is asked to stop.** Between reads of the worker's
 * output it asks {@see Interruptions} whether a stop has been requested, and on
 * the first one it terminates the command's whole process tree through
 * {@see Terminator}, keeps whatever the command had printed, and reports a
 * cancelled result (PROJECT.md section H, item 4). Polling rather than acting
 * in the signal handler is the point: killing a process tree and writing to
 * SQLite are not things to start halfway through an arbitrary opcode. The
 * terminator's verdict is recorded process-wide, because whether the worker is
 * confirmed gone is what decides if the run may release its lock, and that
 * decision is made in a `finally` several layers above this one.
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
     * The descriptors the command's output is read from.
     */
    private const int STANDARD_OUTPUT = 1;
    private const int ERROR_OUTPUT = 2;

    /**
     * Seconds a terminated command is given to exit before it is killed.
     */
    private const float TERMINATION_GRACE_SECONDS = 2.0;

    /**
     * @param Interruptions $interruptions where a stop requested from outside
     *        the process is noticed. Process-wide by nature, and defaulted
     *        rather than required for that reason: an adapter builds its own
     *        runner ({@see \Celyes\RalphPhp\Agent\ProcessAdapter}) and would
     *        otherwise run an agent nothing could stop.
     * @param Terminator $terminator what stops a command and its children
     */
    public function __construct(
        private readonly Interruptions $interruptions = new Interruptions(),
        private readonly Terminator $terminator = new Terminator(),
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

        $inputFile = $input === null ? null : $this->spool($input);

        $descriptors = [
            // Nothing this package runs is interactive. Reading from a file —
            // the null device when there is no input, a spooled copy of it when
            // there is — rather than inheriting the terminal means a command
            // that decides to prompt sees end-of-input and gives up, instead of
            // sitting on a question nobody is there to answer.
            0 => ['file', $inputFile ?? self::nullDevice(), 'rb'],
            self::STANDARD_OUTPUT => ['pipe', 'wb'],
            self::ERROR_OUTPUT => ['pipe', 'wb'],
        ];

        $started = microtime(true);

        try {
            error_clear_last();
            $process = @proc_open($command, $descriptors, $pipes, $workingDirectory, self::environment($environment));

            if (!is_resource($process)) {
                throw $this->cannotStart($command, $workingDirectory);
            }

            [$standardOutput, $errorOutput, $exitCode, $timedOut, $cancelledBy, $termination]
                = $this->wait($process, $pipes, $timeout, $sink);
        } finally {
            if ($inputFile !== null) {
                @unlink($inputFile);
            }
        }

        return new ProcessResult(
            $command,
            $exitCode,
            $standardOutput,
            $errorOutput,
            $timedOut,
            microtime(true) - $started,
            $cancelledBy,
            $termination,
        );
    }

    /**
     * Write the command's input to a file it can be given as descriptor zero.
     *
     * A write pipe would be the obvious way and is the wrong one: the parent
     * has to drain two output pipes while it fills the input pipe, and a
     * briefing larger than the pipe buffer — which an agent's prompt, with the
     * project context and the previous attempt's evidence in it, comfortably is
     * — blocks the write until the child reads, while the child may be blocked
     * writing output nobody is reading. A file cannot deadlock, and the child
     * sees an ordinary readable stream ending in end-of-input.
     *
     * The file goes in the system temporary directory and never in the task
     * workspace: a file written there would be an untracked change inside a
     * worktree whose diff the runner is about to stage (PROJECT.md section F,
     * item 6). It is removed when the command finishes, however that goes.
     *
     * @throws ProcessException when the input cannot be spooled
     */
    private function spool(string $input): string
    {
        $path = @tempnam(sys_get_temp_dir(), 'ralph-input-');

        if ($path === false || @file_put_contents($path, $input) === false) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw new ProcessException(
                'Unable to write the command\'s standard input to a temporary file.',
                [sprintf('Temporary directory: %s.', sys_get_temp_dir())],
            );
        }

        return $path;
    }

    /**
     * Drain both pipes until the command exits or runs out of time.
     *
     * The pipes are read together rather than one after the other: draining
     * standard output to completion first deadlocks a command that meanwhile
     * fills the error pipe's buffer, which is exactly what a failing verification
     * command does.
     *
     * Each chunk is handed to the sink the moment it is read, before it is
     * appended to anything: the whole value of streaming is that a user sees an
     * agent working, and buffering a chunk until the next loop iteration would
     * hold back the last line of a worker that then goes quiet for a minute.
     *
     * A stop requested from outside the process is noticed here, once per turn
     * of the loop. The wait is capped at a second whatever the command's
     * timeout, so a Ctrl+C during a quiet twenty-minute agent is acted on
     * within a second rather than when the agent next prints something.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{string, string, int|null, bool, Interruption|null, Termination|null}
     */
    private function wait(mixed $process, array $pipes, int $timeout, ?OutputSink $sink = null): array
    {
        $standardOutput = '';
        $errorOutput = '';

        $open = [self::STANDARD_OUTPUT => $pipes[self::STANDARD_OUTPUT], self::ERROR_OUTPUT => $pipes[self::ERROR_OUTPUT]];

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        $cancelledBy = null;

        while ($open !== []) {
            $cancelledBy = $this->interruptions->requested();

            if ($cancelledBy !== null) {
                break;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = $open;
            $write = $except = [];

            // Whole-second granularity is enough for a wait measured in
            // seconds, but the remaining budget is never rounded up past the
            // deadline.
            $wait = min(1.0, $remaining);

            if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1_000_000)) === false) {
                // Interrupted by a signal rather than a broken pipe. The
                // handler has already run by the time this returns, so the top
                // of the loop is where the stop is noticed; there is nothing to
                // do here but go back to it.
                continue;
            }

            foreach ($read as $descriptor => $stream) {
                $chunk = fread($stream, 8192);

                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    unset($open[$descriptor]);

                    continue;
                }

                $sink = $this->forward($sink, $descriptor, $chunk);

                if ($descriptor === self::STANDARD_OUTPUT) {
                    $standardOutput .= $chunk;

                    continue;
                }

                $errorOutput .= $chunk;
            }
        }

        $termination = null;

        if ($timedOut || $cancelledBy !== null) {
            $termination = $this->terminate($process, $cancelledBy !== null);

            // Whatever the command wrote before it was stopped is still worth
            // keeping: it is usually the explanation.
            foreach ($open as $descriptor => $stream) {
                $pending = (string) stream_get_contents($stream);
                $sink = $this->forward($sink, $descriptor, $pending);

                if ($descriptor === self::STANDARD_OUTPUT) {
                    $standardOutput .= $pending;

                    continue;
                }

                $errorOutput .= $pending;
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // A stopped command reported no status of its own: the exit code of a
        // process that was killed would say which signal ended it, which is
        // not the command's verdict on its work and must not be read as one.
        $exitCode = $timedOut || $cancelledBy !== null ? null : $this->exitCodeOf($process);

        proc_close($process);

        return [$standardOutput, $errorOutput, $exitCode, $timedOut, $cancelledBy, $termination];
    }

    /**
     * Pass a chunk on, and stop using a sink that throws.
     *
     * A sink is contractually not allowed to throw ({@see OutputSink}), and
     * this exists for when one does anyway. The child process is alive and its
     * pipes are half-drained at this point, so letting the exception out would
     * skip the close and the `proc_close` below and leave a coding agent
     * running with nothing waiting for it. Dropping the sink loses the rest of
     * the streamed copy; the result still carries the whole output.
     *
     * Returns the sink to keep using, or null once it has misbehaved.
     */
    private function forward(?OutputSink $sink, int $descriptor, string $chunk): ?OutputSink
    {
        if ($sink === null || $chunk === '') {
            return $sink;
        }

        try {
            $sink->write(OutputStream::forDescriptor($descriptor), $chunk);
        } catch (Throwable) {
            return null;
        }

        return $sink;
    }

    /**
     * The command's exit status, waiting for it to be reported.
     *
     * proc_close() would return it too, but only for a process nobody has asked
     * about yet; reading the status first and closing afterwards keeps both
     * calls honest.
     *
     * @param resource $process
     */
    private function exitCodeOf(mixed $process): ?int
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $status = proc_get_status($process);

            if ($status['running'] === false) {
                return $status['exitcode'] === -1 ? null : $status['exitcode'];
            }

            // The pipes are closed, so the child is on its way out; give it the
            // moment it needs to be reaped.
            usleep(1000);
        }

        return null;
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
     * @param resource $process
     * @param bool $ownership whether this stop decides what the run may
     *        release — true when it is a cancellation
     */
    private function terminate(mixed $process, bool $ownership): Termination
    {
        $termination = $this->terminator->stop($process, self::TERMINATION_GRACE_SECONDS);

        if ($ownership) {
            Interruptions::recordTermination($termination);
        }

        return $termination;
    }

    /**
     * The environment for the child process.
     *
     * The caller's environment is inherited, because that is where an agent's
     * credentials and the user's PATH live, and overridden only where the
     * caller says. Values are never logged.
     *
     * @param array<string, string|false> $overrides
     * @return array<string, string>
     */
    private static function environment(array $overrides): array
    {
        $environment = getenv();

        foreach ($overrides as $name => $value) {
            if ($value === false) {
                unset($environment[$name]);

                continue;
            }

            $environment[$name] = $value;
        }

        return array_filter($environment, 'is_string');
    }

    /**
     * @param list<string> $command
     */
    private function cannotStart(array $command, string $workingDirectory): ProcessException
    {
        $hints = [];

        if (!is_dir($workingDirectory)) {
            $hints[] = sprintf('Its working directory %s does not exist.', $workingDirectory);
        } else {
            $hints[] = sprintf('Working directory: %s.', $workingDirectory);
        }

        $reason = error_get_last()['message'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $hints[] = 'PHP reported: ' . trim($reason) . '.';
        }

        return new ProcessException(
            sprintf('Unable to start "%s".', implode(' ', $command)),
            $hints,
        );
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
