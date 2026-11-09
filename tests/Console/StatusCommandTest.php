<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\StatusCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
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
 * given up on, and one whose boxes were ticked by hand. Each of them twice over
 * where it matters, because `--json` is the same report for a program and the
 * two must not be able to disagree (PROJECT.md section I, item 3).
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
        self::assertStringContainsString('progress       0 of 2 completed, 2 remaining', $result->standardOutput);
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

        // The counts, and nothing about who ticked the box: every done task
        // here was carried through by the runner, so there is nothing to split.
        self::assertStringContainsString('progress       1 of 1 completed, 0 remaining', $result->standardOutput);
        self::assertStringNotContainsString('ticked by hand', $result->standardOutput, $result->describe());

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

        // What the single configured check decided, not just that it exists:
        // the run section above names it, this says how it came out.
        self::assertStringContainsString('checks: 1 of 1 checks passed in', $result->standardOutput, $result->describe());
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

        // The worker never produced anything to check, so the absence of a
        // verdict is reported rather than left to look like a passing one.
        self::assertStringContainsString('checks: none ran against attempt 1', $result->standardOutput, $result->describe());
    }

    /**
     * A pass the checks decided against reports which check failed, and names
     * every check when there is more than one of them.
     */
    #[Test]
    public function a_failing_check_is_reported_with_the_verdict_of_every_check(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration(['checks' => [
                ['name' => 'lint', 'command' => ['php', '-v'], 'timeout' => 30],
                ['name' => 'tests', 'command' => ['php', '-r', 'fwrite(STDERR, "1 assertion failed"); exit(3);'], 'timeout' => 30],
            ]]);

        $this->project->ralph(['run']);

        $result = $this->project->ralph(['status']);

        self::assertSame(ExitCode::ATTENTION, $result->exitCode, $result->describe());

        // The box stayed where it was: the checks are what decides, and they
        // decided against this.
        self::assertStringContainsString('0 of 1 completed, 1 remaining', $result->standardOutput, $result->describe());

        // The headline names the check that stopped the sequence, and because
        // there is more than one check, each one's own verdict follows.
        self::assertStringContainsString(
            'checks: tests failed; 1 of 2 checks passed',
            $result->standardOutput,
            $result->describe(),
        );
        self::assertMatchesRegularExpression('/\n\s+lint passed in /', $result->standardOutput, $result->describe());
        self::assertStringContainsString('tests failed (exit 3)', $result->standardOutput, $result->describe());
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

        // The count on its own would read as a third of the work being done.
        // It says whose word that rests on, which here is nobody's but the
        // user's.
        self::assertStringContainsString(
            'progress       1 of 2 completed, 1 remaining — all ticked by hand, none verified here',
            $result->standardOutput,
            $result->describe(),
        );
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

        $option = $this->project->ralph(['status', '--jason']);

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--jason"', $option->errorOutput);
    }

    /**
     * `--json` is the same report for a program: one document, on standard
     * output, with the facts the table abbreviates given whole.
     */
    #[Test]
    public function the_json_option_prints_one_document_a_program_can_read(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $result = $this->project->ralph(['status', '--json']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // The whole of standard output is the document, and nothing of the
        // table has leaked into it: a consumer pipes this into a parser.
        $document = JsonDocument::parse($result->standardOutput);

        self::assertSame(1, $document->integer('schema'), $result->describe());
        self::assertSame($this->project->root(), $document->string('project.root'));
        self::assertSame('fixture', $document->string('project.agent'));
        self::assertTrue($document->isNull('project.checklist_problem'));

        self::assertSame(
            ['total' => 1, 'completed' => 1, 'remaining' => 0, 'verified_here' => 1, 'ticked_by_hand' => 0],
            $document->object('progress')->all(),
            $result->describe(),
        );

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame($run->runId(), $document->string('run.run_id'), $result->describe());
        self::assertSame('completed', $document->string('run.state'));
        self::assertSame(['tests'], $document->strings('run.checks'));

        $tasks = $document->objects('tasks');
        self::assertCount(1, $tasks);

        $task = $tasks[0];
        $branch = $this->project->taskBranches()[0] ?? '';

        self::assertSame(self::TASK, $task->string('description'), $result->describe());
        self::assertMatchesRegularExpression('/^TASK-[0-9A-F]{8}$/', $task->string('task'));

        // The distinction the table makes in words, as a field: this box is
        // the runner's, and a consumer must not have to parse a sentence to
        // find that out (PROJECT.md section C, item 5).
        self::assertSame('runner_verified', $task->string('completion'), $result->describe());
        self::assertSame('completed', $task->string('state'));
        self::assertSame(1, $task->integer('attempts'));
        self::assertSame($branch, $task->string('branch'));

        // The commit whole, not the twelve characters the table has room for:
        // the other twenty-eight cannot be recovered from the short form.
        self::assertSame($this->project->headOf($branch), $task->string('commit'), $result->describe());

        // The verdicts behind the box, with the attempt they belong to named.
        self::assertSame(1, $task->integer('attempt'));
        self::assertTrue($task->boolean('verification.passed'), $result->describe());
        self::assertSame('tests', $task->objects('verification.checks')[0]->string('name'));

        self::assertFalse($document->boolean('needs_attention'), $result->describe());
    }

    /**
     * A project whose checklist cannot be read still prints a document: that
     * is the project someone is most likely to be asking a script about.
     */
    #[Test]
    public function the_json_document_survives_a_checklist_it_cannot_read(): void
    {
        // Configured, so there is a project; no checklist at the path it names.
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();
        unlink($this->project->path('.ralph/tasks.md'));

        $result = $this->project->ralph(['status', '--json']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $document = JsonDocument::parse($result->standardOutput);

        self::assertSame([], $document->objects('tasks'), $result->describe());
        self::assertSame(0, $document->integer('progress.total'));

        // Said twice over, deliberately: on standard error for whoever is
        // watching, and in the document for the reader who is not.
        self::assertStringContainsString('.ralph/tasks.md', $document->string('project.checklist_problem'));
        self::assertStringContainsString('.ralph/tasks.md', $result->errorOutput, $result->describe());
    }

    /**
     * A box ticked by hand is counted apart in the document too, as a number
     * rather than as the clause the table prints.
     */
    #[Test]
    public function the_json_document_counts_hand_ticked_boxes_apart(): void
    {
        $this->project->withWorker()->withTasks([self::TASK], [self::OTHER])->withConfiguration();

        $result = $this->project->ralph(['status', '--json']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $document = JsonDocument::parse($result->standardOutput);

        self::assertSame(
            ['total' => 2, 'completed' => 1, 'remaining' => 1, 'verified_here' => 0, 'ticked_by_hand' => 1],
            $document->object('progress')->all(),
            $result->describe(),
        );

        $completions = [];

        foreach ($document->objects('tasks') as $task) {
            $completions[$task->string('description')] = $task->string('completion');
        }

        self::assertSame('pending', $completions[self::TASK], $result->describe());
        self::assertSame('user_completed', $completions[self::OTHER], $result->describe());

        // No pass was ever recorded for either, so neither has a lifecycle
        // state: "it was never taken on" is not one of the seven.
        self::assertTrue($document->objects('tasks')[0]->isNull('state'), $result->describe());
        self::assertTrue($document->isNull('run'), $result->describe());
    }
}
