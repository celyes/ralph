<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Run\RunBudget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two limits that end a run rather than a task.
 *
 * What is asserted here is the arithmetic and the wording of the refusal; that
 * the loop actually asks before taking a task on is
 * {@see RunnerTest::the_run_stops_when_its_time_budget_is_spent()}.
 */
#[CoversClass(RunBudget::class)]
final class RunBudgetTest extends TestCase
{
    #[Test]
    public function a_fresh_run_has_the_configured_limits(): void
    {
        $budget = RunBudget::of($this->limits(10, 7200));

        self::assertSame(10, $budget->iterations());
        self::assertSame(10, $budget->iterationsRemaining());
        self::assertSame(7200, $budget->seconds());
        self::assertSame(7200, $budget->secondsRemaining());
        self::assertTrue($budget->allowsAnotherTask());
        self::assertNull($budget->exhausted());
        self::assertSame('10 of 10 iterations left, 7200 of 7200 seconds', $budget->describe());
    }

    #[Test]
    public function what_has_been_spent_comes_off_both_figures(): void
    {
        $budget = RunBudget::of($this->limits(10, 7200), 4, 600);

        self::assertSame(6, $budget->iterationsRemaining());
        self::assertSame(6600, $budget->secondsRemaining());
        self::assertTrue($budget->allowsAnotherTask());
    }

    #[Test]
    public function the_last_iteration_and_the_last_second_are_still_allowed(): void
    {
        $budget = RunBudget::of($this->limits(10, 7200), 9, 7199);

        self::assertSame(1, $budget->iterationsRemaining());
        self::assertSame(1, $budget->secondsRemaining());
        self::assertTrue($budget->allowsAnotherTask(), 'One second is the smallest timeout a process can be given.');
        self::assertNull($budget->exhausted());
    }

    #[Test]
    public function a_spent_iteration_limit_refuses_another_task_and_names_itself(): void
    {
        $budget = RunBudget::of($this->limits(3, 7200), 3, 60);

        self::assertSame(0, $budget->iterationsRemaining());
        self::assertFalse($budget->allowsAnotherTask());
        self::assertSame('the iteration limit of 3 was reached', $budget->exhausted());
        self::assertSame('the iteration limit of 3 was reached', $budget->describe());
    }

    #[Test]
    public function a_spent_time_budget_refuses_another_task_and_names_itself(): void
    {
        $budget = RunBudget::of($this->limits(10, 1800), 2, 1800);

        self::assertSame(8, $budget->iterationsRemaining(), 'There were iterations left; the time had gone.');
        self::assertSame(0, $budget->secondsRemaining());
        self::assertFalse($budget->allowsAnotherTask());
        self::assertSame('the run\'s time budget of 1800 seconds was spent', $budget->exhausted());
    }

    /**
     * A run that overran its budget is as spent as one that landed on it, and
     * no more than that: a negative remainder would read as a limit that had
     * been exceeded by an amount, which is not a thing a user can act on.
     */
    #[Test]
    public function overspending_is_reported_as_nothing_left_rather_than_as_a_deficit(): void
    {
        $budget = RunBudget::of($this->limits(2, 60), 5, 900);

        self::assertSame(0, $budget->iterationsRemaining());
        self::assertSame(0, $budget->secondsRemaining());
        self::assertFalse($budget->allowsAnotherTask());
    }

    /**
     * Both gone names the iteration limit, because that is the one a user who
     * wanted more of the checklist done would raise.
     */
    #[Test]
    public function the_iteration_limit_is_named_first_when_both_are_spent(): void
    {
        $budget = RunBudget::of($this->limits(4, 300), 4, 300);

        self::assertSame('the iteration limit of 4 was reached', $budget->exhausted());
    }

    #[Test]
    public function a_negative_iteration_count_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Iterations already used cannot be negative.');

        RunBudget::of($this->limits(10, 7200), -1);
    }

    #[Test]
    public function negative_time_spent_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Run time already used cannot be negative.');

        RunBudget::of($this->limits(10, 7200), 0, -1);
    }

    #[Test]
    public function the_budget_is_readable_as_plain_data(): void
    {
        self::assertSame(
            [
                'iterations' => 10,
                'iterations_remaining' => 7,
                'seconds' => 7200,
                'seconds_remaining' => 6000,
                'allows_another_task' => true,
            ],
            RunBudget::of($this->limits(10, 7200), 3, 1200)->toArray(),
        );
    }

    private function limits(int $iterations, int $runTimeout): Limits
    {
        return new Limits($iterations, 3, 1800, $runTimeout);
    }
}
