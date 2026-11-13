<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Agent\ClaudeAdapter;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Process\Interruptions;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Fixture\FixtureMode;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Fixture\FixtureWorker;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped adapter running a scripted agent, end to end.
 *
 * PROJECT.md section J, item 4: each built-in adapter's command construction
 * and result normalisation, against a deterministic fixture, over the five
 * things an attempt can do — succeed, report a block nothing can read, ask a
 * question, fail as a process, and be stopped.
 *
 * **Why it is a third adapter test.** The two files before this one script a
 * stub per CLI and assert the command line that adapter builds;
 * {@see AdapterNormalizationTest} scripts one session and compares what the
 * adapters made of it. Both stop at the adapter's own edges. What neither holds
 * is that a *real process* — one the package found, started, briefed, streamed
 * and stopped — reaches the agent the way the command line says it does, and
 * that what comes back is the normalised result the runner acts on. That needs
 * an agent that reports from the inside, which is the fixture worker of section
 * E, item 12: the journal says which argument array arrived, in which
 * directory, with which briefing, and whether the worker was asked to stop
 * before it died. An adapter could satisfy every assertion in the two earlier
 * files and still hand its agent a prompt it never read.
 *
 * **Driven from the catalogue.** Every case runs once per adapter
 * {@see BuiltInAdapters::providers()} lists, and the adapter is built through
 * {@see AdapterRegistry::default()} from an ordinary agent definition — the
 * path a run takes. A third shipped adapter is held to all of it by existing,
 * and {@see self::every_shipped_adapter_has_its_invocation_written_down()}
 * fails until its command line is written down here.
 *
 * **The invocation is asserted as delivered, not as built.** The expected
 * argument arrays below are composed from each adapter's own constants, and
 * what they are compared against is what the worker recorded of its `argv`. So
 * the two halves of section E, item 2 are checked where they can no longer be
 * assumed: the task description is in the briefing on standard input and in no
 * argument, and the process's working directory *is* the task workspace rather
 * than a directory the command line merely names.
 *
 * No agent CLI, no credentials and no network are involved (section J, item 8).
 */
