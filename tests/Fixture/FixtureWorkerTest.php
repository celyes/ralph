<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\VersionRequirement;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fixture worker doing what a test wrote down for it.
 *
 * PROJECT.md section E, item 12. The worker exists for the sections after this
 * one — section F's worktrees and commits, section G's verification and
 * retries, section H's limits and recovery, and section J, item 5, which names
 * it — so what is held here is the contract those tests will rely on, mode by
 * mode: that a success leaves exactly the scripted change in the task's own
 * workspace, that a failure and a block are told apart from it, that a slow
 * attempt is stopped by the budget, that an interrupted one records having been
 * asked and a stubborn one is killed anyway, that successive runs take
 * successive steps, and that two runs of one step do the same thing.
 *
 * It is driven through {@see CodexAdapter}, a real built-in adapter: the worker
 * ignores its arguments, so what the adapter contributes is the parts of an
 * attempt that have to work for any of this to mean anything — the explicit
 * working directory, the briefing on standard input, the streamed output and
 * the reading of the result block. Which adapter it is does not matter here,
 * and that it does not is
 * {@see \Celyes\RalphPhp\Tests\Agent\AdapterNormalizationTest}'s subject.
 *
 * No agent CLI, no credentials and no network are involved (section J, item 8).
 */
#[CoversClass(FixtureWorker::class)]
#[CoversClass(FixtureWorkerProgram::class)]
#[CoversClass(FixtureStep::class)]
#[CoversClass(FixtureRecord::class)]
#[CoversClass(FixtureMode::class)]
final class FixtureWorkerTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * The agent name the worker is configured under.
     */
    private const string AGENT = 'fixture';

    /**
     * The signal {@see ProcessRunner} asks a worker to stop with, as a number.
     *
     * Written out rather than taken from `SIGTERM`, because the test process
     * is not required to have ext-pcntl — only the worker uses it, and only to
     * notice the signal arriving.
     */
    private const int SIGTERM = 15;

    /**
     * The task's own workspace, which the worker must work in.
     */
    private string $workspace;

    /**
     * The directory the worker is installed in: not the workspace, so that its
     * program, plan and journal cannot turn up in the diff a run would stage.
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
        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->tools);
        TemporaryDirectory::remove($this->project);
    }

    /**
     * A success leaves the scripted change in the task's workspace, says so,
     * and leaves nothing else behind.
     *
     * The last part is what makes the fixture usable for section F: a run
     * stages the task worktree, so a worker that dropped a journal or a plan in
     * there would put its own files into every commit the suite checks.
     */
    #[Test]
    public function a_success_step_changes_the_workspace_and_reports_a_candidate(): void
    {
        file_put_contents($this->workspace . '/obsolete.txt', "to be removed\n");

        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::success(['src/Import.php' => "<?php // --dry-run\n"])->deleting('obsolete.txt'),
        ]);

        $result = $this->attemptAt($worker);

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertTrue($result->hasCandidate());
        self::assertSame(FixtureMode::Success->defaultSummary(), $result->claim()->text());
        self::assertSame(0, $result->exitCode());

        self::assertSame("<?php // --dry-run\n", file_get_contents($this->workspace . '/src/Import.php'));
        self::assertFileDoesNotExist($this->workspace . '/obsolete.txt');
        self::assertSame(['src'], $this->contentsOfWorkspace());

        // The attempt ran where the runner put it, with the briefing the
        // package built — the two things an adapter has to get right and the
        // two a fixture can confirm from the inside.
        $attempt = $worker->attempt();
        self::assertSame($this->workspace, $attempt->directory());
        self::assertSame(1, $attempt->step());
        self::assertSame(FixtureMode::Success, $attempt->mode());
        self::assertStringContainsString(self::DESCRIPTION, $attempt->briefing());
        self::assertStringContainsString($this->workspace, $attempt->briefing());
        self::assertSame([], $worker->signals());
    }

    /**
     * A success with no edits is an attempt that found nothing to do.
     *
     * Section G, item 6's already-satisfied task: the claim is a candidate and
     * the workspace is untouched, which is the input that outcome is decided
     * from.
     */
    #[Test]
    public function a_success_step_can_report_a_candidate_without_changing_anything(): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::success()->saying('The option is already there.'),
        ]);

        $result = $this->attemptAt($worker);

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertSame('The option is already there.', $result->claim()->text());
        self::assertSame([], $this->contentsOfWorkspace());
    }

    /**
     * A failure is an execution error carrying what the worker said.
     */
    #[Test]
    public function a_failure_step_reports_an_execution_error(): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::failure('the import command does not exist')->exitingWith(2),
        ]);

        $result = $this->attemptAt($worker);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertFalse($result->hasCandidate());
        self::assertTrue($result->isRetryable());
        self::assertSame(2, $result->exitCode());
        self::assertSame('the import command does not exist', $result->claim()->text());
        self::assertStringContainsString('could not do the work', $result->evidence()->toMarkdown());
        self::assertCount(1, $worker->attempts());
    }

    /**
     * A worker that printed no result block is an execution error however well
     * it exited.
     *
     * Section 4's rule, and the reason {@see FixtureStep::reportingNothing()}
     * exists: a fixture that could not be silent could not test the one case
     * where a zero exit status must not be taken for a finished task.
     */
    #[Test]
    public function a_step_that_reports_nothing_is_an_execution_error_whatever_its_status(): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::success(['src/Import.php' => "<?php\n"])->reportingNothing(),
        ]);

        $result = $this->attemptAt($worker);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(0, $result->exitCode());
        self::assertSame('', $result->claim()->text());
        self::assertStringContainsString('without reporting a result', $result->evidence()->toMarkdown());

        // The edits are still there, unclaimed: a workspace with changes nobody
        // reported is exactly what section F must not commit.
        self::assertFileExists($this->workspace . '/src/Import.php');
    }

    /**
     * A block is a question, with both halves of it.
     */
    #[Test]
    public function a_blocking_step_asks_for_human_input(): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::blocking('two import formats are plausible', 'say which format the flag applies to'),
        ]);

        $result = $this->attemptAt($worker);

        self::assertSame(AttemptOutcome::Blocked, $result->outcome());
        self::assertTrue($result->requiresHumanInput());
        self::assertFalse($result->isRetryable());

        $blocker = $result->blocker();
        self::assertNotNull($blocker);
        self::assertSame('two import formats are plausible', $blocker->reason());
        self::assertSame('say which format the flag applies to', $blocker->nextAction());
        self::assertSame(self::AGENT, $blocker->reportedBy());
    }

    /**
     * A slow worker is stopped by the attempt's budget, and never notices.
     *
     * The ordinary program that is merely too slow: it installs no handlers, so
     * the signal kills it where it stands and the journal holds the attempt it
     * began and no signal at all. What survives is the output it printed first,
     * which is what the timeout's evidence is made of.
     */
    #[Test]
    public function a_slow_step_is_stopped_by_the_attempt_budget_without_noticing(): void
    {
        $worker = FixtureWorker::install($this->tools, [FixtureStep::slow()]);

        $result = $this->attemptAt($worker, new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertNull($result->exitCode());
        self::assertStringContainsString('waiting 30 seconds', $result->evidence()->toMarkdown());

        self::assertCount(1, $worker->attempts());
        self::assertSame(FixtureMode::Slow, $worker->attempt()->mode());
        self::assertSame([], $worker->signals());
    }

    /**
     * An interrupted worker records the signal that reached it.
     *
     * Section H's interruption handling needs this from the inside: that the
     * package stopped waiting is something its own code can claim, while that
     * the worker was *asked* rather than simply abandoned is something only the
     * worker can report.
     */
    #[Test]
    public function an_interruption_step_records_the_signal_that_stopped_it(): void
    {
        $this->requirePcntl();

        $worker = FixtureWorker::install($this->tools, [FixtureStep::interruption()]);

        $result = $this->attemptAt($worker, new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertSame([self::SIGTERM], $worker->signals());
        self::assertStringContainsString('stopped by signal 15', $result->evidence()->toMarkdown());
        self::assertFalse(FixtureWorker::isRunning($worker->attempt()->pid()));
    }

    /**
     * A worker that refuses to stop is killed, and is gone afterwards either
     * way.
     *
     * The escalation {@see ProcessRunner} performs after its grace period, and
     * the case that makes "the worker has stopped" worth confirming rather than
     * assuming. The elapsed time is asserted because it is the only evidence
     * that the grace period was spent rather than skipped: the worker cannot
     * have exited on its own, so an attempt that came back in under it came
     * back from something still running.
     */
    #[Test]
    public function an_interruption_step_that_refuses_to_stop_is_killed(): void
    {
        $this->requirePcntl();

        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::interruption()->ignoringTermination(),
        ]);

        $result = $this->attemptAt($worker, new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200));

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertSame([self::SIGTERM], $worker->signals());
        self::assertStringContainsString('ignoring signal 15', $result->evidence()->toMarkdown());
        self::assertGreaterThanOrEqual(2.0, $result->seconds());
        self::assertFalse(FixtureWorker::isRunning($worker->attempt()->pid()));
    }

    /**
     * Each run takes the next step, and the last one repeats.
     *
     * What makes a loop testable rather than one attempt of it: "fails, then
     * succeeds" is a plan, and a third attempt gets the last step again instead
     * of the plan running out.
     */
    #[Test]
    public function the_worker_takes_the_next_step_each_run_and_repeats_the_last(): void
    {
        $worker = FixtureWorker::install($this->tools, [
            FixtureStep::failure('the test suite was red'),
            FixtureStep::success(['src/Import.php' => "<?php // first\n"]),
        ]);

        $first = $this->attemptAt($worker);
        $second = $this->attemptAt($worker, attempt: 2);
        $third = $this->attemptAt($worker, attempt: 3);

        self::assertSame(AttemptOutcome::Error, $first->outcome());
        self::assertSame(AttemptOutcome::Candidate, $second->outcome());
        self::assertSame(AttemptOutcome::Candidate, $third->outcome());

        self::assertSame(
            [1, 2, 2],
            array_map(static fn (FixtureRecord $record): ?int => $record->step(), $worker->attempts()),
        );
    }

    /**
     * The same step run twice prints the same thing and reports the same
     * result.
     *
     * Section E, item 12's "deterministic", asserted rather than described: two
     * installations of one step, two workspaces, and byte-identical output. The
     * step number is part of that output, which is why it names the step the
     * worker took and not the number of times it has been run.
     */
    #[Test]
    public function the_same_step_twice_does_the_same_thing(): void
    {
        $step = FixtureStep::success(['src/Import.php' => "<?php // same\n"]);

        $outputs = [];
        $results = [];

        foreach (['first', 'second'] as $pass) {
            $tools = TemporaryDirectory::create('ralph-tools-' . $pass);
            $workspace = TemporaryDirectory::create('ralph-workspace-' . $pass);

            try {
                $worker = FixtureWorker::install($tools, [$step]);
                $sink = new RecordingSink();

                $result = $this->attemptAt($worker, workspace: $workspace, output: $sink);

                $outputs[] = implode('', array_map(
                    static fn (array $write): string => $write['chunk'],
                    $sink->writes,
                ));
                $results[] = [$result->outcome()->value, $result->claim()->text(), $result->exitCode()];

                self::assertSame("<?php // same\n", file_get_contents($workspace . '/src/Import.php'));
            } finally {
                TemporaryDirectory::remove($tools);
                TemporaryDirectory::remove($workspace);
            }
        }

        self::assertSame($outputs[0], $outputs[1]);
        self::assertSame($results[0], $results[1]);
        self::assertStringContainsString('step 1 of 1', $outputs[0]);
    }

    /**
     * A version query is answered, and is not an attempt.
     *
     * Preflight asks before a run (section E, item 9), so a fixture agent has
     * to answer; and a query that consumed a step would silently shift every
     * later assertion in a test that ran preflight.
     */
    #[Test]
    public function a_version_query_is_answered_and_does_not_spend_a_step(): void
    {
        $worker = FixtureWorker::install(
            $this->tools,
            [FixtureStep::failure(), FixtureStep::success()],
            version: '1.2.3',
        );

        $requirement = VersionRequirement::of('the fixture worker', '1.0.0');
        $query = (new ProcessRunner())->run($requirement->command($worker->path()), $this->project);

        self::assertSame(0, $query->exitCode());

        $version = VersionRequirement::versionIn($query->standardOutput());
        self::assertSame('1.2.3', $version);
        self::assertTrue($requirement->isSatisfiedBy($version));

        self::assertSame(1, $worker->versionQueries());
        self::assertSame([], $worker->attempts());

        // The first attempt still gets the first step.
        self::assertSame(AttemptOutcome::Error, $this->attemptAt($worker)->outcome());
        self::assertSame(1, $worker->attempt()->step());
    }

    /**
     * The modes are the ones the project asks for.
     *
     * A guard on the list rather than on any behaviour: section E, item 12 names
     * success, failure, blocking, slow execution and interruption, and a mode
     * dropped or quietly renamed should fail here rather than leave the item
     * recorded as done for a fixture that no longer covers it.
     */
    #[Test]
    public function the_modes_the_project_asks_for_are_the_modes_the_fixture_has(): void
    {
        self::assertSame(
            ['success', 'failure', 'blocking', 'slow', 'interruption'],
            array_map(static fn (FixtureMode $mode): string => $mode->value, FixtureMode::cases()),
        );
    }

    /**
     * Run one attempt against the installed worker.
     */
    private function attemptAt(
        FixtureWorker $worker,
        ?Limits $limits = null,
        int $attempt = 1,
        ?string $workspace = null,
        ?OutputSink $output = null,
    ): ExecutionResult {
        $adapter = new CodexAdapter();

        return $adapter->execute($this->request($worker, $limits, $attempt, $workspace, $output));
    }

    private function request(
        FixtureWorker $worker,
        ?Limits $limits,
        int $attempt,
        ?string $workspace,
        ?OutputSink $output,
    ): ExecutionRequest {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md')->items()[0],
        );

        $definition = new AgentDefinition(self::AGENT, (new CodexAdapter())->name(), $worker->path());

        return new ExecutionRequest(
            new AgentSelection($definition, AgentSource::ProjectDefault),
            TaskGeneration::first($task->id()),
            $attempt,
            $task,
            Workspace::at($workspace ?? $this->workspace),
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
     * What the workspace holds, as sorted top-level names.
     *
     * @return list<string>
     */
    private function contentsOfWorkspace(): array
    {
        $entries = scandir($this->workspace);

        self::assertNotFalse($entries);

        $contents = array_values(array_diff($entries, ['.', '..']));
        sort($contents);

        return $contents;
    }

    /**
     * Skip a test that needs the worker to notice a signal.
     *
     * The worker runs on this same PHP binary, so the extension the child needs
     * is the one this process can see. The package does not require it (see
     * {@see FixtureWorkerProgram::wait()}), so a machine without it skips these
     * two rather than failing them.
     */
    private function requirePcntl(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('The worker needs ext-pcntl to notice the signal that stopped it.');
        }
    }
}
