<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\CheckoutException;
use Celyes\RalphPhp\Exception\InterruptionException;
use Celyes\RalphPhp\Exception\LockException;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\Process\Interruptions;
use Celyes\RalphPhp\Process\KnownProcesses;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\Run\RepositoryLock;
use Celyes\RalphPhp\Run\RunReport;
use Celyes\RalphPhp\Run\Runner;
use Celyes\RalphPhp\Run\TaskPass;
use Celyes\RalphPhp\Run\TaskResult;
use Celyes\RalphPhp\Run\Workbench;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\ChecklistMarker;
use Celyes\RalphPhp\State\ChecklistUpdateState;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\StopKind;
use Celyes\RalphPhp\State\TaskRecord;
use Celyes\RalphPhp\State\VerificationStore;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Tasks\ChecklistFile;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Fixture\FixtureWorker;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * Bare repositories a case added as a remote, to be removed afterwards.
     *
     * @var list<string>
     */
    private array $remotes = [];

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
        // A stop is process-wide state, so a case that interrupted a run has to
        // put it back before the next one starts in an interrupted one.
        Interruptions::release();

        Git::tryRun($this->project->root(), 'worktree', 'prune');
        TemporaryDirectory::remove($this->tools);
        $this->project->remove();

        foreach ($this->remotes as $remote) {
            TemporaryDirectory::remove($remote);
        }

        $this->remotes = [];
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
     * PROJECT.md section H, item 9, for the two situations that reach it
     * through a task: an unresolved decision, and attempts that ran out.
     *
     * The run's row is what is read, not the report, because the user who has
     * to act on this is usually reading `ralph status` the next morning. A
     * reason with no action beside it is where a tool like this loses people:
     * "the attempt limit of 2 per task is spent" leaves them to guess, and the
     * guess most of them make — retry it — would continue the same pass with
     * the same spent attempts and stop again without invoking the agent.
     */
    #[Test]
    public function a_run_that_gave_up_on_a_task_says_what_would_get_it_moving(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// not good enough\n"])]);

        $terminal = new CapturingOutput();
        $report = $this->loopOver(
            ScriptedVerifier::scripted([false, false, true]),
            ['limits' => ['attempts_per_task' => 2]],
            $terminal,
        );

        $given = $report->results()[0];
        self::assertSame(2, $given->attempts());
        self::assertTrue($given->isFailure());
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        // The run itself worked through the rest of the checklist: the task
        // after a failed one is independent work.
        self::assertTrue($report->results()[1]->isCompleted());

        $stop = $report->stop();

        self::assertSame(StopKind::Attempts, $stop->kind());
        self::assertTrue($report->stoppedForAttention());
        self::assertStringContainsString(
            $given->taskId()->value() . ' ran out of attempts',
            $stop->reason(),
        );
        self::assertStringContainsString('limits.attempts_per_task', $stop->nextAction());
        self::assertStringNotContainsString('ralph retry', $stop->nextAction());

        // The pass's own blocker says the same thing, and says it about the
        // attempt limit rather than inviting a retry that would do nothing.
        $blocker = (string) $given->blocker()?->nextAction();
        self::assertStringContainsString('attempt limit of 2 per task is spent', $blocker);
        self::assertStringContainsString('limits.attempts_per_task', $blocker);
        self::assertStringContainsString('restate the task in .ralph/tasks.md', $blocker);
        self::assertStringContainsString((string) $given->record()->worktree()->path(), $blocker);

        // Both halves are on the row, so they outlive the process that printed
        // them.
        $run = $this->journal()->runs()->get($report->run()->rowId());

        self::assertSame($stop->reason(), $run->stopReason());
        self::assertSame($stop->nextAction(), $run->stopAction());
        self::assertSame(StopKind::Attempts, $run->stopKind());

        self::assertStringContainsString('next: ' . $stop->nextAction(), $terminal->standardOutput());
    }

    /**
     * An unresolved decision: the run stops, and the action it reports is the
     * pass's own rather than a second description of it.
     */
    #[Test]
    public function a_run_that_stopped_on_a_decision_reports_the_pass_s_own_next_action(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $this->givenAWorker([
            FixtureStep::blocking('Which importer should this wrap?', 'Name the importer in the task.'),
        ]);

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), [], $terminal);

        $blocked = $report->results()[0];
        $stop = $report->stop();

        self::assertSame(StopKind::Decision, $stop->kind());
        self::assertSame($blocked->taskId(), $stop->task());
        self::assertStringContainsString('Which importer should this wrap?', $stop->reason());
        self::assertSame('Name the importer in the task.', $stop->nextAction());
        self::assertSame($blocked->blocker()?->nextAction(), $stop->nextAction());

        $run = $this->journal()->runs()->get($report->run()->rowId());

        self::assertSame('Name the importer in the task.', $run->stopAction());
        self::assertSame(StopKind::Decision, $run->stopKind());

        self::assertStringContainsString('next: Name the importer in the task.', $terminal->standardOutput());
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

        // A limit is not a problem to be looked into: the run did what it was
        // configured to do, and the action says both ways out of it.
        self::assertSame(StopKind::Limit, $report->stop()->kind());
        self::assertFalse($report->stoppedForAttention());
        self::assertStringContainsString('limits.iterations', $report->nextAction());
        self::assertStringContainsString('run `ralph run` again', $report->nextAction());
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->tasksFile());
        self::assertSame(1, $this->journal()->runs()->get($report->run()->rowId())->iterations());

        // The task the limit kept the run from reaching was not opened: no
        // pass, no branch, no row of its own to explain away later.
        self::assertSame([], $this->journal()->generations()->forTask($this->recorded(self::SECOND)->id()));
    }

    /**
     * The run's time budget stops the loop between tasks, leaving the tasks it
     * did not reach pending rather than failed.
     *
     * The distinction is the whole of it. A run that let the loop begin a task
     * it had no budget for would open a pass, fail it against a spent budget,
     * and leave `status` showing a failure for work no agent was ever asked to
     * do — so what is asserted is as much about the second task's *absence*
     * from the state store as about the first task's success.
     */
    #[Test]
    public function the_run_stops_when_its_time_budget_is_spent(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// one\n"])]);
        $terminal = new CapturingOutput();

        // Two minutes pass every time the clock is read, so the first task
        // costs the run more than the ninety seconds it had in total.
        $report = $this->loopOver(
            ScriptedVerifier::passing(),
            ['limits' => ['run_timeout' => 90]],
            $terminal,
            null,
            $this->aClockThatJumpsBy(120),
        );

        // The first task was finished, not cut short: the budget is a reason
        // not to start the next one, not a reason to abandon this one.
        self::assertCount(1, $report->results());
        self::assertTrue($report->results()[0]->isCompleted());
        self::assertTrue($report->results()[0]->wasChecked());
        self::assertCount(1, $worker->attempts(), 'The second task was never handed to the agent.');

        self::assertStringContainsString('time budget of 90 seconds was spent', $report->stopReason());
        self::assertStringContainsString('1 task still pending', $report->stopReason());
        self::assertStringContainsString('time budget of 90 seconds', $terminal->standardOutput());
        self::assertSame(1, $report->remaining());

        // On disk and in the store: the second task is as it was.
        $markdown = $this->tasksFile();
        self::assertStringContainsString('- [x] ' . self::FIRST, $markdown);
        self::assertStringContainsString('- [ ] ' . self::SECOND, $markdown);

        $journal = $this->journal();
        $second = $this->recorded(self::SECOND);
        self::assertTrue($second->isPending(), 'The task the run did not reach is still pending.');
        self::assertSame([], $journal->generations()->forTask($second->id()));

        // And the run's row says what it spent, which is what a resume reads.
        $run = $journal->runs()->get($report->run()->rowId());
        self::assertSame(1, $run->iterations());
        self::assertGreaterThanOrEqual(90, $run->activeSeconds());
        self::assertSame(LifecycleState::Completed, $run->state());

        // The spent limit names its own setting rather than the other one.
        self::assertSame(StopKind::Limit, $run->stopKind());
        self::assertStringContainsString('limits.run_timeout', (string) $run->stopAction());

        // One commit, for the one task that ran.
        self::assertCount(1, $this->commitsBeyondBase());
    }

    /**
     * A second, deliberately new run picks up where the checklist stands, with
     * its limits whole and nothing the first run finished lost.
     *
     * The other half of PROJECT.md section H, item 3, and the reason the
     * iteration limit of the case above is safe to hit: a user whose run
     * stopped at a limit starts another one, and what they get is the rest of
     * the checklist rather than the whole of it again. Completed work belongs
     * to the task rows and the repository, not to the run that did it — so
     * what is asserted is that the new run's counters start at zero *and* that
     * the first task is not touched a second time.
     */
    #[Test]
    public function a_second_run_starts_its_limits_afresh_without_losing_what_the_first_completed(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([
            FixtureStep::success(['src/Import.php' => "<?php\n\n// first\n"]),
            FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
        ]);

        $first = $this->loopOver(ScriptedVerifier::passing(), ['limits' => ['iterations' => 1]]);
        self::assertSame(1, $first->remaining(), 'The first run stopped one task short.');

        $second = $this->loopOver(ScriptedVerifier::passing(), ['limits' => ['iterations' => 1]]);

        // It took on the task the first run did not reach, and only that one:
        // the checked box is what the loop reads, so the finished task is not
        // offered to the agent again.
        self::assertCount(1, $second->results());
        self::assertSame(self::SECOND, $second->results()[0]->description());
        self::assertTrue($second->results()[0]->isCompleted());
        self::assertCount(2, $worker->attempts(), 'The first task was handed to the agent once, in all.');

        $journal = $this->journal();
        $row = $journal->runs()->get($second->run()->rowId());

        // A fresh start, and identifiable as one: its iteration limit of 1 was
        // whole, which is what let it take on a task at all.
        self::assertTrue($row->isFreshStart());
        self::assertNull($row->resumedFromRunId());
        self::assertSame(1, $row->iterations());
        self::assertSame(1, $row->ownIterations());
        self::assertSame(0, $row->carriedIterations());
        self::assertCount(1, $journal->runs()->lineage($row));

        // And the first run is still there, with its own count and its own
        // completed task underneath it.
        $earlier = $journal->runs()->get($first->run()->rowId());
        self::assertSame(1, $earlier->iterations());
        self::assertSame(LifecycleState::Completed, $earlier->state());

        $finished = $this->recorded(self::FIRST);
        self::assertSame(Completion::RunnerVerified, $finished->completion());

        $generations = $journal->generations()->forTask($finished->id());
        self::assertCount(1, $generations, 'The completed task was not passed over again.');
        self::assertSame($earlier->rowId(), $generations[0]->runId());
        self::assertSame(LifecycleState::Completed, $generations[0]->state());

        // On disk: both boxes ticked, one commit per task.
        $markdown = $this->tasksFile();
        self::assertStringContainsString('- [x] ' . self::FIRST, $markdown);
        self::assertStringContainsString('- [x] ' . self::SECOND, $markdown);
        self::assertCount(2, $this->commitsBeyondBase());
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
     * A run that cannot carry on closes its row with the failure's own hints as
     * the next action.
     *
     * The third of PROJECT.md section H, item 9's situations — ambiguous
     * ownership — arrives this way when it is found before a task can be taken
     * on: a branch unrelated work owns, a workspace registered somewhere the
     * configuration no longer places it, a file the run cannot read. Each of
     * those exceptions already documents what to do about itself; what is
     * asserted here is that the advice reaches the row rather than only the one
     * terminal that saw the failure.
     */
    #[Test]
    public function a_run_that_could_not_carry_on_records_the_failures_own_next_action(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker();

        // Unreadable rather than absent, so the checkout stays clean and the
        // failure happens inside the loop — after the run's row exists, which
        // is the whole point of the case.
        chmod($this->project->path('.ralph/tasks.md'), 0o000);

        try {
            $this->loopOver(ScriptedVerifier::passing());
            self::fail('A run carried on past a checklist it could not read.');
        } catch (RalphException $failure) {
            self::assertNotSame([], $failure->hints(), 'The failure documents nothing to do about itself.');

            chmod($this->project->path('.ralph/tasks.md'), 0o600);

            $run = $this->journal()->runs()->latest();

            self::assertNotNull($run);
            self::assertSame(LifecycleState::Failed, $run->state());
            self::assertSame(StopKind::Failed, $run->stopKind());
            self::assertStringContainsString($failure->getMessage(), (string) $run->stopReason());
            self::assertSame(implode(' ', $failure->hints()), $run->stopAction());
        }
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
     * A task whose criteria were rewritten while its write was owed is not
     * ticked either.
     *
     * Section H, item 8's other source-content check, and the case rewording
     * cannot reach: the box's own line is untouched, so the task keeps its
     * identity and this run's reading of the checklist finds nothing wrong with
     * it — but the acceptance criteria indented under it are no longer the ones
     * the checks passed against. Ticking the box would record a runner-verified
     * completion against criteria nothing was ever verified against, so the
     * pass blocks and its plan is closed rather than retried. The work itself
     * is kept: the agent is not invoked again and no second commit appears,
     * because what has to happen is a person deciding whether the commit still
     * satisfies the task as it now reads.
     */
    #[Test]
    public function a_task_whose_criteria_changed_while_its_write_was_owed_is_not_ticked(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenTheCriteria('It prints the plan it would carry out');
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $first = $this->whileTheChecklistCannotBeWritten(
            fn (): RunReport => $this->loopOver(ScriptedVerifier::passing()),
        );
        $blocked = $first->results()[0];
        self::assertFalse($blocked->wasChecked());

        // The user rewrites what is indented under the task, leaving the task's
        // own line — and so its identity — exactly as it was.
        $this->givenTheCriteria('It prints the diff it would apply');

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), terminal: $terminal);
        $result = $report->results()[0];

        // The same task and the same pass, with the work kept, not repeated.
        self::assertSame($blocked->taskId()->value(), $result->taskId()->value());
        self::assertSame($blocked->generation()->number(), $result->generation()->number());
        self::assertCount(1, $worker->attempts(), 'The agent was not invoked again.');
        self::assertSame(1, $result->attempts());
        self::assertSame($blocked->commitSha(), $result->commitSha());
        self::assertCount(1, $this->commitsBeyondBase());

        // And the box is as the user left it.
        self::assertFalse($result->wasChecked());
        self::assertFalse($result->isCompleted());
        self::assertStringNotContainsString('- [x]', $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Finalizing, $pass->phase());
        self::assertStringContainsString('states it differently now', (string) $pass->blocker()?->reason());
        self::assertStringContainsString('ralph retry', (string) $pass->blocker()?->nextAction());

        // The plan is closed rather than left outstanding: retrying it would
        // tick a line nobody asked about.
        self::assertNull($journal->checklists()->outstandingFor($pass));
        $closed = $journal->checklists()->latestFor($pass);
        self::assertNotNull($closed);
        self::assertSame(ChecklistUpdateState::Failed, $closed->state());
        self::assertNull($closed->appliedAt());

        // Not the user's completion either, and not the runner's.
        self::assertSame(
            Completion::Pending,
            $journal->tasks()->get($result->taskId())->completion(),
        );
    }

    /**
     * A push the remote refuses leaves the pass owing its publication and
     * nothing else.
     *
     * Section H, item 7's precondition, and the state the resume below reads.
     * The remote is deliberately absent — `origin` is in the configuration and
     * not in the repository, which is the mistake a user actually makes when
     * they turn pushing on. Everything before the push succeeded, so the three
     * things that must survive it are asserted: the commit is on its branch at
     * the SHA the row recorded, the row says the publication failed, and the
     * box is as the user left it.
     */
    #[Test]
    public function a_push_the_remote_refuses_leaves_the_pass_owing_only_its_publication(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $terminal = new CapturingOutput();
        $report = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'), $terminal);

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
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Publishing, $pass->phase());
        self::assertSame(PushState::Failed, $pass->push()->state());
        self::assertSame('origin', $pass->push()->remote());
        self::assertNotNull($pass->push()->error(), 'The reason the push failed is kept.');
        self::assertTrue($pass->awaitsPublication(), 'The push is the only thing outstanding.');
        self::assertFalse($pass->isFinalizable());
        self::assertStringContainsString(
            'retry the push alone',
            (string) $pass->blocker()?->nextAction(),
        );

        // No checklist write was ever planned: the pass stopped before it.
        self::assertNull($journal->checklists()->outstandingFor($pass));
    }

    /**
     * The item itself: a resume retries the push and nothing before it.
     *
     * The agent is not invoked, no second commit appears, no attempt is spent,
     * and the commit the first run made is the one that reaches the remote and
     * the one the completed task points at. The worker is given no further
     * steps on purpose — a run that reached it would fail rather than quietly
     * produce a second implementation.
     */
    #[Test]
    public function a_pass_owing_a_publication_is_finished_by_retrying_only_the_push(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $first = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'));
        $failed = $first->results()[0];
        self::assertFalse($failed->wasChecked());

        // The user adds the remote they had not got round to adding.
        $remote = $this->givenABareRemote();

        $terminal = new CapturingOutput();
        $second = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'), $terminal);
        $result = $second->results()[0];

        // The same pass, the same attempt, the same commit.
        self::assertSame($failed->generation()->number(), $result->generation()->number());
        self::assertSame(1, $result->attempts());
        self::assertSame($failed->commitSha(), $result->commitSha());
        self::assertCount(1, $this->commitsBeyondBase(), 'One commit, not two.');

        // The agent was invoked once, by the run that implemented the task.
        self::assertCount(1, $worker->attempts());

        // Read out of the remote rather than from the row that claims it.
        self::assertSame(
            $failed->commitSha(),
            $this->remoteTipOf($remote, (string) $result->branch()),
        );

        // And now it is done.
        self::assertTrue($result->isCompleted());
        self::assertTrue($result->wasChecked());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->tasksFile());

        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNull($pass->phase());
        self::assertSame(PushState::Published, $pass->push()->state());
        self::assertNull($pass->push()->error(), 'The refusal is not still on the row.');
        self::assertNull($pass->blocker(), 'Nor the reason it stopped for.');
        self::assertSame(
            Completion::RunnerVerified,
            $journal->tasks()->get($result->taskId())->completion(),
        );

        // The checks were not run again: the report the task completed on is
        // the one the first run recorded.
        self::assertTrue($result->wasVerified());
        $attempt = $journal->attempts()->get($pass, 1);
        self::assertNotNull($attempt);
        self::assertCount(1, (new VerificationStore($journal->database()))->forAttempt($attempt));

        // One plan for the box, made by the run that wrote it.
        $applied = $journal->checklists()->latestFor($pass);
        self::assertNotNull($applied);
        self::assertSame(ChecklistUpdateState::Applied, $applied->state());
        self::assertSame(1, $applied->attempts());
        self::assertCount(1, $journal->checklists()->forGeneration($pass));

        self::assertStringContainsString('retrying only the push', $terminal->standardOutput());
    }

    /**
     * A remote that refuses twice is recorded twice, and still nothing is
     * repeated.
     *
     * The failure mode this rules out is a resume that treats "the push failed
     * again" as "the task failed", goes back to the agent, and commits the work
     * a second time. The remote is never added here, so the second run's push
     * is refused for the same reason the first one was.
     */
    #[Test]
    public function a_publication_that_is_refused_again_repeats_no_implementation(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $first = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'));
        $failed = $first->results()[0];

        $terminal = new CapturingOutput();
        $second = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'), $terminal);
        $result = $second->results()[0];

        self::assertFalse($result->wasChecked());
        self::assertSame($failed->commitSha(), $result->commitSha());
        self::assertCount(1, $this->commitsBeyondBase(), 'The commit was not made again.');
        self::assertCount(1, $worker->attempts(), 'The agent was not invoked again.');
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());

        // Still owed, still retryable, and the attempt limit was not spent on
        // somebody else's server.
        $pass = $this->journal()->generations()->get($result->generation());
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Publishing, $pass->phase());
        self::assertTrue($pass->awaitsPublication());
        self::assertSame(1, $pass->attempts());
    }

    /**
     * A branch moved off the recorded commit while the push was owed stops the
     * pass instead of publishing whatever is there now.
     *
     * {@see \Celyes\RalphPhp\Git\TaskPublisher} publishes the commit the row
     * records and refuses anything else, and a resume has to carry that
     * refusal rather than fall back to another attempt: what is on the branch
     * now has passed no check of this run, and a remote other people fetch is
     * the wrong place to find that out.
     */
    #[Test]
    public function a_branch_moved_while_the_push_was_owed_blocks_rather_than_publishing(): void
    {
        $this->givenTasks([self::FIRST]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"])]);

        $first = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'));
        $failed = $first->results()[0];
        $branch = (string) $failed->branch();

        $remote = $this->givenABareRemote();

        // A failed pass keeps its worktree for inspection (section F, item 10),
        // and a person who went to look made a commit of their own in it. The
        // branch is now past the commit the row records.
        $worktree = (string) $this->journal()
            ->generations()
            ->get($failed->generation())
            ->worktree()
            ->path();
        file_put_contents($worktree . '/NOTES.md', "Mine.\n");
        Git::run($worktree, 'add', 'NOTES.md');
        Git::commit($worktree, 'Somebody looks at the work and adds to it');

        $report = $this->loopOver(ScriptedVerifier::passing(), $this->pushingTo('origin'));
        $result = $report->results()[0];

        self::assertFalse($result->wasChecked());
        self::assertCount(1, $worker->attempts(), 'The agent was not invoked again.');
        self::assertNull($this->remoteTipOf($remote, $branch), 'Nothing reached the remote.');

        // The commit itself is untouched, which is what the hints send a person
        // to look at.
        self::assertNotNull($failed->commitSha());
        self::assertSame(
            $failed->commitSha(),
            trim(Git::run($this->project->root(), 'rev-parse', (string) $failed->commitSha())->standardOutput),
        );

        $pass = $this->journal()->generations()->get($result->generation());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertTrue($pass->awaitsPublication(), 'The push is still owed.');
        self::assertStringContainsString('could not be published', (string) $pass->blocker()?->reason());
        self::assertStringContainsString('diverged', (string) $pass->blocker()?->reason());
        self::assertStringContainsString(
            'Nothing was implemented or committed again.',
            (string) $pass->blocker()?->nextAction(),
        );
    }

    /**
     * Ctrl+C while an agent is working: PROJECT.md section H, item 4, end to
     * end.
     *
     * The interruption is a real signal, sent by the worker to the process that
     * started it, so it arrives where one actually does — in the middle of an
     * attempt, with a half-finished workspace on disk. Every clause of the item
     * is a separate assertion: the worker is stopped, the interruption is
     * recorded, the files are preserved, and the lock goes back because the
     * worker was confirmed gone.
     */
    #[Test]
    public function a_run_stopped_with_ctrl_c_stops_the_worker_and_records_the_interruption(): void
    {
        $this->requireSignals();
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([
            FixtureStep::interruption()
                ->editing(['src/Import.php' => "<?php\n\n// half done\n"])
                ->interruptingTheRunner(),
        ]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        // The run.
        self::assertTrue($report->wasInterrupted(), $report->describe());
        self::assertStringContainsString('stopped by SIGINT (Ctrl+C)', $report->stopReason());
        self::assertStringContainsString('2 tasks still pending', $report->stopReason());
        self::assertCount(1, $report->results(), 'The second task was taken on after the stop.');

        // The task.
        $result = $report->results()[0];
        self::assertTrue($result->isInterrupted(), $result->describe());
        self::assertFalse($result->isCompleted());
        self::assertFalse($result->wasChecked());
        self::assertTrue($result->stopsTheRun());
        self::assertNull($result->commitSha(), 'An interrupted attempt committed something.');
        self::assertCount(1, $report->interrupted());

        // The worker, which was really stopped: it recorded the signal, and the
        // package stopped it rather than waiting for it to finish its 30
        // seconds.
        self::assertSame([Interruption::SIGTERM], $worker->signals());

        // The rows, which are what resume will believe.
        $journal = $this->journal();
        $pass = $journal->generations()->get($result->generation());
        self::assertSame(LifecycleState::Interrupted, $pass->state());
        self::assertSame(Phase::Executing, $pass->phase(), 'The phase it stopped in is what resume reads.');
        self::assertTrue($pass->state()->isResumable());
        self::assertSame(1, $pass->attempts(), 'An attempt that was cut short still counts.');

        $attempt = $journal->attempts()->latestFor($pass);
        self::assertNotNull($attempt);
        self::assertSame(LifecycleState::Interrupted, $attempt->state());
        self::assertSame(AttemptOutcome::Cancelled, $attempt->outcome());
        self::assertTrue(
            AttemptOutcome::Cancelled->isRetryable(),
            'A cancelled attempt is what makes a resume possible.',
        );

        $run = $journal->runs()->get($report->run()->rowId());
        self::assertSame(LifecycleState::Interrupted, $run->state());
        self::assertStringContainsString('stopped by SIGINT', (string) $run->stopReason());
        self::assertSame(1, $run->iterations());

        // And what to do about it: a stop the user asked for is answered by a
        // resume, not by a new run, and the row says so rather than leaving
        // them to remember which command keeps the kept workspace.
        self::assertSame(StopKind::Interrupted, $run->stopKind());
        self::assertStringContainsString('ralph resume', (string) $run->stopAction());
        self::assertFalse($report->stoppedForAttention(), 'A stop the user asked for is not a problem to look into.');

        // The files: the workspace is kept exactly as the stopped agent left
        // it, which is the evidence a person needs (section F, item 10).
        $workspace = (string) $pass->worktree()->path();
        self::assertDirectoryExists($workspace);
        self::assertStringContainsString('half done', (string) file_get_contents($workspace . '/src/Import.php'));

        // The user's checkout and checklist are untouched, and nothing was
        // committed anywhere.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->tasksFile());
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->tasksFile());
        self::assertSame([], $this->commitsBeyondBase());
    }

    /**
     * The lock goes back, because the worker was confirmed stopped.
     *
     * The conditional half of the item's last clause. A confirmed termination
     * owes nothing, so an interrupted run leaves the repository free and the
     * next command — `ralph resume` — can take it without a word from the user.
     */
    #[Test]
    public function an_interrupted_run_whose_worker_stopped_leaves_the_lock_free(): void
    {
        $this->requireSignals();
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::interruption()->interruptingTheRunner()]);

        $this->loopOver(ScriptedVerifier::passing());

        $record = json_decode((string) file_get_contents($this->project->path('.ralph/runtime/run.lock')), true);
        self::assertIsArray($record);
        self::assertNotNull($record['released_at'] ?? null, 'An interrupted run kept a lock it did not need.');
        self::assertSame([], $record['workers'] ?? null);

        // And the proof that the record is not merely saying so.
        $next = RepositoryLock::in($this->project->root())->acquire('resume');

        try {
            self::assertTrue($next->isHeld());
        } finally {
            $next->release();
        }
    }

    /**
     * A stop that arrived before the run was set up.
     *
     * Nothing is recorded, and that is the right answer rather than a gap: a
     * run that never read the checklist, never opened a row and never started
     * an agent has not been interrupted, it has not happened. The refusal is
     * raised so a caller can report it, and the project is left exactly as it
     * was.
     *
     * It is also the case that makes every setup step's check worth having:
     * `git` is what the steps run, a stop cancels every command the package
     * starts, and without the check this would surface as a user's checkout
     * that could not be read.
     */
    #[Test]
    public function a_run_asked_to_stop_before_it_is_set_up_does_not_start(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n"])]);
        $before = $this->tasksFile();

        Interruptions::request(Interruption::SIGTERM);

        try {
            $this->loopOver(ScriptedVerifier::passing());
            self::fail('A run started after it had been asked to stop.');
        } catch (InterruptionException $stopped) {
            self::assertSame(Interruption::SIGTERM, $stopped->interruption()->signal());
            self::assertStringContainsString('stopped by SIGTERM before the run was set up', $stopped->getMessage());
            self::assertStringContainsString('ralph resume', implode("\n", $stopped->hints()));
        }

        self::assertSame([], $worker->records(), 'An agent was started after the run had been stopped.');
        self::assertDirectoryDoesNotExist($this->project->path('.ralph/runtime/worktrees'));
        self::assertSame($before, $this->tasksFile());
        self::assertSame([], $this->commitsBeyondBase());

        // And the lock is free: nothing was left running to hold it for.
        $next = RepositoryLock::in($this->project->root())->acquire('run');

        try {
            self::assertTrue($next->isHeld());
        } finally {
            $next->release();
        }
    }

    /**
     * A stop that arrived in the gap between two tasks.
     *
     * The loop's own check, which the pass cannot make: the task before it
     * finished, committed and was ticked off, and the next one is simply never
     * started — no workspace, no agent, no attempt. The moment is arranged
     * through the clock the loop reads between tasks, because a signal cannot
     * be aimed at a window measured in microseconds.
     */
    #[Test]
    public function a_stop_between_two_tasks_leaves_the_next_one_untouched(): void
    {
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n// done\n"])]);

        $report = $this->loopOver(
            ScriptedVerifier::passing(),
            terminal: null,
            clock: $this->aClockThatStopsTheRunOnceTicked(self::FIRST),
        );

        // The first task was finished, not cut short: a stop is a reason not to
        // start the next task, never a reason to abandon the one in hand.
        self::assertCount(1, $report->results());
        self::assertTrue($report->results()[0]->isCompleted());
        self::assertTrue($report->results()[0]->wasChecked());
        self::assertCount(1, $worker->attempts(), 'The second task was handed to the agent.');

        self::assertTrue($report->wasInterrupted(), $report->describe());
        self::assertStringContainsString('stopped by SIGINT (Ctrl+C)', $report->stopReason());
        self::assertStringContainsString('1 task still pending', $report->stopReason());

        $markdown = $this->tasksFile();
        self::assertStringContainsString('- [x] ' . self::FIRST, $markdown);
        self::assertStringContainsString('- [ ] ' . self::SECOND, $markdown);

        $journal = $this->journal();
        $second = $this->recorded(self::SECOND);
        self::assertTrue($second->isPending(), 'The task the run did not reach is still pending.');
        self::assertSame([], $journal->generations()->forTask($second->id()));

        $run = $journal->runs()->get($report->run()->rowId());
        self::assertSame(LifecycleState::Interrupted, $run->state());
        self::assertSame(1, $run->iterations(), 'An iteration was counted for a task never taken on.');
    }

    /**
     * A second task is not started after the first was interrupted, even though
     * the tasks are independent work.
     *
     * The distinction from a *failed* task, which does not end the run: a user
     * who pressed Ctrl+C is not asking for the rest of the checklist to be
     * worked through.
     */
    #[Test]
    public function the_task_after_an_interrupted_one_is_left_pending(): void
    {
        $this->requireSignals();
        $this->givenTasks([self::FIRST, self::SECOND]);
        $worker = $this->givenAWorker([
            FixtureStep::interruption()->interruptingTheRunner(),
            FixtureStep::success(['README.md' => "Documented.\n"]),
        ]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertCount(1, $worker->attempts(), 'The agent was handed a second task after the stop.');
        self::assertSame(1, $report->remaining() - 1, 'Both tasks should still be pending.');
        self::assertNull($report->result(self::SECOND));
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->tasksFile());
    }

    /**
     * A run that was not interrupted is recorded as completing, and gives up
     * nothing to the machinery that handles one that was.
     */
    #[Test]
    public function an_ordinary_run_is_still_recorded_as_completed(): void
    {
        $this->givenTasks([self::FIRST]);
        $this->givenAWorker([FixtureStep::success(['src/Import.php' => "<?php\n// done\n"])]);

        $report = $this->loopOver(ScriptedVerifier::passing());

        self::assertFalse($report->wasInterrupted());
        self::assertSame([], $report->interrupted());
        self::assertSame(
            LifecycleState::Completed,
            $this->journal()->runs()->get($report->run()->rowId())->state(),
        );
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
    /**
     * Ctrl+C can only be tested where it can be sent and handled.
     */
    private function requireSignals(): void
    {
        if (!Interruptions::isSupported() || !function_exists('posix_kill')) {
            self::markTestSkipped('Stopping a run needs ext-pcntl, and the worker signals it with ext-posix.');
        }
    }

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
        ?Clock $clock = null,
    ): RunReport {
        return Runner::forProject(
            $this->configuration($overrides),
            $verifier,
            $terminal?->output(),
            AdapterRegistry::default(),
            $clock,
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
     * Rewrite the acceptance criteria indented under the first task.
     *
     * The checklist is a managed path, so editing it here is not the
     * unrelated uncommitted work a run refuses to start over — it is exactly
     * what a user does to the file between two runs.
     */
    private function givenTheCriteria(string $criteria): void
    {
        $this->project->write(
            '.ralph/tasks.md',
            "# Tasks\n\n- [ ] " . self::FIRST . "\n  - " . $criteria . "\n",
        );
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

    /**
     * A clock that jumps forward every time it is read.
     *
     * The run's time budget cannot be asserted against the wall clock: a case
     * that really spent its budget would really take that long, and a budget
     * small enough to spend quickly would also be the timeout the agent's
     * process is given. Reading the clock is what the loop does to measure a
     * task, so a clock that moves when it is read is the smallest substitution
     * that makes the arithmetic observable.
     */
    /**
     * A clock that stops the run once a task's box has been ticked.
     *
     * How a stop is placed in the gap between two tasks, which is a window no
     * signal can be aimed at. The clock is what the loop reads on either side
     * of a pass, so it is the one thing guaranteed to be consulted again after
     * a task has been finalized and before the next is taken on — and a ticked
     * box is the last thing a pass does, which makes "after this task
     * finished" something a test can state rather than count.
     *
     * Everything else is real: the stop goes through the same process-wide
     * channel a signal handler writes to, and the loop notices it the same way.
     */
    private function aClockThatStopsTheRunOnceTicked(string $description): Clock
    {
        return new class ($this->project->path('.ralph/tasks.md'), $description) implements Clock {
            public function __construct(
                private readonly string $checklist,
                private readonly string $description,
            ) {
            }

            public function now(): DateTimeImmutable
            {
                if (str_contains((string) @file_get_contents($this->checklist), '- [x] ' . $this->description)) {
                    Interruptions::request(Interruption::SIGINT);
                }

                return new DateTimeImmutable('2026-11-07 09:00:00', new DateTimeZone('UTC'));
            }
        };
    }

    private function aClockThatJumpsBy(int $seconds): Clock
    {
        return new class ($seconds) implements Clock {
            private int $reads = 0;

            public function __construct(private readonly int $seconds)
            {
            }

            public function now(): DateTimeImmutable
            {
                return (new DateTimeImmutable('2026-11-07 09:00:00', new DateTimeZone('UTC')))
                    ->modify(sprintf('+%d seconds', $this->seconds * $this->reads++));
            }
        };
    }

    /**
     * A task as the state store holds it, found by what the checklist says.
     *
     * By description rather than by id, because the point of asking is usually
     * a task the run never reported on — so there is no result to take an id
     * from.
     */
    private function recorded(string $description): TaskRecord
    {
        foreach ($this->journal()->tasks()->all() as $task) {
            if ($task->description() === $description) {
                return $task;
            }
        }

        self::fail(sprintf('The state store holds no task described as "%s".', $description));
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
     * Publication turned on, aimed at a remote by name.
     *
     * The name is all the configuration carries: whether a repository has a
     * remote by that name is a fact about the repository, which is what makes
     * "pushing is on and `origin` does not exist yet" something a case can set
     * up by saying nothing more.
     *
     * @return array<string, mixed>
     */
    private function pushingTo(string $remote): array
    {
        return ['git' => ['push' => ['enabled' => true, 'remote' => $remote]]];
    }

    /**
     * A bare repository on the same disk, added to the project as `origin`.
     *
     * A real remote with a real `git push` behind it and no network or
     * credentials involved (PROJECT.md section J, items 6 and 8).
     */
    private function givenABareRemote(): string
    {
        $remote = TemporaryDirectory::create('ralph-remote-');
        $this->remotes[] = $remote;

        Git::initialise($remote, bare: true);
        Git::run($this->project->root(), 'remote', 'add', 'origin', $remote);

        return $remote;
    }

    private function remoteTipOf(string $remote, string $branch): ?string
    {
        $result = Git::tryRun($remote, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch);

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
