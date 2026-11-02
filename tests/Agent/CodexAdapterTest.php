<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Agent\CodexAdapterProvider;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ReportReader;
use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Running Codex as the loop runs it.
 *
 * PROJECT.md section E, item 6. The Codex CLI is not installed on a machine
 * running this suite, and the suite must pass without model credentials
 * (section J, item 8), so what stands in for it is a program named `codex`
 * that records the argument array and the standard input it was given and then
 * behaves as the test says. That is the half of the invocation this package is
 * responsible for: which subcommand, which flags, in which order, with the
 * prompt on which stream. Whether the real CLI then does the work is section J,
 * item 11's real run, which records the agent version it was tested against.
 *
 * The stub writes what it received into its own working directory, which is
 * also the assertion that it ran in the task's workspace rather than anywhere
 * else.
 */
#[CoversClass(CodexAdapter::class)]
#[CoversClass(CodexAdapterProvider::class)]
final class CodexAdapterTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * Where the stub records its arguments, relative to its working directory.
     */
    private const string ARGUMENTS_FILE = 'codex-arguments.json';

    /**
     * Where the stub records its prompt, relative to its working directory.
     */
    private const string PROMPT_FILE = 'codex-prompt.md';

    /**
     * The task's own workspace: the directory the agent must run in.
     */
    private string $workspace;

    /**
     * The controlling checkout, which nothing here may touch.
     */
    private string $project;

    /**
     * Where the `codex` stub is installed, named as the configuration names it.
     */
    private string $executable;

    protected function setUp(): void
    {
        $this->workspace = TemporaryDirectory::create('ralph-workspace');
        $this->project = TemporaryDirectory::create('ralph-project');
        $this->executable = $this->project . '/codex';
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->project);
    }

    /**
     * The invocation, whole and in order: the non-interactive subcommand, the
     * stated working root, the sandbox that confines the attempt to it, and the
     * placeholder that makes the prompt come from standard input.
     */
    #[Test]
    public function codex_is_run_non_interactively_in_the_tasks_workspace(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request());

        self::assertSame(
            [
                $this->executable,
                'exec',
                '--cd',
                $this->workspace,
                '--sandbox',
                'workspace-write',
                '-',
            ],
            $this->arguments(),
        );
    }

    /**
     * Plain `codex` draws a terminal UI and waits for a person; `codex exec` is
     * the headless form. An adapter that started the interactive command would
     * sit there until the task timeout killed it and report a timeout for a
     * setup mistake.
     */
    #[Test]
    public function the_non_interactive_subcommand_comes_first(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request());

        self::assertSame(CodexAdapter::COMMAND, $this->arguments()[1]);
    }

    /**
     * The briefing is tens of kilobytes of prose a user wrote. It goes over a
     * stream, which has no size limit of the kind an argument list has — and
     * nothing of the task reaches the argument array, where a reader of the log
     * would be seeing the first line of a prompt instead of a command.
     */
    #[Test]
    public function the_briefing_is_handed_over_on_standard_input_rather_than_as_an_argument(): void
    {
        $this->stub('echo "worked on it";');
        $request = $this->request();

        (new CodexAdapter())->execute($request);

        $prompt = $this->prompt();

        self::assertStringContainsString(self::DESCRIPTION, $prompt);
        // Whole and untouched, at the end of what Codex was handed.
        self::assertTrue(str_ends_with($prompt, $request->toMarkdown()));

        foreach ($this->arguments() as $argument) {
            self::assertStringNotContainsString(self::DESCRIPTION, $argument);
        }
    }

    /**
     * The common half of the briefing arrives intact, which is the part that
     * asks for the result block this adapter then reads: a preamble that
     * mangled it would be an adapter reading a format nothing asked for.
     */
    #[Test]
    public function the_prompt_still_asks_for_the_report_the_adapter_reads(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request());

        $prompt = $this->prompt();

        self::assertStringContainsString('# Reporting back', $prompt);
        self::assertTrue(ReportReader::readText($prompt)->hasReport());
    }

    /**
     * Each fact in the preamble is one an attempt would otherwise discover by
     * failing — and they are composed from the same constants that build the
     * invocation, so the prompt cannot describe a sandbox the adapter did not
     * ask for.
     */
    #[Test]
    public function the_prompt_says_how_codex_is_being_run(): void
    {
        $this->stub('echo "worked on it";');
        $request = $this->request();

        (new CodexAdapter())->execute($request);

        $prompt = $this->prompt();
        // Everything before the common briefing, which is the adapter's own.
        $preamble = substr($prompt, 0, (int) strpos($prompt, $request->toMarkdown()));

        self::assertStringStartsWith('# How you are being run', $prompt);
        self::assertStringContainsString(CodexAdapter::SANDBOX, $preamble);
        self::assertStringContainsString($this->workspace, $preamble);
        self::assertStringContainsString('never answered', $preamble);
        self::assertStringContainsString($this->executable . ' ' . CodexAdapter::COMMAND, $preamble);
    }

    /**
     * Section E, item 8's per-agent model, placed where only this CLI knows it
     * goes.
     */
    #[Test]
    public function a_configured_model_is_named_with_codexs_own_flag(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request(
            definition: new AgentDefinition('codex', 'codex', $this->executable, model: 'gpt-5-codex'),
        ));

        $arguments = $this->arguments();

        self::assertContains('--model', $arguments);
        self::assertSame('gpt-5-codex', $arguments[array_search('--model', $arguments, true) + 1]);
    }

    #[Test]
    public function no_model_flag_is_passed_when_the_agent_configures_none(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request());

        self::assertNotContains('--model', $this->arguments());
    }

    /**
     * Nothing the adapter chose is a setting in disguise: the project's own
     * arguments are appended after the adapter's flags and before the prompt,
     * so a repeated flag's last occurrence — the project's — is the one the CLI
     * keeps.
     */
    #[Test]
    public function the_projects_own_arguments_come_after_the_adapters_and_before_the_prompt(): void
    {
        $this->stub('echo "worked on it";');

        (new CodexAdapter())->execute($this->request(
            definition: new AgentDefinition(
                'codex',
                'codex',
                $this->executable,
                arguments: ['--sandbox', 'danger-full-access', '-c', 'sandbox_workspace_write.network_access=true'],
            ),
        ));

        self::assertSame(
            [
                $this->executable,
                'exec',
                '--cd',
                $this->workspace,
                '--sandbox',
                'workspace-write',
                '--sandbox',
                'danger-full-access',
                '-c',
                'sandbox_workspace_write.network_access=true',
                '-',
            ],
            $this->arguments(),
        );
    }

    /**
     * The transcript is Codex's own prose with the block somewhere in it, which
     * is what the plain output of a session looks like. What the loop acts on is
     * the block; the rest stays the agent's unverified account of itself.
     */
    #[Test]
    public function a_report_in_the_transcript_becomes_a_candidate_to_verify(): void
    {
        $this->stub($this->printing('{"outcome": "candidate", "summary": "Added the option and a test."}'));

        $result = (new CodexAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertTrue($result->hasCandidate());
        self::assertSame('Added the option and a test.', $result->claim()->text());
        self::assertSame(0, $result->exitCode());
    }

    /**
     * Section E, item 10 holds by construction rather than by two adapters
     * agreeing: the reading is {@see \Celyes\RalphPhp\Agent\ProcessAdapter}'s.
     */
    #[Test]
    public function a_question_from_codex_stops_the_run_for_a_person(): void
    {
        $this->stub($this->printing(
            '{"outcome": "blocked", "summary": "two import formats are plausible",'
                . ' "next_action": "say which format the flag applies to"}',
        ));

        $result = (new CodexAdapter())->execute($this->request());
        $blocker = $result->blocker();

        self::assertSame(AttemptOutcome::Blocked, $result->outcome());
        self::assertTrue($result->requiresHumanInput());
        self::assertNotNull($blocker);
        self::assertStringContainsString('say which format', $blocker->nextAction());
    }

    #[Test]
    public function a_session_that_reports_it_could_not_do_the_work_is_an_execution_error(): void
    {
        $this->stub($this->printing('{"outcome": "error", "summary": "the import command does not exist"}'));

        $result = (new CodexAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('could not do the work', $result->evidence()->label());
    }

    /**
     * Section 4's rule, reaching Codex unchanged: a session that exited 0
     * without reporting anything has not said it changed something, and
     * verifying and committing on the strength of an exit status is how work
     * nobody claimed gets committed.
     */
    #[Test]
    public function a_transcript_with_no_report_is_an_execution_error_whatever_the_status(): void
    {
        $this->stub('echo "I had a look around."; exit(0);');

        $result = (new CodexAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('without reporting a result', $result->evidence()->label());
    }

    #[Test]
    public function a_failed_session_is_an_execution_error_naming_its_status(): void
    {
        $this->stub('fwrite(STDERR, "stream error: unexpected status 429"); exit(1);');

        $result = (new CodexAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(1, $result->exitCode());
        self::assertStringContainsString('429', $result->evidence()->toMarkdown());
    }

    /**
     * Cancellation: the budget is the adapter's to pass on and
     * {@see \Celyes\RalphPhp\Agent\ProcessAdapter}'s to enforce, and a session
     * that overran it is recorded as a timeout rather than as an ordinary
     * failure — which is what keeps a user from raising the wrong limit.
     */
    #[Test]
    public function an_overrunning_session_is_stopped_and_recorded_as_a_timeout(): void
    {
        $sink = new RecordingSink();
        $this->stub('echo "thinking"; sleep(30); echo "never";');

        $result = (new CodexAdapter())->execute($this->request(
            limits: new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200),
            output: $sink,
        ));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertLessThan(10.0, $result->seconds());
        self::assertStringContainsString('was stopped after 1 second', implode(' ', $sink->notes));
        // What it printed before it was stopped is the explanation, and is kept.
        self::assertStringContainsString('thinking', $result->evidence()->toMarkdown());
    }

    /**
     * The three notes the package writes around any invocation, with Codex in
     * them: a log that says which attempt, what was started, and how it ended.
     */
    #[Test]
    public function the_invocation_is_noted_as_arguments_rather_than_a_shell_line(): void
    {
        $sink = new RecordingSink();
        $this->stub($this->printing('{"outcome": "candidate", "summary": "done"}'));

        (new CodexAdapter())->execute($this->request(output: $sink));

        self::assertStringContainsString('agent codex', $sink->notes[0]);
        self::assertStringContainsString($this->executable . ' exec --cd ' . $this->workspace, $sink->notes[1]);
        self::assertStringContainsString('characters on standard input', $sink->notes[1]);
        self::assertStringContainsString('codex exited with status 0', $sink->notes[2]);
    }

    /**
     * A shipped adapter reaches the registry the way a project's own does, and
     * under the name the package declares — the name attempts, logs and commits
     * are attributed to.
     */
    #[Test]
    public function the_adapter_is_registered_under_the_declared_built_in_name(): void
    {
        $provider = new CodexAdapterProvider();
        $registry = AdapterRegistry::default();

        self::assertSame(BuiltInAdapters::CODEX, $provider->adapter());
        self::assertSame(BuiltInAdapters::CODEX, (new CodexAdapter())->name());
        self::assertTrue($registry->has(BuiltInAdapters::CODEX));
        self::assertInstanceOf(CodexAdapter::class, $registry->create($this->selection()));
    }

    /**
     * One instance serves a whole run: the model and the extra arguments are
     * read from each request, so nothing about a task can be kept between
     * attempts.
     */
    #[Test]
    public function the_provider_builds_an_adapter_without_settling_anything_from_the_definition(): void
    {
        $provider = new CodexAdapterProvider();

        $first = $provider->create(new AgentDefinition('codex', 'codex', 'codex', model: 'gpt-5-codex'));
        $second = $provider->create(new AgentDefinition('codex', 'codex', '/opt/codex/bin/codex'));

        self::assertNotSame($first, $second);
        self::assertSame(BuiltInAdapters::CODEX, $first->name());
        self::assertSame(BuiltInAdapters::CODEX, $second->name());
    }

    /**
     * A program named as the configuration names Codex, which records what it
     * was handed and then does what the test says.
     *
     * PHP rather than a shell script, run through its own absolute binary: the
     * body is written as code, not composed into a command line, and the stub
     * is reached through the configured `executable` exactly as a real CLI is.
     */
    private function stub(string $body): void
    {
        $script = sprintf(
            "#!%s\n<?php\nfile_put_contents(getcwd() . '/%s', json_encode(\$argv));\n"
                . "file_put_contents(getcwd() . '/%s', stream_get_contents(STDIN));\n%s\n",
            PHP_BINARY,
            self::ARGUMENTS_FILE,
            self::PROMPT_FILE,
            $body,
        );

        if (file_put_contents($this->executable, $script) === false || !chmod($this->executable, 0o700)) {
            throw new RuntimeException('Unable to install the codex stub at ' . $this->executable . '.');
        }
    }

    /**
     * A stub body printing a result block, as a session's transcript holds it:
     * prose, the block, and the exit status.
     */
    private function printing(string $report, int $status = 0): string
    {
        return sprintf(
            "echo \"Reading the import command...\\n\";\necho %s;\nexit(%d);",
            var_export(sprintf("```%s\n%s\n```\n", ResultProtocol::FENCE, $report), true),
            $status,
        );
    }

    /**
     * The argument array the stub was started with, program included.
     *
     * Read from the workspace, because that is where the stub's working
     * directory was — which is the same assertion as
     * {@see \Celyes\RalphPhp\Tests\Agent\ProcessAdapterTest}'s, made again here
     * because a `--cd` that disagreed with the process's own directory would be
     * an adapter pointing Codex at another tree.
     *
     * @return list<string>
     */
    private function arguments(): array
    {
        $recorded = json_decode($this->read(self::ARGUMENTS_FILE), true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($recorded);

        return array_values(array_map(static fn (mixed $argument): string => (string) $argument, $recorded));
    }

    private function prompt(): string
    {
        return $this->read(self::PROMPT_FILE);
    }

    private function read(string $name): string
    {
        $path = $this->workspace . '/' . $name;

        self::assertFileExists($path, 'The codex stub did not run in the task workspace.');

        return (string) file_get_contents($path);
    }

    private function selection(?AgentDefinition $definition = null): AgentSelection
    {
        return new AgentSelection(
            $definition ?? new AgentDefinition('codex', 'codex', $this->executable),
            AgentSource::ProjectDefault,
        );
    }

    private function request(
        ?Limits $limits = null,
        ?AgentDefinition $definition = null,
        ?OutputSink $output = null,
    ): ExecutionRequest {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            $this->selection($definition),
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
