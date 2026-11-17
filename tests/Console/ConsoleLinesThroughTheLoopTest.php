<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Log\TerminalSink;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\CliResult;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the terminal actually received, command by command.
 *
 * PROJECT.md section K, items 13 and 14 are a promise about every byte the
 * package writes to a terminal: a package-composed line ends with a newline,
 * a chunk streamed from another process keeps its own endings and is not given
 * a second one, and nothing gains a blank line it was not asked for.
 * {@see OutputTest}, {@see LogFileTest} and {@see ClaudeStreamTest} hold that
 * promise one class at a time, with a captured stream and a composed chunk.
 *
 * This is the same promise read off the real thing: `ralph` as a subprocess,
 * its two pipes captured as bytes, every command and every outcome the package
 * has an exit code for. The distinction matters because the line endings of a
 * terminal are a property of the *whole* program — a command that composed its
 * own text perfectly and then printed a worker's unterminated chunk after it
 * still hands the user two sentences on one line, and no single class's test
 * can see that.
 *
 * The invariant each case asserts, on both streams:
 *
 *  - a stream that was written to ends with exactly one newline, so the shell
 *    prompt that follows starts in column one and no command leaves a blank
 *    line behind it;
 *  - no three consecutive newlines anywhere, which is the duplicate blank line
 *    item 13 names;
 *  - every line the package composed is whole, and bytes that came from another
 *    process are the bytes that process wrote.
 *
 * No case here asserts *what* a command says. That belongs to the command's own
 * test; this is about where the lines end.
 */
