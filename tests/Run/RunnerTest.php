<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\CheckoutException;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\Run\RunReport;
use Celyes\RalphPhp\Run\Runner;
use Celyes\RalphPhp\Run\TaskPass;
use Celyes\RalphPhp\Run\TaskResult;
use Celyes\RalphPhp\Run\Workbench;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\VerificationStore;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Fixture\FixtureWorker;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The loop, end to end, against a real repository and a real worker.
 *
 * PROJECT.md section G, item 1 is one sentence with seven verbs in it, and this
 * is where they are held to being carried out in that order and to meaning what
 * they say. Everything under the loop is real: a Git repository with linked
 * worktrees, the fixture worker started through the Codex adapter as an
 * executable with a briefing on standard input, the SQLite state store, the
 * Markdown checklist on disk. The one substitution is the verifier
 * ({@see ScriptedVerifier}), because a check that really ran would make every
 * case here depend on a test suite being installed inside a temporary worktree
 * — and because the loop's correctness does not depend on how a check is
 * executed, only on what it decided.
 *
 * Four assertions recur, each standing for a way the loop could do real harm.
 * The checklist on disk is read back, because a box ticked for work that did
 * not pass is the one lie a user cannot catch. The repository is read with
 * `git log`, because a result object claiming a commit is not a commit. The
 * controlling checkout is asserted untouched, because the user is sitting in
 * it. And the state store is read back rather than the returned report,
 * because the report dies with the process and the rows are what `status`,
 * `logs` and `resume` will believe.
 *
 * No agent CLI, no credentials and no network (section J, item 8).
 */
