<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\RetryCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph retry TASK-ID` on the command line, after a task the loop gave up on.
 *
 * What a retry has to be, in three properties. It takes on the named task and
 * nothing else, which is why a person types an identifier instead of running
 * the loop again. It continues the pass that stopped — same generation, same
 * branch, attempts counted on — so the history the item asks to preserve is
 * still there afterwards. And it grants the configured attempt allowance
 * again, because a task whose attempts ran out is the commonest thing there is
 * to retry and a command that refused it would be no use.
 *
 * The refusals matter as much as the retry: each of the four states a task can
 * be in that a retry is *not* the answer for is told what the answer is
 * instead.
 */
#[CoversClass(RetryCommand::class)]
final class RetryCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private const string OTHER = 'Document the import command in the README';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and a retried task commits in a real worktree.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The whole command, in one case: a task that ran out of attempts is tried
     * again, on the pass it already had, and finished.
     */
    #[Test]
    public function it_continues_the_pass_of_a_task_that_ran_out_of_attempts(): void
    {
        $this->givenATaskItGaveUpOn();

        $before = $this->pass();
        self::assertSame(LifecycleState::Failed, $before?->state());
        self::assertSame(1, $before->attempts());

        // The agent does the work this time.
        $this->project->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]));

        $result = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            'retrying ' . TaskId::fromDescription(self::TASK)->value(),
            $result->standardOutput,
            $result->describe(),
        );
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());

        // The same pass, carried on: its earlier attempt is still counted, its
        // generation is still the first, and it owns one branch with one commit.
        $after = $this->pass();

        self::assertSame(LifecycleState::Completed, $after?->state());
        self::assertSame(2, $after->attempts(), 'The attempt that failed is preserved, not replaced.');
        self::assertTrue($after->generation()->isFirst());
        self::assertSame($before->generation()->number(), $after->generation()->number());

        $branches = $this->project->taskBranches();
        self::assertCount(1, $branches);
        self::assertCount(1, $this->project->commitsOn($branches[0] ?? ''));
    }

    /**
     * The attempt allowance starts again, which is what makes the command
     * useful at all.
     */
    #[Test]
    public function it_grants_the_configured_attempt_allowance_again(): void
    {
        $this->givenATaskItGaveUpOn();

        // The limit is one attempt per task, and the task has already had one.
        // A retry that spent the task's exhausted allowance would refuse before
        // invoking anything; this one invokes the agent and fails again.
        $result = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::ATTENTION, $result->exitCode, $result->describe());
        self::assertStringContainsString('attempt 2', $result->standardOutput, $result->describe());
        self::assertSame(2, $this->pass()?->attempts());
    }

    /**
     * Only the named task, whatever else the checklist has pending.
     */
    #[Test]
    public function it_takes_on_the_named_task_and_no_others(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK, self::OTHER])
            ->withConfiguration();

        // The run took both tasks on — the task after a failed one is
        // independent work — and gave up on both.
        $this->project->ralph(['run']);

        $other = $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::OTHER));
        self::assertNotNull($other);

        $this->project->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]));

        $result = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());

        // The other task, which this agent would now have passed, was not
        // taken on: its box, its state and its attempts are where the run left
        // them.
        self::assertStringContainsString('- [ ] ' . self::OTHER, $this->project->checklist());
        self::assertStringNotContainsString(
            TaskId::fromDescription(self::OTHER)->value(),
            $result->standardOutput,
            $result->describe(),
        );

        $otherAfter = $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::OTHER));

        self::assertNotNull($otherAfter);
        self::assertSame($other->state(), $otherAfter->state());
        self::assertSame($other->attempts(), $otherAfter->attempts());
    }

    /**
     * A task the checklist does not state cannot be retried, and the typo does
     * not become a run of the project.
     *
     * The second half is the point: a run row recording a mistyped identifier
     * would be the project's latest run, which is what `ralph resume`
     * continues, so the typo would hand back the counters the interrupted run
     * had spent.
     */
    #[Test]
    public function an_identifier_no_task_has_is_refused_without_recording_a_run(): void
    {
        $this->givenATaskItGaveUpOn();

        $before = $this->project->journal()->runs()->latest();
        self::assertNotNull($before);

        $result = $this->project->ralph(['retry', 'TASK-11111111']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('states no task TASK-11111111', $result->errorOutput);
        self::assertStringContainsString('ralph status', $result->errorOutput, $result->describe());

        $after = $this->project->journal()->runs()->latest();

        self::assertNotNull($after);
        self::assertSame($before->runId(), $after->runId(), 'The refusal opened a run of its own.');
    }

    /**
     * A checked task has nothing outstanding, and reopening it is a different
     * thing with a different answer.
     */
    #[Test]
    public function a_checked_task_is_refused_and_pointed_at_unticking_it(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('is checked off in .ralph/tasks.md', $result->errorOutput);
        self::assertStringContainsString('untick the item', $result->errorOutput, $result->describe());
        self::assertStringContainsString('`ralph run`', $result->errorOutput, $result->describe());
    }

    /**
     * A task no run has taken on has no attempt to continue, and `ralph run` is
     * the command for it.
     */
    #[Test]
    public function a_task_that_has_never_run_is_refused(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['retry', TaskId::fromDescription(self::TASK)->value()]);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('has never been run', $result->errorOutput, $result->describe());
        self::assertStringContainsString('`ralph run`', $result->errorOutput, $result->describe());
    }

    /**
     * What is not an identifier is a usage mistake, and the command says what
     * one looks like.
     */
    #[Test]
    public function it_refuses_a_missing_or_malformed_identifier(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $missing = $this->project->ralph(['retry']);

        self::assertSame(ExitCode::USAGE, $missing->exitCode, $missing->describe());
        self::assertStringContainsString('needs a task identifier', $missing->errorOutput);
        self::assertStringContainsString('Usage: ralph retry TASK-ID', $missing->errorOutput);

        $malformed = $this->project->ralph(['retry', 'the dry run one']);

        self::assertSame(ExitCode::USAGE, $malformed->exitCode, $malformed->describe());
        self::assertStringContainsString('is not a task identifier', $malformed->errorOutput);

        $option = $this->project->ralph(['retry', 'TASK-11111111', '--agent', 'fixture']);

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--agent"', $option->errorOutput, $option->describe());
    }

    /**
     * A run that took the task on and gave up on it after its one attempt.
     */
    private function givenATaskItGaveUpOn(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $run = $this->project->ralph(['run']);

        self::assertSame(ExitCode::ATTENTION, $run->exitCode, $run->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
    }

    /**
     * The pass recorded against the project's first task.
     */
    private function pass(): ?GenerationRecord
    {
        return $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::TASK));
    }
}
