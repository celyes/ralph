<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use InvalidArgumentException;
use RuntimeException;

/**
 * One scripted run of the fixture worker.
 *
 * PROJECT.md section E, item 12's "deterministic": a step says everything the
 * worker will do, so two runs of the same step produce the same files, the same
 * output and the same exit status. Nothing is read from the clock, the
 * environment or the briefing, and nothing is random.
 *
 * A worker is installed with a *list* of steps and takes the next one each time
 * it is run, repeating the last. That is what makes the loop's own behaviour
 * testable rather than only one attempt of it: "fails, then succeeds" is
 * `[FixtureStep::failure(), FixtureStep::success()]`, which is what section G,
 * item 4 (failure evidence feeding the next attempt) and section H, item 2 (the
 * per-task attempt limit) are about.
 *
 * The knobs are deliberately few, and each one is something the package behaves
 * differently about:
 *
 * - **Edits.** What the attempt leaves in the workspace. A success with no
 *   edits is the already-satisfied task of section G, item 6; a success with
 *   edits is what section F stages and commits; an edit mapped to `null` is a
 *   deletion, so a staged diff can contain one.
 * - **The exit status**, because the package weighs it against what the agent
 *   declared ({@see \Celyes\RalphPhp\Agent\ProcessAdapter::reported()}).
 * - **Reporting nothing**, because "silence is not success" is a rule with
 *   consequences for the whole loop and a fixture that could not be silent
 *   could not test it.
 * - **How long it waits**, for the attempt timeout and the run budget.
 * - **Refusing to stop**, for the escalation from a termination signal to a
 *   kill.
 * - **Interrupting the runner**, for Ctrl+C: the worker signals the process
 *   that started it, so a stop arrives while an agent is genuinely working.
 *
 * Edit paths are relative to the workspace, which is the worker's working
 * directory. `..` is allowed on purpose — section F, item 6 stages the task
 * worktree and nothing else, and a worker that could not write outside it could
 * not demonstrate that — and an absolute path is refused, because a test that
 * means "somewhere else" should say so relative to a workspace a test owns
 * rather than name a path on the machine running the suite.
 */
final class FixtureStep
{
    private const string MODE = 'mode';
    private const string SUMMARY = 'summary';
    private const string NEXT_ACTION = 'next_action';
    private const string STATUS = 'status';
    private const string EDITS = 'edits';
    private const string SECONDS = 'seconds';
    private const string REPORTS = 'reports';
    private const string IGNORES_TERMINATION = 'ignores_termination';
    private const string INTERRUPTS_RUNNER = 'interrupts_runner';

    /**
     * How long a waiting step waits when the test does not say.
     *
     * Long enough that a one-second attempt timeout always runs out first, and
     * not so long that a suite whose worker is somehow never stopped sits for
     * minutes before failing.
     */
    public const int DEFAULT_WAIT_SECONDS = 30;

    /**
     * @param array<string, string|null> $edits workspace-relative path to its
     *        new contents, or to null to delete it
     */
    private function __construct(
        private readonly FixtureMode $mode,
        private readonly string $summary,
        private readonly string $nextAction,
        private readonly int $status,
        private readonly array $edits,
        private readonly int $seconds,
        private readonly bool $reports,
        private readonly bool $ignoresTermination,
        private readonly bool $interruptsTheRunner,
    ) {
    }

    /**
     * An attempt that changes the workspace and reports a candidate.
     *
     * @param array<string, string|null> $edits what it leaves behind
     */
    public static function success(array $edits = []): self
    {
        return self::inMode(FixtureMode::Success)->editing($edits);
    }

    /**
     * An attempt that reports it could not do the work, and exits non-zero.
     */
    public static function failure(string $summary = ''): self
    {
        $step = self::inMode(FixtureMode::Failure);

        return $summary === '' ? $step : $step->saying($summary);
    }

    /**
     * An attempt that asks for a decision only a person can make.
     */
    public static function blocking(string $summary = '', string $nextAction = ''): self
    {
        $step = self::inMode(FixtureMode::Blocking);
        $step = $summary === '' ? $step : $step->saying($summary);

        return $nextAction === '' ? $step : $step->asking($nextAction);
    }