#[CoversClass(Runner::class)]
#[CoversClass(TaskPass::class)]
#[CoversClass(TaskResult::class)]
#[CoversClass(RunReport::class)]
#[CoversClass(Journal::class)]
#[CoversClass(Workbench::class)]
final class RunnerTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string DONE = 'Create the import command';

    /**
     * What a worker would write over the project's checks to make anything
     * pass: the suite gone, something that always succeeds in its place.
     */
    private const string REPLACED_CHECKS = '{"checks": [{"name": "quick", "command": ["true"]}]}';

    private TemporaryProject $project;

    /**
     * Where the fixture worker lives: outside the repository, so its program,
     * plan and journal cannot turn up in a diff the run stages.
     */
    private string $tools;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the loop commits in real worktrees.');
        }

        $this->project = TemporaryProject::create();
        $this->tools = TemporaryDirectory::create('ralph-tools');

        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('README.md', "An example project.\n");
        $this->project->write('src/Import.php', "<?php\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    protected function tearDown(): void
    {
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        TemporaryDirectory::remove($this->tools);
        $this->project->remove();
    }

    /**
     * The whole item, in one case: a pending task is selected, worked on,
     * verified, committed and checked off.
     */
    #[Test]
    public function a_pending_task_is_prepared_implemented_verified_committed_and_checked_off(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);
        $before = $this->snapshotOfTheCheckout();

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertCount(1, $report->results());
        $result = $report->results()[0];

        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertTrue($result->wasVerified());
        self::assertSame(1, $result->attempts());

        // The commit a reviewer will see, read out of Git rather than from the
        // value that claims it.
        $sha = (string) $result->commitSha();
        self::assertSame(1, preg_match('/^[0-9a-f]{40}$/', $sha));
        self::assertSame($sha, $this->tipOf((string) $result->branch()));
        self::assertSame(['src/Import.php'], $this->filesIn($sha));
        self::assertStringContainsString($result->taskId()->value(), $this->messageOf($sha));

        // The checklist on disk, which is what the user reads.
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        // The rows, which are what the other commands will believe.
        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNull($pass->phase());
        self::assertSame($sha, $pass->commitSha());
        self::assertSame(
            Completion::RunnerVerified,
            $journal->tasks()->get($result->taskId())->completion(),
        );

        $attempt = $journal->attempts()->latestFor($pass);
        self::assertNotNull($attempt);
        self::assertSame(LifecycleState::Completed, $attempt->state());
        self::assertTrue($journal->attempts()->verificationSupports($attempt));
        self::assertNotNull($attempt->logPath());
        self::assertFileExists((string) $attempt->logPath());

        // The checkbox edit is the one change a run makes to the user's
        // checkout, and it is left uncommitted: HEAD and the branch are where
        // they were, and the checklist is the only dirty path. The task's own
        // commit lives on the task branch, not here.
        $after = $this->snapshotOfTheCheckout();
        self::assertSame($before['head'], $after['head']);
        self::assertSame($before['branch'], $after['branch']);
        self::assertSame('M .ralph/tasks.md', trim($after['status']));
    }

    /**
     * The checks are run by the package, against the workspace, with the
     * agent's work in it and before anything is staged.
     */
    #[Test]
    public function the_checks_run_against_the_task_workspace_the_agent_changed(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// the agent was here\n"])]);

        $verifier = ScriptedVerifier::passing('src/Import.php');
        $report = $this->loopOver($verifier);

        self::assertCount(1, $verifier->calls);
        self::assertStringContainsString(
            '// the agent was here',
            $verifier->sawInWorkspace['src/Import.php'] ?? '',
        );

        // The workspace, not the user's checkout, and not the project root.
        self::assertStringStartsWith(
            $this->project->path('.ralph/runtime/worktrees'),
            $verifier->calls[0]['directory'],
        );
        self::assertSame(
            "<?php\n",
            (string) file_get_contents($this->project->path('src/Import.php')),
            'The user\'s own checkout is not where the agent works.',
        );

        // And what it was handed is the run's frozen checks, by name.
        self::assertSame(['tests'], $verifier->calls[0]['checks']);
        self::assertSame(['tests'], $report->run()->checkNames());
    }

    /**
     * Pending tasks in file order, checked ones skipped and left as the user's
     * own completion rather than the package's claim.
     */
    #[Test]
    public function pending_tasks_run_in_file_order_and_checked_items_are_skipped(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND], [self::DONE]);
        $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// first\n"]),
            FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
        ]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertSame(
            [self::FIRST, self::SECOND],
            array_map(static fn (TaskResult $r): string => $r->description(), $report->results()),
        );
        self::assertSame(2, $report->completedCount());
        self::assertSame(0, $report->remaining());
        self::assertTrue($report->isClean());

        $markdown = $this->tasksFile();
        self::assertStringContainsString('- [x] ' . self::FIRST, $markdown);
        self::assertStringContainsString('- [x] ' . self::SECOND, $markdown);
        self::assertStringContainsString('- [x] ' . self::DONE, $markdown);

        // The item the user had already ticked is recorded as theirs, and no
        // pass was ever opened at it.
        $journal = $this->journal();
        $done = $journal->tasks()->all();
        $imported = null;

        foreach ($done as $task) {
            if ($task->description() === self::DONE) {
                $imported = $task;
            }
        }

        self::assertNotNull($imported);
        self::assertSame(Completion::UserCompleted, $imported->completion());
        self::assertSame([], $journal->generations()->forTask($imported->id()));

        // Two commits, one per task, each on its own branch.
        self::assertNotSame($report->results()[0]->branch(), $report->results()[1]->branch());
        self::assertNotSame($report->results()[0]->commitSha(), $report->results()[1]->commitSha());
    }

    /**
     * A failing check leaves the task unchecked, keeps nothing out of the
     * repository, and keeps the workspace for whoever has to look at it.
     */
    #[Test]
    public function a_failing_check_leaves_the_task_unchecked_and_keeps_the_work_for_inspection(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// not good enough\n"])]);

        $report = $this->loopOver(ScriptedVerifier::failing());
        $result = $report->results()[0];

        self::assertFalse($result->isCompleted());
        self::assertFalse($result->wasChecked());
        self::assertTrue($result->isFailure());
        self::assertNull($result->commitSha());
        self::assertFalse($result->isNoChange());

        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
        self::assertSame([], $this->commitsBeyondBase());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Verifying, $pass->phase(), 'The phase is where it stopped.');
        self::assertNotNull($pass->blocker());
        self::assertStringContainsString('verification failed', (string) $pass->blocker()?->reason());

        // The workspace stays, and the evidence is stored for the next attempt.
        self::assertDirectoryExists((string) $pass->worktree()->path());

        $attempt = $journal->attempts()->latestFor($pass);
        self::assertNotNull($attempt);
        self::assertSame(LifecycleState::Failed, $attempt->state());
        self::assertFalse($journal->attempts()->verificationSupports($attempt));
        self::assertNotNull($attempt->evidence());
        self::assertStringContainsString('Failed asserting', (string) $attempt->evidence()?->toMarkdown());

        // The whole sequence of checks is on the attempt, as it ran.
        $results = (new VerificationStore($journal->database()))->forAttempt($attempt);
        self::assertCount(1, $results);
        self::assertTrue($results[0]->isFailure());
        self::assertSame(1, $results[0]->exitCode());
    }

    /**
     * The next attempt is told what the last one ran into.
     */
    #[Test]
    public function a_second_run_continues_the_pass_and_tells_the_agent_what_failed(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// first try\n"]),
            FixtureStep::success(['src/Import.php' => "<?php\n\n// second try\n"]),
        ]);

        $first = $this->loopOver(ScriptedVerifier::failing());
        self::assertTrue($first->results()[0]->isFailure());

        $second = $this->loopOver(ScriptedVerifier::passing());
        $result = $second->results()[0];

        // The same pass, continued: one generation, two attempts, one commit.
        self::assertSame($first->results()[0]->generation()->number(), $result->generation()->number());
        self::assertSame(2, $result->attempts());
        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertCount(1, $this->commitsBeyondBase());

        $attempts = $worker->attempts();
        self::assertCount(2, $attempts);
        self::assertStringNotContainsString('Failed asserting', $attempts[0]->briefing());
        self::assertStringContainsString('Failed asserting', $attempts[1]->briefing());
    }

    /**
     * An agent that asks for human input blocks its task and stops the run,
     * rather than handing the next task to an agent that is waiting for an
     * answer.
     */
    #[Test]
    public function an_agent_that_asks_for_human_input_blocks_the_task_and_stops_the_run(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([FixtureStep::blocking('Which importer should this wrap?', 'Name the importer in the task.')]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertCount(1, $report->results(), 'The second task is not taken on.');
        $result = $report->results()[0];

        self::assertTrue($result->isBlocked());
        self::assertTrue($result->stopsTheRun());
        self::assertNull($result->verification(), 'Nothing was verified: there was no candidate.');
        self::assertStringContainsString($result->taskId()->value(), $report->stopReason());
        self::assertStringContainsString('needs human input', $report->stopReason());

        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertStringContainsString('Which importer', (string) $pass->blocker()?->reason());

        $run = $journal->runs()->get($report->run()->rowId());
        self::assertSame(LifecycleState::Completed, $run->state());
        self::assertSame(1, $run->iterations());
    }

    /**
     * A failed task does not end the run: the tasks after it are independent
     * work.
     */
    #[Test]
    public function a_failed_task_leaves_the_loop_free_to_take_on_the_next_one(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([
            FixtureStep::failure('the importer could not be found'),
            FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
        ]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertCount(2, $report->results());
        self::assertTrue($report->results()[0]->isFailure());
        self::assertTrue($report->results()[1]->isCompleted());
        self::assertTrue($report->needsAttention());
        self::assertSame(1, $report->completedCount());

        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
        self::assertStringContainsString('- [x] ' . self::SECOND, $this->tasksFile());
    }

    /**
     * A task the checks already pass without any change is a verified
     * no-change result, and not an empty commit.
     */
    #[Test]
    public function a_task_that_was_already_satisfied_completes_without_a_commit(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success()->saying('nothing to do: it already has one')]);

        $report = $this->loopOver(ScriptedVerifier::passing());
        $result = $report->results()[0];

        self::assertTrue($result->isCompleted());
        self::assertTrue($result->isNoChange());
        self::assertNull($result->commitSha());
        self::assertTrue($result->wasChecked());
        self::assertSame([], $this->commitsBeyondBase());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        $pass = $this->journal()->generations()->get($result->generation());
        self::assertTrue($pass->isNoChange());
        self::assertTrue($pass->hasImplementation());
    }

    /**
     * The iteration limit stops the loop with the count intact.
     */
    #[Test]
    public function the_run_stops_at_the_iteration_limit(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// one\n"])]);

        $report = $this->loopOver(ScriptedVerifier::passing(), ['limits' => ['iterations' => 1]]);

        self::assertCount(1, $report->results());
        self::assertStringContainsString('iteration limit of 1', $report->stopReason());
        self::assertSame(1, $report->remaining());
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->tasksFile());
        self::assertSame(1, $this->journal()->runs()->get($report->run()->rowId())->iterations());
    }

    /**
     * A checklist with nothing pending is not an error, and nothing is written
     * to the repository over it.
     */
    #[Test]
    public function a_checklist_with_nothing_pending_ends_the_run_without_touching_anything(): void
    {
        $this->givenTasks([], [self::DONE]);
        $this->givenAWorker();
        $before = $this->snapshotOfTheCheckout();

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertSame([], $report->results());
        self::assertStringContainsString('nothing pending', $report->stopReason());
        self::assertSame([], $this->commitsBeyondBase());
        self::assertSame($before, $this->snapshotOfTheCheckout());
        self::assertSame(LifecycleState::Completed, $report->run()->state());
    }

    /**
     * The run is refused before anything is written when the user has
     * uncommitted work of their own.
     */
    #[Test]
    public function unrelated_uncommitted_changes_refuse_the_run_before_it_starts(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker();
        $this->project->write('src/Import.php', "<?php\n\n// mine, not committed\n");

        try {
            $this->loopOver(ScriptedVerifier::passing());
            self::fail('A run was allowed over the user\'s own uncommitted work.');
        } catch (CheckoutException $refusal) {
            self::assertStringContainsString('src/Import.php', $refusal->getMessage());
        }

        self::assertFileDoesNotExist($this->project->path('.ralph/runtime/state.sqlite'));
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
    }

    /**
     * Checks that cannot be run at all are a setup failure, not a verdict: the
     * run ends, and its row says why.
     */
    #[Test]
    public function checks_that_cannot_be_run_fail_the_task_rather_than_passing_it(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// fine\n"])]);

        $report = $this->loopOver(ScriptedVerifier::unrunnable());
        $result = $report->results()[0];

        self::assertTrue($result->isFailure());
        self::assertFalse($result->wasChecked());
        self::assertNull($result->commitSha());
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        $pass = $this->journal()->generations()->get($result->generation());
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertStringContainsString(
            'checks could not be run',
            (string) $pass->blocker()?->reason(),
        );
    }

    /**
     * The run row holds what the run was, so that `status`, `logs` and `resume`
     * do not have to ask the configuration what it has become.
     */
    #[Test]
    public function the_run_records_the_agent_the_base_and_the_frozen_checks(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// done\n"])]);

        $report = $this->loopOver(ScriptedVerifier::passing());
        $run = $this->journal()->runs()->get($report->run()->rowId());

        self::assertSame('fixture', $run->agent()->name());
        self::assertSame('codex', $run->agent()->adapter());
        self::assertSame('HEAD', $run->baseReference());
        self::assertSame($this->headOfTheCheckout(), $run->baseSha());
        self::assertSame($this->project->path('.ralph/tasks.md'), $run->tasksPath());
        self::assertSame(['tests'], $run->checkNames());
        self::assertSame(1, $run->iterations());
        self::assertNotNull($run->finishedAt());

        // The agent is on the pass and the attempt too, which is what resume
        // reads rather than the configuration.
        $pass = $this->journal()->generations()->get($report->results()[0]->generation());
        self::assertSame('fixture', $pass->agent()->name());
        self::assertSame($run->rowId(), $pass->runId());
    }

    /**
     * A completed pass's workspace is removed; a failed one's is kept. Both are
     * the cleaner's decision, taken with the pass's final record.
     */
    #[Test]
    public function a_completed_passs_workspace_is_removed_and_a_failed_ones_is_kept(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// one\n"]),
            FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
        ]);

        $report = $this->loopOver(ScriptedVerifier::scripted([true, false]));

        $completed = $report->results()[0];
        $failed = $report->results()[1];

        self::assertTrue($completed->isCompleted());
        self::assertTrue($failed->isFailure());

        $journal = $this->journal();
        self::assertDirectoryDoesNotExist(
            (string) $journal->generations()->get($completed->generation())->worktree()->path(),
        );
        self::assertDirectoryExists(
            (string) $journal->generations()->get($failed->generation())->worktree()->path(),
        );

        // The completed pass's branch and commit survive the workspace.
        self::assertSame($completed->commitSha(), $this->tipOf((string) $completed->branch()));
    }

    /**
     * Each attempt is written up for the next one to read.
     */
    #[Test]
    public function each_attempt_leaves_a_memory_note_behind(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// done\n"])->saying('added the option')]);

        $report = $this->loopOver(ScriptedVerifier::passing());
        $notes = glob($this->project->path('.ralph/runtime/memory/*/*.md')) ?: [];

        self::assertNotSame([], $notes, 'An attempt that said something leaves a note.');

        $written = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $notes));
        self::assertStringContainsString('added the option', $written);
        self::assertStringContainsString(
            'Nothing in it has been checked.',
            $written,
            'What the agent said is stored as a claim, not as a finding.',
        );
        self::assertTrue($report->results()[0]->isCompleted());
    }

    /**
     * The checks a run is held to are the ones it froze, and an edit to them is
     * reported rather than obeyed.
     *
     * Section G, item 3's whole reason for existing: the worker whose work the
     * checks judge can write to the repository, `.ralph/config.json` included,
     * so a loop that asked the file for its checks each time would let the
     * worker choose what it is held to — replace the suite with `true`, and
     * every task after it passes.
     */
    #[Test]
    public function a_workers_edit_to_the_checks_does_not_change_what_this_run_verifies(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([
            FixtureStep::success([
                'src/Import.php' => "<?php\n\n// one\n",
                // The project's own configuration, four directories above a
                // task workspace: the file the next run will read.
                '../../../../.ralph/config.json' => self::REPLACED_CHECKS,
            ]),
            FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
        ]);

        $terminal = new CapturingOutput();
        $verifier = ScriptedVerifier::passing();
        $report = $this->loopFromTheConfigFile($verifier, $terminal);

        // Both tasks were verified against the frozen set, including the one
        // taken on after the file had already been rewritten.
        self::assertSame(
            [['tests'], ['tests']],
            array_map(static fn (array $call): array => $call['checks'], $verifier->calls),
        );
        self::assertSame(['tests'], $report->run()->checkNames());
        self::assertSame(['tests'], $report->run()->verification()->names());
        self::assertSame(2, $report->completedCount());

        // And the edit is said out loud: a run that quietly ignored it would
        // leave a user reading settings the run is not obeying.
        $drift = $report->verificationDrift();
        self::assertTrue($drift->hasChanged());
        self::assertSame(['tests'], $drift->removed());
        self::assertSame(['quick'], $drift->added());

        $printed = $terminal->standardOutput();
        self::assertStringContainsString('the verification configuration changed', $printed);
        self::assertStringContainsString('no longer configured: tests', $printed);
        self::assertStringContainsString('newly configured: quick', $printed);
        self::assertStringContainsString('checks in force: tests', $printed);
        self::assertSame(
            1,
            substr_count($printed, 'no longer configured: tests'),
            'One edit is reported once, not after every task behind it.',
        );

        // The file does now say what the worker wrote, which is what the next
        // run will be held to.
        self::assertSame(
            ['quick'],
            VerificationSnapshot::of((new ConfigurationLoader())->load($this->project->root()))->names(),
        );
    }

    /**
     * A configuration the worker left unreadable is reported, and is not a
     * reason to throw away the run's work: the checks were frozen before the
     * file was touched.
     */
    #[Test]
    public function a_configuration_left_unreadable_is_reported_without_ending_the_run(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success([
            'src/Import.php' => "<?php\n\n// done\n",
            '../../../../.ralph/config.json' => '{ this is no longer JSON',
        ])]);

        $terminal = new CapturingOutput();
        $report = $this->loopFromTheConfigFile(ScriptedVerifier::passing(), $terminal);
        $result = $report->results()[0];

        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertSame(LifecycleState::Completed, $report->run()->state());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        $drift = $report->verificationDrift();
        self::assertTrue($drift->hasChanged());
        self::assertStringContainsString('not valid JSON', (string) $drift->unreadableReason());
        self::assertStringContainsString('unreadable:', $terminal->standardOutput());
    }

    /**
     * Run the loop over this project.
     *
     * @param array<string, mixed> $overrides merged into the configuration
     */
    private function loopOver(ScriptedVerifier $verifier, array $overrides = []): RunReport
    {
        return Runner::forProject(
            $this->configuration($overrides),
            $verifier,
            null,
            AdapterRegistry::default(),
        )->run();
    }

    /**
     * Run the loop over this project, configured from a committed
     * `.ralph/config.json`.
     *
     * The file matters to the cases about it: what a run freezes is compared
     * against what the file says afterwards, and a worker's edit is only a
     * worker's edit if there is a real file to edit.
     *
     * @param array<string, mixed> $overrides merged into the configuration
     */
    private function loopFromTheConfigFile(
        ScriptedVerifier $verifier,
        CapturingOutput $terminal,
        array $overrides = [],
    ): RunReport {
        $this->project->writeConfigArray($this->settings($overrides));
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'Add the configuration');

        return Runner::forProject(
            (new ConfigurationLoader())->load($this->project->root()),
            $verifier,
            $terminal->output(),
            AdapterRegistry::default(),
        )->run();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configuration(array $overrides = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($this->settings($overrides), $this->project->root());
    }

    /**
     * The settings every case here runs under.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return array_replace_recursive([
            'agent' => 'fixture',
            'agents' => [
                'fixture' => [
                    'adapter' => 'codex',
                    'executable' => $this->tools . '/' . FixtureWorker::NAME,
                ],
            ],
            'checks' => [['name' => 'tests', 'command' => ['php', '-v'], 'timeout' => 30]],
            // Nothing to install and nothing to copy: the worker needs no
            // dependencies, and preparation is section F, item 5's subject
            // rather than this one's.
            'workspace' => ['prepare' => [], 'copy' => []],
        ], $overrides);
    }

    /**
     * @param list<FixtureStep> $steps
     */
    private function givenAWorker(array $steps = []): FixtureWorker
    {
        return FixtureWorker::install($this->tools, $steps);
    }

    /**
     * Write the checklist, and commit it as the user's own file.
     *
     * @param list<string> $pending
     * @param list<string> $checked
     */
    private function givenTasks(array $pending, array $checked = []): void
    {
        $lines = ['# Tasks', ''];

        foreach ($pending as $description) {
            $lines[] = '- [ ] ' . $description;
        }

        foreach ($checked as $description) {
            $lines[] = '- [x] ' . $description;
        }

        $this->project->write('.ralph/tasks.md', implode("\n", [...$lines, '']));
        $this->project->write('.ralph/CONTEXT.md', "# Conventions\n\nKeep it small.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'Add the checklist');
    }

    private function tasksFile(): string
    {
        return (string) file_get_contents($this->project->path('.ralph/tasks.md'));
    }

    private function journal(): Journal
    {
        return Journal::over(
            (new StateBootstrap())->openAt($this->project->path('.ralph/runtime/state.sqlite'))->database(),
        );
    }

    /**
     * Everything about the user's checkout a run must not change.
     *
     * @return array<string, string>
     */
    private function snapshotOfTheCheckout(): array
    {
        return [
            'head' => $this->headOfTheCheckout(),
            'branch' => Git::run($this->project->root(), 'rev-parse', '--abbrev-ref', 'HEAD')->standardOutput,
            'status' => Git::run($this->project->root(), 'status', '--porcelain')->standardOutput,
        ];
    }

    private function headOfTheCheckout(): string
    {
        return trim(Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput);
    }

    private function tipOf(string $branch): ?string
    {
        $result = Git::tryRun($this->project->root(), 'rev-parse', '--verify', $branch);

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    /**
     * Every commit in the repository that is not the base.
     *
     * @return list<string>
     */
    private function commitsBeyondBase(): array
    {
        $result = Git::run($this->project->root(), 'log', '--all', '--format=%H', 'HEAD..');
        $lines = array_filter(array_map('trim', explode("\n", $result->standardOutput)));

        return array_values($lines);
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $sha): array
    {
        $result = Git::run($this->project->root(), 'show', '--name-only', '--format=', $sha);

        return array_values(array_filter(array_map('trim', explode("\n", $result->standardOutput))));
    }

    private function messageOf(string $sha): string
    {
        return Git::run($this->project->root(), 'show', '--no-patch', '--format=%B', $sha)->standardOutput;
    }
}
