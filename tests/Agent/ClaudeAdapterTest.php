<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Agent\ClaudeAdapter;
use Celyes\RalphPhp\Agent\ClaudeAdapterProvider;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ReportReader;
use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
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
 * Running Claude Code as the loop runs it.
 *
 * PROJECT.md section E, item 7. The suite must pass without model credentials
 * (section J, item 8) — and a real `claude --print` would spend money, need an
 * account and answer differently every time — so what stands in for the CLI is
 * a program named `claude` that records the argument array and the standard
 * input it was given and then behaves as the test says. That is the half of the
 * invocation this package is responsible for: which flags, in which order, with
 * the prompt on which stream. Whether the real CLI then does the work is
 * section J, item 11's real run, which records the agent version it was tested
 * against.
 *
 * The flags themselves were read off `claude --help` at 2.1.226: `--print` is
 * the non-interactive form, `--output-format` takes `text`, `--permission-mode`
 * takes `bypassPermissions`, there is no `--cd`, and `--input-format` is
 * documented as working only with `--print` — which is what makes standard
 * input the prompt when no positional argument is given.
 *
 * The stub writes what it received into its own working directory, which is
 * also the assertion that it ran in the task's workspace rather than anywhere
 * else — and for this CLI that is the whole of how its project root is chosen,
 * there being no flag to state it with.
 */
