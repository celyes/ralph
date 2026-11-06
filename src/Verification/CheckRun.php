<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\State\Timestamp;
use InvalidArgumentException;

/**
 * What happened when one configured check was run.
 *
 * One row of `verification_results`, as a value. The command is kept as the
 * argument array that was executed rather than a shell string, so what is
 * reported is what ran; {@see self::describe()} is the display form and is
 * never handed to a process.
 *
 * **The output is an excerpt, and that is deliberate.** A failing test suite
 * prints megabytes, the full output belongs in the attempt's log, and this
 * value is read on every `status` call and fed into the next attempt's bounded
 * evidence. {@see self::logPath()} is where the whole thing is.
 */
final class CheckRun
{
    /**
     * How much of a check's output is kept beside the result.
     *
     * Enough for a stack trace or a failing assertion, which is what a next
     * attempt and a person both need first.
     */
    public const int OUTPUT_CHARACTERS = 2000;

    /**
     * @param list<string>   $command        executable and arguments, as executed
     * @param string|null    $directory      where it ran, when that was recorded
     * @param int|null       $exitCode       its status, when it exited
     * @param int|null       $durationMs     how long it took, when it ran
     * @param int            $timeoutSeconds the limit it was held to
     * @param string|null    $output         excerpt of its combined output
     * @param string|null    $logPath        where the full output was kept
     *
     * @throws InvalidArgumentException when the name or command is empty, the
     *                                 timeout is not positive, or the timing is
     *                                 negative
     */
    private function __construct(
        private readonly string $name,
        private readonly array $command,
        private readonly ?string $directory,
        private readonly CheckOutcome $outcome,
        private readonly ?int $exitCode,
        private readonly ?int $durationMs,
        private readonly int $timeoutSeconds,
        private readonly ?string $output,
        private readonly ?string $logPath,
        private readonly ?Timestamp $startedAt,
        private readonly ?Timestamp $finishedAt,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A check result must name the check it is for.');
        }

