<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Process\ProcessRunner;
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
 * drained without deadlocking, that a timeout actually stops the process — and
 * none of that can be observed through a double.
 */
#[CoversClass(ProcessRunner::class)]
final class ProcessRunnerTest extends TestCase
{
    private ProcessRunner $runner;

    private string $directory;

    private Environment $environment;

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner();
        $this->directory = TemporaryDirectory::create('ralph-process');
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
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
     * context make it, which is well past a pipe buffer. Spooling it to a file
     * is what makes that impossible to deadlock on.
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
     * The briefing is the user's text and the project's context; a copy of it
     * is not left lying in the temporary directory after the run.
     */
    #[Test]
    public function the_spooled_input_is_removed_afterwards(): void
    {
        $before = $this->spooledInputFiles();

        $this->runner->run([PHP_BINARY, '-r', 'echo 1;'], $this->directory, 5, [], 'briefing');
        $timedOut = $this->runner->run(
            [PHP_BINARY, '-r', 'sleep(30);'],
            $this->directory,
            1,
            [],
            'briefing',
        );

        self::assertTrue($timedOut->timedOut(), $timedOut->describe());
        self::assertSame($before, $this->spooledInputFiles());
    }

    /**
     * Never in the working directory: a file written into a task's worktree
     * would be an untracked change in a diff the runner is about to stage.
     */
    #[Test]
    public function input_is_not_spooled_into_the_commands_working_directory(): void
    {
        $this->runner->run([PHP_BINARY, '-r', 'echo 1;'], $this->directory, 5, [], 'briefing');

        self::assertSame(['.', '..'], scandir($this->directory));
    }

    /**
     * @return list<string>
     */
    private function spooledInputFiles(): array
    {
        $found = glob(sys_get_temp_dir() . '/ralph-input-*');

        return $found === false ? [] : $found;
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
