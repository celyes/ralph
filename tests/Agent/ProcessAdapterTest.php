<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ProcessAdapter;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Log\AttemptLog;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Running a coding agent as a process.
 *
 * Real subprocesses, as in {@see \Celyes\RalphPhp\Tests\Process\ProcessRunnerTest}
 * and for the same reason: what is under test is what the operating system was
 * told — which directory the child ran in, which argument array it received,
 * whether a shell saw any of it, whether a timeout actually stopped it. A
 * double would only confirm that the adapter called a method.
 *
 * The agent is PHP itself, run with `-r`, because a program that prints its own
 * working directory and arguments is the only agent these assertions need.
 */
#[CoversClass(ProcessAdapter::class)]
final class ProcessAdapterTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * The task's own workspace: the directory the agent must run in.
     */
    private string $workspace;

    /**
     * The controlling checkout, which nothing here may touch.
     */
    private string $project;

    private Environment $environment;

    protected function setUp(): void
    {
        $this->workspace = TemporaryDirectory::create('ralph-workspace');
        $this->project = TemporaryDirectory::create('ralph-project');
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->project);
    }

    #[Test]
    public function the_agent_runs_in_the_tasks_workspace(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'echo getcwd();']);

        $result = $adapter->execute($this->request());

        self::assertSame($this->workspace, $result->claim()->text());
    }

    /**
     * The failure this is about is not exotic: an adapter that forgets to pass
     * a working directory gets the PHP process's own, which is the controlling
     * checkout — the one repository section F, item 1 promises not to touch.
     */
    #[Test]
    public function the_agent_does_not_inherit_the_runners_own_directory(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'file_put_contents("agent-was-here.txt", getcwd());']);

        $adapter->execute($this->request());

        self::assertFileExists($this->workspace . '/agent-was-here.txt');
        self::assertFileDoesNotExist(getcwd() . '/agent-was-here.txt');
        self::assertFileDoesNotExist($this->project . '/agent-was-here.txt');
    }

    /**
     * The item's own words: arbitrary task text stays out of shell command
     * interpolation. A description written to look like a command line reaches
     * the agent as the sentence it is, and nothing in it runs.
     */
    #[Test]
    public function a_task_description_a_shell_would_act_on_arrives_as_one_argument(): void
    {
        $description = 'Escape $HOME; touch written.txt && echo * | tee "quoted" `date`';
        $adapter = new ScriptedProcessAdapter(
            ['-r', 'echo count($argv) . "|" . $argv[1];'],
            descriptionAsArgument: true,
        );

        $result = $adapter->execute($this->request(description: $description));

        self::assertSame('2|' . $description, $result->claim()->text());
        self::assertFileDoesNotExist($this->workspace . '/written.txt');
    }

    #[Test]
    public function the_briefing_can_be_handed_over_whole_on_standard_input(): void
    {
        $request = $this->request();
        $adapter = new ScriptedProcessAdapter(
            ['-r', 'echo file_get_contents("php://stdin");'],
            briefingOnInput: true,
        );

        $result = $adapter->execute($request);

        self::assertSame(trim($request->toMarkdown()), $result->claim()->text());
        self::assertStringContainsString(self::DESCRIPTION, $result->claim()->text());
    }

    #[Test]
    public function an_agent_given_no_input_reads_end_of_input_rather_than_waiting(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'echo var_export(fgets(STDIN), true);']);

        $result = $adapter->execute($this->request());

        self::assertSame('false', $result->claim()->text());
    }

    /**
     * How credentials reach an agent today: the child inherits the environment,
     * so nothing secret has to be written into a configuration file or an
     * argument. Per-agent variables are section E, item 8.
     */
    #[Test]
    public function the_agent_inherits_the_environment_its_authentication_lives_in(): void
    {
        $this->environment->set('RALPH_TEST_AGENT_TOKEN', 'from-the-environment');
        $adapter = new ScriptedProcessAdapter(['-r', 'echo getenv("RALPH_TEST_AGENT_TOKEN");']);

        $result = $adapter->execute($this->request());

        self::assertSame('from-the-environment', $result->claim()->text());
    }

    #[Test]
    public function an_agent_that_finished_is_read_by_the_adapter_itself(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "implemented"; exit(0);']);

        $result = $adapter->execute($this->request());

        self::assertSame(1, $adapter->interpretations);
        self::assertTrue($result->hasCandidate());
        self::assertSame('implemented', $result->claim()->text());
        self::assertSame(0, $result->exitCode());
    }

    #[Test]
    public function an_agent_that_failed_is_an_execution_error_carrying_its_last_words(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'fwrite(STDERR, "no model configured\n"); exit(7);']);

        $result = $adapter->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertFalse($result->hasCandidate());
        self::assertSame(7, $result->exitCode());
        self::assertSame('scripted exited with status 7', $result->evidence()->label());
        self::assertStringContainsString('no model configured', $result->evidence()->toMarkdown());
    }

    /**
     * A program that failed explains itself on its error stream; what it wrote
     * to standard output on the way there is the part worth dropping.
     */
    #[Test]
    public function the_evidence_quotes_the_error_stream_in_preference_to_the_chatter(): void
    {
        $adapter = new ScriptedProcessAdapter(
            ['-r', 'echo "ordinary chatter"; fwrite(STDERR, "the real cause"); exit(1);'],
        );

        $result = $adapter->execute($this->request());

        self::assertStringContainsString('the real cause', $result->evidence()->toMarkdown());
        self::assertStringNotContainsString('ordinary chatter', $result->evidence()->toMarkdown());
    }

    #[Test]
    public function a_failure_with_nothing_printed_still_records_evidence(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'exit(2);']);

        $result = $adapter->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('printed nothing', $result->evidence()->toMarkdown());
    }

    /**
     * A timeout is normalised by the base class, not by each adapter: a killed
     * process printed nothing conclusive and reported no status, so there is
     * nothing agent-specific left to read — and an adapter that missed the case
     * would report it as an ordinary failure and have the user raise the wrong
     * limit.
     */
    #[Test]
    public function an_agent_that_overran_the_task_timeout_is_a_timeout_the_adapter_never_sees(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "working"; sleep(30);']);

        $result = $adapter->execute($this->request(
            limits: new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200),
        ));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertSame(0, $adapter->interpretations);
        self::assertNull($result->exitCode());
        self::assertSame('scripted was stopped after 1 second (the task timeout)', $result->evidence()->label());
        self::assertStringContainsString('working', $result->evidence()->toMarkdown());
        self::assertGreaterThan(0.0, $result->seconds());
    }

    /**
     * The attempt is held to the lesser of the two bounds, so a run with
     * seconds left cannot be overrun by a task whose own timeout is generous —
     * and the evidence names the limit that actually ran out.
     */
    #[Test]
    public function an_attempt_is_bounded_by_the_runs_remaining_time_as_well(): void
    {
        $adapter = new ScriptedProcessAdapter(['-r', 'sleep(30);']);

        $result = $adapter->execute($this->request(
            limits: new Limits(attemptsPerTask: 3, taskTimeout: 1800, runTimeout: 1),
        ));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertSame(
            'scripted was stopped after 1 second (the run\'s remaining time)',
            $result->evidence()->label(),
        );
    }

    /**
     * A configured name is resolved before the process starts, so the command
     * in the log is the binary that ran rather than the name that was typed.
     */
    #[Test]
    public function a_configured_name_is_resolved_on_path_without_a_shell(): void
    {
        $directory = $this->workspace . '/bin';
        mkdir($directory, 0o700);
        file_put_contents($directory . '/ralph-fixture-agent', "#!/bin/sh\necho \"\$1\"\n");
        chmod($directory . '/ralph-fixture-agent', 0o700);

        $this->environment->set('PATH', $directory);

        $adapter = new ScriptedProcessAdapter([], descriptionAsArgument: true);
        $result = $adapter->execute($this->request(
            definition: new AgentDefinition('scripted', 'scripted', 'ralph-fixture-agent'),
        ));

        self::assertSame(self::DESCRIPTION, $result->claim()->text());
        self::assertNotNull($adapter->interpreted);
        self::assertSame(
            [$directory . '/ralph-fixture-agent', self::DESCRIPTION],
            $adapter->interpreted->command(),
        );
    }

    /**
     * Thrown rather than returned, which the interface is explicit about: an
     * uninstalled CLI is a setup problem that a retry would hit identically,
     * and no other agent is run in its place.
     */
    #[Test]
    public function a_missing_executable_stops_the_run_instead_of_returning_a_result(): void
    {
        $this->environment->set('PATH', $this->project . '/bin');
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "never";']);

        try {
            $adapter->execute($this->request(
                definition: new AgentDefinition('scripted', 'scripted', 'ralph-no-such-agent-cli'),
            ));
            self::fail('Expected a ProcessException.');
        } catch (ProcessException $exception) {
            $hints = implode(' ', $exception->hints());

            self::assertStringContainsString('ralph-no-such-agent-cli', $exception->getMessage());
            self::assertStringContainsString('scripted', $exception->getMessage());
            // Where it looked, so the user can see which PATH was searched.
            self::assertStringContainsString('PATH: ' . $this->project . '/bin', $hints);
            self::assertStringContainsString('does not run a different agent', $hints);
        }

        self::assertSame(0, $adapter->interpretations);
    }

    #[Test]
    public function a_configured_path_that_holds_nothing_executable_is_reported_as_a_path(): void
    {
        $path = $this->project . '/bin/agent';
        $adapter = new ScriptedProcessAdapter([]);

        try {
            $adapter->execute($this->request(
                definition: new AgentDefinition('scripted', 'scripted', $path),
            ));
            self::fail('Expected a ProcessException.');
        } catch (ProcessException $exception) {
            self::assertStringContainsString('Nothing executable exists at ' . $path, implode(' ', $exception->hints()));
        }
    }

    /**
     * A relative path has two meanings here — the project root preflight
     * checked it against, and the per-task worktree the process would resolve
     * it in — so neither is guessed.
     */
    #[Test]
    public function a_relative_executable_path_is_refused_rather_than_resolved_in_a_worktree(): void
    {
        $adapter = new ScriptedProcessAdapter([]);

        try {
            $adapter->execute($this->request(
                definition: new AgentDefinition('scripted', 'scripted', './bin/agent'),
            ));
            self::fail('Expected a ProcessException.');
        } catch (ProcessException $exception) {
            self::assertStringContainsString('relative path', $exception->getMessage());
            self::assertStringContainsString($this->workspace, implode(' ', $exception->hints()));
            self::assertStringContainsString('absolute path', implode(' ', $exception->hints()));
        }
    }

    /**
     * PROJECT.md section E, item 4, and the half of it a subclass gets for
     * free: {@see ScriptedProcessAdapter} does nothing to stream, because an
     * adapter that had to remember to would be an adapter whose agent went
     * silently dark the first time someone forgot.
     *
     * What is asserted is the timing rather than the text: the first line
     * reached the sink before the second was written.
     */
    #[Test]
    public function the_agents_output_is_streamed_while_it_works(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(
            ['-r', 'fwrite(STDOUT, "reading the task\n"); usleep(300000); fwrite(STDOUT, "done\n");'],
        );

        $adapter->execute($this->request(output: $sink));

        self::assertGreaterThanOrEqual(2, $sink->chunkCountOf(OutputStream::Standard));
        self::assertSame("reading the task\n", $sink->writes[0]['chunk']);
    }

    /**
     * Streamed and captured both: the sink is for the user and the log, and the
     * collected output is what the adapter reads to decide what the agent did.
     */
    #[Test]
    public function streaming_does_not_take_the_output_away_from_the_adapter(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "implemented";']);

        $result = $adapter->execute($this->request(output: $sink));

        self::assertSame('implemented', $result->claim()->text());
        self::assertSame('implemented', $sink->textOf(OutputStream::Standard));
    }

    /**
     * The three notes the base class writes around every invocation: which
     * attempt this is, what was started, and how it ended. The last is the
     * item's "exit code and timing", recorded where a reader of the log finds
     * it — and recorded by the package, so an agent cannot write it.
     */
    #[Test]
    public function the_attempt_and_how_it_ended_are_noted_around_the_output(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "chatter"; exit(3);']);

        $adapter->execute($this->request(output: $sink));

        self::assertCount(3, $sink->notes);
        self::assertStringContainsString('attempt 1, agent scripted', $sink->notes[0]);
        self::assertStringContainsString(self::DESCRIPTION, $sink->notes[0]);
        self::assertStringStartsWith('running ' . PHP_BINARY, $sink->notes[1]);
        self::assertMatchesRegularExpression(
            '/^scripted exited with status 3 after \d+\.\ds$/',
            $sink->notes[2],
        );
    }

    /**
     * So that a log of an attempt whose agent could not be found still says
     * which attempt it was looking for.
     */
    #[Test]
    public function the_attempt_is_noted_before_the_executable_is_resolved(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter([]);

        try {
            $adapter->execute($this->request(
                definition: new AgentDefinition('scripted', 'scripted', $this->project . '/bin/agent'),
                output: $sink,
            ));
            self::fail('Expected a ProcessException.');
        } catch (ProcessException) {
            self::assertCount(1, $sink->notes);
            self::assertStringContainsString('attempt 1, agent scripted', $sink->notes[0]);
        }
    }

    /**
     * The command is noted as the resolved program and separate arguments, not
     * as a shell line: a log that showed a composed command line would invite a
     * reader to paste it into a shell, which is the one thing this adapter
     * exists to never do.
     */
    #[Test]
    public function the_noted_command_shows_argument_boundaries_rather_than_a_shell_line(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(['-r', 'echo 1;'], descriptionAsArgument: true);

        $adapter->execute($this->request(
            description: 'Escape $HOME; touch written.txt',
            output: $sink,
        ));

        self::assertStringContainsString("'Escape \$HOME; touch written.txt'", $sink->notes[1]);
    }

    /**
     * The briefing can be thousands of characters and is in the log already as
     * the request it came from; its size is the part worth seeing.
     */
    #[Test]
    public function the_briefing_is_noted_by_size_rather_than_printed_again(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(['-r', 'echo 1;'], briefingOnInput: true);

        $adapter->execute($this->request(output: $sink));

        self::assertStringContainsString('characters on standard input', $sink->notes[1]);
        self::assertStringNotContainsString('Acceptance criteria', $sink->notes[1]);
    }

    #[Test]
    public function a_stopped_attempt_notes_the_limit_that_ran_out(): void
    {
        $sink = new RecordingSink();
        $adapter = new ScriptedProcessAdapter(['-r', 'echo "working"; sleep(30);']);

        $adapter->execute($this->request(
            limits: new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200),
            output: $sink,
        ));

        self::assertSame('scripted was stopped after 1 second (the task timeout)', $sink->notes[2]);
        self::assertSame('working', $sink->textOf(OutputStream::Standard));
    }

    /**
     * The whole of the item, end to end, against the real destinations: an
     * agent's output on the terminal as it is produced and in the task's own
     * log afterwards, with the exit status and the elapsed time recorded in the
     * log by the package rather than claimed by the agent.
     */
    #[Test]
    public function an_attempt_is_shown_on_the_terminal_and_kept_in_the_tasks_log(): void
    {
        $terminal = new CapturingOutput();
        $request = $this->request();
        $logs = LogDirectory::in($this->project);
        $log = AttemptLog::open($logs, $request->generation(), $request->attempt(), $terminal->output());

        $adapter = new ScriptedProcessAdapter(
            ['-r', 'echo "reading the task\n"; fwrite(STDERR, "warning: slow\n"); exit(0);'],
        );

        $result = $adapter->execute($this->request(output: $log));
        $log->close();

        self::assertTrue($result->hasCandidate());

        self::assertStringContainsString('reading the task', $terminal->standardOutput());
        self::assertStringContainsString('warning: slow', $terminal->errorOutput());

        $kept = (string) file_get_contents($logs->agentLog($request->generation(), 1));
        self::assertStringContainsString('attempt 1, agent scripted', $kept);
        self::assertStringContainsString("--- stdout\nreading the task", $kept);
        self::assertStringContainsString("--- stderr\nwarning: slow", $kept);
        self::assertStringContainsString('--- scripted exited with status 0 after', $kept);
        self::assertGreaterThan(0, $log->bytes());
    }

    private function request(
        string $description = self::DESCRIPTION,
        ?Limits $limits = null,
        ?AgentDefinition $definition = null,
        ?OutputSink $output = null,
    ): ExecutionRequest {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . $description . "\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            new AgentSelection(
                $definition ?? new AgentDefinition('scripted', 'scripted', PHP_BINARY),
                AgentSource::ProjectDefault,
            ),
            TaskGeneration::first($task->id()),
            1,
            $task,
            Workspace::at($this->workspace),
            (new AttemptContextLoader())->loadFor(
                (new ConfigurationLoader())->defaults($this->project),
                $task->id(),
            ),
            ExecutionBudget::of($limits ?? new Limits(attemptsPerTask: 3, taskTimeout: 60, runTimeout: 7200)),
            null,
            $output,
        );
    }
}