    /**
     * An attempt that takes too long, as an ordinary program does: it waits,
     * and a termination signal kills it where it stands.
     */
    public static function slow(int $seconds = self::DEFAULT_WAIT_SECONDS): self
    {
        return self::inMode(FixtureMode::Slow)->waiting($seconds);
    }

    /**
     * An attempt that waits to be interrupted and records the signal that
     * stopped it.
     */
    public static function interruption(int $seconds = self::DEFAULT_WAIT_SECONDS): self
    {
        return self::inMode(FixtureMode::Interruption)->waiting($seconds);
    }

    /**
     * A step in a mode, with that mode's defaults.
     */
    public static function inMode(FixtureMode $mode): self
    {
        return new self(
            $mode,
            $mode->defaultSummary(),
            $mode->defaultNextAction(),
            $mode->defaultStatus(),
            [],
            $mode->waits() ? self::DEFAULT_WAIT_SECONDS : 0,
            true,
            false,
            false,
        );
    }

    /**
     * The same step, leaving these files in the workspace as well.
     *
     * @param array<string, string|null> $edits workspace-relative path to its
     *        new contents, or to null to delete it
     *
     * @throws InvalidArgumentException when a path is empty or absolute
     */
    public function editing(array $edits): self
    {
        foreach (array_keys($edits) as $path) {
            if (trim($path) === '') {
                throw new InvalidArgumentException('An edit must name a file.');
            }

            if (str_starts_with($path, '/')) {
                throw new InvalidArgumentException(sprintf(
                    'The edit path "%s" is absolute; edits are relative to the workspace the attempt runs in.',
                    $path,
                ));
            }
        }

        return $this->with(edits: [...$this->edits, ...$edits]);
    }

    /**
     * The same step, deleting these files from the workspace.
     */
    public function deleting(string ...$paths): self
    {
        return $this->editing(array_fill_keys($paths, null));
    }

    /**
     * The same step, with this as the summary it reports.
     */
    public function saying(string $summary): self
    {
        return $this->with(summary: $summary);
    }

    /**
     * The same step, with this as the next action it reports.
     */
    public function asking(string $nextAction): self
    {
        return $this->with(nextAction: $nextAction);
    }

    /**
     * The same step, exiting with this status.
     */
    public function exitingWith(int $status): self
    {
        if ($status < 0 || $status > 255) {
            throw new InvalidArgumentException('An exit status is between 0 and 255.');
        }

        return $this->with(status: $status);
    }

    /**
     * The same step, printing prose and no result block at all.
     *
     * The case section 4 rules on: a zero exit status does not finish a task,
     * and an attempt that reported nothing is an execution error whatever it
     * exited with.
     */
    public function reportingNothing(): self
    {
        return $this->with(reports: false);
    }

    /**
     * The same step, waiting this long before it finishes.
     */
    public function waiting(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A step cannot wait for a negative number of seconds.');
        }

