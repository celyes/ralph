<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use JsonException;
use RuntimeException;

/**
 * One line of the fixture worker's journal: something the worker did.
 *
 * The journal is how a test sees what the package did to the worker rather than
 * only what the worker's output said about it. That difference is the whole
 * point for the sections this fixture exists for:
 *
 * - section F needs to know the attempt ran **in the task's worktree** and not
 *   in the controlling checkout, which is {@see self::directory()};
 * - section G needs to know how many attempts there were, which is the number
 *   of {@see self::ATTEMPT} records — a claim about retries that the loop's own
 *   logs would be free to be wrong about;
 * - section H needs to know the worker was **asked** to stop before it was
 *   killed, and that is {@see self::SIGNAL}, which nothing but the worker
 *   itself can report.
 *
 * It lives beside the installed executable rather than in the workspace, which
 * matters: a file written inside a task's worktree would turn up in the diff
 * the runner stages (section F, item 6), and the fixture would be corrupting
 * the thing it is there to test.
 *
 * An attempt's record is written **before** the step runs, so a worker that is
 * killed mid-step still leaves the evidence that it ran. A record is one JSON
 * object on one line, appended under a lock.
 */
final class FixtureRecord
{
    /**
     * The worker was run as an agent: a briefing arrived and a step began.
     */
    public const string ATTEMPT = 'attempt';

    /**
     * The worker was asked to stop, and noticed.
     */
    public const string SIGNAL = 'signal';

    /**
     * The worker was asked for its version, which preflight does before a run.
     */
    public const string VERSION = 'version';

    private const string KIND = 'kind';
    private const string PID = 'pid';
    private const string STEP = 'step';
    private const string MODE = 'mode';
    private const string ARGUMENTS = 'arguments';
    private const string DIRECTORY = 'directory';
    private const string BRIEFING = 'briefing';
    private const string SIGNAL_NUMBER = 'signal_number';

    /**
     * @param list<string> $arguments
     */
    private function __construct(
        private readonly string $kind,
        private readonly int $pid,
        private readonly ?int $step,
        private readonly ?FixtureMode $mode,
        private readonly array $arguments,
        private readonly string $directory,
        private readonly string $briefing,
        private readonly ?int $signal,
    ) {
    }

    /**
     * @param int $step the 1-based step this run is taking
     * @param list<string> $arguments the agent's argument list, as the adapter
     *        built it and without the program itself
     */
    public static function attempt(
        int $step,
        FixtureMode $mode,
        array $arguments,
        string $directory,
        string $briefing,
    ): self {
        return new self(self::ATTEMPT, self::currentPid(), $step, $mode, $arguments, $directory, $briefing, null);
    }

    /**
     * @param list<string> $arguments
     */
    public static function versionQuery(array $arguments, string $directory): self
    {
        return new self(self::VERSION, self::currentPid(), null, null, $arguments, $directory, '', null);
    }

    /**
     * @param int $step the step that was running when the signal arrived
     */
    public static function stoppedBy(int $signal, int $step): self
    {
        return new self(self::SIGNAL, self::currentPid(), $step, null, [], '', '', $signal);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isAttempt(): bool
    {
        return $this->kind === self::ATTEMPT;
    }

    public function isSignal(): bool
    {
        return $this->kind === self::SIGNAL;
    }

    public function isVersionQuery(): bool
    {
        return $this->kind === self::VERSION;
    }

    /**
     * The process the worker ran as.
     *
     * Not deterministic and not meant to be: it is an observation, and the one
     * a test needs to check that a worker which refused to stop is nevertheless
     * gone.
     */
    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Which step of the plan this was, counting from one.
     */
    public function step(): ?int
    {
        return $this->step;
    }

    public function mode(): ?FixtureMode
    {
        return $this->mode;
    }

    /**
     * What the adapter started the worker with, without the program itself.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * The working directory the worker was given.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The briefing the worker read from standard input.
     */
    public function briefing(): string
    {
        return $this->briefing;
    }

    /**
     * The signal that reached the worker, for a {@see self::SIGNAL} record.
     */
    public function signal(): ?int
    {
        return $this->signal;
    }

    /**
     * Append a record to a journal.
     *
     * Locked, because the signal handler of one worker and the start of the
     * next can both be writing, and an interleaved line is a journal a test
     * cannot read at all.
     *
     * @throws RuntimeException when the journal cannot be written
     */
    public static function append(string $journal, self $record): void
    {
        try {
            $line = json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode a fixture worker journal record.', 0, $exception);
        }

        if (file_put_contents($journal, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append to the fixture worker journal at ' . $journal . '.');
        }
    }

    /**
     * Every record in a journal, in the order they were written.
     *
     * A journal that does not exist yet is an empty list: the worker has not
     * been run, which is a fact a test asserts as readily as any other.
     *
     * @return list<self>
     *
     * @throws RuntimeException when the journal exists and cannot be read
     */
    public static function read(string $journal): array
    {
        if (!is_file($journal)) {
            return [];
        }

        $contents = file_get_contents($journal);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the fixture worker journal at ' . $journal . '.');
        }

        $records = [];

        foreach (explode("\n", $contents) as $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    'The fixture worker journal at ' . $journal . ' holds a line that is not JSON.',
                    0,
                    $exception,
                );
            }

            if (!is_array($decoded)) {
                throw new RuntimeException('The fixture worker journal at ' . $journal . ' holds a line that is not a record.');
            }

            $records[] = self::fromArray($decoded);
        }

        return $records;
    }

    /**
     * How many of these records are attempts.
     *
     * @param list<self> $records
     */
    public static function attemptsIn(array $records): int
    {
        return count(array_filter($records, static fn (self $record): bool => $record->isAttempt()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::KIND => $this->kind,
            self::PID => $this->pid,
            self::STEP => $this->step,
            self::MODE => $this->mode?->value,
            self::ARGUMENTS => $this->arguments,
            self::DIRECTORY => $this->directory,
            self::BRIEFING => $this->briefing,
            self::SIGNAL_NUMBER => $this->signal,
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws RuntimeException when the line is not a record this class wrote
     */
    public static function fromArray(array $raw): self
    {
        $kind = $raw[self::KIND] ?? null;

        if (!is_string($kind) || !in_array($kind, [self::ATTEMPT, self::SIGNAL, self::VERSION], true)) {
            throw new RuntimeException('A fixture worker journal record names no known kind.');
        }

        $mode = $raw[self::MODE] ?? null;
        $arguments = $raw[self::ARGUMENTS] ?? [];

        if (!is_array($arguments)) {
            throw new RuntimeException('A fixture worker journal record holds arguments that are not a list.');
        }

        return new self(
            $kind,
            self::integer($raw, self::PID) ?? 0,
            self::integer($raw, self::STEP),
            is_string($mode) ? FixtureMode::tryFrom($mode) : null,
            array_values(array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments)),
            self::text($raw, self::DIRECTORY),
            self::text($raw, self::BRIEFING),
            self::integer($raw, self::SIGNAL_NUMBER),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function integer(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function text(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The running process, as a number a record can hold.
     */
    private static function currentPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }
}
