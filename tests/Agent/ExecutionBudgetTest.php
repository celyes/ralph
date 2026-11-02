<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Config\Limits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an attempt has left to spend.
 */
#[CoversClass(ExecutionBudget::class)]
final class ExecutionBudgetTest extends TestCase
{
    #[Test]
    public function a_fresh_task_has_the_whole_of_the_configured_limits(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 3, taskTimeout: 600, runTimeout: 7200));

        self::assertSame(3, $budget->attempts());
        self::assertSame(3, $budget->attemptsRemaining());
        self::assertSame(600, $budget->taskSeconds());
        self::assertSame(7200, $budget->runSeconds());
        self::assertSame(600, $budget->seconds());
        self::assertFalse($budget->isExhausted());
        self::assertFalse($budget->isLastAttempt());
        self::assertNull($budget->exhausted());
    }

    #[Test]
    public function attempts_already_recorded_are_taken_off_the_allowance(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 3), attemptsUsed: 2);

        self::assertSame(1, $budget->attemptsRemaining());
        self::assertTrue($budget->isLastAttempt());
        self::assertFalse($budget->isExhausted());
    }

    #[Test]
    public function the_process_timeout_is_the_lesser_of_the_task_and_run_bounds(): void
    {
        $budget = ExecutionBudget::of(
            new Limits(taskTimeout: 1800, runTimeout: 7200),
            runSecondsUsed: 6960.0,
        );

        self::assertSame(1800, $budget->taskSeconds());
        self::assertSame(240, $budget->runSeconds());

        // The task bound alone would let this attempt overrun the run limit by
        // twenty-six minutes and then report the wrong limit as the cause.
        self::assertSame(240, $budget->seconds());
        self::assertTrue($budget->isLimitedByRun());
    }

    #[Test]
    public function the_task_bound_is_what_limits_an_attempt_early_in_a_run(): void
    {
        $budget = ExecutionBudget::of(new Limits(taskTimeout: 600, runTimeout: 7200), runSecondsUsed: 30.0);

        self::assertSame(600, $budget->seconds());
        self::assertFalse($budget->isLimitedByRun());
    }

    #[Test]
    public function remaining_run_time_is_rounded_down_so_it_is_never_overstated(): void
    {
        $budget = ExecutionBudget::of(new Limits(runTimeout: 100), runSecondsUsed: 40.6);

        self::assertSame(59, $budget->runSeconds());
    }

    #[Test]
    public function a_run_with_a_fraction_of_a_second_left_has_none(): void
    {
        $budget = ExecutionBudget::of(new Limits(runTimeout: 100), runSecondsUsed: 99.6);

        self::assertSame(0, $budget->runSeconds());
        self::assertTrue($budget->isExhausted());
    }

    #[Test]
    public function a_spent_attempt_allowance_is_exhausted_and_names_the_attempt_limit(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 3), attemptsUsed: 3);

        self::assertSame(0, $budget->attemptsRemaining());
        self::assertTrue($budget->isExhausted());
        self::assertSame('the attempt limit of 3 per task', $budget->exhausted());
    }

    #[Test]
    public function more_attempts_than_the_limit_do_not_make_the_remainder_negative(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 2), attemptsUsed: 5);

        self::assertSame(0, $budget->attemptsRemaining());
        self::assertTrue($budget->isExhausted());
    }

    #[Test]
    public function a_spent_run_budget_names_the_run_rather_than_the_task_timeout(): void
    {
        $budget = ExecutionBudget::of(new Limits(taskTimeout: 600, runTimeout: 600), runSecondsUsed: 600.0);

        self::assertTrue($budget->isExhausted());
        self::assertSame('the run\'s total time budget', $budget->exhausted());
    }

    #[Test]
    public function a_task_timeout_of_nothing_names_the_task_timeout(): void
    {
        $budget = ExecutionBudget::of(new Limits(taskTimeout: 0, runTimeout: 7200));

        self::assertTrue($budget->isExhausted());
        self::assertSame('the task timeout of 0 seconds', $budget->exhausted());
    }

    #[Test]
    public function the_attempt_limit_is_reported_first_when_both_are_spent(): void
    {
        $budget = ExecutionBudget::of(
            new Limits(attemptsPerTask: 2, runTimeout: 600),
            attemptsUsed: 2,
            runSecondsUsed: 600.0,
        );

        // The attempt limit is the one a user would raise.
        self::assertSame('the attempt limit of 2 per task', $budget->exhausted());
    }

    #[Test]
    public function attempts_already_used_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts already used cannot be negative.');

        ExecutionBudget::of(new Limits(), attemptsUsed: -1);
    }

    #[Test]
    public function run_time_already_used_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Run time already used cannot be negative.');

        ExecutionBudget::of(new Limits(), runSecondsUsed: -0.5);
    }

    #[Test]
    public function the_description_says_what_is_left(): void
    {
        self::assertSame(
            '3 of 3 attempts left, up to 600 seconds',
            ExecutionBudget::of(new Limits(attemptsPerTask: 3, taskTimeout: 600))->describe(),
        );
    }

    #[Test]
    public function the_description_says_when_it_is_the_last_attempt(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 3, taskTimeout: 600), attemptsUsed: 2);

        self::assertSame('last of 3 attempts, up to 600 seconds', $budget->describe());
    }

    #[Test]
    public function the_description_says_when_the_run_is_what_shortened_the_attempt(): void
    {
        $budget = ExecutionBudget::of(
            new Limits(attemptsPerTask: 1, taskTimeout: 1800, runTimeout: 7200),
            runSecondsUsed: 6960.0,
        );

        self::assertSame(
            'last of 1 attempts, up to 240 seconds (bounded by the run\'s remaining time)',
            $budget->describe(),
        );
    }

    #[Test]
    public function the_description_of_a_spent_budget_says_which_limit_is_spent(): void
    {
        $budget = ExecutionBudget::of(new Limits(attemptsPerTask: 3), attemptsUsed: 3);

        self::assertSame('no budget left: the attempt limit of 3 per task is spent', $budget->describe());
    }

    #[Test]
    public function the_budget_is_plain_data_for_a_structured_event(): void
    {
        $budget = ExecutionBudget::of(
            new Limits(attemptsPerTask: 3, taskTimeout: 1800, runTimeout: 7200),
            attemptsUsed: 1,
            runSecondsUsed: 6000.0,
        );

        self::assertSame([
            'attempts' => 3,
            'attempts_remaining' => 2,
            'task_seconds' => 1800,
            'run_seconds' => 1200,
            'seconds' => 1200,
            'limited_by_run' => true,
        ], $budget->toArray());
    }
}