        if ($command === []) {
            throw new InvalidArgumentException(sprintf('Check "%s" has no command.', $name));
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'Check "%s" cannot have been held to a %d second timeout.',
                $name,
                $timeoutSeconds,
            ));
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new InvalidArgumentException(sprintf(
                'Check "%s" cannot have taken a negative amount of time.',
                $name,
            ));
        }
    }

    /**
     * The result of a check that was run.
     *
     * @param float|null $seconds how long it took, as the process runner
     *        measured it
     *
     * @throws InvalidArgumentException when the pieces do not describe a check
     */
    public static function of(
        VerificationCommand $check,
        CheckOutcome $outcome,
        ?string $directory = null,
        ?int $exitCode = null,
        ?float $seconds = null,
        string $output = '',
        ?string $logPath = null,
        ?Timestamp $startedAt = null,
        ?Timestamp $finishedAt = null,
    ): self {
        return new self(
            $check->name(),
            $check->command(),
            $directory,
            $outcome,
            $exitCode,
            $seconds === null ? null : (int) round($seconds * 1000),
            $check->timeout(),
            self::excerptOf($output),
            $logPath,
            $startedAt,
            $finishedAt,
        );
    }

    /**
     * The result of a check that was never started.
     *
     * Recorded rather than left out, so the sequence is readable afterwards:
     * what failed, and what was waiting behind it.
     */
    public static function notRun(VerificationCommand $check, ?string $directory = null): self
    {
        return new self(
            $check->name(),
            $check->command(),
            $directory,
            CheckOutcome::NotRun,
            null,
            null,
            $check->timeout(),
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Read a stored row back.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the row does not describe a check
     */
    public static function fromRow(array $row): self
    {
        return new self(
            is_string($row['name'] ?? null) ? $row['name'] : '',
            self::decodeCommand(is_string($row['command'] ?? null) ? $row['command'] : ''),
            is_string($row['working_directory'] ?? null) ? $row['working_directory'] : null,
            CheckOutcome::from(is_string($row['outcome'] ?? null) ? $row['outcome'] : ''),
            isset($row['exit_code']) ? (int) $row['exit_code'] : null,
            isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            (int) ($row['timeout_seconds'] ?? 0),
            is_string($row['output_excerpt'] ?? null) ? $row['output_excerpt'] : null,
            is_string($row['log_path'] ?? null) ? $row['log_path'] : null,
            Timestamp::recorded(is_string($row['started_at'] ?? null) ? $row['started_at'] : null),
            Timestamp::recorded(is_string($row['finished_at'] ?? null) ? $row['finished_at'] : null),
        );
    }

    /**
     * The check's configured label.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The command as executed.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /**
     * The command as a single display string. For logs and status only.
     */
    public function describe(): string
    {
        return implode(' ', $this->command);
    }

    /**
     * Where it ran, when that was recorded.
     */
    public function directory(): ?string
    {
        return $this->directory;
    }

    public function outcome(): CheckOutcome
    {
        return $this->outcome;
    }

    public function isPassed(): bool
    {
        return $this->outcome->isPassed();
    }

    public function isFailure(): bool
    {
        return $this->outcome->isFailure();
    }

    public function ran(): bool
    {
        return $this->outcome->ran();
    }

    /**
     * Its status, or null when it never exited — a timeout, or a check that was
     * not reached.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    /**
     * How long it took, in seconds, or null when it did not run.
     */
    public function seconds(): ?float
    {
        return $this->durationMs === null ? null : $this->durationMs / 1000;
    }

    /**
     * The limit it was held to.
     */
    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * An excerpt of what it printed, or null when it printed nothing worth
     * keeping.
     */
    public function output(): ?string
    {
        return $this->output;
    }

    public function hasOutput(): bool
    {
        return $this->output !== null && $this->output !== '';
    }

    /**
     * Where the full output was kept, when it was kept anywhere.
     */
    public function logPath(): ?string
    {
        return $this->logPath;
    }

    public function startedAt(): ?Timestamp
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?Timestamp
    {
        return $this->finishedAt;
    }

    /**
     * One line for a status table or a log note.
     */
    public function summary(): string
    {
        $summary = sprintf('%s %s', $this->name, $this->outcome->label());

        if ($this->exitCode !== null && !$this->isPassed()) {
            $summary .= sprintf(' (exit %d)', $this->exitCode);
        }

        if ($this->outcome === CheckOutcome::TimedOut) {
            $summary .= sprintf(' (after %ds)', $this->timeoutSeconds);
        }

        $seconds = $this->seconds();

        if ($seconds !== null && $this->isPassed()) {
            $summary .= sprintf(' in %.1fs', $seconds);
        }

        return $summary;
    }

    /**
     * The sentence a failure note is written from.
     */
    public function reason(): string
    {
        return sprintf('`%s` %s', $this->describe(), $this->outcome->describe());
    }

    /**
     * The columns of one `verification_results` row, less the attempt and the
     * position, which belong to the caller writing the sequence.
     *
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'name' => $this->name,
            'command' => json_encode($this->command, JSON_THROW_ON_ERROR),
            'working_directory' => $this->directory,
            'outcome' => $this->outcome->value,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'timeout_seconds' => $this->timeoutSeconds,
            'output_excerpt' => $this->output,
            'log_path' => $this->logPath,
            'started_at' => $this->startedAt?->value(),
            'finished_at' => $this->finishedAt?->value(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'outcome' => $this->outcome->value,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'timeout_seconds' => $this->timeoutSeconds,
            'log_path' => $this->logPath,
        ];
    }

    /**
     * Read a stored command back as the argument array it was.
     *
     * @return list<string>
     */
    private static function decodeCommand(string $json): array
    {
        $decoded = json_decode($json, true);
        $command = [];

        if (!is_array($decoded)) {
            return $command;
        }

        foreach ($decoded as $part) {
            if (is_scalar($part)) {
                $command[] = (string) $part;
            }
        }

        return $command;
    }

    /**
     * Keep the end of long output rather than the start.
     *
     * A failing test suite's useful lines — the assertion, the summary — are at
     * the bottom, and a build that printed a hundred passing lines first would
     * otherwise spend the whole excerpt on them.
     */
    private static function excerptOf(string $output): ?string
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) <= self::OUTPUT_CHARACTERS) {
            return $trimmed;
        }

        return '…' . mb_substr($trimmed, -self::OUTPUT_CHARACTERS);
    }
}
