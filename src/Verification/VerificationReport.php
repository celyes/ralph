<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\State\FailureEvidence;
use InvalidArgumentException;

/**
 * The result of running a task's configured checks, in the order they ran.
 *
 * The verdict the runner acts on. It is a sequence rather than a boolean
 * because the order is evidence: the first failure is what stopped the run, and
 * the checks behind it were never reached.
 *
 * **Passing means every check passed.** Not "none failed": a report in which
 * one check passed and three were never reached is not a pass, and a report
 * with no checks in it cannot be constructed at all. That is the same rule
 * {@see \Celyes\RalphPhp\State\AttemptStore::verificationSupports()} applies to
 * the stored rows, stated once more here so a caller holding the report does
 * not have to go back to the database to find out whether it may commit.
 */
final class VerificationReport
{
    /**
     * @param list<CheckRun> $runs
     */
    private function __construct(private readonly array $runs)
    {
    }

    /**
     * The report for a sequence of checks.
     *
     * @throws InvalidArgumentException when there are no checks in it. A report
     *         that nothing was verified is not a thing the runner may be
     *         handed: it would pass every emptiness test a boolean verdict
     *         could offer while standing behind nothing at all.
     */
    public static function of(CheckRun ...$runs): self
    {
        if ($runs === []) {
            throw new InvalidArgumentException(
                'A verification report must contain at least one check. '
                . 'Nothing having been run is not the same as nothing having failed.',
            );
        }

        return new self(array_values($runs));
    }

    /**
     * Every check, in the order it was reached.
     *
     * @return list<CheckRun>
     */
    public function runs(): array
    {
        return $this->runs;
    }

    public function count(): int
    {
        return count($this->runs);
    }

    /**
     * Whether the work may be committed.
     */
    public function passed(): bool
    {
        foreach ($this->runs as $run) {
            if (!$run->isPassed()) {
                return false;
            }
        }

        return true;
    }

    public function failed(): bool
    {
        return !$this->passed();
    }

    /**
     * The checks that decided against the work.
     *
     * @return list<CheckRun>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->runs, static fn (CheckRun $run): bool => $run->isFailure()));
    }

    /**
     * The check that stopped the sequence, or null when none did.
     */
    public function firstFailure(): ?CheckRun
    {
        return $this->failures()[0] ?? null;
    }

    /**
     * The checks that were never reached.
     *
     * @return list<CheckRun>
     */
    public function notRun(): array
    {
        return array_values(array_filter(
            $this->runs,
            static fn (CheckRun $run): bool => !$run->ran(),
        ));
    }

    public function passedCount(): int
    {
        return count(array_filter($this->runs, static fn (CheckRun $run): bool => $run->isPassed()));
    }

    /**
     * How long the whole sequence took, as far as it got.
     */
    public function seconds(): float
    {
        $total = 0.0;

        foreach ($this->runs as $run) {
            $total += $run->seconds() ?? 0.0;
        }

        return $total;
    }

    /**
     * What the next attempt is told about this one.
     *
     * The failing check's name, its command and an excerpt of its output —
     * bounded by {@see FailureEvidence}, because this goes into a prompt and an
     * unbounded test log would crowd out the task itself. A passing report has
     * nothing to say here.
     */
    public function evidence(): FailureEvidence
    {
        $evidence = FailureEvidence::none();

        foreach ($this->failures() as $failure) {
            $evidence = $evidence->with(
                sprintf('verification %s: %s', $failure->outcome()->label(), $failure->name()),
                trim(sprintf("%s\n%s", $failure->reason(), $failure->output() ?? '')),
            );
        }

        $skipped = $this->notRun();

        if ($skipped !== []) {
            $evidence = $evidence->with(
                'checks not reached',
                implode(', ', array_map(static fn (CheckRun $run): string => $run->name(), $skipped)),
            );
        }

        return $evidence;
    }

    /**
     * One line for the terminal and the attempt's log.
     */
    public function describe(): string
    {
        if ($this->passed()) {
            return sprintf(
                '%d of %d checks passed in %.1fs',
                $this->passedCount(),
                $this->count(),
                $this->seconds(),
            );
        }

        $failure = $this->firstFailure();

        if ($failure === null) {
            return sprintf('%d of %d checks passed', $this->passedCount(), $this->count());
        }

        return sprintf(
            '%s %s; %d of %d checks passed',
            $failure->name(),
            $failure->outcome()->describe(),
            $this->passedCount(),
            $this->count(),
        );
    }

    /**
     * Every check as a line, for `ralph logs` and the failure path.
     *
     * @return list<string>
     */
    public function details(): array
    {
        return array_map(static fn (CheckRun $run): string => $run->summary(), $this->runs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'checks' => array_map(static fn (CheckRun $run): array => $run->toArray(), $this->runs),
        ];
    }
}
