<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\ConfigurationValidator;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What decides whether work is taken on, and what it is called afterwards.
 *
 * PROJECT.md section J, item 2: configuration validation, task-state
 * transitions, limits, and the difference between a box the user ticked and a
 * box this package earned. Each of the four already has a unit suite —
 * {@see \Celyes\RalphPhp\Tests\Config\ConfigurationValidatorTest} for the forty
 * settings a loader refuses, {@see \Celyes\RalphPhp\Tests\State\LifecycleStateTest}
 * for the transition table, {@see RunBudgetTest} and
 * {@see \Celyes\RalphPhp\Tests\Agent\ExecutionBudgetTest} for the arithmetic of
 * the limits, {@see \Celyes\RalphPhp\Tests\Tasks\TaskListTest} for the two
 * kinds of completion — and {@see RunnerTest} drives most of them through the
 * loop in process.
 *
 * What is left, and what these cases are, is the seams a user meets:
 *
 * - A rejected setting is only a gate if *every* command is behind it, and
 *   only safe if a rejection costs the project nothing.
 * - A transition table is only worth something if a real run writes states
 *   from it — and if the task it never reached has no state at all.
 * - A limit is only a limit if the process exits with the status that says so
 *   and the store afterwards agrees about what was spent.
 * - The two completions are only distinguishable if `status` tells them apart
 *   out loud, to a person and to a program.
 *
 * So every case here is a real `ralph` subprocess against a real repository
 * with the fixture worker as its agent, and the assertions are what a user or
 * a script can see: exit statuses, the checklist on disk, Git, and what
 * `status` prints. No agent CLI, no credentials and no network (section J,
 * item 8).
 */
