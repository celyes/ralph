<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Config\Limits;
use InvalidArgumentException;

/**
 * What a run has left between tasks: iterations, and seconds.
 *
 * The two limits of PROJECT.md section H, item 2 that belong to the *run*
 * rather than to a task. {@see ExecutionBudget} is the other half — attempts
 * and the timeout one attempt is given — and the division is deliberate: a
 * task's budget bounds how long the loop stays on one item, and this bounds how
 * much of the checklist one run works through and how long it keeps the
 * repository.
 *
 * **It is read before a task is taken on, not after.** The run's limits are
 * reasons not to start the next task, and that distinction is the whole point.
 * A run that let the loop begin a task it has no budget for would record a
 * generation, fail it against a spent budget, and leave a checklist item marked
 * failed for work no agent was ever asked to do — the user's next `status`
 * would then show a trail of failures where the truthful answer is that the run
 * stopped and those tasks are still pending.
 *
 * **Both figures come off the run's row.** The iteration count and the active
 * seconds are persisted as the loop goes ({@see \Celyes\RalphPhp\State\RunStore::countIteration()},
 * {@see \Celyes\RalphPhp\State\RunStore::addActiveSeconds()}), so a budget
 * measured from them is measured against everything the run has spent and not
 * against what this process happens to remember.
 *
 * Exhaustion is reported rather than raised, for the same reason it is in
 * {@see ExecutionBudget}: reaching a limit is how a run is supposed to end.
 * {@see self::exhausted()} names which one, because "the run stopped" is not an
 * answer a user can act on and "raise `limits.iterations`" is.
 */
final class RunBudget
{
    /**
     * @param int $iterations the configured iteration limit this was measured against
     * @param int $iterationsRemaining tasks the run may still take on
     * @param int $seconds the configured total active run-time budget
     * @param int $secondsRemaining seconds left of that budget
     */
    private function __construct(
        private readonly int $iterations,
        private readonly int $iterationsRemaining,
        private readonly int $seconds,
        private readonly int $secondsRemaining,
    ) {
    }

    /**
     * What is left of the run's limits.
     *
     * @param int $iterationsUsed tasks already taken on, read from the run's
     *        row so that the count survives an interrupted run
     * @param int $secondsUsed active run time already spent, likewise
     *
     * @throws InvalidArgumentException when either figure is negative, which
     *                                 would make a budget larger than the
     *                                 configuration allows
     */
    public static function of(Limits $limits, int $iterationsUsed = 0, int $secondsUsed = 0): self
    {
        if ($iterationsUsed < 0) {
            throw new InvalidArgumentException('Iterations already used cannot be negative.');
        }

        if ($secondsUsed < 0) {
            throw new InvalidArgumentException('Run time already used cannot be negative.');
        }

        return new self(
            $limits->iterations(),
            max(0, $limits->iterations() - $iterationsUsed),
            $limits->runTimeout(),
            max(0, $limits->runTimeout() - $secondsUsed),
        );
    }

    /**
     * The configured iteration limit.
     */
    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * Tasks the run may still take on.
     */
    public function iterationsRemaining(): int
    {
        return $this->iterationsRemaining;
    }

    /**
     * The configured total active run-time budget, in seconds.
     */
    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * Seconds left of that budget.
     */
    public function secondsRemaining(): int
    {
        return $this->secondsRemaining;
    }

    /**
     * Whether the loop may begin another task.
     *
     * One second is enough to try: it is the smallest timeout a process can be
     * given, and a minimum of the loop's own invention would stop runs the
     * configuration allows.
     */
    public function allowsAnotherTask(): bool
    {
        return $this->iterationsRemaining >= 1 && $this->secondsRemaining >= 1;
    }

    /**
     * Which limit is spent, for the reason the run stopped.
     *
     * Null while there is room. Iterations are named first when both are gone,
     * because that is the limit a user who wanted more of the checklist done
     * would raise.
     */
    public function exhausted(): ?string
    {
        if ($this->iterationsRemaining < 1) {
            return sprintf('the iteration limit of %d was reached', $this->iterations);
        }

        if ($this->secondsRemaining < 1) {
            return sprintf('the run\'s time budget of %d seconds was spent', $this->seconds);
        }

        return null;
    }

    /**
     * One line for a log header or a status display.
     */
    public function describe(): string
    {
        $exhausted = $this->exhausted();

        if ($exhausted !== null) {
            return $exhausted;
        }

        return sprintf(
            '%d of %d iterations left, %d of %d seconds',
            $this->iterationsRemaining,
            $this->iterations,
            $this->secondsRemaining,
            $this->seconds,
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
            'iterations' => $this->iterations,
            'iterations_remaining' => $this->iterationsRemaining,
            'seconds' => $this->seconds,
            'seconds_remaining' => $this->secondsRemaining,
            'allows_another_task' => $this->allowsAnotherTask(),
        ];
    }
}
