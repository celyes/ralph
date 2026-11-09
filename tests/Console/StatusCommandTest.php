<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\StatusCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph status` on the command line, against the real state store.
 *
 * The command answers two questions — where the last run got to, and what is
 * waiting for a person — so the cases are the states those answers differ in:
 * a project that has never run, one whose task passed, one whose task was
 * given up on, and one whose boxes were ticked by hand.
 *
 * Three properties recur, each standing for a way a diagnostic can be worse
 * than none. It reads rather than writes, because a report that initialised
 * what it reported on would have changed the answer. It works before anything
 * else does, because a diagnostic that needs a working project is no use to a
 * user whose project is not working. And it distinguishes a box this package
 * verified from a box somebody ticked, because "done" with no commit behind it
 * is the one claim the package must not make on a user's behalf (PROJECT.md
 * section C, item 5).
 */
#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private const string OTHER = 'Document the import command in the README';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the report is of a real repository.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * Before anything has run: the project, its checklist, and pending tasks
     * with the identifiers the other commands are named with.
     */
    #[Test]
    public function it_reports_a_project_that_has_never_run(): void
    {
        $this->project->withWorker()->withTasks([self::TASK, self::OTHER])->withConfiguration();

        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString('Ralph PHP status for ' . $this->project->root(), $result->standardOutput);
        self::assertStringContainsString('.ralph/tasks.md — 2 tasks, 0 checked, 2 pending', $result->standardOutput);
        self::assertStringContainsString('fixture (project default)', $result->standardOutput);
        self::assertStringNotContainsString('Latest run', $result->standardOutput, $result->describe());

        // The pending task, with the identifier `ralph logs` and `ralph retry`
        // are named with.
        self::assertMatchesRegularExpression(
            '/pending\s+TASK-[0-9A-F]{8}\s+-\s+-\s+Add a --dry-run option/',
            $result->standardOutput,
            $result->describe(),
        );

        // Nothing was written by the asking.
        self::assertFileDoesNotExist($this->project->path('.ralph/runtime/state.sqlite'));
    }

    /**
     * After a task passed: the run, and the task's state, attempts, branch and
     * commit.
     */
    #[Test]
    public function it_reports_the_run_and_the_commit_a_verified_task_produced(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('.ralph/tasks.md — 1 task, 1 checked, 0 pending', $result->standardOutput);

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);

        self::assertStringContainsString(
            sprintf('Latest run %s — the checklist was worked through', $run->runId()),
            $result->standardOutput,
            $result->describe(),
        );
        self::assertStringContainsString('agent        fixture (adapter "codex")', $result->standardOutput);
        self::assertStringContainsString('checks       tests', $result->standardOutput);
        self::assertStringContainsString('iterations   1', $result->standardOutput);
        self::assertStringContainsString('stopped      every pending task was completed', $result->standardOutput);
        self::assertStringContainsString('next         ', $result->standardOutput);

        // The commit column is the commit the repository actually has on the
        // task's branch, not a claim read back out of the same row that wrote
        // it.
        $branch = $this->project->taskBranches()[0] ?? '';

        self::assertStringContainsString('branch ' . $branch, $result->standardOutput, $result->describe());
        self::assertStringContainsString(
            substr($this->project->headOf($branch), 0, 12),
            $result->standardOutput,
            $result->describe(),
        );
        self::assertMatchesRegularExpression('/completed\s+TASK-[0-9A-F]{8}\s+1\s+/', $result->standardOutput);
    }

    /**
     * A task the loop gave up on is reported as waiting for a person, with what
     * to do about it, and the exit status says the same thing.
     */
    #[Test]
    public function a_failed_task_is_reported_as_waiting_and_asks_for_attention(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $this->project->ralph(['run']);

        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::ATTENTION, $result->exitCode, $result->describe());
        self::assertStringContainsString('— a task ran out of attempts', $result->standardOutput, $result->describe());
        self::assertMatchesRegularExpression('/failed\s+TASK-[0-9A-F]{8}\s+1\s+-\s+/', $result->standardOutput);

        // The phase is reported as one the pass stopped in rather than as one
        // something is still doing.
        self::assertStringContainsString('stopped while waiting for the agent', $result->standardOutput);
        self::assertStringContainsString('next: read `ralph logs TASK-', $result->standardOutput);
    }

    /**
     * A box the user ticked is reported as the user's, not as the runner's.
     */
    #[Test]
    public function an_item_the_user_checked_off_is_reported_as_theirs(): void
    {
        $this->project->withWorker()->withTasks([self::TASK], [self::OTHER])->withConfiguration();

        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('1 checked, 1 pending', $result->standardOutput);
        self::assertMatchesRegularExpression(
            '/done \(by you\)\s+TASK-[0-9A-F]{8}\s+-\s+-\s+Document the import command/',
            $result->standardOutput,
            $result->describe(),
        );
        self::assertStringContainsString(
            'marked done in the checklist, not verified by',
            $result->standardOutput,
            $result->describe(),
        );
    }

    /**
     * A project with no configuration is reported on the defaults rather than
     * refused: the question has an answer, and it is more useful than a refusal.
     */
    #[Test]
    public function it_reports_a_project_that_was_never_initialised(): void
    {
        // A repository, and nothing of this package's in it.
        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            'configuration  defaults (no configuration file)',
            $result->standardOutput,
            $result->describe(),
        );
        // The checklist the defaults name is not there, which is reported as
        // the problem it is rather than taking the command down with it.
        self::assertStringContainsString('No tasks.', $result->standardOutput, $result->describe());
        self::assertStringContainsString('.ralph/tasks.md', $result->errorOutput, $result->describe());
    }

    /**
     * A mistyped option is a refusal, not a report with the option ignored.
     */
    #[Test]
    public function it_refuses_arguments_and_unknown_options(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $argument = $this->project->ralph(['status', 'TASK-1A2B3C4D']);

        self::assertSame(ExitCode::USAGE, $argument->exitCode, $argument->describe());
        self::assertStringContainsString('takes no arguments', $argument->errorOutput);

        $option = $this->project->ralph(['status', '--json']);

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--json"', $option->errorOutput);
    }
}
