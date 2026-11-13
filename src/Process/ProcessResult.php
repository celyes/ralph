<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

use Celyes\RalphPhp\Support\Secrets;

/**
 * What running one command produced.
 *
 * Carries the argument array that was executed alongside the output, so that a
 * log line or an error message can state exactly what ran rather than a
 * reconstruction of it.
 *
 * **It can be asked to forget a secret it captured.** A command this package
 * started may print a credential the package itself gave it, and what is
 * captured here is the source of the evidence, claims and excerpts that are
 * then stored and printed. {@see self::redactedWith()} is where that is taken
 * out, once, at the point of capture — so everything downstream is redacted
 * without having to remember to be (PROJECT.md section I, item 6).
 *
 * **Three ways a command can end, not two.** It finished, it overran its
 * timeout, or the run it belonged to was interrupted — and the third is not a
 * failure of the command at all. Keeping it apart from a timeout is what lets
 * an adapter report a cancelled attempt as cancelled (PROJECT.md section H,
 * item 4) rather than have a user raise a limit that was never reached.
 */
final class ProcessResult
{
    /**
     * Bytes of each stream {@see self::describe()} renders.
     *
     * A command's output is as long as the command made it: a worker killed
     * mid-run, or a check whose test runner looped, can leave hundreds of
     * megabytes here. Rendering all of it helps nobody — and is big enough to
     * end the process doing the rendering, because `sprintf()` refuses a
     * result it cannot size and does it as a fatal error rather than an
     * exception. So a description shows the head and the tail of a stream that
     * long and says how much it left out.
     */
    private const int DESCRIBED_BYTES = 64 * 1024;

    /**
     * @param list<string> $command the argument array that was executed
     * @param int|null $exitCode the process's status, or null when it was
     *        killed for exceeding its timeout and so never reported one
     * @param float $seconds wall-clock duration
     * @param Interruption|null $cancelledBy the stop the run was asked to make
     *        while this command was running, or null when it ran to its own
     *        conclusion
     * @param Termination|null $termination what stopping the command's process
     *        tree achieved, where it had to be stopped at all
     */
    public function __construct(
        private readonly array $command,
        private readonly ?int $exitCode,
        private readonly string $standardOutput,
        private readonly string $errorOutput,
        private readonly bool $timedOut = false,
        private readonly float $seconds = 0.0,
        private readonly ?Interruption $cancelledBy = null,
        private readonly ?Termination $termination = null,
    ) {
    }

    /**
     * The same result with every known secret replaced by its variable name.
     *
     * Applied to the captured streams and to the argument array, which are the
     * three parts of a result that reach a diagnostic: an error message quotes
     * the command, and the evidence, the agent's claim and a check's excerpt
     * all come out of the output. Everything else — the status, the duration,
     * what stopped it — is a number or an observation of this package's own.
     *
     * The caller is whoever ran a process that had been handed credentials:
     * {@see \Celyes\RalphPhp\Agent\ProcessAdapter::execute()} does it for the
     * agent, which is the one process this package authenticates. What was
     * already streamed to the attempt's log is not affected and is not meant to
     * be: see {@see \Celyes\RalphPhp\Log\LogFile}.
     */
    public function redactedWith(Secrets $secrets): self
    {
        if ($secrets->isEmpty()) {
            return $this;
        }

        return new self(
            $secrets->redactLines($this->command),
            $this->exitCode,
            $secrets->redact($this->standardOutput),
            $secrets->redact($this->errorOutput),
            $this->timedOut,
            $this->seconds,
            $this->cancelledBy,
            $this->termination,
        );
    }

    /**
     * The same result with its standard output replaced.
     *
     * For the one caller that has a better text than the bytes it captured:
     * {@see \Celyes\RalphPhp\Agent\ProcessAdapter::decode()}, where an agent
     * whose non-interactive output is a machine format hands over the words
     * that format carried ({@see \Celyes\RalphPhp\Agent\ClaudeStream}). Only
     * the output changes — the command, the status, the duration and what
     * stopped it are the package's own observations and are not an adapter's to
     * restate.
     *
     * The error output is left alone deliberately. It is not part of a
     * structured stream: a CLI writes its warnings and its own failures there,
     * and that is the one text a decoding must not touch, since a session that
     * failed before it emitted anything says so only there.
     */
    public function withStandardOutput(string $standardOutput): self
    {
        if ($standardOutput === $this->standardOutput) {
            return $this;
        }

        return new self(
            $this->command,
            $this->exitCode,
            $standardOutput,
            $this->errorOutput,
            $this->timedOut,
            $this->seconds,
            $this->cancelledBy,
            $this->termination,
        );
    }

