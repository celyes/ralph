<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\Process\Interruptions;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\Process\ProcessTree;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Log\ThrowingSink;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Running a command.
 *
 * Real subprocesses throughout: what is under test is the interaction with the
 * operating system — that no shell sees the argument array, that both pipes are
 * drained without deadlocking, that a timeout actually stops the process, that
 * a Ctrl+C stops it and everything it started — and none of that can be
 * observed through a double.
 */
#[CoversClass(ProcessRunner::class)]
final class ProcessRunnerTest extends TestCase
{
    private ProcessRunner $runner;

    private string $directory;

    private Environment $environment;

    /**
     * Grandchildren a test started, which nothing else will reap.
     *
     * @var list<int>
     */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner();
        $this->directory = TemporaryDirectory::create('ralph-process');
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        // Process-wide by design, so a test that stopped a run has to put it
        // back before the next one starts.
        Interruptions::release();

        foreach ($this->spawned as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }

        $this->spawned = [];
        $this->environment->restore();
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function it_captures_output_and_the_exit_code(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'],
            $this->directory,
        );

        self::assertSame(3, $result->exitCode(), $result->describe());
        self::assertSame('out', $result->standardOutput());
        self::assertSame('err', $result->errorOutput());
        self::assertFalse($result->succeeded());
        self::assertFalse($result->timedOut());
    }

    #[Test]
    public function a_successful_command_reports_success(): void
    {
        $result = $this->runner->run([PHP_BINARY, '-r', 'echo "  hello\n";'], $this->directory);

        self::assertTrue($result->succeeded(), $result->describe());
        self::assertSame('hello', $result->trimmedOutput());
    }

    #[Test]
    public function the_argument_array_is_not_interpreted_by_a_shell(): void
    {
        $argument = '$HOME; touch written.txt && echo *';

        $result = $this->runner->run([PHP_BINARY, '-r', 'echo $argv[1];', $argument], $this->directory);

        self::assertSame($argument, $result->standardOutput(), $result->describe());
        self::assertFileDoesNotExist($this->directory . '/written.txt');
    }

    #[Test]
    public function the_working_directory_is_the_one_given(): void
    {
        $result = $this->runner->run([PHP_BINARY, '-r', 'echo getcwd();'], $this->directory);

        self::assertSame(realpath($this->directory), realpath($result->trimmedOutput()), $result->describe());
    }

    #[Test]
    public function the_environment_is_inherited_and_can_be_overridden(): void
    {
        $this->environment->set('RALPH_INHERITED', 'yes');
        $this->environment->set('RALPH_REMOVED', 'yes');

        $script = 'echo getenv("RALPH_INHERITED") . "|" . getenv("RALPH_OVERRIDDEN") . "|"'
            . ' . var_export(getenv("RALPH_REMOVED"), true);';

        $result = $this->runner->run(
            [PHP_BINARY, '-r', $script],
            $this->directory,
            ProcessRunner::DEFAULT_TIMEOUT_SECONDS,
            ['RALPH_OVERRIDDEN' => 'set-here', 'RALPH_REMOVED' => false],
        );

        self::assertSame('yes|set-here|false', $result->trimmedOutput(), $result->describe());
    }

    #[Test]
    public function a_command_that_overruns_its_timeout_is_stopped(): void
    {
        $started = microtime(true);

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "started"); sleep(30);'],
            $this->directory,
            1,
        );

        $elapsed = microtime(true) - $started;

        self::assertTrue($result->timedOut(), $result->describe());
        self::assertNull($result->exitCode());
        self::assertFalse($result->succeeded());
        // Whatever it managed to say before it was stopped is kept, because it
        // is usually the explanation.
        self::assertSame('started', $result->standardOutput(), $result->describe());
        self::assertLessThan(15.0, $elapsed, 'the runner waited far longer than the timeout');
        self::assertStringContainsString('did not finish within', $result->failureReason());
    }

    #[Test]
    public function large_output_on_both_streams_does_not_deadlock(): void
    {
        $script = '$block = str_repeat("x", 1024);'
            . 'for ($i = 0; $i < 512; $i++) { fwrite(STDOUT, $block); fwrite(STDERR, $block); }';

        $result = $this->runner->run([PHP_BINARY, '-r', $script], $this->directory, 30);

        self::assertTrue($result->succeeded(), $result->describe());
        self::assertSame(512 * 1024, strlen($result->standardOutput()));
        self::assertSame(512 * 1024, strlen($result->errorOutput()));
    }

    #[Test]
    public function a_command_that_reads_input_sees_end_of_input_instead_of_waiting(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo var_export(fgets(STDIN), true);'],
            $this->directory,
            5,
        );

        self::assertTrue($result->succeeded(), $result->describe());
        self::assertSame('false', $result->trimmedOutput());
    }

    #[Test]
    public function a_command_given_input_reads_it_and_then_end_of_input(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo file_get_contents("php://stdin") . var_export(fgets(STDIN), true);'],
            $this->directory,
            5,
            [],
            "first\nsecond\n",
        );

        self::assertTrue($result->succeeded(), $result->describe());
        self::assertSame("first\nsecond\nfalse", $result->trimmedOutput());
    }

    /**
     * An agent's briefing is as long as the task, its criteria and the project
     * context make it, which is well past a pipe buffer. The command's input is
     * written in the same turn as its output is read, which is what makes a
     * briefing that large impossible to deadlock on: a parent that filled the
     * input pipe before reading would wait on a child that is itself waiting to
     * be read.
     */
    #[Test]
    public function large_input_is_delivered_while_the_command_is_also_writing_output(): void
    {
        $input = str_repeat("briefing line\n", 60_000);

        $result = $this->runner->run(
            [
                PHP_BINARY,
                '-r',
                'fwrite(STDERR, str_repeat("x", 256 * 1024)); echo strlen(file_get_contents("php://stdin"));',
            ],
            $this->directory,
            20,
            [],
            $input,
        );

        self::assertTrue($result->succeeded(), $result->describe());
        self::assertSame((string) strlen($input), $result->trimmedOutput());
        self::assertSame(256 * 1024, strlen($result->errorOutput()));
    }

    #[Test]
    public function empty_input_is_an_empty_stream_rather_than_no_stream(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo var_export(file_get_contents("php://stdin"), true);'],
            $this->directory,
            5,
            [],
            '',
        );

        self::assertSame("''", $result->trimmedOutput(), $result->describe());
    }

    /**
     * The briefing is the user's text and the project's context, and it reaches
     * the command through the command's own standard input: there is no file
     * anywhere holding a copy of it, before the run or after it.
     *
     * Read off the process filesystem, which is the only way to ask a program
     * what its own descriptor zero is. A system without one skips the question;
     * the two assertions below it are the ones that would notice a file.
     */
    #[Test]
    public function the_input_reaches_the_command_through_its_own_standard_input(): void
    {
        if (!ProcessTree::isReadable()) {
            self::markTestSkipped('Asking a program what its standard input is needs a process filesystem.');
        }

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo readlink("/proc/self/fd/0"), "|", file_get_contents("php://stdin");'],
            $this->directory,
            5,
            [],
            'briefing',
        );

        self::assertStringStartsWith('pipe:', $result->trimmedOutput(), $result->describe());
        self::assertStringEndsWith('|briefing', $result->trimmedOutput(), $result->describe());
    }

    /**
     * Never in the working directory: a file written into a task's worktree
     * would be an untracked change in a diff the runner is about to stage.
     */
    #[Test]
    public function input_is_not_written_into_the_commands_working_directory(): void
    {
        $this->runner->run([PHP_BINARY, '-r', 'echo 1;'], $this->directory, 5, [], 'briefing');

        self::assertSame(['.', '..'], scandir($this->directory));
    }

    /**
     * PROJECT.md section E, item 4. The assertion that matters is not what the
     * sink ended up holding — collecting would produce the same string — but
     * that it was handed the first line *before* the second was written. A
     * coding agent can be quiet for minutes, and a loop that showed nothing
     * until the process exited would be indistinguishable from one that had
     * hung.
     */
    #[Test]
    public function output_is_streamed_while_the_command_is_still_running(): void
    {
        $sink = new RecordingSink();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "first\n"); usleep(300000); fwrite(STDOUT, "second\n");'],
            $this->directory,
            30,
            [],
            null,
            $sink,
        );

        self::assertGreaterThanOrEqual(2, $sink->chunkCountOf(OutputStream::Standard), $result->describe());
        self::assertSame("first\n", $sink->writes[0]['chunk']);
        self::assertSame("first\nsecond\n", $sink->textOf(OutputStream::Standard));
    }

    /**
     * The sink is for the user and the log; the result is what an adapter reads
     * to decide what the agent did, and an adapter cannot read a chunk that has
     * already been streamed past.
     */
    #[Test]
    public function streamed_output_is_also_collected_into_the_result(): void
    {
        $sink = new RecordingSink();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(2);'],
            $this->directory,
            30,
            [],
            null,
            $sink,
        );

        self::assertSame('out', $result->standardOutput());
        self::assertSame('err', $result->errorOutput());
        self::assertSame('out', $sink->textOf(OutputStream::Standard));
        self::assertSame('err', $sink->textOf(OutputStream::Error));
        self::assertSame(2, $result->exitCode());
    }

    /**
     * Which descriptor a chunk came off decides where the terminal sends it and
     * how the log marks it, so it has to survive the trip.
     */
    #[Test]
    public function each_chunk_arrives_with_the_stream_it_came_off(): void
    {
        $sink = new RecordingSink();

        $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "only on stderr");'],
            $this->directory,
            30,
            [],
            null,
            $sink,
        );

        self::assertSame('', $sink->textOf(OutputStream::Standard));
        self::assertSame('only on stderr', $sink->textOf(OutputStream::Error));
    }

    /**
     * An attempt stopped by its timeout is exactly the attempt whose output a
     * user needs, so what the worker managed to say is streamed as well as
     * kept.
     */
    #[Test]
    public function what_a_stopped_command_printed_is_streamed_too(): void
    {
        $sink = new RecordingSink();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "started"); sleep(30);'],
            $this->directory,
            1,
            [],
            null,
            $sink,
        );

        self::assertTrue($result->timedOut(), $result->describe());
        self::assertSame('started', $sink->textOf(OutputStream::Standard));
    }

    /**
     * The timeout path drains whatever is still sitting in the pipes after the
     * process is killed, and that drained tail has to be streamed as well as
     * kept — otherwise the log of a timed-out attempt is missing its last
     * kilobytes, which on a worker that was killed mid-sentence is exactly the
     * part being read for.
     *
     * The condition is arranged rather than hoped for: a sink slower than the
     * worker keeps the pipe buffer full, so there is certainly unread output
     * when the deadline arrives. The assertion is then that the sink saw
     * everything the result did — a drain that only collects makes the result
     * longer than what was streamed.
     *
     * The worker pauses between blocks, and stops writing before it would have
     * printed more than this test needs. A worker that writes flat out instead
     * is a memory bomb and not a sharper test: the pipe drain inside the
     * process library reads for as long as a full chunk is waiting, so a
     * producer that can keep ahead of the reader is never left for the
     * deadline check in {@see ProcessRunner::wait()} to notice, and the
     * collected output grows until the suite is killed. What is under test is
     * the tail that the timeout path drains, which a pipe kept full delivers
     * either way.
     */
    #[Test]
    public function the_tail_left_in_the_pipes_at_a_timeout_is_streamed_as_well_as_kept(): void
    {
        $sink = new RecordingSink(delayMicroseconds: 5000);
        $script = '$block = str_repeat("x", 65536);'
            . 'for ($i = 0; $i < 2000; $i++) { fwrite(STDOUT, $block); usleep(1000); }'
            . 'sleep(30);';

        $result = $this->runner->run([PHP_BINARY, '-r', $script], $this->directory, 1, [], null, $sink);

        self::assertTrue($result->timedOut(), $result->describe());
        self::assertGreaterThan(0, strlen($result->standardOutput()));
        self::assertSame(
            strlen($result->standardOutput()),
            $sink->byteCountOf(OutputStream::Standard),
            'the output kept and the output streamed should be the same output',
        );
    }

    #[Test]
    public function the_sink_is_not_closed_by_the_runner(): void
    {
        $sink = new RecordingSink();

        $this->runner->run([PHP_BINARY, '-r', 'echo 1;'], $this->directory, 30, [], null, $sink);

        // An attempt's log spans the agent and then the verification commands,
        // so a runner that closed it would leave the checks writing to a closed
        // file.
        self::assertSame(0, $sink->closes);
    }

    /**
     * A sink is contractually not allowed to throw, and this is what happens
     * when one does anyway: the child is still waited for, its output is still
     * collected, and its exit status is still reported. The alternative is an
     * exception out of the pipe-draining loop and a coding agent left running
     * with nothing waiting for it.
     */
    #[Test]
    public function a_sink_that_throws_does_not_abandon_the_child_process(): void
    {
        $broken = new ThrowingSink();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "one"); usleep(200000); fwrite(STDOUT, "two"); exit(4);'],
            $this->directory,
            30,
            [],
            null,
            $broken,
        );

        self::assertSame('onetwo', $result->standardOutput(), $result->describe());
        self::assertSame(4, $result->exitCode());
        // Dropped after the first failure rather than retried per chunk.
        self::assertSame(1, $broken->attempts);
    }

    #[Test]
    public function a_command_run_without_a_sink_still_collects_its_output(): void
    {
        $result = $this->runner->run([PHP_BINARY, '-r', 'echo "collected";'], $this->directory);

        self::assertSame('collected', $result->standardOutput());
    }

    /**
     * The whole of PROJECT.md section H, item 4 at this level: a command that
     * is running when the run is asked to stop is stopped, and what comes back
     * says so.
     *
     * The command signals its own parent — this process — so the stop arrives
     * while it is genuinely running, exactly as a keyboard interrupt would.
     */
    #[Test]
    public function a_command_running_when_the_run_is_stopped_is_cancelled(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', $this->interruptsItsParent()],
            $this->directory,
            timeout: 30,
        );

        self::assertTrue($result->wasCancelled(), $result->describe());
        self::assertFalse($result->timedOut(), 'A cancellation is not a timeout: the limit was never reached.');
        self::assertFalse($result->succeeded());
        self::assertNull($result->exitCode(), 'A stopped command reported no verdict on its work.');
        self::assertSame('stopped by SIGINT (Ctrl+C)', $result->cancelledBy()?->reason());
        self::assertStringContainsString('cancelled', $result->describe());
        self::assertStringContainsString('stopped by SIGINT', $result->failureReason());
    }

    #[Test]
    public function what_a_cancelled_command_printed_before_it_was_stopped_is_kept_and_streamed(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        $sink = new RecordingSink();

        $this->runner->run(
            [PHP_BINARY, '-r', $this->interruptsItsParent()],
            $this->directory,
            timeout: 30,
            sink: $sink,
        );

        self::assertStringContainsString('working', $sink->textOf(OutputStream::Standard));
    }

    /**
     * The process tree, through the runner: a worker's children go with it.
     */
    #[Test]
    public function the_children_of_a_cancelled_command_are_stopped_too(): void
    {
        $this->requireSignals();

        if (!ProcessTree::isReadable()) {
            self::markTestSkipped('Reaching a grandchild needs a process filesystem.');
        }

        Interruptions::listen();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', $this->spawnsAChildThenInterruptsItsParent()],
            $this->directory,
            timeout: 30,
        );

        $grandchild = (int) trim($result->standardOutput());
        self::assertGreaterThan(0, $grandchild, 'The command never reported the process it started: '
            . $result->describe());
        $this->spawned[] = $grandchild;

        self::assertTrue($result->wasCancelled(), $result->describe());
        self::assertContains($grandchild, $result->termination()?->signalled() ?? []);
        self::assertFalse(
            ProcessTree::isProcessAlive($grandchild),
            'The process the worker started outlived the run that was stopped.',
        );
    }

    /**
     * What the run reads on its way out to decide whether it may release the
     * repository lock. A confirmed stop owes nothing.
     */
    #[Test]
    public function a_cancelled_command_that_was_confirmed_stopped_leaves_nothing_owed(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        $result = $this->runner->run(
            [PHP_BINARY, '-r', $this->interruptsItsParent()],
            $this->directory,
            timeout: 30,
        );

        self::assertTrue($result->termination()?->isConfirmed(), (string) $result->termination()?->describe());
        self::assertSame([], Interruptions::unconfirmed());
    }

    /**
     * A command that timed out is stopped the same way and reported
     * differently, and it does not touch what the run owes: the run carries on
     * to the next task and gives its lock back when it finishes.
     */
    #[Test]
    public function a_timeout_stops_the_command_without_making_the_run_owe_anything(): void
    {
        $result = $this->runner->run([PHP_BINARY, '-r', 'echo "slow"; sleep(30);'], $this->directory, timeout: 1);

        self::assertTrue($result->timedOut(), $result->describe());
        self::assertFalse($result->wasCancelled());
        self::assertTrue($result->termination()?->isConfirmed(), (string) $result->termination()?->describe());
        self::assertSame([], Interruptions::unconfirmed());
    }

    #[Test]
    public function nothing_is_started_once_a_stop_has_been_asked_for(): void
    {
        Interruptions::request(Interruption::SIGTERM);

        $result = $this->runner->run([PHP_BINARY, '-r', 'file_put_contents("ran.txt", "yes");'], $this->directory);

        self::assertTrue($result->wasCancelled(), $result->describe());
        self::assertSame('stopped by SIGTERM', $result->cancelledBy()?->reason());
        self::assertSame(0.0, $result->seconds());
        self::assertFileDoesNotExist(
            $this->directory . '/ran.txt',
            'A command was started after the run had been asked to stop.',
        );
    }

    /**
     * A command that finished normally says nothing about being stopped, which
     * is what keeps every other caller of this class unaffected.
     */
    #[Test]
    public function a_command_that_ran_to_its_own_conclusion_was_not_cancelled(): void
    {
        $result = $this->runner->run([PHP_BINARY, '-r', 'echo "done";'], $this->directory);

        self::assertFalse($result->wasCancelled());
        self::assertNull($result->cancelledBy());
        self::assertNull($result->termination(), 'Nothing had to be stopped.');
        self::assertTrue($result->succeeded());
    }

    /**
     * A program that prints, interrupts the process that started it, and then
     * stays there to be stopped.
     *
     * Not `sleep()`: a signal cuts that short, and a command that returned from
     * it would exit by itself instead of waiting to be terminated.
     */
    private function interruptsItsParent(): string
    {
        return <<<'CODE'
            fwrite(STDOUT, "working\n");
            fflush(STDOUT);
            posix_kill(posix_getppid(), SIGINT);
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline) { usleep(20000); }
            CODE;
    }

    /**
     * The same, having started a child of its own first.
     */
    private function spawnsAChildThenInterruptsItsParent(): string
    {
        return <<<'CODE'
            $quiet = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
            $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], $quiet, $pipes);
            fwrite(STDOUT, proc_get_status($child)['pid'] . "\n");
            fflush(STDOUT);
            posix_kill(posix_getppid(), SIGINT);
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline) { usleep(20000); }
            CODE;
    }

    private function requireSignals(): void
    {
        if (!Interruptions::isSupported() || !function_exists('posix_kill')) {
            self::markTestSkipped('Stopping a run needs ext-pcntl, and signalling a parent needs ext-posix.');
        }
    }

    #[Test]
    public function a_missing_executable_cannot_be_started(): void
    {
        $missing = $this->directory . '/not-a-program';

        try {
            $this->runner->run([$missing], $this->directory);
            self::fail('Expected a ProcessException.');
        } catch (ProcessException $exception) {
            self::assertStringContainsString($missing, $exception->getMessage());
            self::assertNotSame([], $exception->hints());
        }
    }

    #[Test]
    public function a_missing_working_directory_is_reported_as_such(): void
    {
        $directory = $this->directory . '/gone';

        try {
            $this->runner->run([PHP_BINARY, '-v'], $directory);
            self::fail('Expected a ProcessException.');
        } catch (ProcessException $exception) {
            self::assertStringContainsString(
                sprintf('Its working directory %s does not exist.', $directory),
                implode("\n", $exception->hints()),
            );
        }
    }

    #[Test]
    public function an_empty_command_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner->run([], $this->directory);
    }

    #[Test]
    public function a_nonpositive_timeout_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner->run([PHP_BINARY, '-v'], $this->directory, 0);
    }
}