#[CoversClass(CodexAdapter::class)]
#[CoversClass(ClaudeAdapter::class)]
#[CoversClass(BuiltInAdapters::class)]
final class BuiltInAdapterFixtureTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * The agent name the worker is configured under.
     *
     * One name for every adapter: `agents` entries are user-chosen labels, and
     * the names in a log note and in a piece of evidence are the configured
     * agent's, so holding it fixed keeps a case's assertions about them from
     * being about which adapter ran.
     */
    private const string AGENT = 'fixture';

    /**
     * The signal {@see \Celyes\RalphPhp\Process\ProcessRunner} asks a worker to
     * stop with, as a number.
     *
     * Written out rather than taken from `SIGTERM`, because this process is not
     * required to have ext-pcntl — only the worker uses it, and only to notice
     * the signal arriving.
     */
    private const int SIGTERM = 15;

    /**
     * The task's own workspace: the directory every adapter must run in.
     */
    private string $workspace;

    /**
     * Where the worker is installed, which is deliberately not the workspace:
     * its program, plan and journal would otherwise be in the diff a run
     * stages.
     */
    private string $tools;

    /**
     * A project directory, for the default configuration an attempt's context
     * is loaded from.
     */
    private string $project;

    protected function setUp(): void
    {
        $this->workspace = TemporaryDirectory::create('ralph-workspace');
        $this->tools = TemporaryDirectory::create('ralph-tools');
        $this->project = TemporaryDirectory::create('ralph-project');
    }

    protected function tearDown(): void
    {
        // Process-wide by design, so a case that stopped a run puts it back
        // before the next one starts — including the flag, which would
        // otherwise refuse to start the next case's worker at all.
        Interruptions::release();

        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->tools);
        TemporaryDirectory::remove($this->project);
    }

    /**
     * Each adapter the package ships, by the name it registers under.
     *
     * @return iterable<string, array{string}>
     */
    public static function adapters(): iterable
    {
        foreach (BuiltInAdapters::providers() as $provider) {
            yield $provider->adapter() => [$provider->adapter()];
        }
    }

    /**
     * The command line, as the agent received it.
     *
     * The whole array and in order, because the order is the part a CLI reads:
     * the adapter's own flags, then the project's, then whatever placeholder
     * sends that agent to standard input for its prompt.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_starts_the_agent_with_the_invocation_it_declares(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools);

        $this->attemptWith($adapter, $worker);

        self::assertSame($this->invocationOf($adapter), $worker->attempt()->arguments());
    }

    /**
     * The briefing arrives whole, on standard input, and in no argument.
     *
     * Section E, item 2's guarantee from the receiving end: a task description
     * is text an agent reads, and there is no point on this path where it is a
     * word in a command line. The briefing is compared against the request's
     * own Markdown, so an adapter that truncated it — or sent its preamble and
     * forgot the task — fails here rather than producing an agent that worked
     * on half a sentence.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_hands_the_whole_briefing_over_on_standard_input(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools);
        $request = $this->requestFor($adapter, $worker);

        $this->adapterFor($adapter, $worker)->execute($request);

        $briefing = $worker->attempt()->briefing();

        self::assertStringContainsString(self::DESCRIPTION, $briefing);
        // Whole and untouched, at the end of what the agent was handed: what
        // comes before it is that adapter's own preamble about being run.
        self::assertTrue(
            str_ends_with($briefing, $request->toMarkdown()),
            'The common briefing did not arrive whole at the end of the prompt.',
        );
        // And the block this attempt is judged by was asked for.
        self::assertStringContainsString(ResultProtocol::FENCE, $briefing);

        foreach ($worker->attempt()->arguments() as $argument) {
            self::assertStringNotContainsString(self::DESCRIPTION, $argument);
        }
    }

    /**
     * The process ran in the task's workspace and nowhere else.
     *
     * Asked of the worker rather than of the adapter, because a command line
     * that names a directory and a process that is started somewhere else is
     * exactly the mistake this cannot otherwise catch — and the controlling
     * checkout is what the package has promised not to touch (section F, item
     * 1).
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_runs_the_agent_in_the_task_workspace(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools);

        $this->attemptWith($adapter, $worker);

        self::assertSame($this->workspace, $worker->attempt()->directory());
        self::assertNotSame($this->tools, $worker->attempt()->directory());
    }

    /**
     * Success: a candidate to verify, with the agent's claim kept as a claim.
     *
     * The edit is asserted in the workspace because that is what the attempt
     * leaves for section F to stage, and the claim because it is what a user
     * reads next to a commit nobody has checked yet.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_reads_a_scripted_success_as_a_candidate(string $adapter): void
    {
        $step = FixtureStep::success(['src/Import.php' => "<?php // the dry run\n"]);
        $worker = FixtureWorker::install($this->tools, [$step]);

        $result = $this->attemptWith($adapter, $worker);

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertTrue($result->hasCandidate());
        self::assertSame($step->summary(), $result->claim()->text());
        self::assertSame(0, $result->exitCode());
        self::assertFileExists($this->workspace . '/src/Import.php');
        self::assertSame(FixtureMode::Success, $worker->attempt()->mode());
    }

    /**
     * Malformed output: a block in the right place that is not a report.
     *
     * Section E, item 5's refusal, through a real process. The distinction that
     * matters is from the case below it: there is something to quote, so the
     * evidence holds the block and says what was wrong with it, and the next
     * attempt is told that rather than being told nothing arrived. No claim is
     * taken from it — a summary read out of a block the package refused would
     * be a claim it had already decided it could not read.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_refuses_a_result_block_it_cannot_read(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::success()->reportingMalformed(),
        ]);

        $result = $this->attemptWith($adapter, $worker);
        $evidence = $result->evidence()->toMarkdown();

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertFalse($result->hasCandidate());
        self::assertFalse($result->requiresHumanInput());
        self::assertTrue($result->isRetryable());
        self::assertSame('', $result->claim()->text());
        self::assertStringContainsString('could not read', $result->evidence()->label());
        // The block itself, and why it was refused.
        self::assertStringContainsString(ResultProtocol::OUTCOME, $evidence);
        self::assertStringContainsString('not valid JSON', $evidence);
        // And what the next attempt has to do instead.
        self::assertStringContainsString('one fenced `' . ResultProtocol::FENCE . '` block', $evidence);
    }

    /**
     * Nothing reported at all, which is the other half of the same rule.
     *
     * Here beside the refusal above because the pair is the point: an exit
     * status of zero finishes nothing (section 4), and the two silences are
     * told apart in the evidence a user and the next attempt read.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_refuses_a_session_that_reported_nothing(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::success()->reportingNothing(),
        ]);

        $result = $this->attemptWith($adapter, $worker);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertFalse($result->hasCandidate());
        self::assertSame(0, $result->exitCode());
        self::assertStringContainsString(
            'exited with status 0 without reporting a result',
            $result->evidence()->label(),
        );
    }

    /**
     * Blocking: a question reaches the run as a question.
     *
     * Both halves of it, because a blocker without a next action is a stop
     * nobody can act on — and the outcome is not retryable, which is what
     * keeps an attempt from being spent asking the same thing again.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_turns_a_scripted_question_into_a_blocked_attempt(string $adapter): void
    {
        $step = FixtureStep::blocking(
            'two import formats are plausible',
            'say which format the flag applies to',
        );
        $worker = FixtureWorker::install($this->tools, [$step]);

        $result = $this->attemptWith($adapter, $worker);
        $blocker = $result->blocker();

        self::assertSame(AttemptOutcome::Blocked, $result->outcome());
        self::assertTrue($result->requiresHumanInput());
        self::assertFalse($result->hasCandidate());
        self::assertNotNull($blocker);
        self::assertStringContainsString($step->summary(), $blocker->reason());
        self::assertSame($step->nextAction(), $blocker->nextAction());
        self::assertSame(self::AGENT, $blocker->reportedBy());
        self::assertSame($step->summary(), $result->claim()->text());
    }

    /**
     * Process failure: an agent that said it could not do the work, and exited
     * saying so too.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_reads_a_scripted_failure_as_an_execution_error(string $adapter): void
    {
        $step = FixtureStep::failure('the import command does not exist');
        $worker = FixtureWorker::install($this->tools, [$step]);

        $result = $this->attemptWith($adapter, $worker);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(1, $result->exitCode());
        self::assertTrue($result->isRetryable());
        self::assertSame($step->summary(), $result->claim()->text());
        self::assertStringContainsString('could not do the work', $result->evidence()->label());
    }

    /**
     * Process failure of the other kind: a program that died without saying
     * anything.
     *
     * A status no agent chose — the fixture's own error status — and no block,
     * which is what a CLI that crashed on a bad flag or a missing dependency
     * leaves behind. The status is named in the evidence, because it is the
     * only thing there is to go on.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_reports_a_crashed_agent_with_the_status_it_died_with(string $adapter): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::failure()->reportingNothing()->exitingWith(127),
        ]);

        $result = $this->attemptWith($adapter, $worker);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(127, $result->exitCode());
        self::assertSame('', $result->claim()->text());
        self::assertStringContainsString(
            'exited with status 127 without reporting a result',
            $result->evidence()->label(),
        );
    }

    /**
     * Cancellation: a run stopped while the agent was working.
     *
     * The real thing rather than a flag set beforehand — the worker signals the
     * process that started it, so Ctrl+C arrives mid-attempt exactly as it
     * would from a terminal (PROJECT.md section H, item 4). What has to come
     * back is a cancellation and not a timeout: the limit was never reached, and
     * a user shown a timeout here raises the wrong one. Nothing is interpreted
     * from the partial output, so a session that was about to report a candidate
     * is not read as one; the attempt stays retryable, which is what makes an
     * interrupted run resumable.
     *
     * The worker's own journal is the other half: it was *asked* to stop, with
     * a termination signal, and it is gone afterwards. That the package stopped
     * waiting is something its own code could claim; that the worker was asked
     * only the worker can report.
     */
    #[Test]
    #[DataProvider('adapters')]
    public function each_adapter_records_an_attempt_the_user_stopped_as_cancelled(string $adapter): void
    {
        $this->requireSignals();

        $sink = new RecordingSink();
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::interruption(15)
                ->editing(['src/Half.php' => "<?php // half done\n"])
                ->interruptingTheRunner(),
        ]);

        Interruptions::listen();

        $result = $this->attemptWith(
            $adapter,
            $worker,
            // Comfortably longer than the worker waits, so that a cancellation
            // that failed to happen would be read as a timeout and fail here
            // rather than pass as the thing it is not.
            new Limits(attemptsPerTask: 3, taskTimeout: 60, runTimeout: 7200),
            $sink,
        );

        self::assertSame(AttemptOutcome::Cancelled, $result->outcome());
        self::assertNull($result->exitCode(), 'A stopped attempt reported no verdict on the work.');
        self::assertFalse($result->hasCandidate());
        self::assertFalse($result->requiresHumanInput());
        self::assertTrue($result->isRetryable());
        self::assertSame('', $result->claim()->text());
        self::assertStringContainsString('was stopped', $result->evidence()->label());
        self::assertStringContainsString('SIGINT', $result->evidence()->label());
        self::assertStringContainsString('was stopped', implode(' ', $sink->notes));

        self::assertSame([self::SIGTERM], $worker->signals());
        self::assertFalse(FixtureWorker::isRunning($worker->attempt()->pid()));
        // Whatever it had done is still there, for section F, item 10 to keep
        // and section H's reconciliation to cope with.
        self::assertFileExists($this->workspace . '/src/Half.php');
    }

    /**
     * Every adapter the package ships has its command line written down here.
     *
     * A guard on the table rather than on the adapters: each case above
     * compares what the agent received against {@see self::invocationOf()}, and
     * a third built-in adapter would otherwise be run by every case and have
     * its invocation compared against nothing.
     */
    #[Test]
    public function every_shipped_adapter_has_its_invocation_written_down(): void
    {
        $providers = BuiltInAdapters::providers();

        self::assertGreaterThan(1, count($providers));

        foreach ($providers as $provider) {
            self::assertNotSame(
                [],
                $this->invocationOf($provider->adapter()),
                sprintf('The "%s" adapter\'s invocation is not written down in this test.', $provider->adapter()),
            );
        }
    }

    /**
     * The argument array each shipped adapter starts its agent with, without
     * the program itself.
     *
     * Composed from the adapters' own constants, so this cannot assert a flag
     * nobody passes — and left empty for a name it does not know, which is
     * what {@see self::every_shipped_adapter_has_its_invocation_written_down()}
     * refuses.
     *
     * @return list<string>
     */
    private function invocationOf(string $adapter): array
    {
        return match ($adapter) {
            BuiltInAdapters::CODEX => [
                CodexAdapter::COMMAND,
                '--cd',
                $this->workspace,
                '--sandbox',
                CodexAdapter::SANDBOX,
                CodexAdapter::PROMPT_FROM_INPUT,
            ],
            BuiltInAdapters::CLAUDE => [
                ClaudeAdapter::NON_INTERACTIVE,
                '--output-format',
                ClaudeAdapter::OUTPUT_FORMAT,
                ClaudeAdapter::VERBOSE,
                ClaudeAdapter::PARTIAL_MESSAGES,
                '--permission-mode',
                ClaudeAdapter::PERMISSIONS,
            ],
            default => [],
        };
    }

    /**
     * Run one attempt at the task with one adapter.
     */
    private function attemptWith(
        string $adapter,
        FixtureWorker $worker,
        ?Limits $limits = null,
        ?OutputSink $output = null,
    ): ExecutionResult {
        return $this->adapterFor($adapter, $worker)->execute(
            $this->requestFor($adapter, $worker, $limits, $output),
        );
    }

    /**
     * The adapter, built the way a run builds it: from the registry, for an
     * ordinary agent definition.
     */
    private function adapterFor(string $adapter, FixtureWorker $worker): ExecutionAdapter
    {
        return AdapterRegistry::default()->create(
            new AgentSelection($this->definition($adapter, $worker), AgentSource::ProjectDefault),
        );
    }

    /**
     * The configured agent: one name, the installed worker as its executable,
     * and nothing agent-specific.
     */
    private function definition(string $adapter, FixtureWorker $worker): AgentDefinition
    {
        return new AgentDefinition(self::AGENT, $adapter, $worker->path());
    }

    private function requestFor(
        string $adapter,
        FixtureWorker $worker,
        ?Limits $limits = null,
        ?OutputSink $output = null,
    ): ExecutionRequest {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            new AgentSelection($this->definition($adapter, $worker), AgentSource::ProjectDefault),
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

    /**
     * Skip a case that needs a real interruption.
     *
     * Stopping a run needs ext-pcntl and signalling the runner needs ext-posix,
     * neither of which the package requires: a machine without them skips this
     * rather than failing it, in the same way
     * {@see \Celyes\RalphPhp\Tests\Process\ProcessRunnerTest} does.
     */
    private function requireSignals(): void
    {
        if (!Interruptions::isSupported() || !function_exists('posix_kill')) {
            self::markTestSkipped('Stopping a run needs ext-pcntl, and signalling the runner needs ext-posix.');
        }
    }
}