        return $this->with(seconds: $seconds);
    }

    /**
     * The same step, sending Ctrl+C to the runner before it waits.
     *
     * The only way an interruption can be tested where it actually happens:
     * PROJECT.md section H, item 4 is about a signal arriving *while an agent
     * is working*, and a test that set the flag beforehand would exercise the
     * loop's bookkeeping while skipping the part that stops a live process. The
     * worker signals its own parent, which is the process that started it — the
     * runner — exactly as a keyboard interrupt in a terminal would reach it.
     *
     * A waiting mode, therefore: the worker must still be there when the runner
     * acts on the signal, or there would be nothing to stop.
     *
     * @throws InvalidArgumentException when the step would have exited first
     */
    public function interruptingTheRunner(): self
    {
        if (!$this->mode->waits()) {
            throw new InvalidArgumentException(sprintf(
                'A step in mode "%s" does not wait, so it would have exited before the interruption it sent '
                    . 'could reach it; use FixtureStep::interruption() or ::slow().',
                $this->mode->value,
            ));
        }

        return $this->with(interruptsTheRunner: true);
    }

    /**
     * The same step, recording a termination signal and carrying on anyway.
     *
     * Only {@see FixtureMode::Interruption} handles signals at all, so this is
     * the worker that has to be killed rather than asked — which is the
     * escalation {@see \Celyes\RalphPhp\Process\ProcessRunner} performs after
     * its grace period, and the one case where "the worker has stopped" is a
     * claim worth checking.
     */
    public function ignoringTermination(): self
    {
        return $this->with(ignoresTermination: true);
    }

    public function mode(): FixtureMode
    {
        return $this->mode;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function nextAction(): string
    {
        return $this->nextAction;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string|null>
     */
    public function edits(): array
    {
        return $this->edits;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    public function reports(): bool
    {
        return $this->reports;
    }

    public function ignoresTermination(): bool
    {
        return $this->ignoresTermination;
    }

    public function interruptsTheRunner(): bool
    {
        return $this->interruptsTheRunner;
    }

    /**
     * The step as the plan file holds it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::MODE => $this->mode->value,
            self::SUMMARY => $this->summary,
            self::NEXT_ACTION => $this->nextAction,
            self::STATUS => $this->status,
            self::EDITS => $this->edits,
            self::SECONDS => $this->seconds,
            self::REPORTS => $this->reports,
            self::IGNORES_TERMINATION => $this->ignoresTermination,
            self::INTERRUPTS_RUNNER => $this->interruptsTheRunner,
        ];
    }

    /**
     * The step a plan file holds.
     *
     * Read by the worker program itself, in the child process, through the same
     * class that wrote it: the plan's field names are declared once, so a
     * renamed knob cannot leave the installed worker reading a key nobody
     * writes and silently doing something else.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws RuntimeException when the plan is not a step this class wrote
     */
    public static function fromArray(array $raw): self
    {
        $mode = FixtureMode::tryFrom(self::string($raw, self::MODE));

        if ($mode === null) {
            throw new RuntimeException(sprintf('The step names the unknown mode "%s".', self::string($raw, self::MODE)));
        }

        $edits = $raw[self::EDITS] ?? [];

        if (!is_array($edits)) {
            throw new RuntimeException('The step\'s edits are not an object.');
        }

        $files = [];

        foreach ($edits as $path => $contents) {
            if (!is_string($path) || !(is_string($contents) || $contents === null)) {
                throw new RuntimeException('An edit is not a path mapped to contents or to null.');
            }

            $files[$path] = $contents;
        }

        return new self(
            $mode,
            self::string($raw, self::SUMMARY),
            self::string($raw, self::NEXT_ACTION),
            self::integer($raw, self::STATUS),
            $files,
            self::integer($raw, self::SECONDS),
            self::boolean($raw, self::REPORTS),
            self::boolean($raw, self::IGNORES_TERMINATION),
            self::boolean($raw, self::INTERRUPTS_RUNNER),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws RuntimeException
     */
    private static function string(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The step\'s "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws RuntimeException
     */
    private static function integer(array $raw, string $key): int
    {
        $value = $raw[$key] ?? null;

        if (!is_int($value)) {
            throw new RuntimeException(sprintf('The step\'s "%s" is not a whole number.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws RuntimeException
     */
    private static function boolean(array $raw, string $key): bool
    {
        $value = $raw[$key] ?? null;

        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('The step\'s "%s" is not true or false.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, string|null>|null $edits
     */
    private function with(
        ?string $summary = null,
        ?string $nextAction = null,
        ?int $status = null,
        ?array $edits = null,
        ?int $seconds = null,
        ?bool $reports = null,
        ?bool $ignoresTermination = null,
        ?bool $interruptsTheRunner = null,
    ): self {
        return new self(
            $this->mode,
            $summary ?? $this->summary,
            $nextAction ?? $this->nextAction,
            $status ?? $this->status,
            $edits ?? $this->edits,
            $seconds ?? $this->seconds,
            $reports ?? $this->reports,
            $ignoresTermination ?? $this->ignoresTermination,
            $interruptsTheRunner ?? $this->interruptsTheRunner,
        );
    }
}
