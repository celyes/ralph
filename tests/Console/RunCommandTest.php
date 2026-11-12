<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\LoopCommand;
use Celyes\RalphPhp\Console\Command\ProjectCommand;
use Celyes\RalphPhp\Console\Command\RunCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\StopKind;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
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

    /**
     * A declared authentication variable, and a value long enough to be treated
     * as a credential ({@see \Celyes\RalphPhp\Support\Secrets::MINIMUM_LENGTH}).
     */
    private const string SECRET_VARIABLE = 'RALPH_TEST_RUN_KEY';

    private const string SECRET = 'sk-ralph-0123456789-never-printed';

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
     * PROJECT.md section I, item 6, through the binary: after a real run whose
     * agent printed its own credential, the only place in the runtime directory
     * that still holds the value is the worker's own log.
     *
     * The assertion is deliberately the *whole* set of runtime files rather
     * than the few a case thought to name. Everything the run wrote is read —
     * the state database, the event stream, the memory notes, the completion
     * record, the staged diff, the lock — so a future diagnostic that quoted an
     * agent's output without going through redaction fails this test by
     * appearing in the list.
     *
     * And the log itself is asserted to still hold the key, because that is the
     * documented side of the item rather than a gap in it: the file is a
     * verbatim copy, `ralph logs --help` says so, and a test that let it be
     * redacted would be pinning the opposite of the decision.
     */
    #[Test]
    public function the_only_runtime_file_holding_a_declared_secret_is_the_workers_log(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])
                    ->saying('Authenticated with ' . self::SECRET . ' and added the option'),
            )
            ->withTasks([self::TASK])
            ->withConfiguration([
                'agents' => ['fixture' => ['environment' => [self::SECRET_VARIABLE]]],
            ]);

        $result = $this->project->ralph(['run'], [self::SECRET_VARIABLE => self::SECRET]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $holding = $this->project->runtimeFilesContaining(self::SECRET);

        self::assertSame(
            ['.ralph/runtime/logs/' . $this->project->journal()->generations()->forTask(
                $this->project->journal()->tasks()->all()[0]->id(),
            )[0]->slug() . '/attempt-01-agent.log'],
            $holding,
            'A declared secret reached a package-generated diagnostic: ' . implode(', ', $holding),
        );

        // The agent's claim, as the package kept it: the same sentence with the
        // variable's name where its value was.
        $logs = $this->project->ralph(['logs', $this->project->journal()->tasks()->all()[0]->id()->value()]);

        self::assertSame(ExitCode::SUCCESS, $logs->exitCode, $logs->describe());
        self::assertStringContainsString(
            'the agent said (unverified): Authenticated with [redacted ' . self::SECRET_VARIABLE
                . '] and added the option',
            $logs->standardOutput,
            $logs->describe(),
        );

        // And the raw log is printed as it was captured, which is why the
        // command's help documents what these files hold.
        self::assertStringContainsString(self::SECRET, $logs->standardOutput, $logs->describe());
    }

    /**
     * `ralph run | head`: the terminal stops reading, and the run carries on.
     *
     * PROJECT.md section H, item 9 and section J, item 13. Until this was
     * fixed, the first write that failed with `errno=32 Broken pipe` became an
     * `ErrorException` and ended the run — so a reader who piped a run into
     * `head`, or quit a pager on the first screen, lost every task after the
     * one being worked on and was told the run had failed. A terminal is a copy
     * of what the loop is doing, never the thing itself: losing it is not a
     * reason to stop.
     *
     * Two tasks, because the loss is only half the defect: the run that was
     * reported as failed had also abandoned everything still pending.
     */
    #[Test]
    public function a_run_whose_output_is_no_longer_read_finishes_its_work(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['src/Export.php' => "<?php\n\n// --dry-run\n"]),
            )
            ->withTasks([self::TASK, 'Add a --dry-run option to the export command'])
            ->withConfiguration();

        $result = $this->project->ralphWithoutAReader(['run']);

        // Not a failure, and not a broken-pipe exception on the way out.
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringNotContainsString('Broken pipe', $result->errorOutput, $result->describe());

        // Both tasks were carried through, not just the one that was in flight
        // when the reader went away.
        self::assertSame(2, $this->project->journal()->tasks()->verified()->count(), $result->describe());
        self::assertStringNotContainsString('- [ ] ', $this->project->checklist());
        self::assertCount(2, $this->project->taskBranches());

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame(LifecycleState::Completed, $run->state(), $result->describe());
    }

    /**
     * The same run, written for a program: the event stream another process
     * follows while the run is going on (PROJECT.md section I, item 3).
     *
     * Through the binary rather than against the runner, because what is worth
     * pinning is that a real `ralph run` leaves the file behind at the path a
     * supervising process would be told to watch — the stream's own behaviour
     * is {@see \Celyes\RalphPhp\Tests\Event\EventStreamTest}.
     */
    #[Test]
    public function it_writes_the_runs_events_as_json_lines(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $events = $this->project->events();

        self::assertSame(
            ['run.started', 'task.started', 'task.finished', 'run.finished'],
            array_map(static fn (JsonDocument $event): string => $event->string('event'), $events),
            $result->describe(),
        );

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);

        // Every line carries the run the state store recorded, which is what a
        // consumer correlates the stream by, and the sequence that orders it.
        self::assertSame(
            array_fill(0, 4, $run->runId()),
            array_map(static fn (JsonDocument $event): string => $event->string('run'), $events),
            $result->describe(),
        );
        self::assertSame(
            [1, 2, 3, 4],
            array_map(static fn (JsonDocument $event): int => $event->integer('seq'), $events),
        );
        self::assertSame(
            array_fill(0, 4, 1),
            array_map(static fn (JsonDocument $event): int => $event->integer('schema'), $events),
        );

        // The outcomes are the words the store uses, not a second vocabulary:
        // the task's lifecycle state and the run's stop kind.
        self::assertSame('completed', $events[2]->string('outcome'), $result->describe());
        self::assertSame('finished', $events[3]->string('outcome'), $result->describe());

        // The task is named on the task lines and on neither of the run's.
        self::assertTrue($events[0]->isNull('task'), $result->describe());
        self::assertTrue($events[3]->isNull('task'), $result->describe());
        self::assertSame($events[1]->string('task'), $events[2]->string('task'));
        self::assertMatchesRegularExpression('/^TASK-[0-9A-F]{8}$/', $events[1]->string('task'));

        // Enough of the pass's own record to act on without opening the
        // database: the commit it produced, and the branch it is on.
        self::assertSame(
            $this->project->headOf($this->project->taskBranches()[0]),
            $events[2]->string('data.commit'),
            $result->describe(),
        );
    }

    /**
     * The finishing lines carry the store's word, and each line names the
     * occurrence it reports (PROJECT.md section I, item 4).
     *
     * Through the binary, because the claim being made is about what another
     * program finds in the file after a real run: that acting on
     * `task.finished` is acting on something the next process to open the
     * database will also find, and that meeting the same line twice is
     * detectable. The gate itself is
     * {@see \Celyes\RalphPhp\Tests\Run\DurableOutcomeTest}.
     */
    #[Test]
    public function the_finishing_events_carry_what_the_store_holds(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $events = $this->project->events();
        [, , $finished, $ended] = $events;

        $journal = $this->project->journal();
        $run = $journal->runs()->latest();
        self::assertNotNull($run);

        // The task's word, read back off the row rather than off the result the
        // loop was holding, and confirmed as such.
        self::assertTrue($finished->boolean('data.durable.confirmed'), $result->describe());
        self::assertSame('completed', $finished->string('outcome'));
        self::assertSame('completed', $finished->string('data.durable.state'));
        self::assertSame(
            $journal->tasks()->verified()->count(),
            1,
            'the store agrees the pass was the runner\'s own completion',
        );

        // Including the tick: a completed pass owes no checklist write, which
        // is the difference between a task a consumer can act on and one this
        // package has not finished with.
        self::assertFalse($finished->boolean('data.durable.checklist_write_owed'));
        self::assertSame(
            $this->project->headOf($this->project->taskBranches()[0]),
            $finished->string('data.durable.commit'),
        );

        // The run's ending, likewise read back off a row that is closed by the
        // time the line is written.
        self::assertTrue($ended->boolean('data.durable.confirmed'), $result->describe());
        self::assertSame('finished', $ended->string('outcome'));
        self::assertSame(LifecycleState::Completed->value, $ended->string('data.durable.state'));

        // Four occurrences, four identities: the key a consumer deduplicates on
        // names what happened, so no two of one run's lines can share one.
        $ids = array_map(static fn (JsonDocument $event): string => $event->string('id'), $events);

        self::assertCount(4, array_unique($ids), $result->describe());
        self::assertSame(
            array_fill(0, 4, true),
            array_map(static fn (string $id): bool => (bool) preg_match('/^ev_[0-9a-f]{32}$/', $id), $ids),
        );
    }

    /**
     * A run that gave up on a task exits with the status that says so.
     *
     * {@see ExitCode::EXECUTION}, the status for work that was attempted and
     * did not succeed: not a success, and not the blocking status either, since
     * nothing is waiting on a decision — the task can simply be attempted
     * again. The box stays unticked.
     */
    #[Test]
    public function a_task_it_gave_up_on_leaves_the_box_unticked_and_asks_for_attention(): void
    {
        $this->project
            ->withWorker(FixtureStep::failure('could not work out the option parsing'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());
        self::assertStringContainsString('ran out of attempts', $result->standardOutput, $result->describe());
        self::assertStringContainsString('next: ', $result->standardOutput, $result->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
        self::assertSame([], $this->project->taskBranches(), 'An unverified task committed nothing.');
    }

    /**
     * A run the iteration limit stopped exits 124, and `ralph status`
     * afterwards exits 0.
     *
     * The pair is the rule the whole set rests on (PROJECT.md section I,
     * item 5): a code says what the command found, not what happened in the
     * past. The run stopped short of the checklist, so chaining anything onto
     * it must not go ahead — but what the project is *left* holding is a
     * pending task, which is indistinguishable from the pending task of a
     * project nobody has run, and a `status` that reported the limit again
     * would make an untouched project's status depend on a run that is over.
     */
    #[Test]
    public function a_run_the_limit_stopped_exits_with_the_limit_status_and_status_does_not(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::TASK, 'Add a --quiet option to the import command'])
            ->withConfiguration(['limits' => ['iterations' => 1]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::LIMIT, $result->exitCode, $result->describe());
        self::assertStringContainsString('still pending', $result->standardOutput, $result->describe());

        // The first task was done properly on the way: the limit stops the
        // loop, it does not undo the iteration that spent it.
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());

        $status = $this->project->ralph(['status']);

        self::assertSame(ExitCode::SUCCESS, $status->exitCode, $status->describe());

        // And the document agrees with the status, which is the field a script
        // reads instead of the code when it has the report in a file.
        $document = JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput);

        self::assertFalse($document->boolean('needs_attention'), 'A limit left something waiting for a person.');
        self::assertSame(ExitCode::SUCCESS, $document->integer('exit_code'));
    }

    /**
     * A task that asks a question exits 75, with the work preserved.
     *
     * The blocking status: not a failure — nothing went wrong and nothing was
     * undone — and not a success, which is the distinction a caller cannot make
     * from the summary line without parsing prose.
     */
    #[Test]
    public function a_task_that_asks_a_question_exits_with_the_blocking_status(): void
    {
        $this->project
            ->withWorker(FixtureStep::blocking('the import command has two option parsers', 'Say which one to use'))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());
        self::assertStringContainsString('two option parsers', $result->standardOutput, $result->describe());
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());

        self::assertSame(
            LifecycleState::Blocked,
            $this->project->journal()->generations()->latestFor(
                \Celyes\RalphPhp\Tasks\TaskId::fromDescription(self::TASK),
            )?->state(),
        );
    }

    /**
     * A refusal raised after the agent has run exits 75, and `ralph status`
     * afterwards says the same thing.
     *
     * The case the blocking status is really for: a worker that reworded the
     * task in the project's own checklist while working on it, which the runner
     * will not silently apply old progress to (section C, item 7). Work exists
     * — the implementation is verified and committed on its branch — nothing
     * was undone, and what is missing is the user's decision about which task
     * the commit belongs to. An execution-failure status would send a reader to
     * the logs looking for something that went wrong, and a success would hide
     * it entirely.
     *
     * Both halves are asserted because they are produced by different code: the
     * run's status comes from the ending it recorded, and `status`'s comes from
     * reading the pass back out of the store afterwards.
     */
    #[Test]
    public function a_refusal_after_the_agent_ran_exits_as_blocked_and_status_agrees(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([
                'src/Import.php' => "<?php\n\n// --dry-run\n",
                // Out of the worktree and into the project's own checklist:
                // .ralph/runtime/worktrees/TASK-X is three levels down.
                '../../../tasks.md' => "# Tasks\n\n- [ ] Add a --wet-run option to the import command\n",
            ]))
            ->withTasks([self::TASK])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());
        self::assertStringContainsString('no longer states', $result->standardOutput, $result->describe());

        // The work the refusal was about is still there, which is what makes
        // this a block rather than a failure.
        self::assertNotSame([], $this->project->taskBranches());

        $run = $this->project->journal()->runs()->latest();

        self::assertNotNull($run);
        self::assertSame(StopKind::Decision, $run->stopKind(), 'The row disagrees with the status it exited with.');

        $status = $this->project->ralph(['status']);

        self::assertSame(ExitCode::BLOCKED, $status->exitCode, $status->describe());
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
     * `--bail` stops the run at the first task that does not pass, and the
     * command exits on the failure rather than on the end of the checklist.
     *
     * Through the binary because the option and the exit status are what only
     * the command can be held to; that the loop stops where it says it does is
     * {@see \Celyes\RalphPhp\Tests\Run\RunnerTest}.
     */
    #[Test]
    public function the_bail_option_stops_the_run_at_the_first_failed_task(): void
    {
        $second = 'Document the import command in the README';

        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// not good enough\n"]))
            ->withTasks([self::TASK, $second])
            // A check that is a real process and a real non-zero status.
            ->withConfiguration(['checks' => [['command' => ['php', '-r', 'exit(1);']]]]);

        $result = $this->project->ralph(['run', '--bail']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());
        self::assertStringContainsString('bailing: --bail was given', $result->standardOutput, $result->describe());
        self::assertStringContainsString(
            'asked to stop at the first failure',
            $result->standardOutput,
            $result->describe(),
        );

        // The second task was never taken on: still pending, and no pass of
        // its own.
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
        self::assertStringContainsString('- [ ] ' . $second, $this->project->checklist());
        self::assertSame(1, $this->project->journal()->runs()->latest()?->iterations());
        self::assertSame(StopKind::Failed, $this->project->journal()->runs()->latest()?->stopKind());
    }

    /**
     * Both bail options at once is a refusal, because neither reading of it is
     * more likely than a typo — and a run that guessed could work through a
     * checklist the user expected it to stop in.
     */
    #[Test]
    public function the_two_bail_options_together_are_refused_without_starting_a_run(): void
    {
        $this->project->withWorker()->withTasks([self::TASK])->withConfiguration();

        $result = $this->project->ralph(['run', '--bail', '--no-bail']);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertStringContainsString('both "--bail" and "--no-bail"', $result->errorOutput);
        self::assertStringContainsString('limits.bail_on_failure', $result->errorOutput);

        // Nothing was taken on.
        self::assertStringContainsString('- [ ] ' . self::TASK, $this->project->checklist());
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
        self::assertStringContainsString('--bail', $result->standardOutput);
        self::assertStringContainsString('--no-bail', $result->standardOutput);
        self::assertStringContainsString('limits.bail_on_failure', $result->standardOutput);
    }
}
