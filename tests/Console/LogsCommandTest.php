<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\LogsCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph logs TASK-ID` on the command line, against real logs and real rows.
 *
 * The command a user reaches for when a task did not come out, so what it is
 * held to here is that the two things it joins are both really there: the
 * worker's own output, read off the file the run streamed it to, and the
 * verdicts of the checks, read out of the state store. A report that showed
 * one without the other would leave the reader to guess whether the agent or
 * the checks had the problem.
 *
 * The worker's output is asserted byte for byte, because the one promise the
 * command makes about it is that nothing was done to it.
 */
#[CoversClass(LogsCommand::class)]
final class LogsCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the logs are of a real run.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * A verified task's pass, its attempt, the worker's output and the checks.
     */
    #[Test]
    public function it_prints_the_pass_the_worker_output_and_the_check_results(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])
                ->saying('Added the option and its tests.'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $task = $this->taskId();
        $result = $this->project->ralph(['logs', $task]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString($task . ': completed, attempt 1', $result->standardOutput, $result->describe());
        self::assertStringContainsString('attempt 1: completed', $result->standardOutput, $result->describe());

        // The agent's own words, labelled as the agent's: nothing it said was
        // verified by running anything.
        self::assertStringContainsString(
            'the agent said (unverified): Added the option and its tests.',
            $result->standardOutput,
            $result->describe(),
        );

        // The worker's log, verbatim and whole.
        $log = $this->project->path(sprintf('.ralph/runtime/logs/%s/attempt-01-agent.log', $task));

        self::assertFileExists($log);
        self::assertStringContainsString(
            sprintf('worker log %s (%d bytes):', $log, strlen((string) file_get_contents($log))),
            $result->standardOutput,
            $result->describe(),
        );
        self::assertStringContainsString((string) file_get_contents($log), $result->standardOutput);

        // What the checks concluded, out of the rows rather than out of the log.
        self::assertStringContainsString('checks:', $result->standardOutput, $result->describe());
        self::assertStringContainsString('php -v', $result->standardOutput, $result->describe());

        // And the file beside the log that explains the ticked box.
        self::assertStringContainsString('completion evidence: ', $result->standardOutput, $result->describe());
    }

    /**
     * Every attempt at a task, in order, with the failure that preceded the
     * success.
     */
    #[Test]
    public function it_prints_each_attempt_of_a_pass_that_was_tried_twice(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// nothing of the sort\n"])
                    ->saying('Had a go at the option.'),
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])
                    ->saying('Added the option properly.'),
            )
            ->withTasks([self::TASK])
            ->withConfiguration([
                'limits' => ['attempts_per_task' => 2],
                // A check that really decides something: it passes only once
                // the file says what the task asked for, so the first attempt
                // fails verification and is tried again (section G, item 4).
                'checks' => [[
                    'name' => 'tests',
                    'command' => ['php', '-r', 'exit(str_contains(file_get_contents("src/Import.php"), "--dry-run") ? 0 : 1);'],
                    'timeout' => 30,
                ]],
            ]);

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['logs', $this->taskId()]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('attempt 1: failed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('attempt 2: completed', $result->standardOutput, $result->describe());

        // Both attempts' own output, and both verdicts.
        self::assertStringContainsString('Had a go at the option.', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Added the option properly.', $result->standardOutput, $result->describe());
        self::assertSame(
            2,
            substr_count($result->standardOutput, 'checks:'),
            'Each attempt is shown with what the checks decided about it.',
        );

        // In the order they happened, within the pass.
        self::assertLessThan(
            strpos($result->standardOutput, 'attempt 2: completed'),
            strpos($result->standardOutput, 'attempt 1: failed'),
            $result->describe(),
        );
    }

    /**
     * A task no run has taken on has no logs, and is told so rather than shown
     * an empty report.
     */
    #[Test]
    public function a_task_nothing_has_been_recorded_for_is_refused(): void
    {
        $this->project
            ->withWorker(FixtureStep::success())
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['logs', 'TASK-11111111']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('Nothing has been recorded for TASK-11111111.', $result->errorOutput);
        self::assertStringContainsString('ralph status', $result->errorOutput, $result->describe());
        self::assertSame('', $result->standardOutput, $result->describe());
    }

    /**
     * A project nothing has ever run in has no store to read.
     */
    #[Test]
    public function a_project_with_no_state_store_is_refused(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['logs', 'TASK-11111111']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('no state store', $result->errorOutput, $result->describe());
        self::assertStringContainsString('ralph run', $result->errorOutput, $result->describe());
    }

    /**
     * What is not an identifier is a usage mistake, told apart from an
     * identifier nothing was recorded for.
     */
    #[Test]
    public function it_refuses_a_missing_or_malformed_identifier(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $missing = $this->project->ralph(['logs']);

        self::assertSame(ExitCode::USAGE, $missing->exitCode, $missing->describe());
        self::assertStringContainsString('needs a task identifier', $missing->errorOutput);
        self::assertStringContainsString('Usage: ralph logs TASK-ID', $missing->errorOutput);

        $malformed = $this->project->ralph(['logs', 'the dry run one']);

        self::assertSame(ExitCode::USAGE, $malformed->exitCode, $malformed->describe());
        self::assertStringContainsString('is not a task identifier', $malformed->errorOutput);

        $extra = $this->project->ralph(['logs', 'TASK-11111111', 'TASK-22222222']);

        self::assertSame(ExitCode::USAGE, $extra->exitCode, $extra->describe());
        self::assertStringContainsString('takes 1 argument', $extra->errorOutput);
    }

    /**
     * An identifier is accepted however the user typed it, since it is read off
     * a report and typed back in.
     */
    #[Test]
    public function it_accepts_an_identifier_in_lower_case(): void
    {
        $this->project
            ->withWorker(FixtureStep::success())
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['logs', strtolower($this->taskId())]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString($this->taskId(), $result->standardOutput, $result->describe());
    }

    /**
     * The identifier of the project's only task.
     *
     * Generated from the task's own words, as the package generates it
     * (section C, item 4), rather than read back out of the store: a report
     * naming the task by the identifier the store happens to hold would agree
     * with itself whatever that was.
     */
    private function taskId(): string
    {
        return TaskId::fromDescription(self::TASK)->value();
    }
}
