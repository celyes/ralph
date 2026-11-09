<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Run\RunIntent;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The difference between `ralph run`, `ralph resume` and `ralph retry`.
 *
 * Four questions, three answers each, and nothing else: which agent, whether a
 * run row is opened or continued, which tasks are taken on, and what the lock
 * records. What is pinned here is the answers, because each of them is a
 * decision some other class acts on — {@see \Celyes\RalphPhp\Run\Runner} for
 * three of them and {@see \Celyes\RalphPhp\Run\RepositoryLock} for the fourth
 * — and a wrong one would be a resume that started a fresh budget or a retry
 * that worked through the whole checklist.
 */
#[CoversClass(RunIntent::class)]
final class RunIntentTest extends TestCase
{
    #[Test]
    public function a_fresh_run_takes_the_checklist_and_opens_its_own_row(): void
    {
        $intent = RunIntent::fresh();

        self::assertSame(RunIntent::RUN, $intent->purpose());
        self::assertNull($intent->agent());
        self::assertNull($intent->task());
        self::assertFalse($intent->continuesARun());
        self::assertFalse($intent->isRetry());
        self::assertSame('processing pending tasks', $intent->describe());
    }

    #[Test]
    public function a_fresh_run_carries_the_agent_the_command_line_named(): void
    {
        self::assertSame('claude', RunIntent::fresh('claude')->agent());
    }

    /**
     * A resume continues a row, which is what makes the counters survive an
     * interruption instead of being handed back whole (section H, item 3).
     */
    #[Test]
    public function a_resume_continues_a_run_without_naming_a_task(): void
    {
        $intent = RunIntent::resumed();

        self::assertSame(RunIntent::RESUME, $intent->purpose());
        self::assertTrue($intent->continuesARun());
        self::assertFalse($intent->isRetry());
        self::assertNull($intent->task());
        self::assertSame('resuming the interrupted run', $intent->describe());
    }

    /**
     * `--agent` reaches a resume, which needs it in order to refuse it: the
     * refusal is what tells a user what to type instead.
     */
    #[Test]
    public function a_resume_carries_the_agent_the_command_line_named(): void
    {
        self::assertSame('codex', RunIntent::resumed('codex')->agent());
    }

    /**
     * A retry names one task and opens a fresh row: the point of retrying is
     * another go at the configured limits, and inheriting the budget of the run
     * that used them up would refuse the task before it started.
     */
    #[Test]
    public function a_retry_names_one_task_and_does_not_continue_a_run(): void
    {
        $task = TaskId::fromDescription('Add a --dry-run option to the import command');
        $intent = RunIntent::retrying($task);

        self::assertSame(RunIntent::RETRY, $intent->purpose());
        self::assertTrue($intent->isRetry());
        self::assertFalse($intent->continuesARun());
        self::assertSame($task, $intent->task());
        self::assertSame('retrying ' . $task->value(), $intent->describe());
    }

    /**
     * The three purposes are distinct strings, since the lock reports one of
     * them to the runner it is refusing beside.
     */
    #[Test]
    public function the_three_purposes_are_told_apart(): void
    {
        self::assertSame(
            ['run', 'resume', 'retry'],
            [RunIntent::RUN, RunIntent::RESUME, RunIntent::RETRY],
        );
    }
}
