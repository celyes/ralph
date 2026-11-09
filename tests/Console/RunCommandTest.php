<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\LoopCommand;
use Celyes\RalphPhp\Console\Command\ProjectCommand;
use Celyes\RalphPhp\Console\Command\RunCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph run` on the command line, against the real runner and state store.
 *
 * PROJECT.md section I, item 1 for the command that does the work. What is
 * under test here is the wiring and the reporting, not the loop: the loop has
 * its own suite ({@see \Celyes\RalphPhp\Tests\Run\RunnerTest}), and repeating
 * it through the binary would be slower and no more conclusive. What only the
 * command can be held to is that it finds the project it was run in, hands the
 * runner a real verifier, lets the run's own output through, and turns how the
 * run ended into an exit status a script can read.
 *
 * Run as a real subprocess for the same reason `ralph init` and
 * `ralph preflight` are: the working directory is where the project comes from,
 * and the exit status and the stream a failure was printed on are part of the
 * behaviour the item asks for.
 */
#[CoversClass(RunCommand::class)]
#[CoversClass(LoopCommand::class)]
#[CoversClass(ProjectCommand::class)]
final class RunCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and a run commits in real worktrees.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The whole command, in one case: a pending task is worked on, verified,
     * committed, checked off, and the run reports itself.
     */
    #[Test]
    public function it_carries_a_pending_task_through_the_loop_and_exits_successfully(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // The run's own account of itself reached the terminal: the command
        // does not summarise a run in its own words.
        self::assertStringContainsString('fixture (adapter "codex"', $result->standardOutput, $result->describe());
        self::assertStringContainsString('processing pending tasks', $result->standardOutput, $result->describe());
        self::assertStringContainsString('completed: verified and committed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $result->standardOutput, $result->describe());

        // The checklist on disk, the repository, and the rows the other four
        // commands will read.
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());

        $branches = $this->project->taskBranches();
        self::assertCount(1, $branches, 'A verified task owns one branch.');
        self::assertNotSame([], $this->project->commitsOn($branches[0]));

        // Recorded as the runner's own completion, not merely as a ticked box.
        self::assertSame(1, $this->project->journal()->tasks()->verified()->count());

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame('fixture', $run->agent()->name());
        self::assertSame(LifecycleState::Completed, $run->state());
    }

    /**
     * A run that gave up on a task exits with the status that says so.
     *
     * Not a failure of the command — the run did what it was asked and recorded
     * why it stopped — and not a success either, which is the distinction
     * {@see ExitCode::ATTENTION} exists to make. The box stays unticked.
     */
    #[Test]
    public function a_task_it_gave_up_on_leaves_the_box_unticked_and_asks_for_attention(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::ATTENTION, $result->exitCode, $result->describe());
        self::assertStringContainsString('ran out of attempts', $result->standardOutput, $result->describe());
        self::assertStringContainsString('next: ', $result->standardOutput, $result->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
        self::assertSame([], $this->project->taskBranches(), 'An unverified task committed nothing.');
    }

    /**
     * `--agent` chooses the agent for this run, and the choice is recorded.
     */
    #[Test]
    public function the_agent_option_overrides_the_project_default_for_the_run(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration([
                'agent' => 'codex',
                'agents' => ['other' => [
                    'adapter' => 'codex',
                    'executable' => $this->project->path('../tools/missing'),
                ]],
            ]);

        // The project default is the real `codex`, which is not installed here,
        // so a run that ignored the option would fail its preflight instead.
        $result = $this->project->ralph(['run', '--agent', 'fixture']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            'selected by the --agent option',
            $result->standardOutput,
            $result->describe(),
        );
        self::assertSame('fixture', $this->project->journal()->runs()->latest()?->agent()->name());
    }

    /**
     * An agent nobody configured is refused before a run is opened.
     */
    #[Test]
    public function an_unknown_agent_is_refused_without_starting_a_run(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['run', '--agent', 'gemini']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('No agent named "gemini" is configured.', $result->errorOutput);
        self::assertStringNotContainsString('processing pending tasks', $result->standardOutput, $result->describe());
    }

    /**
     * A project that was never initialised is not run on the defaults.
     *
     * The defaults would write a state database, a branch and a worktree into a
     * directory whose owner never asked for any of it, so a configuration is
     * required by the commands that do work — unlike `status`, which answers.
     */
    #[Test]
    public function a_project_with_no_configuration_is_refused(): void
    {
        // A repository and a checklist, and no `.ralph/config.json`.
        $this->project->withWorker()->withTasks([self::TASK]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('config.json', $result->errorOutput, $result->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
    }

    /**
     * A mistyped option is a refusal, not a run with the option ignored.
     */
    #[Test]
    public function it_refuses_arguments_and_unknown_options(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $argument = $this->project->ralph(['run', 'TASK-1A2B3C4D']);

        self::assertSame(ExitCode::USAGE, $argument->exitCode, $argument->describe());
        self::assertStringContainsString('takes no arguments', $argument->errorOutput);

        $option = $this->project->ralph(['run', '--agant', 'fixture']);

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--agant"', $option->errorOutput);

        // Nothing was taken on by either refusal.
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
    }

    /**
     * The documented usage is available without a project to run in.
     */
    #[Test]
    public function its_help_describes_the_loop_and_needs_no_project(): void
    {
        $result = $this->project->ralph(['help', 'run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('Usage: ralph run [--agent NAME]', $result->standardOutput);
        self::assertStringContainsString('--agent NAME', $result->standardOutput);
    }
}
