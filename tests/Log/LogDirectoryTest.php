<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a task's logs live, and what they are called.
 *
 * Paths rather than contents: the reason this is one class is that the runner
 * and `ralph logs TASK-ID` have to agree about the layout, and what is asserted
 * here is the agreement.
 */
#[CoversClass(LogDirectory::class)]
final class LogDirectoryTest extends TestCase
{
    private TaskId $task;

    private string $project;

    protected function setUp(): void
    {
        $this->task = TaskId::fromDescription('Add a --dry-run option to the import command');
        $this->project = TemporaryDirectory::create('ralph-logs');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->project);
    }

    #[Test]
    public function the_directory_is_derived_from_the_project_root(): void
    {
        self::assertSame(
            $this->project . '/' . Defaults::LOGS_DIRECTORY,
            LogDirectory::in($this->project)->directory(),
        );
    }

    #[Test]
    public function an_attempt_is_a_file_in_its_generations_directory(): void
    {
        $logs = LogDirectory::in($this->project);

        self::assertSame(
            $logs->directory() . '/' . $this->task->value() . '/attempt-02-agent.log',
            $logs->agentLog(TaskGeneration::first($this->task), 2),
        );
    }

    /**
     * So that an `ls` of the directory reads in the order the attempts were
     * made.
     */
    #[Test]
    public function the_attempt_number_is_padded(): void
    {
        $logs = LogDirectory::in($this->project);
        $generation = TaskGeneration::first($this->task);

        self::assertStringEndsWith('attempt-01-agent.log', $logs->agentLog($generation, 1));
        self::assertStringEndsWith('attempt-09-agent.log', $logs->agentLog($generation, 9));
        self::assertStringEndsWith('attempt-10-agent.log', $logs->agentLog($generation, 10));
        self::assertStringEndsWith('attempt-100-agent.log', $logs->agentLog($generation, 100));
    }

    /**
     * Section C, item 8: reopening a completed task preserves its history. Its
     * earlier logs are part of that history, so the second pass logs somewhere
     * else rather than beside — or over — the first.
     */
    #[Test]
    public function a_reopened_task_logs_in_a_directory_of_its_own(): void
    {
        $logs = LogDirectory::in($this->project);

        $first = $logs->agentLog(TaskGeneration::first($this->task), 1);
        $second = $logs->agentLog(TaskGeneration::of($this->task, 2), 1);

        self::assertNotSame($first, $second);
        self::assertSame(
            $logs->directory() . '/' . TaskGeneration::of($this->task, 2)->slug() . '/attempt-01-agent.log',
            $second,
        );
    }

    /**
     * The naming leaves room for section G's verification logs beside the
     * agent's, which is the whole reason the kind is a suffix.
     */
    #[Test]
    public function a_log_of_another_kind_sits_beside_the_agents(): void
    {
        $logs = LogDirectory::in($this->project);
        $generation = TaskGeneration::first($this->task);

        self::assertSame(
            dirname($logs->agentLog($generation, 2)) . '/attempt-02-check-tests.log',
            $logs->logFor($generation, 2, 'check-tests'),
        );
    }

    #[Test]
    public function an_attempt_number_below_one_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An attempt number starts at 1.');

        LogDirectory::in($this->project)->agentLog(TaskGeneration::first($this->task), 0);
    }

    /**
     * A kind is a name this package composes, so anything that would need
     * escaping is a bug here rather than bad user input — and a path traversal
     * through it would write a log outside the runtime directory.
     */
    #[Test]
    public function a_kind_that_is_not_a_file_name_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A log kind is lowercase words joined by hyphens');

        LogDirectory::in($this->project)->logFor(TaskGeneration::first($this->task), 1, '../../escaped');
    }

    #[Test]
    public function opening_a_log_creates_the_generations_directory(): void
    {
        $logs = LogDirectory::in($this->project);
        $generation = TaskGeneration::of($this->task, 3);

        $log = $logs->openAgentLog($generation, 1);
        $log->close();

        self::assertSame($logs->agentLog($generation, 1), $log->path());
        self::assertDirectoryExists($logs->forGeneration($generation));
    }
}
