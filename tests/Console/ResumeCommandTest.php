<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\ResumeCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph resume` on the command line, after a run that was really interrupted.
 *
 * The interruption is a real SIGINT to a real run, which is the only way to
 * produce the thing this command exists for. A test that hand-wrote an
 * `interrupted` row would be testing its own idea of what an interrupted run
 * leaves behind, and the rows, the worktree and the branch left behind are
 * exactly what the resume has to reconcile (PROJECT.md section H, item 5).
 *
 * Four properties, one per part of what a resume is for:
 *
 *  - the work is finished rather than started over — one commit for the task,
 *    not two, and the pass's attempt history kept;
 *  - the agent is the interrupted run's, and `--agent` naming a different one
 *    is refused with what to type instead (section H, item 6);
 *  - the iteration and time counters carry over, so the resume finishes the
 *    interrupted run's budget instead of being handed a fresh one (item 3);
 *  - a project with nothing to resume is told so, and nothing is run.
 */
#[CoversClass(ResumeCommand::class)]
final class ResumeCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    /**
     * The worker's own output, which is the moment a Ctrl+C has something to
     * interrupt.
     *
     * The worker's line rather than the runner's "--- running": the runner
     * prints that before the process it started has run at all, and a signal
     * sent then stops a worker that has not yet recorded the attempt it is
     * making. What this case is about is an agent interrupted part-way through
     * working, so it waits until the agent says it is.
     */
    private const string WORKING = ', mode slow';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and a resumed run commits in a real worktree.');
        }

        if (!extension_loaded('pcntl')) {
            self::markTestSkipped(
                'The pcntl extension is not installed, so a signalled run cannot record its interruption.',
            );
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The whole command, in one case: an interrupted task is finished by the
     * agent that started it, once, and the box is ticked.
     */
    #[Test]
    public function it_finishes_an_interrupted_task_without_implementing_or_committing_it_twice(): void
    {
        $this->givenAnInterruptedRun();

        $interrupted = $this->project->journal()->runs()->latest();
        self::assertNotNull($interrupted);
        self::assertSame(LifecycleState::Interrupted, $interrupted->state());

        $result = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('resuming the interrupted run', $result->standardOutput, $result->describe());

        // Reconciled before anything was worked on, and said so.
        self::assertStringContainsString(
            'the repository matches what the pass recorded',
            $result->standardOutput,
            $result->describe(),
        );

        // The task was finished, once.
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());

        $branches = $this->project->taskBranches();
        self::assertCount(1, $branches, 'The resumed pass kept the branch it owned.');
        self::assertCount(
            1,
            $this->project->commitsOn($branches[0] ?? ''),
            'A resumed task is committed once, not once per run.',
        );

        // The pass is the interrupted one carried on, not a second pass: its
        // attempts are cumulative and its generation is still the first.
        $pass = $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::TASK));

        self::assertNotNull($pass);
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertSame(2, $pass->attempts(), 'The interrupted attempt is kept and the resume adds one.');
        self::assertTrue($pass->generation()->isFirst());
    }

    /**
     * The resumed run continues the interrupted run's budget, and says what it
     * inherited.
     */
    #[Test]
    public function the_resumed_run_inherits_the_interrupted_runs_counters(): void
    {
        $this->givenAnInterruptedRun();

        $interrupted = $this->project->journal()->runs()->latest();
        self::assertNotNull($interrupted);

        $result = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            sprintf('continuing %s: %d iteration', $interrupted->runId(), $interrupted->iterations()),
            $result->standardOutput,
            $result->describe(),
        );

        $resumed = $this->project->journal()->runs()->latest();

        self::assertNotNull($resumed);
        self::assertNotSame($interrupted->runId(), $resumed->runId(), 'A resume opens its own row.');
        self::assertTrue($resumed->isResume());
        self::assertSame($interrupted->runId(), $resumed->resumedFromRunId());
        self::assertSame($interrupted->iterations(), $resumed->carriedIterations());
        self::assertGreaterThanOrEqual($interrupted->iterations(), $resumed->iterations());
    }

    /**
     * `--agent` can confirm the recorded agent, and nothing else.
     */
    #[Test]
    public function it_refuses_to_continue_an_interrupted_run_with_a_different_agent(): void
    {
        $this->givenAnInterruptedRun();

        $result = $this->project->ralph(['resume', '--agent', 'claude']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            'would change agents part-way through its work',
            $result->errorOutput,
            $result->describe(),
        );
        // What to type instead, both to continue this work and to use the
        // other agent.
        self::assertStringContainsString('Omit "--agent"', $result->errorOutput, $result->describe());
        self::assertStringContainsString('ralph run --agent claude', $result->errorOutput, $result->describe());

        // Nothing was taken on, and the interrupted work is still interrupted.
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
        self::assertSame(
            LifecycleState::Interrupted,
            $this->project->journal()->runs()->latest()?->state(),
        );
    }

    /**
     * Naming the recorded agent is allowed, and says what it confirmed.
     */
    #[Test]
    public function it_continues_when_the_option_names_the_recorded_agent(): void
    {
        $this->givenAnInterruptedRun();

        $result = $this->project->ralph(['resume', '--agent', 'fixture']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('selected by the run being continued', $result->standardOutput);
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());
    }

    /**
     * A project whose runs all finished has nothing to continue, and is told
     * what to run instead.
     */
    #[Test]
    public function a_completed_run_is_not_resumed(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('there is nothing in it to resume', $result->errorOutput);
        self::assertStringContainsString('`ralph run`', $result->errorOutput, $result->describe());
    }

    /**
     * A project that has never run has no run to continue.
     */
    #[Test]
    public function a_project_that_has_never_run_has_nothing_to_resume(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('no recorded run', $result->errorOutput, $result->describe());
        self::assertStringContainsString('`ralph run`', $result->errorOutput, $result->describe());
    }

    /**
     * A mistyped option is a refusal, not a resume with the option ignored.
     */
    #[Test]
    public function it_refuses_arguments_and_unknown_options(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $argument = $this->project->ralph(['resume', 'TASK-1A2B3C4D']);

        self::assertSame(ExitCode::USAGE, $argument->exitCode, $argument->describe());
        self::assertStringContainsString('takes no arguments', $argument->errorOutput);

        $option = $this->project->ralph(['resume', '--force']);

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--force"', $option->errorOutput);
    }

    /**
     * A run stopped by SIGINT while its agent was working, leaving the rows and
     * the workspace a Ctrl+C leaves.
     *
     * The worker waits long enough to be interrupted and then, on its next
     * invocation, does the work: the second step is what the resume gets.
     */
    private function givenAnInterruptedRun(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::slow(30),
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
            )
            ->withTasks([self::TASK])
            ->withConfiguration(['limits' => ['attempts_per_task' => 2]]);

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        // 130, which is 128 + SIGINT and what a shell reports for any
        // interrupted process. Not a failure and not a block — nothing went
        // wrong and nothing has to be decided — but not a success either: a
        // caller must not read "stopped half way" as "the checklist is done"
        // (PROJECT.md section I, item 5).
        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());
        self::assertStringContainsString('Run `ralph resume`', $interrupted->standardOutput, $interrupted->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
    }
}
