<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\CheckoutException;
use Celyes\RalphPhp\Exception\LockException;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Process\KnownProcesses;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\Run\RepositoryLock;
use Celyes\RalphPhp\Run\RunReport;
use Celyes\RalphPhp\Run\Runner;
use Celyes\RalphPhp\Run\TaskPass;
use Celyes\RalphPhp\Run\TaskResult;
use Celyes\RalphPhp\Run\Workbench;
use Celyes\RalphPhp\State\ChecklistMarker;
use Celyes\RalphPhp\State\ChecklistUpdateState;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\VerificationStore;
use Celyes\RalphPhp\Tasks\ChecklistFile;
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
     * The other half of section G, item 7: what the ticked box rests on, kept
     * as one document.
     *
     * By the end of a pass these facts are scattered — the checks are rows in
     * `verification_results`, the commit is a column on the generation, the
     * publication is three more, the agent's claim is on the attempt, and the
     * tick is a line in a file the package does not own. Every one is
     * reachable, and none of them answers the question a reviewer actually
     * arrives with. So the assertions here are about one file holding all of
     * them, written at the moment they were true.
     */
    #[Test]
    public function a_checked_off_task_records_the_evidence_for_the_tick_together(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])
                ->saying('added the option and a test for it'),
        ]);

        $terminal = new CapturingOutput();
        $result = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal)->results()[0];

        // The conditions were read, and the write they permitted landed.
        $completion = $result->completion();
        self::assertNotNull($completion);
        self::assertTrue($completion->isEarned());
        self::assertSame([], $completion->unmet());
        self::assertTrue($completion->wasChecked());
        self::assertNotNull($completion->edit());

        // Beside the attempt's logs, where `ralph logs` sends a reader.
        $record = (string) $completion->recordPath();
        self::assertSame(
            $this->project->path(sprintf(
                '.ralph/runtime/logs/%s/attempt-01-completed.md',
                $result->generation()->slug(),
            )),
            $record,
        );
        self::assertFileExists($record);

        $written = (string) file_get_contents($record);

        // Each condition on ticking a box, with this pass's answer to it.
        self::assertStringContainsString('- [x] **Verification passed', $written);
        self::assertStringContainsString('- [x] **An implementation is recorded', $written);
        self::assertStringContainsString('- [x] **Publication is settled', $written);

        // The checks the package ran, named with the command it ran them with.
        self::assertStringContainsString('**tests** — `php -v`', $written);

        // The commit a reviewer will read, and what is in it.
        self::assertStringContainsString((string) $result->commitSha(), $written);
        self::assertStringContainsString('`src/Import.php`', $written);

        // The agent that produced it, by name and without a credential.
        self::assertStringContainsString('Carried out by ', $written);

        // The line in the user's file that was changed, which is the one fact
        // here that exists nowhere else.
        self::assertStringContainsString('.ralph/tasks.md` line', $written);

        // The agent's words, kept as words.
        self::assertStringContainsString('added the option and a test for it', $written);
        self::assertStringContainsString('Nothing in them has been checked', $written);

        // And the limit, where a reader meets it rather than in a docblock.
        self::assertStringContainsString('does not say that any configured check tests', $written);

        self::assertStringContainsString('completion evidence kept at', $terminal->standardOutput());
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
     * The next attempt is told what the last one ran into, across runs.
     *
     * The attempt counter survives the first run ending, so a task with two
     * attempts to its name gets its second from the run after the one that
     * spent the first — and that attempt reads the failure out of the state
     * store rather than out of a loop variable that died with the process.
     */
    #[Test]
    public function a_second_run_continues_the_pass_and_tells_the_agent_what_failed(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// first try\n"]),
            FixtureStep::success(['src/Import.php' => "<?php\n\n// second try\n"]),
        ]);

        // One attempt per task for the first run, which its failing check
        // spends; then the limit is raised, as a user who looked at the failure
        // would raise it, and the second run makes the attempt that is now
        // allowed. The lever is the configuration rather than the loop, because
        // what this case is about is the counter and the evidence outliving a
        // process — within one run, the retrying above covers it.
        $first = $this->loopOver(ScriptedVerifier::failing(), ['limits' => ['attempts_per_task' => 1]]);
        self::assertTrue($first->results()[0]->isFailure());
        self::assertSame(1, $first->results()[0]->attempts());

        $second = $this->loopOver(ScriptedVerifier::passing(), ['limits' => ['attempts_per_task' => 2]]);
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
     * A failing check is tried again in the same run, and the attempt that
     * follows is told what the last one ran into.
     *
     * PROJECT.md section G, item 4, in the case it exists for. The first
     * attempt's work is kept rather than thrown away, the agent is handed the
     * failing check's output as the package's own observation, and the box is
     * ticked only once something passes.
     */
    #[Test]
    public function a_failing_check_is_tried_again_with_the_failure_as_evidence(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// first try\n"]),
            FixtureStep::success(['src/DryRun.php' => "<?php\n\n// second try\n"]),
        ]);
        $terminal = new CapturingOutput();

        $report = $this->loopOver(
            ScriptedVerifier::scripted([false, true]),
            ['limits' => ['attempts_per_task' => 2]],
            $terminal,
        );
        $result = $report->results()[0];

        // One pass, two attempts, one commit, and the box ticked at the end of
        // it rather than after the attempt that passed nothing.
        self::assertSame(2, $result->attempts());
        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());
        self::assertCount(1, $this->commitsBeyondBase());

        // What the second attempt was told, read out of the briefing the worker
        // actually received.
        $attempts = $worker->attempts();
        self::assertCount(2, $attempts);
        self::assertStringNotContainsString('Failed asserting', $attempts[0]->briefing());
        self::assertStringContainsString('Failed asserting', $attempts[1]->briefing());
        self::assertStringContainsString(
            'Observed by Ralph PHP itself',
            $attempts[1]->briefing(),
            'The failing check is the package\'s observation, not a previous attempt\'s opinion.',
        );
        self::assertStringContainsString('attempt 2 follows', $terminal->standardOutput());

        // The retry continues in the workspace the failing attempt left, so the
        // work that was kept is part of what is committed.
        self::assertSame(['src/DryRun.php', 'src/Import.php'], $this->filesIn((string) $result->commitSha()));

        // And that workspace is still the pass's own, so completing the task
        // removes the checkout as it would for a task that passed first time.
        self::assertDirectoryDoesNotExist(
            (string) $this->journal()->generations()->get($result->generation())->worktree()->path(),
        );
    }

    /**
     * A task whose checks never pass is tried up to its attempt limit, left
     * unchecked, and does not take the rest of the run with it.
     */
    #[Test]
    public function a_task_whose_checks_keep_failing_stops_at_its_attempt_limit(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// not good enough\n"])]);

        $verifier = ScriptedVerifier::scripted([false, false, false, true]);
        $report = $this->loopOver($verifier, ['limits' => ['attempts_per_task' => 3]]);

        $failed = $report->results()[0];

        self::assertSame(3, $failed->attempts());
        self::assertTrue($failed->isFailure());
        self::assertFalse($failed->wasChecked());
        self::assertNull($failed->commitSha());
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        // Three attempts at the first task and one at the second, each one
        // verified by something that is not the worker.
        self::assertCount(4, $verifier->calls);

        // The row says what the checks said, not that the runner ran out of
        // patience: the blocker a reviewer reads is the failing check.
        self::assertStringContainsString('verification failed', (string) $failed->blocker()?->reason());

        // The limit stops the retrying, not the run. The task after it is
        // independent work and is taken on and finished.
        self::assertCount(2, $report->results());
        self::assertTrue($report->results()[1]->isCompleted());
        self::assertCount(1, $this->commitsBeyondBase());

        // Each attempt is on the record, and each one of them failed.
        $journal = $this->journal();
        $pass = $journal->generations()->get($failed->generation());
        $attempts = $journal->attempts()->forGeneration($pass);
        self::assertCount(3, $attempts);

        foreach ($attempts as $attempt) {
            self::assertSame(LifecycleState::Failed, $attempt->state());
            self::assertFalse($journal->attempts()->verificationSupports($attempt));
            self::assertStringContainsString('Failed asserting', (string) $attempt->evidence()?->toMarkdown());
        }
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
     *
     * Section G, item 6, and the evidence half of it: what a reviewer can read
     * instead of the commit that was deliberately not made.
     */
    #[Test]
    public function a_task_that_was_already_satisfied_completes_without_a_commit(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success()->saying('nothing to do: it already has one')]);

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal);
        $result = $report->results()[0];

        self::assertTrue($result->isCompleted());
        self::assertTrue($result->isNoChange());
        self::assertNull($result->commitSha());
        self::assertTrue($result->wasChecked());
        self::assertSame([], $this->commitsBeyondBase(), 'Not an empty commit.');
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        $pass = $this->journal()->generations()->get($result->generation());
        self::assertTrue($pass->isNoChange());
        self::assertTrue($pass->hasImplementation());

        // The case the ticked box rests on, made at the moment of the decision
        // and carried on the result.
        $satisfaction = $result->satisfaction();
        self::assertNotNull($satisfaction);
        self::assertTrue($satisfaction->isJustified());
        self::assertTrue($satisfaction->isAtBase());
        self::assertSame($this->headOfTheCheckout(), $satisfaction->head());
        self::assertStringContainsString('1 check(s) passed', $result->reason());

        // And written down beside the attempt's logs, because there is no
        // commit for `git show` to be pointed at.
        $record = (string) $satisfaction->recordPath();
        self::assertSame(
            $this->project->path(sprintf(
                '.ralph/runtime/logs/%s/attempt-01-satisfied.md',
                $pass->generation()->slug(),
            )),
            $record,
        );
        self::assertFileExists($record);

        $written = (string) file_get_contents($record);
        self::assertStringContainsString('already satisfied', $written);
        self::assertStringContainsString('**tests** — `php -v`', $written);
        self::assertStringContainsString('nothing to do: it already has one', $written);
        self::assertStringContainsString('Nothing in them has been checked', $written);
        self::assertStringContainsString('does not say that any configured check tests', $written);

        self::assertStringContainsString('already satisfied', $terminal->standardOutput());
    }

    /**
     * A worker that ticked the checklist inside its own workspace and changed
     * nothing else is not a satisfied task.
     *
     * The case an empty index cannot be read as "already done" on its own, and
     * the reason section G, item 6 asks for evidence rather than for the
     * outcome: everything a green run can say is true here — the checks
     * passed, there is nothing to commit — and completing it would make a
     * model-written checkbox into verified execution state, which section D,
     * item 6 forbids.
     */
    #[Test]
    public function a_worker_that_only_edited_ralphs_own_files_does_not_complete_the_task(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);

        // The checklist as the worker would leave it: its own box ticked, in
        // the copy inside its workspace, and no other change anywhere.
        $this->givenAWorker([FixtureStep::success([
            '.ralph/tasks.md' => "# Tasks\n\n- [x] " . self::FIRST . "\n- [ ] " . self::SECOND . "\n",
        ])->saying('done — I have ticked it off')]);
        $before = $this->snapshotOfTheCheckout();

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal);

        self::assertCount(1, $report->results(), 'A blocked task stops the run.');
        $result = $report->results()[0];

        // Blocked, not completed and not failed: the checks passed, so there is
        // nothing a further attempt would be told, and whether the task is
        // really done is a question for a person.
        self::assertTrue($result->isBlocked());
        self::assertTrue($result->stopsTheRun());
        self::assertTrue($result->wasVerified());
        self::assertFalse($result->isNoChange());
        self::assertFalse($result->wasChecked());
        self::assertNull($result->commitSha());

        // The user's checklist still says the work is pending, which is the
        // whole point: the agent's tick was a claim in a directory nobody
        // commits from.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
        self::assertSame([], $this->commitsBeyondBase());
        self::assertSame($before, $this->snapshotOfTheCheckout());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Verifying, $pass->phase(), 'It never entered committing.');
        self::assertStringContainsString(
            'Ralph PHP\'s own files (.ralph/tasks.md)',
            (string) $pass->blocker()?->reason(),
        );
        self::assertStringContainsString('ralph retry', (string) $pass->blocker()?->nextAction());

        // What was found is on the attempt's row and in the record, and the
        // workspace is kept so both can be read against the files.
        $attempt = $journal->attempts()->get($pass, 1);
        self::assertNotNull($attempt);
        self::assertStringContainsString(
            'no-change outcome refused',
            (string) $attempt->evidence()?->toMarkdown(),
        );
        self::assertDirectoryExists((string) $pass->worktree()->path());

        $record = $this->project->path(sprintf(
            '.ralph/runtime/logs/%s/attempt-01-satisfied.md',
            $pass->generation()->slug(),
        ));
        self::assertFileExists($record);

        $written = (string) file_get_contents($record);
        self::assertStringContainsString('## Why it was refused', $written);
        self::assertStringContainsString('## Changes kept out of the commit', $written);
        self::assertStringContainsString('.ralph/tasks.md', $written);
        self::assertStringContainsString('done — I have ticked it off', $written);

        self::assertStringContainsString('not supported', $terminal->standardOutput());
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
     * The other half of the frozen checks, end to end: a green run whose diff
     * deleted the test that would have failed.
     *
     * Section G, item 5. Item 3 stops a worker choosing *which* commands judge
     * its work; nothing there stops it choosing what those commands then find.
     * The verifier here passes — which is the point, since a case where the
     * checks failed would prove nothing about this — so everything asserted
     * below follows from the diff alone.
     */
    #[Test]
    public function a_diff_that_deletes_the_projects_tests_is_not_committed(): void
    {
        $this->project->write('tests/ImportTest.php', <<<'PHP'
            <?php

            final class ImportTest
            {
                public function testItImports(): void
                {
                    self::assertTrue(import());
                }
            }

            PHP);
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'Add the import test');

        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([FixtureStep::success([
            'src/Import.php' => "<?php\n\n// --dry-run\n",
            'tests/ImportTest.php' => null,
        ])]);
        $before = $this->snapshotOfTheCheckout();

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal);

        self::assertCount(1, $report->results(), 'A blocked task stops the run.');
        $result = $report->results()[0];

        // Blocked rather than failed: the checks passed, so there is nothing
        // for another attempt to act on, and what is in question is a judgement
        // only a person can make.
        self::assertTrue($result->isBlocked());
        self::assertTrue($result->stopsTheRun());
        self::assertTrue($result->wasVerified(), 'The checks passed; the diff is what stopped it.');

        // The three things that must not have happened.
        self::assertNull($result->commitSha());
        self::assertFalse($result->wasChecked());
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());

        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Verifying, $pass->phase(), 'It never entered committing.');
        self::assertStringContainsString('weakens', (string) $pass->blocker()?->reason());
        self::assertStringContainsString('not comprehensive', (string) $pass->blocker()?->nextAction());

        // The work is kept twice over: the workspace with the diff still
        // staged, and the patch as a file a reviewer can read on its own.
        $workspace = (string) $pass->worktree()->path();
        self::assertDirectoryExists($workspace);
        self::assertSame(
            ['src/Import.php', 'tests/ImportTest.php'],
            $this->stagedIn($workspace),
        );

        $diff = $this->project->path(sprintf(
            '.ralph/runtime/logs/%s/attempt-01-staged.diff',
            $pass->generation()->slug(),
        ));
        self::assertFileExists($diff);
        self::assertStringContainsString(
            '-    public function testItImports(): void',
            (string) file_get_contents($diff),
        );

        // The attempt's row carries what was found, for the next person rather
        // than for the next attempt.
        $attempt = $journal->attempts()->get($pass, 1);
        self::assertNotNull($attempt);
        self::assertStringContainsString('a test file removed', (string) $attempt->evidence()?->toMarkdown());

        self::assertStringContainsString('weakens', $terminal->standardOutput());
        self::assertSame($before, $this->snapshotOfTheCheckout());
    }

    /**
     * A checkbox write the filesystem refuses leaves the pass pending its
     * finalization: the work is kept, and the write is what is still owed.
     *
     * Section G, item 8's first half. Everything before the write succeeded —
     * the agent produced a diff, the checks passed, the commit was made — so
     * the only thing missing from a completed task is one line in a file the
     * package does not own, and the state store has to say exactly that rather
     * than record a pass that failed.
     */
    #[Test]
    public function a_checkbox_write_that_fails_leaves_the_pass_pending_finalization(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $terminal = new CapturingOutput();
        $report = $this->whileTheChecklistCannotBeWritten(
            fn (): RunReport => $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal),
        );

        $result = $report->results()[0];

        // The work: committed, verified, and not thrown away.
        self::assertTrue($result->wasVerified());
        self::assertFalse($result->wasChecked());
        self::assertFalse($result->isCompleted());
        self::assertCount(1, $this->commitsBeyondBase());

        $sha = (string) $result->commitSha();
        self::assertSame($sha, $this->tipOf((string) $result->branch()));

        // The box is as the user left it.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Finalizing, $pass->phase());
        self::assertTrue($pass->isFinalizable(), 'The Git side of the tick is already satisfied.');
        self::assertStringContainsString(
            'pending finalization',
            (string) $pass->blocker()?->nextAction(),
        );

        // The row that makes it recognisable afterwards as owing a write.
        $owed = $journal->checklists()->outstandingFor($pass);
        self::assertNotNull($owed);
        self::assertSame(ChecklistUpdateState::Pending, $owed->state());
        self::assertSame(ChecklistMarker::Checked, $owed->marker());
        self::assertSame('.ralph/tasks.md', $owed->path());
        self::assertSame(1, $owed->attempts(), 'The write was tried once.');
        self::assertTrue($owed->wasRefused());
        self::assertStringContainsString('Unable to write', (string) $owed->error());
        self::assertNull($owed->resultingHash());
        self::assertNull($owed->appliedAt());
        self::assertSame(
            ChecklistFile::hash($this->tasksFile()),
            $owed->expectedHash(),
            'The plan names the file it was about to write to.',
        );

        // And the task is not the user's completion either way.
        self::assertSame(
            Completion::Pending,
            $journal->tasks()->get($result->taskId())->completion(),
        );

        self::assertStringContainsString('could not be ticked', $terminal->standardOutput());
    }

    /**
     * The second half: a resume writes the box and nothing else.
     *
     * The agent is not invoked, no second commit appears, no attempt is spent,
     * and the commit the first run made is the one the completed task points
     * at. The worker is given no further steps on purpose — a run that reached
     * it would fail rather than quietly produce a second implementation.
     */
    #[Test]
    public function a_pass_pending_finalization_is_finished_by_writing_only_the_box(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $first = $this->whileTheChecklistCannotBeWritten(
            fn (): RunReport => $this->loopOver(ScriptedVerifier::passing()),
        );
        $blocked = $first->results()[0];
        self::assertFalse($blocked->wasChecked());

        $terminal = new CapturingOutput();
        $second = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal);
        $result = $second->results()[0];

        // The same pass, the same attempt, the same commit.
        self::assertSame($blocked->generation()->number(), $result->generation()->number());
        self::assertSame(1, $result->attempts());
        self::assertSame($blocked->commitSha(), $result->commitSha());
        self::assertCount(1, $this->commitsBeyondBase());

        // The agent was invoked once, by the run that implemented the task.
        self::assertCount(1, $worker->attempts());

        // And now it is done.
        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNull($pass->phase());
        self::assertSame(
            Completion::RunnerVerified,
            $journal->tasks()->get($result->taskId())->completion(),
        );

        // The write is recorded as landed, against the file it produced.
        $owed = $journal->checklists()->outstandingFor($pass);
        self::assertNull($owed, 'Nothing is owed any more.');

        $applied = $journal->checklists()->latestFor($pass);
        self::assertNotNull($applied);
        self::assertSame(ChecklistUpdateState::Applied, $applied->state());
        self::assertSame(2, $applied->attempts(), 'The refused write, and the one that landed.');
        self::assertNotNull($applied->appliedAt());
        self::assertSame(ChecklistFile::hash($this->tasksFile()), $applied->resultingHash());
        self::assertCount(1, $journal->checklists()->forGeneration($pass), 'One plan, not two.');

        // The checks were not run again: the report the task completed on is
        // the one the first run recorded.
        self::assertTrue($result->wasVerified());
        $attempt = $journal->attempts()->get($pass, 1);
        self::assertNotNull($attempt);
        self::assertCount(1, (new VerificationStore($journal->database()))->forAttempt($attempt));

        self::assertStringContainsString('writing only the box', $terminal->standardOutput());
    }

    /**
     * A task reworded while its write was owed is not ticked by the run that
     * finds it reworded.
     *
     * A pass can sit pending finalization for as long as it takes someone to
     * notice the file is read-only, and the user may well edit the checklist in
     * that time. Rewording gives the line a different identity, so the task the
     * write was planned for is not in the checklist the next run reads: the
     * loop takes the new line on as new work and does not go near the old plan.
     * The plan stays outstanding — it is a recovery path's business, not a
     * reason to tick a line nobody asked about.
     */
    #[Test]
    public function a_task_reworded_while_its_write_was_owed_is_not_ticked(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
            FixtureStep::blocking('the rewording needs a decision'),
        ]);

        $first = $this->whileTheChecklistCannotBeWritten(
            fn (): RunReport => $this->loopOver(ScriptedVerifier::passing()),
        );
        $blocked = $first->results()[0];

        // The user rewords the task, which gives it a different identity.
        $reworded = self::FIRST . ', and a --verbose option too';
        $this->project->write('.ralph/tasks.md', "# Tasks\n\n- [ ] " . $reworded . "\n");

        $report = $this->loopOver(ScriptedVerifier::passing());

        // The run worked on the reworded line, which is a different task.
        self::assertCount(1, $report->results());
        self::assertSame($reworded, $report->results()[0]->description());
        self::assertFalse($report->results()[0]->wasChecked());

        // Nothing was ticked, and the pass that is owed a write still is.
        self::assertStringNotContainsString('- [x]', $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($blocked->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Finalizing, $pass->phase());

        $owed = $journal->checklists()->outstandingFor($pass);
        self::assertNotNull($owed);
        self::assertSame(ChecklistUpdateState::Pending, $owed->state());
        self::assertSame(1, $owed->attempts(), 'The write was not tried again.');

        // What a recovery path will find: one write still owed, naming the pass
        // that owes it.
        $outstanding = $journal->checklists()->outstanding();
        self::assertCount(1, $outstanding);
        self::assertTrue($outstanding[0]->generation()->equals($pass->generation()));
        self::assertSame($blocked->taskId()->value(), $outstanding[0]->task()->value());
    }

    /**
     * The lock, from the loop's side: a run does not start beside another one.
     *
     * PROJECT.md section H, item 1. The refusal has to come before anything is
     * read or written, because the damage two runners do is done by the time
     * either of them notices the other — so the assertions are about what did
     * *not* happen: no state database, no worktree, no worker started, and the
     * user's checklist exactly as it was.
     */
    #[Test]
    public function a_run_does_not_start_while_another_runner_holds_the_repository(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n"])]);

        $held = RepositoryLock::in($this->project->root())->acquire('run');
        $held->record('run-20261101-120000-aaaaaa');
        $before = $this->tasksFile();

        try {
            $this->loopOver(ScriptedVerifier::passing());
            self::fail('A second run started while the repository was locked.');
        } catch (LockException $refusal) {
            self::assertStringContainsString('Another Ralph PHP runner is working', $refusal->getMessage());
            self::assertStringContainsString(
                'run-20261101-120000-aaaaaa',
                implode("\n", $refusal->hints()),
            );
        } finally {
            $held->release();
        }

        self::assertSame([], $worker->records(), 'The agent was started beside another run.');
        self::assertFileDoesNotExist($this->project->path('.ralph/runtime/state.sqlite'));
        self::assertDirectoryDoesNotExist($this->project->path('.ralph/runtime/worktrees'));
        self::assertSame($before, $this->tasksFile());
        self::assertSame([], $this->commitsBeyondBase());
    }

    /**
     * A run releases the lock on its way out, whatever happened in it.
     *
     * The release is the ordinary path; the kernel covers the other one. Both
     * halves are asserted: the record says released, and the next run can
     * actually take it — which is the half a `finally` that quietly did nothing
     * would still pass.
     */
    #[Test]
    public function a_finished_run_leaves_the_lock_free_for_the_next_one(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $this->loopOver(ScriptedVerifier::passing());

        $holder = RepositoryLock::in($this->project->root())->holder();
        self::assertNotNull($holder);
        self::assertTrue($holder->isReleased());
        self::assertSame(Runner::PURPOSE, $holder->purpose());
        self::assertNotNull($holder->runId(), 'The lock did not name the run that took it.');

        $next = RepositoryLock::in($this->project->root())->acquire('run');

        try {
            self::assertFalse($next->wasReclaimed(), 'A released lock was reported as taken over.');
        } finally {
            $next->release();
        }
    }

    /**
     * A lock left behind by a runner that stopped: taken over, and said so.
     *
     * The second half of section H, item 1. The run proceeds, because the
     * process named by the record is gone and a free lock is the kernel saying
     * so; and it prints what it displaced, because a run that silently
     * continued past an interrupted one would hide the fact that there is
     * interrupted work in the repository at all.
     */
    #[Test]
    public function a_run_takes_over_a_lock_left_by_a_runner_that_has_stopped_and_says_so(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);
        $this->givenAnAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa');

        $terminal = new CapturingOutput();
        $report = $this->loopOver(
            ScriptedVerifier::passing(),
            terminal: $terminal,
            lock: RepositoryLock::in($this->project->root(), null, KnownProcesses::none()),
        );

        self::assertTrue($report->results()[0]->isCompleted());
        self::assertStringContainsString('stopped without releasing it', $terminal->standardOutput());
        self::assertStringContainsString('run-20261101-120000-aaaaaa', $terminal->standardOutput());
    }

    /**
     * Confirmation before a takeover: the recorded process is still there.
     *
     * The lock is free and the record names a live process, which cannot both
     * be true of a Ralph PHP runner. Nothing starts.
     */
    #[Test]
    public function a_run_refuses_to_take_over_a_lock_whose_process_is_still_running(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n"])]);
        $this->givenAnAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa');

        try {
            $this->loopOver(
                ScriptedVerifier::passing(),
                lock: RepositoryLock::in($this->project->root(), null, KnownProcesses::running(4242)),
            );
            self::fail('A run started over a worker that has not stopped.');
        } catch (LockException $refusal) {
            self::assertStringContainsString('still running', $refusal->getMessage());
        }

        self::assertSame([], $worker->records());
        self::assertFileDoesNotExist($this->project->path('.ralph/runtime/state.sqlite'));
    }

    /**
     * A lock file holding a record that claims to be held, over a free lock.
     */
    private function givenAnAbandonedLock(int $pid, string $runId): void
    {
        $this->project->write('.ralph/runtime/run.lock', (string) json_encode([
            'purpose' => 'run',
            'host' => gethostname(),
            'pid' => $pid,
            'run_id' => $runId,
            'acquired_at' => '2026-11-01T12:00:00+00:00',
            'released_at' => null,
        ]));
    }

    /**
     * Run something with the checklist's directory read-only.
     *
     * The realistic shape of a refused checkbox write, and the only one that
     * reaches {@see \Celyes\RalphPhp\Tasks\ChecklistFile::replace()}'s failure
     * paths: the replacement is written beside the original and renamed over
     * it, so a read-only *file* would still be replaced and it is the directory
     * that has to say no. The runtime directory is created first, because it
     * lives inside the one being locked and the state store has to be able to
     * open.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function whileTheChecklistCannotBeWritten(callable $work): mixed
    {
        $directory = dirname($this->project->path('.ralph/tasks.md'));

        if (!is_dir($this->project->path('.ralph/runtime'))) {
            self::assertTrue(mkdir($this->project->path('.ralph/runtime'), 0o755, true));
        }

        self::assertTrue(chmod($directory, 0o500));
        clearstatcache(true, $directory);

        if (is_writable($directory)) {
            chmod($directory, 0o700);
            self::markTestSkipped('This user can write to a directory it has no write permission on.');
        }

        try {
            return $work();
        } finally {
            chmod($directory, 0o700);
        }
    }

    /**
     * Run the loop over this project.
     *
     * @param array<string, mixed> $overrides merged into the configuration
     */
    private function loopOver(
        ScriptedVerifier $verifier,
        array $overrides = [],
        ?CapturingOutput $terminal = null,
        ?RepositoryLock $lock = null,
    ): RunReport {
        return Runner::forProject(
            $this->configuration($overrides),
            $verifier,
            $terminal?->output(),
            AdapterRegistry::default(),
            null,
            $lock,
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
            // One attempt per task unless a case asks for more. The loop
            // retries a task whose checks failed (section G, item 4), so left
            // at the default of three every failing case here would quietly
            // become three cases — and how many attempts a case makes is
            // something its assertions depend on and should state.
            'limits' => ['attempts_per_task' => 1],
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

    /**
     * What is in a workspace's index, read back from Git.
     *
     * `--no-renames`, because what is being asserted is the index's own
     * contents: a rename reported as one path would hide whether the deletion
     * it depends on is in there with it.
     *
     * @return list<string>
     */
    private function stagedIn(string $directory): array
    {
        $output = trim(
            Git::run($directory, 'diff', '--cached', '--name-only', '--no-renames')->standardOutput,
        );

        if ($output === '') {
            return [];
        }

        $paths = explode("\n", $output);
        sort($paths);

        return array_map('trim', $paths);
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
