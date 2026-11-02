<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

use Celyes\RalphPhp\Exception\ProcessException;
use InvalidArgumentException;

/**
 * Runs a command and collects its result.
 *
 * Every command is an argument array executed with no shell, so neither a task
 * description, a branch name, nor a path with spaces in it can change what gets
 * executed or add a command to it. The working directory is always explicit:
 * the runner never depends on, and never changes, the process's own.
 *
 * This is the collecting runner: it waits for the command, captures both
 * streams, and enforces a timeout. It is what preflight needs to ask `git` and
 * an agent executable a question. Streaming a worker's output to the terminal
 * and to its log while it runs is a different job, specified in PROJECT.md
 * section E, and will be built alongside the adapters.
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
     * Run a command and wait for it.
     *
     * @param list<string> $command the program and its arguments
     * @param string $workingDirectory absolute path the command runs in
     * @param int $timeout seconds before the command is terminated
     * @param array<string, string|false> $environment variables to add to, or
     *        with `false` to remove from, the inherited environment
     * @param string|null $input what the command reads from standard input, or
     *        null for nothing at all
     *
     * @throws ProcessException when the command cannot be started
     */
    public function run(
        array $command,
        string $workingDirectory,
        int $timeout = self::DEFAULT_TIMEOUT_SECONDS,
        array $environment = [],
        ?string $input = null,
    ): ProcessResult {
        if ($command === []) {
            throw new InvalidArgumentException('A command must have at least an executable.');
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException('A command timeout must be at least 1 second.');
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

            [$standardOutput, $errorOutput, $exitCode, $timedOut] = $this->wait($process, $pipes, $timeout);
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
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{string, string, int|null, bool}
     */
    private function wait(mixed $process, array $pipes, int $timeout): array
    {
        $standardOutput = '';
        $errorOutput = '';

        $open = [self::STANDARD_OUTPUT => $pipes[self::STANDARD_OUTPUT], self::ERROR_OUTPUT => $pipes[self::ERROR_OUTPUT]];

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while ($open !== []) {
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
                // Interrupted by a signal — Ctrl+C during a run, which section
                // H handles — rather than a broken pipe. Resume waiting.
                continue;
            }

            foreach ($read as $descriptor => $stream) {
                $chunk = fread($stream, 8192);

                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    unset($open[$descriptor]);

                    continue;
                }

                if ($descriptor === self::STANDARD_OUTPUT) {
                    $standardOutput .= $chunk;

                    continue;
                }

                $errorOutput .= $chunk;
            }
        }

        if ($timedOut) {
            $this->terminate($process);

            // Whatever the command wrote before it was stopped is still worth
            // keeping: it is usually the explanation.
            foreach ($open as $descriptor => $stream) {
                $pending = (string) stream_get_contents($stream);

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

        $exitCode = $timedOut ? null : $this->exitCodeOf($process);

        proc_close($process);

        return [$standardOutput, $errorOutput, $exitCode, $timedOut];
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
     * Stop a command that overran its timeout.
     *
     * Asked first, killed second: a terminated command gets the chance to clean
     * up after itself, and only a command that ignores that is killed outright.
     * Stopping the whole process tree of an agent that spawns children is
     * specified in PROJECT.md section H and belongs with interruption handling.
     *
     * @param resource $process
     */
    private function terminate(mixed $process): void
    {
        proc_terminate($process);

        $deadline = microtime(true) + self::TERMINATION_GRACE_SECONDS;

        while (microtime(true) < $deadline) {
            if (proc_get_status($process)['running'] === false) {
                return;
            }

            usleep(10_000);
        }

        proc_terminate($process, 9);
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