#[CoversClass(ClaudeAdapter::class)]
#[CoversClass(ClaudeAdapterProvider::class)]
final class ClaudeAdapterTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * Where the stub records its arguments, relative to its working directory.
     */
    private const string ARGUMENTS_FILE = 'claude-arguments.json';

    /**
     * Where the stub records its prompt, relative to its working directory.
     */
    private const string PROMPT_FILE = 'claude-prompt.md';

    /**
     * The task's own workspace: the directory the agent must run in.
     */
    private string $workspace;

    /**
     * The controlling checkout, which nothing here may touch.
     */
    private string $project;

    /**
     * Where the `claude` stub is installed, named as the configuration names it.
     */
    private string $executable;

    protected function setUp(): void
    {
        $this->workspace = TemporaryDirectory::create('ralph-workspace');
        $this->project = TemporaryDirectory::create('ralph-project');
        $this->executable = $this->project . '/claude';
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->project);
    }

    /**
     * The invocation, whole and in order: the flag that runs without a
     * terminal, the streamed output format with the two flags this CLI needs
     * alongside it, the permission mode an unattended attempt needs — and no
     * positional argument, whose absence is what sends Claude Code to standard
     * input for its prompt.
     */
    #[Test]
    public function claude_is_run_non_interactively_in_the_tasks_workspace(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request());

        self::assertSame(
            [
                $this->executable,
                '--print',
                '--output-format',
                'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--permission-mode',
                'bypassPermissions',
            ],
            $this->arguments(),
        );
    }

    /**
     * Plain `claude` starts the interactive session: it draws a terminal UI and
     * waits for a person. An adapter that started it would sit there until the
     * task timeout killed it and report a timeout for a setup mistake.
     */
    #[Test]
    public function the_non_interactive_flag_comes_first(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request());

        self::assertSame(ClaudeAdapter::NON_INTERACTIVE, $this->arguments()[1]);
    }

    /**
     * There is no flag to state this CLI's project root with: it is the
     * directory the process was started in, which {@see ProcessAdapter}
     * guarantees and a subclass cannot change. So the stub having recorded
     * anything at all is the assertion — and `--add-dir` is pointedly absent,
     * because every directory it added would be one more place an attempt's
     * changes could land and never be committed.
     */
    #[Test]
    public function the_workspace_is_the_working_directory_and_the_only_one(): void
    {
        $this->stub('file_put_contents(getcwd() . "/where.txt", getcwd());');

        (new ClaudeAdapter())->execute($this->request());

        self::assertSame($this->workspace, $this->read('where.txt'));
        self::assertNotContains('--add-dir', $this->arguments());
        self::assertNotContains('--cd', $this->arguments());
    }

    /**
     * The briefing is tens of kilobytes of prose a user wrote. It goes over a
     * stream, which has no size limit of the kind an argument list has — and
     * passing no positional argument is not an omission but the thing that
     * makes the stream the prompt rather than ignored input.
     */
    #[Test]
    public function the_briefing_is_handed_over_on_standard_input_rather_than_as_an_argument(): void
    {
        $this->stub('echo "worked on it";');
        $request = $this->request();

        (new ClaudeAdapter())->execute($request);

        $prompt = $this->prompt();

        self::assertStringContainsString(self::DESCRIPTION, $prompt);
        // Whole and untouched, at the end of what Claude Code was handed.
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

        (new ClaudeAdapter())->execute($this->request());

        $prompt = $this->prompt();

        self::assertStringContainsString('# Reporting back', $prompt);
        self::assertTrue(ReportReader::readText($prompt)->hasReport());
    }

    /**
     * Each fact in the preamble is one an attempt would otherwise discover by
     * failing — and they are composed from the same constants that build the
     * invocation, so the prompt cannot describe a permission mode the adapter
     * did not ask for.
     */
    #[Test]
    public function the_prompt_says_how_claude_is_being_run(): void
    {
        $this->stub('echo "worked on it";');
        $request = $this->request();

        (new ClaudeAdapter())->execute($request);

        $prompt = $this->prompt();
        // Everything before the common briefing, which is the adapter's own.
        $preamble = substr($prompt, 0, (int) strpos($prompt, $request->toMarkdown()));

        self::assertStringStartsWith('# How you are being run', $prompt);
        self::assertStringContainsString(ClaudeAdapter::PERMISSIONS, $preamble);
        self::assertStringContainsString($this->workspace, $preamble);
        self::assertStringContainsString('never answered', $preamble);
        self::assertStringContainsString($this->executable . ' ' . ClaudeAdapter::NON_INTERACTIVE, $preamble);
    }

    /**
     * The asymmetry with Codex that an operator should not have to discover:
     * this CLI has no sandbox, so the adapter cannot make a write outside the
     * workspace *fail* — only make sure the agent knows such a write is thrown
     * away. And only the last message is captured, so a report printed before
     * it is a report nothing read.
     */
    #[Test]
    public function the_prompt_says_what_this_invocation_cannot_enforce(): void
    {
        $this->stub('echo "worked on it";');
        $request = $this->request();

        (new ClaudeAdapter())->execute($request);

        $preamble = substr($this->prompt(), 0, (int) strpos($this->prompt(), $request->toMarkdown()));

        self::assertStringContainsString('nothing will stop you writing outside it', $preamble);
        self::assertStringContainsString('final message', $preamble);
    }

    /**
     * Section E, item 8's per-agent model, placed where only this CLI knows it
     * goes.
     */
    #[Test]
    public function a_configured_model_is_named_with_claudes_own_flag(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request(
            definition: new AgentDefinition('claude', 'claude', $this->executable, model: 'claude-opus-5'),
        ));

        $arguments = $this->arguments();

        self::assertContains('--model', $arguments);
        self::assertSame('claude-opus-5', $arguments[array_search('--model', $arguments, true) + 1]);
    }

    #[Test]
    public function no_model_flag_is_passed_when_the_agent_configures_none(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request());

        self::assertNotContains('--model', $this->arguments());
    }

    /**
     * Nothing the adapter chose is a setting in disguise: the project's own
     * arguments are appended after the adapter's flags, so a repeated option's
     * last occurrence — the project's — is the one the CLI keeps. A project
     * that would rather give up running commands than approve everything says
     * so here.
     */
    #[Test]
    public function the_projects_own_arguments_come_after_the_adapters(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request(
            definition: new AgentDefinition(
                'claude',
                'claude',
                $this->executable,
                arguments: ['--permission-mode', 'acceptEdits', '--append-system-prompt', 'Prefer small diffs.'],
            ),
        ));

        self::assertSame(
            [
                $this->executable,
                '--print',
                '--output-format',
                'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--permission-mode',
                'bypassPermissions',
                '--permission-mode',
                'acceptEdits',
                '--append-system-prompt',
                'Prefer small diffs.',
            ],
            $this->arguments(),
        );
    }

    /**
     * A model and the project's arguments together, in the order the class
     * description promises: the adapter's flags, the model, the project's last.
     */
    #[Test]
    public function a_model_is_placed_before_the_projects_own_arguments(): void
    {
        $this->stub('echo "worked on it";');

        (new ClaudeAdapter())->execute($this->request(
            definition: new AgentDefinition(
                'claude',
                'claude',
                $this->executable,
                model: 'sonnet',
                arguments: ['--model', 'opus'],
            ),
        ));

        $arguments = $this->arguments();

        self::assertSame(['--model', 'sonnet', '--model', 'opus'], array_slice($arguments, -4));
    }

    /**
     * In print mode the CLI prints its final message, so what the adapter reads
     * is that message with the block in it. What the loop acts on is the block;
     * the rest stays the agent's unverified account of itself.
     */
    #[Test]
    public function a_report_in_the_final_message_becomes_a_candidate_to_verify(): void
    {
        $this->stub($this->printing('{"outcome": "candidate", "summary": "Added the option and a test."}'));

        $result = (new ClaudeAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertTrue($result->hasCandidate());
        self::assertSame('Added the option and a test.', $result->claim()->text());
        self::assertSame(0, $result->exitCode());
    }

    /**
     * PROJECT.md section K: the session reaches the terminal while it is
     * happening, not as the JSON it arrives in.
     *
     * The stub prints the events of a short session with a pause in the middle,
     * so what is asserted is both halves: the tool call was shown before the
     * session had ended — one chunk, then another, which is what streaming
     * means — and what was shown is the rendering rather than the envelopes. A
     * sink handed the whole feed at the end would satisfy a test that only
     * looked at the text.
     */
    #[Test]
    public function the_session_is_shown_while_it_happens_rather_than_as_json(): void
    {
        $sink = new RecordingSink();
        $this->stub($this->streaming(
            '{"outcome": "candidate", "summary": "Added the option and a test."}',
            pauseMicroseconds: 300000,
        ));

        (new ClaudeAdapter())->execute($this->request(output: $sink));

        $shown = $sink->textOf(OutputStream::Standard);

        self::assertGreaterThanOrEqual(2, $sink->chunkCountOf(OutputStream::Standard));
        self::assertStringContainsString('claude claude-opus-5 · session 01234567', $shown);
        self::assertStringContainsString('✻ thinking Reading the import command', $shown);
        self::assertStringContainsString('⏺ Bash(vendor/bin/phpunit --filter Import)', $shown);
        self::assertStringContainsString('⎿ OK (3 tests, 5 assertions)', $shown);
        self::assertStringContainsString('✓ done · 4 turns', $shown);
        // The envelopes themselves are not what a reader is shown.
        self::assertStringNotContainsString('{"type":', $shown);
        // The tool call was on the terminal before the session's last event.
        self::assertLessThan(
            strpos($shown, '✓ done'),
            (int) strpos($shown, '⏺ Bash'),
        );
    }

    /**
     * The format changed what a person sees and nothing about what the loop
     * acts on: the report is read out of the session's final message, which the
     * stream carries as its own field.
     */
    #[Test]
    public function a_report_in_the_streamed_final_message_becomes_a_candidate_to_verify(): void
    {
        $this->stub($this->streaming('{"outcome": "candidate", "summary": "Added the option and a test."}'));

        $result = (new ClaudeAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertSame('Added the option and a test.', $result->claim()->text());
        self::assertSame(0, $result->exitCode());
    }

    /**
     * What a failed session said, in the evidence the next attempt is handed:
     * the agent's own words out of the stream, and the errors the result event
     * carried — which name a failure its final message may not mention.
     */
    #[Test]
    public function a_failed_streamed_session_has_the_agents_words_in_its_evidence(): void
    {
        $this->stub($this->streaming(
            '{"outcome": "error", "summary": "the import command does not exist"}',
            errors: ['tool use limit reached'],
        ));

        $result = (new ClaudeAdapter())->execute($this->request());
        $evidence = $result->evidence()->toMarkdown();

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('could not do the work', $result->evidence()->label());
        self::assertStringContainsString('tool use limit reached', $evidence);
        self::assertStringContainsString('Added the option', $evidence);
        // The feed itself is not what a person is asked to read.
        self::assertStringNotContainsString('"stream_event"', $evidence);
    }

    /**
     * Output with no events in it is left alone, by the renderer and by the
     * reading both.
     *
     * Not a leftover from the earlier format but the case a project creates by
     * putting `--output-format text` in `agents.claude.arguments`, where its
     * occurrence is the one the CLI keeps — and the case a CLI creates by
     * printing a fail-fast failure as prose before it emits anything. Either
     * way the words are shown as they came and read as they came, rather than
     * becoming an attempt that reported nothing.
     */
    #[Test]
    public function a_session_that_printed_no_events_is_shown_and_read_as_it_came(): void
    {
        $sink = new RecordingSink();
        $this->stub($this->printing('{"outcome": "candidate", "summary": "Added the option."}'));

        $result = (new ClaudeAdapter())->execute($this->request(output: $sink));

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertStringContainsString(
            'Reading the import command...',
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * Section E, item 10 holds by construction rather than by two adapters
     * agreeing: the reading is {@see \Celyes\RalphPhp\Agent\ProcessAdapter}'s,
     * so the same report from either CLI stops the run the same way.
     */
    #[Test]
    public function a_question_from_claude_stops_the_run_for_a_person(): void
    {
        $this->stub($this->printing(
            '{"outcome": "blocked", "summary": "two import formats are plausible",'
                . ' "next_action": "say which format the flag applies to"}',
        ));

        $result = (new ClaudeAdapter())->execute($this->request());
        $blocker = $result->blocker();

        self::assertSame(AttemptOutcome::Blocked, $result->outcome());
        self::assertTrue($result->requiresHumanInput());
        self::assertNotNull($blocker);
        self::assertStringContainsString('say which format', $blocker->nextAction());
        self::assertSame('claude', $blocker->reportedBy());
    }

    #[Test]
    public function a_session_that_reports_it_could_not_do_the_work_is_an_execution_error(): void
    {
        $this->stub($this->printing('{"outcome": "error", "summary": "the import command does not exist"}'));

        $result = (new ClaudeAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('could not do the work', $result->evidence()->label());
    }

    /**
     * Section 4's rule, reaching Claude Code unchanged: a session that exited 0
     * without reporting anything has not said it changed something, and
     * verifying and committing on the strength of an exit status is how work
     * nobody claimed gets committed.
     */
    #[Test]
    public function a_final_message_with_no_report_is_an_execution_error_whatever_the_status(): void
    {
        $this->stub('echo "I had a look around."; exit(0);');

        $result = (new ClaudeAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertStringContainsString('without reporting a result', $result->evidence()->label());
    }

    #[Test]
    public function a_failed_session_is_an_execution_error_naming_its_status(): void
    {
        $this->stub('fwrite(STDERR, "Invalid API key - please run /login"); exit(1);');

        $result = (new ClaudeAdapter())->execute($this->request());

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(1, $result->exitCode());
        self::assertStringContainsString('Invalid API key', $result->evidence()->toMarkdown());
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

        $result = (new ClaudeAdapter())->execute($this->request(
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
     * No session is ever resumed: each attempt is given its briefing afresh, so
     * a stopped session is not state the next attempt reads — which is what
     * makes abandoning one safe.
     */
    #[Test]
    public function no_earlier_session_is_continued_or_resumed(): void
    {
        $this->stub($this->printing('{"outcome": "candidate", "summary": "done"}'));

        (new ClaudeAdapter())->execute($this->request(attempt: 3));

        $arguments = $this->arguments();

        self::assertNotContains('--continue', $arguments);
        self::assertNotContains('-c', $arguments);
        self::assertNotContains('--resume', $arguments);
        self::assertNotContains('-r', $arguments);
    }

    /**
     * The three notes the package writes around any invocation, with Claude
     * Code in them: a log that says which attempt, what was started, and how it
     * ended.
     */
    #[Test]
    public function the_invocation_is_noted_as_arguments_rather_than_a_shell_line(): void
    {
        $sink = new RecordingSink();
        $this->stub($this->printing('{"outcome": "candidate", "summary": "done"}'));

        (new ClaudeAdapter())->execute($this->request(output: $sink));

        self::assertStringContainsString('agent claude', $sink->notes[0]);
        self::assertStringContainsString(
            $this->executable . ' --print --output-format stream-json --verbose --include-partial-messages',
            $sink->notes[1],
        );
        self::assertStringContainsString('characters on standard input', $sink->notes[1]);
        self::assertStringContainsString('claude exited with status 0', $sink->notes[2]);
    }

    /**
     * A shipped adapter reaches the registry the way a project's own does, and
     * under the name the package declares — the name attempts, logs and commits
     * are attributed to.
     */
    #[Test]
    public function the_adapter_is_registered_under_the_declared_built_in_name(): void
    {
        $provider = new ClaudeAdapterProvider();
        $registry = AdapterRegistry::default();

        self::assertSame(BuiltInAdapters::CLAUDE, $provider->adapter());
        self::assertSame(BuiltInAdapters::CLAUDE, (new ClaudeAdapter())->name());
        self::assertTrue($registry->has(BuiltInAdapters::CLAUDE));
        self::assertInstanceOf(ClaudeAdapter::class, $registry->create($this->selection()));
    }

    /**
     * One instance serves a whole run: the model and the extra arguments are
     * read from each request, so nothing about a task can be kept between
     * attempts.
     */
    #[Test]
    public function the_provider_builds_an_adapter_without_settling_anything_from_the_definition(): void
    {
        $provider = new ClaudeAdapterProvider();

        $first = $provider->create(new AgentDefinition('claude', 'claude', 'claude', model: 'claude-opus-5'));
        $second = $provider->create(new AgentDefinition('claude', 'claude', '/opt/claude/bin/claude'));

        self::assertNotSame($first, $second);
        self::assertSame(BuiltInAdapters::CLAUDE, $first->name());
        self::assertSame(BuiltInAdapters::CLAUDE, $second->name());
    }

    /**
     * The default configuration's own `claude` agent builds and runs, which is
     * the path a user who ran `ralph init` and nothing else takes: the name, the
     * adapter behind it and the executable the defaults name all agree.
     */
    #[Test]
    public function the_default_configurations_claude_agent_is_buildable(): void
    {
        $configuration = (new ConfigurationLoader())->defaults($this->project);
        $definition = $configuration->agent(BuiltInAdapters::CLAUDE);

        self::assertInstanceOf(AgentDefinition::class, $definition);
        self::assertSame(BuiltInAdapters::CLAUDE, $definition->adapter());
        self::assertInstanceOf(
            ClaudeAdapter::class,
            AdapterRegistry::default()->create(new AgentSelection($definition, AgentSource::CommandLine)),
        );
    }

    /**
     * A program named as the configuration names Claude Code, which records
     * what it was handed and then does what the test says.
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
            throw new RuntimeException('Unable to install the claude stub at ' . $this->executable . '.');
        }
    }

    /**
     * A stub body printing a result block, as a print-mode final message holds
     * it: the agent's prose, the block, and the exit status.
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
     * A stub body printing a session as `stream-json` carries it.
     *
     * The events of a short attempt in the order the real CLI emits them: the
     * session's own line, a thought, a tool call, its result, and the closing
     * result event with the final message in it. Written as the CLI writes
     * them — one JSON object per line — because what is under test is the
     * package's reading of that format, and a fixture that pretty-printed it
     * would be testing a format nothing produces.
     *
     * @param list<string> $errors what the result event reports went wrong
     * @param int $pauseMicroseconds how long the session takes between events,
     *        for a test that needs to see output arrive before it ends
     */
    private function streaming(
        string $report,
        int $status = 0,
        array $errors = [],
        int $pauseMicroseconds = 0,
    ): string {
        $message = sprintf("Added the option.\n\n```%s\n%s\n```", ResultProtocol::FENCE, $report);

        $events = [
            ['type' => 'system', 'subtype' => 'init', 'session_id' => '0123456789abcdef', 'model' => 'claude-opus-5'],
            [
                'type' => 'stream_event',
                'event' => ['type' => 'content_block_start', 'content_block' => ['type' => 'thinking']],
            ],
            [
                'type' => 'stream_event',
                'event' => [
                    'type' => 'content_block_delta',
                    'delta' => ['type' => 'thinking_delta', 'thinking' => 'Reading the import command'],
                ],
            ],
            ['type' => 'stream_event', 'event' => ['type' => 'content_block_stop']],
            [
                'type' => 'assistant',
                'message' => [
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'name' => 'Bash',
                            'input' => ['command' => 'vendor/bin/phpunit --filter Import'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'user',
                'message' => [
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'content' => [['type' => 'text', 'text' => 'OK (3 tests, 5 assertions)']],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'stream_event',
                'event' => [
                    'type' => 'content_block_delta',
                    'delta' => ['type' => 'text_delta', 'text' => 'Added the option.'],
                ],
            ],
            [
                'type' => 'result',
                'subtype' => $status === 0 ? 'success' : 'error_during_execution',
                'is_error' => $status !== 0,
                'num_turns' => 4,
                'duration_ms' => 8123,
                'total_cost_usd' => 0.0412,
                'result' => $message,
                'errors' => $errors,
            ],
        ];

        $lines = array_map(
            static fn (array $event): string => (string) json_encode($event, JSON_UNESCAPED_SLASHES),
            $events,
        );

        return sprintf(
            "foreach (%s as \$line) {\n    echo \$line, \"\\n\";\n    usleep(%d);\n}\nexit(%d);",
            var_export($lines, true),
            $pauseMicroseconds,
            $status,
        );
    }

    /**
     * The argument array the stub was started with, program included.
     *
     * Read from the workspace, because that is where the stub's working
     * directory was — which for this CLI is also the only statement of its
     * project root, there being no flag to say it with.
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

        self::assertFileExists($path, 'The claude stub did not run in the task workspace.');

        return (string) file_get_contents($path);
    }

    private function selection(?AgentDefinition $definition = null): AgentSelection
    {
        return new AgentSelection(
            $definition ?? new AgentDefinition('claude', 'claude', $this->executable),
            AgentSource::ProjectDefault,
        );
    }

    private function request(
        ?Limits $limits = null,
        ?AgentDefinition $definition = null,
        ?OutputSink $output = null,
        int $attempt = 1,
    ): ExecutionRequest {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            $this->selection($definition),
            TaskGeneration::first($task->id()),
            $attempt,
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