    /**
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function standardOutput(): string
    {
        return $this->standardOutput;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Whether the command was killed for running longer than its timeout.
     */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * Whether the run was interrupted while this command was running.
     */
    public function wasCancelled(): bool
    {
        return $this->cancelledBy !== null;
    }

    /**
     * The stop that cut the command short, or null when nothing did.
     */
    public function cancelledBy(): ?Interruption
    {
        return $this->cancelledBy;
    }

    /**
     * What stopping the command's process tree achieved, where it was stopped.
     *
     * Null for a command that ended on its own. Present for a timeout and for
     * a cancellation, and the reason it is kept on the result is that whether
     * the worker is confirmed gone decides what the run may release
     * (PROJECT.md section H, item 4).
     */
    public function termination(): ?Termination
    {
        return $this->termination;
    }

    /**
     * Whether the command finished and reported success.
     *
     * A cancelled command did not: it was stopped partway, and nothing it was
     * asked to do can be assumed done.
     */
    public function succeeded(): bool
    {
        return !$this->timedOut && !$this->wasCancelled() && $this->exitCode === 0;
    }

    /**
     * The ordinary output with surrounding whitespace removed.
     *
     * Most of the commands this package runs for its own purposes — resolving a
     * reference, reading a version — answer with a single line, and every
     * caller of those wants it without the trailing newline.
     */
    public function trimmedOutput(): string
    {
        return trim($this->standardOutput);
    }

    /**
     * The first line of output, trimmed.
     */
    public function firstOutputLine(): string
    {
        return self::firstLineOf($this->trimmedOutput());
    }

    /**
     * The command's own explanation of a failure, as a single line.
     *
     * Git and Composer both report the cause on the first line of their error
     * output and may follow it with several lines of advice; a check that is
     * already printing its own hints wants the cause only. Standard output is
     * the fallback for a command that failed without writing to stderr.
     */
    public function failureReason(): string
    {
        if ($this->cancelledBy !== null) {
            return sprintf('it was stopped after %s seconds: %s', self::describeSeconds($this->seconds), $this->cancelledBy->reason());
        }

        if ($this->timedOut) {
            return sprintf('it did not finish within %s seconds', self::describeSeconds($this->seconds));
        }

        foreach ([$this->errorOutput, $this->standardOutput] as $stream) {
            $line = self::firstLineOf(trim($stream));
            if ($line !== '') {
                return self::withoutFatalPrefix($line);
            }
        }

        return sprintf('it exited with status %d', $this->exitCode ?? -1);
    }

    /**
     * A readable rendering of the whole invocation, for diagnostics.
     *
     * Readable rather than complete: each stream is shown up to
     * {@see self::DESCRIBED_BYTES}. The result itself still carries every byte
     * that was captured, for a caller that wants it.
     */
    public function describe(): string
    {
        return sprintf(
            "command: %s\nexit code: %s\nstdout:\n%s\nstderr:\n%s",
            implode(' ', $this->command),
            match (true) {
                $this->cancelledBy !== null => 'cancelled',
                $this->timedOut => 'timed out',
                default => (string) ($this->exitCode ?? -1),
            },
            self::describedStream($this->standardOutput),
            self::describedStream($this->errorOutput),
        );
    }

    /**
     * A duration without a pointless fractional part: "20" rather than "20.0",
     * while a sub-second one keeps its tenth.
     */
    private static function describeSeconds(float $seconds): string
    {
        $formatted = sprintf('%.1f', $seconds);

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }

    private static function firstLineOf(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return trim(explode("\n", str_replace("\r\n", "\n", $text))[0]);
    }

    /**
     * One stream as a description shows it.
     *
     * The head and the tail, because both are read for: a failure explains
     * itself at the start, and a command that was killed was in the middle of
     * saying something at the end. Cut on character boundaries — `mb_strcut()`
     * measures in bytes, so the budget stays a byte budget and nothing scans
     * the whole stream to apply it.
     */
    private static function describedStream(string $stream): string
    {
        if ($stream === '') {
            return '  (empty)';
        }

        $length = strlen($stream);

        if ($length <= self::DESCRIBED_BYTES) {
            return $stream;
        }

        $half = intdiv(self::DESCRIBED_BYTES, 2);
        $head = mb_strcut($stream, 0, $half);
        $tail = mb_strcut($stream, $length - $half);

        return sprintf(
            "%s\n  (%d bytes left out of %d)\n%s",
            $head,
            $length - strlen($head) - strlen($tail),
            $length,
            $tail,
        );
    }

    /**
     * Drop Git's "fatal: " marker, which would read oddly inside a sentence of
     * ours that already says the command failed.
     */
    private static function withoutFatalPrefix(string $line): string
    {
        foreach (['fatal: ', 'error: '] as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return substr($line, strlen($prefix));
            }
        }

        return $line;
    }
}