#[CoversClass(Output::class)]
#[CoversClass(TerminalSink::class)]
final class ConsoleLinesThroughTheLoopTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private const string OTHER = 'Document the import command in the README';

    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    /**
     * Output the slow worker prints itself, so a run signalled on it is a run
     * whose agent had really started.
     */
    private const string WORKING = ', mode slow';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and these are real runs in real repositories.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * Every command of a project that has run, on the output it really printed.
     *
     * The sweep is the point: item 13 says *every* package-generated write, and
     * a case per command would be the same three assertions eleven times over
     * while leaving the twelfth command — whichever is added next — uncovered.
     * The commands run in an order that gives each of them something to say:
     * the run first, so `status`, `logs` and `retry` have a pass to report.
     */
    #[Test]
    public function every_command_ends_each_stream_it_wrote_on_a_line_of_its_own(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::TASK, self::OTHER])
            // One task per run, so the commands that report remaining work and
            // the run's own limit-exhausted ending are both in the sweep.
            ->withConfiguration(['limits' => ['iterations' => 1]]);

        $identifier = TaskId::fromDescription(self::TASK)->value();

        $invocations = [
            // The two commands that answer before a project is even looked at.
            [],
            ['--help'],
            ['--version'],
            ['help', 'run'],
            // The project's own commands, in the order that gives each of them
            // a pass to talk about.
            ['preflight'],
            ['run'],
            ['status'],
            ['status', '--json'],
            ['logs', $identifier],
            ['clean'],
            ['clean', '--all'],
            ['resume'],
            ['retry', $identifier],
        ];

        foreach ($invocations as $arguments) {
            $result = $this->project->ralph($arguments);

            self::assertWroteWholeLines($result);
        }
    }

    /**
     * The same invariant on the diagnostic stream, which is where a user meets
     * it most often.
     *
     * Each of these fails for a different reason — an unknown command, a
     * missing argument, a task nothing is recorded for, an agent the project
     * does not define, two options that contradict each other, a setting the
     * validator rejects and a directory that is not a repository — so between
     * them they cover the error paths the package composes its own text for.
     */
    #[Test]
    public function a_failing_command_ends_its_diagnostics_on_a_line_of_their_own(): void
    {
        $this->givenAProjectThatCanRun(FixtureStep::success([self::EDITED => self::EDIT]));

        foreach ([['nonsense'], ['logs'], ['logs', 'TASK-FFFFFFFF'], ['run', '--agent', 'nobody'], ['clean', 'TASK-FFFFFFFF', '--all']] as $arguments) {
            $result = $this->project->ralph($arguments);

            self::assertNotSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
            self::assertNotSame('', $result->errorOutput, $result->describe());
            self::assertWroteWholeLines($result);
        }

        // A setting the validator refuses, which fails later than the five
        // above: the project is loaded first, so the message is composed by a
        // different part of the package.
        $this->project->editConfiguration(['limits' => ['iterations' => 0]]);

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());
        self::assertWroteWholeLines($refused);

        // And a directory that is no project at all, which is the one case
        // here that writes on *both* streams at once: a status of the defaults
        // on one, the missing checklist on the other. Each has to end its own
        // last line, which is what item 13 means by per-stream.
        $elsewhere = TemporaryDirectory::create('ralph-not-a-repository');

        try {
            $outside = Cli::run(['status'], $elsewhere);

            self::assertNotSame('', $outside->standardOutput, $outside->describe());
            self::assertNotSame('', $outside->errorOutput, $outside->describe());
            self::assertWroteWholeLines($outside);
        } finally {
            TemporaryDirectory::remove($elsewhere);
        }
    }

    /**
     * A run that gave up, and a run that was stopped: the two outcomes whose
     * last words are written from a different place in the code than a
     * successful run's.
     */
    #[Test]
    public function a_run_that_did_not_finish_still_ends_on_a_whole_line(): void
    {
        $this->givenAProjectThatCanRun(FixtureStep::failure('could not work out the option parsing'));

        $failed = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $failed->exitCode, $failed->describe());
        self::assertWroteWholeLines($failed);

        $blocked = LoopProject::create();

        try {
            $blocked
                ->withWorker(FixtureStep::blocking('the import format is undecided', 'ask the owner which it is'))
                ->withTasks([self::TASK])
                ->withConfiguration();

            $stopped = $blocked->ralph(['run']);

            self::assertSame(ExitCode::BLOCKED, $stopped->exitCode, $stopped->describe());
            self::assertWroteWholeLines($stopped);
        } finally {
            $blocked->remove();
        }
    }

    /**
     * A run interrupted with Ctrl+C, which writes its last lines from the
     * signal handler.
     *
     * The path is worth a case of its own because it is the one place the
     * package writes while something else may be mid-line: the worker was
     * printing when the signal arrived.
     */
    #[Test]
    public function an_interrupted_run_ends_its_output_on_a_whole_line(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('The pcntl extension is not installed, so a run cannot be signalled.');
        }

        $this->project
            ->withWorker(FixtureStep::slow(30))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());
        self::assertWroteWholeLines($interrupted);
    }

    /**
     * A streamed chunk that stopped mid-line does not have the next package
     * line written onto the end of it.
     *
     * The check here prints without a newline, which is an ordinary thing for a
     * program to do and the exact case item 13 is about: before it, the line
     * the run printed next — `--- check … passed` — arrived glued to the
     * check's own last word. The assertion is on both halves: the bytes the
     * check wrote are in the output unchanged, and the package's next line
     * starts a line.
     */
    #[Test]
    public function output_a_check_left_mid_line_does_not_run_into_the_next_package_line(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::TASK])
            ->withConfiguration(['checks' => [[
                'name' => 'unterminated',
                'command' => ['php', '-r', 'echo "the workspace is checked";'],
                'timeout' => 30,
            ]]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertWroteWholeLines($result);

        // The check's own bytes, exactly as it wrote them, on a line of their
        // own rather than at the end of the line that announced the check or
        // at the start of the line that reported its result.
        self::assertContains('the workspace is checked', $result->standardOutputLines(), $result->describe());
        self::assertStringContainsString(
            "the workspace is checked\n--- check unterminated passed",
            $result->standardOutput,
            $result->describe(),
        );
    }

    /**
     * A chunk that ends its lines with a carriage return keeps it, and is not
     * given a newline of the package's own as well.
     *
     * Item 14's half of the promise: the endings in streamed output are the
     * worker's business. A `\r\n` that became `\r\n\n` would double-space a
     * Windows worker's output in the terminal, and a `\r` dropped on the way
     * through would join two of its lines.
     */
    #[Test]
    public function a_checks_carriage_returns_reach_the_terminal_as_they_were(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::TASK])
            ->withConfiguration(['checks' => [[
                'name' => 'crlf',
                'command' => ['php', '-r', 'echo "first line\r\nsecond line";'],
                'timeout' => 30,
            ]]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertWroteWholeLines($result);

        // One ending, the one the check wrote.
        self::assertStringContainsString("first line\r\nsecond line\n", $result->standardOutput, $result->describe());
        self::assertStringNotContainsString("first line\r\n\n", $result->standardOutput);
        // Once: the line the run printed to announce the check quotes the
        // command's source, where the ending is two escaped characters, so the
        // only real carriage return in the output is the one the check wrote.
        self::assertSame(1, substr_count($result->standardOutput, "first line\r\n"));
        self::assertSame(1, substr_count($result->standardOutput, "second line\n"));
    }

    /**
     * The worker's own streamed output: whole, once, and still parseable.
     *
     * The run prints what the agent printed as it arrives, and the agent's
     * report is a fenced block whose lines have to survive the trip for the
     * adapter to read it at all. So this asserts the stream and the result
     * together: the fence appears on its own lines, exactly once per attempt,
     * and the run went on to accept the report.
     */
    #[Test]
    public function the_workers_streamed_lines_arrive_whole_and_once(): void
    {
        $this->givenAProjectThatCanRun(FixtureStep::success([self::EDITED => self::EDIT]));

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertWroteWholeLines($result);

        $lines = $result->standardOutputLines();

        self::assertContains('```ralph-result', $lines, $result->describe());
        self::assertContains('```', $lines, $result->describe());
        self::assertSame(1, substr_count($result->standardOutput, '```ralph-result'));
        self::assertContains(
            '{"outcome":"candidate","summary":"Made the change the task asked for."}',
            $lines,
            $result->describe(),
        );
    }

    /**
     * A stored log whose last byte is not a newline does not take the line the
     * command writes after it.
     *
     * `ralph logs` prints a worker log as the bytes it captured
     * ({@see \Celyes\RalphPhp\Console\Command\LogsCommand}), and a log can
     * genuinely end mid-line: a worker killed between a chunk and its newline
     * leaves one. The log here is cut off by hand, because a fixture worker
     * cannot be killed mid-byte reliably, and what is asserted is the package's
     * side of the arrangement — the foreign bytes are printed unchanged, and the
     * command's own next line starts a line of its own.
     */
    #[Test]
    public function a_log_that_ends_mid_line_does_not_run_into_the_commands_next_line(): void
    {
        $this->givenAProjectThatCanRun(FixtureStep::failure('could not work out the option parsing'));

        $failed = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $failed->exitCode, $failed->describe());

        $identifier = TaskId::fromDescription(self::TASK)->value();
        $log = $this->project->path('.ralph/runtime/logs/' . $identifier . '/attempt-01-agent.log');

        self::assertFileExists($log);

        $cut = rtrim((string) file_get_contents($log), "\r\n") . ' and then the worker was killed';

        self::assertNotFalse(file_put_contents($log, $cut), 'The log could not be cut off.');

        $logs = $this->project->ralph(['logs', $identifier]);

        self::assertSame(ExitCode::SUCCESS, $logs->exitCode, $logs->describe());
        self::assertWroteWholeLines($logs);
        self::assertStringContainsString(
            "and then the worker was killed\n",
            $logs->standardOutput,
            $logs->describe(),
        );
    }

    /**
     * `status --json` is a document, and the newline rule must not have put
     * anything inside it.
     *
     * The machine-readable output is where a stray blank line stops being
     * untidy and starts being a parse error, so it is asserted by parsing:
     * one trailing newline, and what precedes it decodes.
     */
    #[Test]
    public function the_json_status_is_one_document_and_one_trailing_newline(): void
    {
        $this->givenAProjectThatCanRun(FixtureStep::success([self::EDITED => self::EDIT]));

        $this->project->ralph(['run']);

        $status = $this->project->ralph(['status', '--json']);

        self::assertSame(ExitCode::SUCCESS, $status->exitCode, $status->describe());
        self::assertWroteWholeLines($status);
        self::assertIsArray(json_decode(rtrim($status->standardOutput, "\n"), true), $status->describe());
    }

    /**
     * A project with a worker, a task and settings every case here can run.
     */
    private function givenAProjectThatCanRun(FixtureStep $step): void
    {
        $this->project
            ->withWorker($step)
            ->withTasks([self::TASK])
            ->withConfiguration();
    }

    /**
     * Both streams of an invocation, held to the whole of items 13 and 14.
     */
    private static function assertWroteWholeLines(CliResult $result): void
    {
        foreach (['standard output' => $result->standardOutput, 'error output' => $result->errorOutput] as $stream => $text) {
            if ($text === '') {
                continue;
            }

            $where = sprintf("%s, on %s:\n%s", implode(' ', $result->command), $stream, $result->describe());

            self::assertStringEndsWith("\n", $text, 'Unfinished last line: ' . $where);
            self::assertStringEndsNotWith("\n\n", $text, 'A blank line was left behind: ' . $where);
            self::assertStringNotContainsString("\n\n\n", $text, 'A duplicate blank line: ' . $where);
        }
    }
}
