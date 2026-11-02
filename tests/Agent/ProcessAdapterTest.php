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
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
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

    private function request(
        string $description = self::DESCRIPTION,
        ?Limits $limits = null,
        ?AgentDefinition $definition = null,
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
        );
    }
}