#[CoversClass(ConfigurationValidator::class)]
#[CoversClass(LifecycleState::class)]
#[CoversClass(Completion::class)]
final class StateAndLimitsThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string THIRD = 'Validate the import file before reading it';

    private const string FOURTH = 'Report the row count after an import';

    private const string DONE = 'Create the import command';

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
     * A setting the validator refuses stops every command, and costs nothing.
     *
     * Section B, item 3 asks for invalid values to be rejected "before
     * starting work", and the unit suite shows the loader doing it. What it
     * cannot show is that there is no way around the loader: `status` is a
     * question rather than work, `logs` only reads, and `resume` and `retry`
     * are the commands a user reaches for when something has already gone
     * wrong — a project with one unusable line of JSON must get the same
     * answer from all of them, pointing at the same line.
     *
     * And the rejection has to be inert. No state store, no branch and no edit
     * to the checklist, so that fixing the setting is the whole of the
     * recovery rather than the start of it.
     */
    #[Test]
    public function a_setting_the_validator_refuses_stops_every_command_before_it_touches_the_project(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// should never run\n"]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['limits' => ['iterations' => 0]]);

        $before = $this->project->checklist();
        $identifier = TaskId::fromDescription(self::FIRST)->value();

        $invocations = [
            ['run'],
            ['run', '--agent', 'fixture'],
            ['status'],
            ['status', '--json'],
            ['resume'],
            ['retry', $identifier],
            ['logs', $identifier],
        ];

        foreach ($invocations as $arguments) {
            $result = $this->project->ralph($arguments);
            $command = 'ralph ' . implode(' ', $arguments);

            self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $command . ': ' . $result->describe());

            // The file to open and the line in it, in the message itself: a
            // status of 78 says a setting is wrong, not which one.
            self::assertStringContainsString('.ralph/config.json', $result->errorOutput, $command);
            self::assertStringContainsString('"limits.iterations"', $result->errorOutput, $command);

            // On standard error, so a `status --json` a script is piping is
            // not a document with a complaint in place of the status.
            self::assertSame('', $result->standardOutput, $command . ' prints nothing to be parsed.');
        }

        self::assertFileDoesNotExist(
            $this->project->path('.ralph/runtime/state.sqlite'),
            'A refused setting does not get as far as creating a state store.',
        );
        self::assertSame([], $this->project->taskBranches());
        self::assertSame($before, $this->project->checklist());
    }

    /**
     * The setting broken after a run, which the next run refuses without
     * costing the project the work the first one finished.
     *
     * The other half of the gate. Validation happens before a run reads the
     * state store, so a user who breaks a setting between two runs is in the
     * one position where "reject it" could plausibly mean "lose it": the
     * refusal arrives with a finished task, a branch and a commit already on
     * the record. What is asserted is that the refusal changes none of them,
     * and that putting the setting back is all it takes for the loop to carry
     * on from where it stopped.
     */
    #[Test]
    public function a_setting_broken_between_two_runs_is_refused_without_losing_the_finished_work(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['limits' => ['iterations' => 1]]);

        self::assertSame(ExitCode::LIMIT, $this->project->ralph(['run'])->exitCode);

        $finished = $this->pass(self::FIRST);
        $branch = (string) $finished->branch();
        $commit = (string) $finished->commitSha();
        $checklist = $this->project->checklist();

        // The user leaves a blank argument in the check command, which is the
        // kind of setting whose being wrong would let unverified work through
        // if it were ignored rather than refused.
        $this->project->editConfiguration(['checks' => [['command' => ['php', '  ']]]]);

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());
        self::assertStringContainsString('"checks.0.command.1"', $refused->errorOutput, $refused->describe());

        // Nothing moved: the finished pass, its branch, its commit and the
        // file are as the first run left them.
        self::assertSame($checklist, $this->project->checklist());
        self::assertSame([$branch], $this->project->taskBranches());
        self::assertSame($commit, $this->project->headOf($branch));

        // Put back, the setting is the whole of the recovery.
        $this->project->editConfiguration();

        // Nothing was recorded against the configuration that was never
        // accepted: no second run, and no pass opened on the task it would
        // have taken on. Read now rather than above, because reading the store
        // means loading the settings, and for those few lines there were none.
        self::assertCount(1, $this->project->journal()->runs()->all());
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::SECOND)));

        $carried = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $carried->exitCode, $carried->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $carried->standardOutput, $carried->describe());
        self::assertSame($commit, $this->pass(self::FIRST)->commitSha(), 'The finished pass is still the finished pass.');
        self::assertCount(1, $this->project->journal()->generations()->forTask($this->identify(self::FIRST)));
        self::assertStringContainsString('- [x] ' . self::SECOND, $this->project->checklist());
    }

    /**
     * One run over four tasks, each of which ends in the state its own pass
     * earned — and the fourth in no state at all.
     *
     * The transition table as a run actually exercises it, rather than as a
     * table: a pass carried through to `completed`, a pass whose agent failed
     * left `failed`, a pass whose agent asked a question left `blocked`, and a
     * task the run never reached with no generation row to explain away. The
     * three terminal rows coexist in one project and are read back out of the
     * database by `status`, so a state that could not survive the round trip —
     * or a run that overwrote one of the earlier two when the third stopped it
     * — shows up here.
     *
     * The exit status is the blocking one and not the failure one, because the
     * run stopped on the question: the thing a caller has to do next is answer
     * it (section I, item 5).
     */
    #[Test]
    public function one_run_leaves_each_task_in_the_state_its_own_pass_earned(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::failure('could not work out which README section to write in'),
                FixtureStep::blocking('Which importer should this validate?', 'Name the importer in the task.'),
            )
            ->withTasks([self::FIRST, self::SECOND, self::THIRD, self::FOURTH])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());

        $states = [
            self::FIRST => LifecycleState::Completed,
            self::SECOND => LifecycleState::Failed,
            self::THIRD => LifecycleState::Blocked,
        ];

        foreach ($states as $description => $state) {
            self::assertSame($state, $this->pass($description)->state(), $description);
        }

        // The task the run stopped short of was not opened. "Never taken on"
        // is not one of the seven states, and inventing a row for it would
        // leave a pass to account for that no agent was ever asked about.
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::FOURTH)));

        // Only the completed pass earned a box, a branch and a commit.
        $checklist = $this->project->checklist();
        self::assertStringContainsString('- [x] ' . self::FIRST, $checklist);
        self::assertStringContainsString('- [ ] ' . self::SECOND, $checklist);
        self::assertStringContainsString('- [ ] ' . self::THIRD, $checklist);
        self::assertStringContainsString('- [ ] ' . self::FOURTH, $checklist);
        self::assertCount(1, $this->project->taskBranches());

        // And the same four answers come back out of the store, through a
        // second process that only read it.
        $status = $this->project->ralph(['status', '--json']);
        self::assertSame(ExitCode::BLOCKED, $status->exitCode, $status->describe());

        $reported = [];

        foreach (JsonDocument::parse($status->standardOutput)->objects('tasks') as $task) {
            $reported[$task->string('description')] = $task->isNull('state') ? null : $task->string('state');
        }

        self::assertSame(
            [
                self::FIRST => LifecycleState::Completed->value,
                self::SECOND => LifecycleState::Failed->value,
                self::THIRD => LifecycleState::Blocked->value,
                self::FOURTH => null,
            ],
            $reported,
            $status->describe(),
        );
    }

    /**
     * An agent that outlives the task timeout, which fails the task rather
     * than being waited for.
     *
     * The one limit of section H, item 2 that nothing can demonstrate in
     * process: a worker really has to be started, really has to take longer
     * than it is allowed, and really has to be stopped. So the fixture waits
     * thirty seconds against a budget of two, and the case is over in two —
     * which is also the assertion, because a timeout that was not enforced
     * would take the other twenty-eight.
     *
     * A pass stopped this way is `failed` and not `blocked`: nothing is
     * waiting on a decision, the agent was too slow. Its work is kept for
     * inspection and its box is not ticked.
     */
    #[Test]
    public function an_agent_that_outlives_the_task_timeout_fails_the_task_rather_than_being_waited_for(): void
    {
        $this->project
            ->withWorker(FixtureStep::slow(30)->editing(['src/Import.php' => "<?php\n\n// half-written\n"]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['limits' => ['task_timeout' => 2, 'attempts_per_task' => 1]]);

        $started = microtime(true);
        $result = $this->project->ralph(['run']);
        $elapsed = microtime(true) - $started;

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());
        self::assertLessThan(
            30,
            $elapsed,
            'The worker was waited out rather than stopped at the configured timeout.',
        );

        self::assertStringContainsString('the task timeout', $result->standardOutput, $result->describe());

        $pass = $this->pass(self::FIRST);
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Executing, $pass->phase(), 'The pass stopped where it was: waiting for the agent.');
        self::assertNull($pass->commitSha(), 'An attempt that never finished has nothing to commit.');

        // The half-written work is kept where a person can look at it, and the
        // box stays empty.
        self::assertDirectoryExists($this->project->path('.ralph/runtime/worktrees/' . $this->identify(self::FIRST)->value()));
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * A task handed back to the agent until its attempt allowance is spent,
     * and then left alone.
     *
     * The per-task limit, through the binary, against a real worker and a
     * check that really runs and really fails: three attempts where the
     * allowance is three, one generation holding all of them rather than three
     * passes, and no fourth attempt once the allowance is gone. The run ends
     * with the failure status because the task ran out of attempts — which is
     * not the same event as a *run* reaching a limit it was configured with,
     * and the two must not come back as the same status.
     *
     * The failure is a failing check rather than a failing agent on purpose:
     * an agent that reports an error settles its pass at one attempt, so it
     * could not show an allowance being spent at all.
     */
    #[Test]
    public function a_task_is_handed_back_to_the_agent_until_its_attempt_allowance_is_spent(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php

// not good enough
"]))
            ->withTasks([self::FIRST])
            ->withConfiguration([
                'limits' => ['attempts_per_task' => 3],
                // A check that is a real process and a real non-zero status.
                'checks' => [['command' => ['php', '-r', 'exit(1);']]],
            ]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());

        $pass = $this->pass(self::FIRST);
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(3, $pass->attempts(), 'The allowance was spent, and not exceeded.');

        $journal = $this->project->journal();
        self::assertCount(1, $journal->generations()->forTask($this->identify(self::FIRST)));
        self::assertCount(3, $journal->attempts()->forGeneration($pass));

        foreach ($journal->attempts()->forGeneration($pass) as $attempt) {
            self::assertSame(LifecycleState::Failed, $attempt->state());
        }

        // Spent attempts are a reason to stop retrying, not a reason to tick a
        // box or to claim a commit.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());
        self::assertSame([], $this->project->commitsOn((string) $pass->branch()));
        self::assertSame(
            Completion::Pending,
            $this->completionOf(self::FIRST),
            'A task nothing verified is pending, whatever was attempted on it.',
        );
    }

    /**
     * The run limits, which stop a run with the status that says a limit did
     * it and leave an accurate count behind.
     *
     * Section H, items 2 and 3 at the process boundary. A limit is not a
     * failure and a caller must not have to read a sentence to tell the
     * difference, so the status is the limit one; `status` afterwards exits
     * successfully, because a spent budget is yesterday's event and the task
     * left over is indistinguishable from the pending task of a project nobody
     * has run. Then the next run is identifiably new, with its counters whole,
     * and it takes on what was left rather than the whole checklist again.
     */
    #[Test]
    public function a_spent_iteration_limit_stops_the_run_with_the_status_and_the_count_that_say_so(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['limits' => ['iterations' => 1]]);

        $limited = $this->project->ralph(['run']);

        self::assertSame(ExitCode::LIMIT, $limited->exitCode, $limited->describe());
        self::assertStringContainsString('limits.iterations', $limited->allOutput(), $limited->describe());

        $first = $this->project->journal()->runs()->latest();
        self::assertNotNull($first);
        self::assertSame(1, $first->iterations());
        self::assertTrue($first->isFreshStart());

        // The task the limit kept the run from reaching is pending, not failed.
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::SECOND)));
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->project->checklist());

        // Asking about the project is not the same question as how the run
        // ended, and does not inherit its status.
        $status = $this->project->ralph(['status']);
        self::assertSame(ExitCode::SUCCESS, $status->exitCode, $status->describe());

        $second = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $second->exitCode, $second->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $second->standardOutput, $second->describe());

        // A deliberately new run: its own counters, and nothing inherited.
        $row = $this->project->journal()->runs()->latest();
        self::assertNotNull($row);
        self::assertNotSame($first->runId(), $row->runId());
        self::assertTrue($row->isFreshStart());
        self::assertNull($row->resumedFromRunId());
        self::assertSame(1, $row->iterations());
        self::assertSame(0, $row->carriedIterations());

        // And the first run's completed task was not offered to the agent
        // again: two tasks, two branches, one commit each.
        self::assertCount(1, $this->project->journal()->generations()->forTask($this->identify(self::FIRST)));
        self::assertCount(2, $this->project->taskBranches());

        foreach ($this->project->taskBranches() as $branch) {
            self::assertCount(1, $this->project->commitsOn($branch), $branch . ' holds one commit.');
        }
    }

    /**
     * The run's time budget, spent on the clock, stopping the loop before it
     * takes on another task.
     *
     * The fourth limit, and the only one that cannot be demonstrated without
     * time really passing: the first task is held up by a check that sleeps
     * for longer than the whole run was given, so the budget is spent by the
     * time the loop comes back for the second task. {@see RunnerTest} drives
     * this against a clock a test controls, which proves the arithmetic; what
     * is proved here is that the seconds the loop counts are the seconds that
     * actually elapsed.
     *
     * A budget is a reason not to *start* another task, never a reason to
     * abandon the one in hand — so the first task is completed and committed,
     * and the second has no pass at all rather than a failure against a budget
     * no agent was ever asked to work inside.
     */
    #[Test]
    public function a_time_budget_spent_on_the_clock_stops_the_run_before_the_next_task(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration([
                'limits' => ['run_timeout' => 2],
                // Slow on purpose, and a real process: the run has to spend
                // more than its budget before the loop asks for another task.
                'checks' => [['command' => ['php', '-r', 'sleep(3);'], 'timeout' => 30]],
            ]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::LIMIT, $result->exitCode, $result->describe());
        self::assertStringContainsString('limits.run_timeout', $result->allOutput(), $result->describe());
        self::assertStringContainsString('1 task still pending', $result->allOutput(), $result->describe());

        // The task in hand was carried through, budget or no budget.
        self::assertSame(LifecycleState::Completed, $this->pass(self::FIRST)->state());
        self::assertNotNull($this->pass(self::FIRST)->commitSha());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        // The next one was not opened, and what the run spent is on the record
        // for the run after it to read.
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::SECOND)));
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->project->checklist());

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertGreaterThanOrEqual(2, $run->activeSeconds(), 'The seconds counted are the seconds that passed.');
        self::assertSame(1, $run->iterations());
    }

    /**
     * A box the user ticked and a box this run earned, which `status` refuses
     * to report as the same thing.
     *
     * The distinction of section C, item 5, from the only place a user can see
     * it. The checklist records one bit, so after the run the two items are
     * the same two characters in the file — and everything that would let a
     * reviewer tell them apart has to come from the store: the imported item
     * has no pass, no attempt, no branch and no commit, while the one this run
     * did has all four. A project reporting "2 of 2 completed" with no split
     * would be claiming verification it cannot show for half of it.
     *
     * Asserted twice over, because the two readers are different: the counts
     * and the labels a person reads in the table, and the `completion` field
     * and the two separate numbers a script reads out of the document.
     */
    #[Test]
    public function a_box_the_user_ticked_is_not_reported_as_a_box_this_run_earned(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::FIRST], [self::DONE])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        // In the file the two are now indistinguishable, which is the point.
        $checklist = $this->project->checklist();
        self::assertStringContainsString('- [x] ' . self::DONE, $checklist);
        self::assertStringContainsString('- [x] ' . self::FIRST, $checklist);

        self::assertSame(Completion::UserCompleted, $this->completionOf(self::DONE));
        self::assertSame(Completion::RunnerVerified, $this->completionOf(self::FIRST));

        // The imported item has nothing behind it, and no attempt was spent on
        // finding that out: it was skipped, not run and found to be done.
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::DONE)));
        self::assertCount(1, $this->project->taskBranches());

        $verified = $this->pass(self::FIRST);
        self::assertSame(LifecycleState::Completed, $verified->state());
        self::assertNotNull($verified->commitSha());

        // What a person reads: one sentence that splits the count, and a row
        // for each task saying on whose word it is done.
        $table = $this->project->ralph(['status']);
        self::assertSame(ExitCode::SUCCESS, $table->exitCode, $table->describe());
        self::assertStringContainsString(
            '2 of 2 completed, 0 remaining — 1 verified here, 1 ticked by hand',
            $table->standardOutput,
            $table->describe(),
        );

        // What a script reads: the split as two numbers, and the field that is
        // always there.
        $document = JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput);

        self::assertSame(
            ['total' => 2, 'completed' => 2, 'remaining' => 0, 'verified_here' => 1, 'ticked_by_hand' => 1],
            $document->object('progress')->all(),
        );

        $reported = [];

        foreach ($document->objects('tasks') as $task) {
            $reported[$task->string('description')] = [
                'completion' => $task->string('completion'),
                'attempts' => $task->integer('attempts'),
                'has_commit' => !$task->isNull('commit'),
            ];
        }

        self::assertSame(
            [
                self::FIRST => ['completion' => 'runner_verified', 'attempts' => 1, 'has_commit' => true],
                self::DONE => ['completion' => 'user_completed', 'attempts' => 0, 'has_commit' => false],
            ],
            $reported,
        );
    }

    /**
     * The identifier the loop knows a task by, which comes from its words.
     */
    private function identify(string $description): TaskId
    {
        return TaskId::fromDescription($description);
    }

    /**
     * The newest pass at a task, where its state, branch and commit live.
     */
    private function pass(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor($this->identify($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }

    /**
     * Where a task's completion came from, as the store records it.
     */
    private function completionOf(string $description): Completion
    {
        $task = $this->project->journal()->tasks()->find($this->identify($description));

        if ($task === null) {
            self::fail(sprintf('The state store holds no task described as "%s".', $description));
        }

        return $task->completion();
    }
}
