<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\Limits;
use InvalidArgumentException;

/**
 * What an attempt has left to spend: tries, and seconds.
 *
 * The configured limits say what a task and a run are allowed in total; this is
 * what remains of them at the moment one attempt starts, which is the form the
 * adapter needs. Two different things are made of it.
 *
 * **A ceiling for the process.** {@see self::seconds()} is the number the
 * adapter gives the process it starts, and it is the *lesser* of the two
 * bounds. A task timeout of thirty minutes means nothing when the run has four
 * minutes of its total budget left: allowing the longer one would let a single
 * attempt overrun the run limit and report a timeout belonging to the wrong
 * limit. Taking the minimum here is what keeps both limits true without either
 * adapter working it out again.
 *
 * **Something to tell the agent.** An attempt that knows it is the last one can
 * be told so, and that changes what a sensible agent does with the time: the
 * final attempt at a task is the wrong moment to start a refactor. The number
 * is carried rather than the adapters guessing it from an attempt counter.
 *
 * Exhaustion is reported, not raised. {@see self::isExhausted()} is a question
 * the runner asks *before* preparing an attempt — a budget with nothing left
 * means the task stops, which is section H's decision to make and not
 * something an adapter should discover by being handed a zero-second timeout.
 * Construction therefore accepts a spent budget; invoking an adapter with one
 * is the mistake, and {@see ExecutionRequest} refuses it.
 */
final class ExecutionBudget
{
    /**
     * @param int $attempts the per-task attempt limit this was measured against
     * @param int $attemptsRemaining tries left, counting the one about to run
     * @param int $taskSeconds seconds one attempt at a task may take
     * @param int $runSeconds seconds left of the run's total time budget
     */
    private function __construct(
        private readonly int $attempts,
        private readonly int $attemptsRemaining,
        private readonly int $taskSeconds,
        private readonly int $runSeconds,
    ) {
    }

    /**
     * What is left of the configured limits.
     *
     * @param int $attemptsUsed attempts already recorded against this task,
     *        across resumes: the counters survive an interrupted run
     *        (PROJECT.md section H, item 3), so this is read from the state
     *        store rather than from a loop variable
     * @param float $runSecondsUsed active run time already spent
     *
     * @throws InvalidArgumentException when either figure is negative, which
     *                                 would make a budget larger than the
     *                                 configuration allows
     */
    public static function of(Limits $limits, int $attemptsUsed = 0, float $runSecondsUsed = 0.0): self
    {
        if ($attemptsUsed < 0) {
            throw new InvalidArgumentException('Attempts already used cannot be negative.');
        }

        if ($runSecondsUsed < 0.0) {
            throw new InvalidArgumentException('Run time already used cannot be negative.');
        }

        return new self(
            $limits->attemptsPerTask(),
            max(0, $limits->attemptsPerTask() - $attemptsUsed),
            $limits->taskTimeout(),
            // Rounded down, so the remaining budget is never reported as more
            // than it is: a run with 0.4 seconds left has none.
            max(0, (int) floor($limits->runTimeout() - $runSecondsUsed)),
        );
    }

    /**
     * The per-task attempt limit.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Attempts left, including the one about to run.
     */
    public function attemptsRemaining(): int
    {
        return $this->attemptsRemaining;
    }

    /**
     * Whether nothing follows this attempt.
     */
    public function isLastAttempt(): bool
    {
        return $this->attemptsRemaining === 1;
    }

    /**
     * Seconds one attempt at a task may take, from the configuration.
     */
    public function taskSeconds(): int
    {
        return $this->taskSeconds;
    }

    /**
     * Seconds left of the run's total time budget.
     */
    public function runSeconds(): int
    {
        return $this->runSeconds;
    }

    /**
     * Seconds this attempt may take: the lesser of the two bounds.
     *
     * At least 1 whenever the budget is not exhausted, because that is the
     * smallest timeout a process can be given.
     */
    public function seconds(): int
    {
        return min($this->taskSeconds, $this->runSeconds);
    }

    /**
     * Whether the run bound is what limits this attempt rather than the task
     * bound.
     *
     * Worth saying in a log line: an attempt cancelled after four minutes under
     * a thirty-minute task timeout looks like a bug until it is clear the run
     * budget was what ran out.
     */
    public function isLimitedByRun(): bool
    {
        return $this->runSeconds < $this->taskSeconds;
    }

    /**
     * Whether there is nothing left to spend.
     *
     * Either bound reaching zero is enough. The runner checks this before
     * preparing an attempt; see the class description.
     */
    public function isExhausted(): bool
    {
        return $this->attemptsRemaining < 1 || $this->seconds() < 1;
    }

    /**
     * Which limit is spent, for the message that says why a task stopped.
     *
     * Null when the budget still has room. Attempts are reported first: a task
     * out of both has its attempt limit as the one the user would raise.
     */
    public function exhausted(): ?string
    {
        if ($this->attemptsRemaining < 1) {
            return sprintf('the attempt limit of %d per task', $this->attempts);
        }

        if ($this->seconds() < 1) {
            return $this->isLimitedByRun()
                ? 'the run\'s total time budget'
                : sprintf('the task timeout of %d seconds', $this->taskSeconds);
        }

        return null;
    }

    /**
     * One line for a log header or a prompt.
     */
    public function describe(): string
    {
        $exhausted = $this->exhausted();
        if ($exhausted !== null) {
            return sprintf('no budget left: %s is spent', $exhausted);
        }

        return sprintf(
            '%s, up to %d seconds%s',
            $this->isLastAttempt()
                ? sprintf('last of %d attempts', $this->attempts)
                : sprintf('%d of %d attempts left', $this->attemptsRemaining, $this->attempts),
            $this->seconds(),
            $this->isLimitedByRun() ? ' (bounded by the run\'s remaining time)' : '',
        );
    }

    /**
     * The budget as plain data, for a log or a structured event.
     *
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'attempts_remaining' => $this->attemptsRemaining,
            'task_seconds' => $this->taskSeconds,
            'run_seconds' => $this->runSeconds,
            'seconds' => $this->seconds(),
            'limited_by_run' => $this->isLimitedByRun(),
        ];
    }
}
