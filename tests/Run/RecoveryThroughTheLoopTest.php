<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\CliResult;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Recovering a run whose work outlived the record of it, through the real
 * binary.
 *
 * PROJECT.md section J, item 7: a post-commit crash, a failed checkbox write
 * and a concurrent edit to the task file — with the thing all three have to
 * guarantee asserted in every one of them, that neither the commit nor the
 * implementation happens twice (acceptance criterion 6).
 *
 * The three moments are the gaps between a durable act and the record of it. A
 * commit is made and then written down. A box is ticked after the commit is
 * already durable. The file holding that box belongs to the user, who may be
 * editing it while the agent works. {@see PassRecoveryTest} holds
 * reconciliation itself to each of those against real commits in process, and
 * {@see RunnerTest} drives the loop over an unwritable checklist. What only a
 * subprocess can answer is what the *next command* does: whether `ralph resume`
 * and `ralph run` finish the work, say what happened, and exit with something a
 * script can read — and whether the branch's log holds one commit afterwards or
 * two.
 *
 * **How a crash is produced.** The run is really interrupted: SIGINT reaches a
 * real process with its handlers installed while the agent is working, which is
 * the only honest way to get an interrupted run row and a preserved workspace.
 * What the dying process had done and not yet recorded is then done here with
 * the package's own committer, and the one column it never reached is cleared —
 * {@see PassRecoveryTest}'s construction, because the window between `git
 * commit` returning and the `UPDATE` landing cannot be aimed at from outside.
 * Everything after that point is the real `ralph` binary.
 *
 * **What is pinned rather than fixed.** A pass that crashed after committing is
 * resumed without its commit being made again — the branch holds one commit, and
 * the committer refuses point blank to add a second — but it is not *finished*:
 * with no push outstanding and no checklist write yet planned, the resume takes
 * the task on as ordinary work, the agent is invoked a second time, and the pass
 * stops for a person. The duplicate the item rules out does not happen; the
 * finish section H promises does not either. Asserted as it behaves, and
 * recorded as a defect in PROGRESS.md.
 */
#[CoversNothing]
final class RecoveryThroughTheLoopTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    /**
     * The file the worker edits, which the project already has, so the commit
     * under recovery changes the user's code rather than adding a file beside
     * it.
     */
    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    /**
     * Where the controlling checkout's checklist is, from inside a task
     * workspace.
     *
     * A workspace is `.ralph/runtime/worktrees/<pass>`, so three levels up is
     * the project's own `.ralph/`. Relative because the edit is written by the
     * worker in its working directory, and a fixture that accepted absolute
     * paths would let a test write anywhere on the machine running it.
     */
    private const string CONTROLLING_CHECKLIST = '../../../tasks.md';

    /**
     * Output a waiting agent has printed, which is when a run is signalled.
     */
    private const string WORKING = ', mode slow';

    /**
     * The checklist these cases start from, written byte for byte so that what
     * an edit did to it is assertable.
     */
    private const string CHECKLIST = "# Tasks\n\n- [ ] " . self::TASK . "\n";

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this recovers real repositories.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The named case: a commit no row knew about is taken on by the resume
     * rather than made a second time.
     *
     * Section H, item 5. Three readings, each from a different place, and
     * together they are the whole claim: the branch has exactly one commit on
     * it, that commit is the one the crash made, and the row now names it. A
     * resume that had re-implemented the task would fail the first; one that had
     * started a branch of its own would fail the second.
     *
     * The agent is invoked again all the same, which is the defect this case
     * pins: the pass owes neither a push nor a planned checklist write, so
     * nothing in the loop recognises it as finishable and it is taken on as
     * ordinary work. Nothing that attempt does can land — the next case is why —
     * and the pass stops for a person with the commit kept and the box as the
     * user left it.
     */
    #[Test]
    public function a_commit_the_crash_never_recorded_is_adopted_instead_of_being_made_again(): void
    {
        $this->requirePcntl();

        $this->project
            ->withWorker(
                FixtureStep::slow(30)->editing([self::EDITED => self::EDIT]),
                // The resumed attempt finds the implementation already in the
                // workspace and has nothing to add, which is what an agent
                // handed a task that is already done reports.
                FixtureStep::success(),
            )
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration(['limits' => ['attempts_per_task' => 2]]);

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());

        $crashed = $this->givenTheCommitWasMadeAndNeverRecorded();
        $branch = (string) $this->passFor(self::TASK)->branch();

        $resumed = $this->project->ralph(['resume']);

        // What the resume says it found, before anything else happens: the
        // commit, named as adopted rather than as created.
        self::assertStringContainsString('adopted the commit', $resumed->standardOutput, $resumed->describe());
        self::assertStringContainsString(substr($crashed, 0, 12), $resumed->standardOutput);

        // One commit on the branch, and it is the crash's own.
        self::assertSame([$branch], $this->project->taskBranches(), 'The resume started a branch of its own.');
        self::assertCount(1, $this->project->commitsOn($branch), 'The work was committed twice.');
        self::assertSame($crashed, $this->project->headOf($branch));
        self::assertSame(self::EDIT, $this->committedContentOf($branch, self::EDITED));

        // And the row names it, which is the write the crash never made.
        $pass = $this->passFor(self::TASK);

        self::assertSame($crashed, $pass->commitSha());

        // The pinned part: the agent was invoked a second time, the pass did not
        // finish, and the box is still the user's own line.
        self::assertCount(2, $this->project->worker()->attempts());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(ExitCode::BLOCKED, $resumed->exitCode, $resumed->describe());
        self::assertSame(self::CHECKLIST, $this->project->checklist());

        // And what it stops on, which is the reading that makes the defect
        // visible: the second attempt had nothing to stage, and the workspace
        // being past the base commit is read as work that may be in a commit
        // nothing recorded — the commit this very run adopted a moment earlier.
        self::assertStringContainsString(
            'may be in a commit nothing recorded',
            (string) $pass->blocker()?->reason(),
        );
    }

    /**
     * Whatever the resumed attempt produces, the commit is not made twice.
     *
     * The other half of "not duplicated", and the guarantee the first case
     * rests on. The resumed attempt here does edit the workspace, so there is
     * something to stage, and a pass that counted its commits per attempt would
     * put a second one on the branch. The committer refuses instead, naming the
     * commit the row already holds, and says what a resume may retry — the push
     * or the checklist write, never the implementation.
     *
     * The refused edit is left in the workspace rather than discarded: the pass
     * is kept for inspection (section F, item 10), and reverting a worker's
     * files to make the refusal tidy would destroy the evidence of what it did.
     */
    #[Test]
    public function a_resumed_pass_that_has_already_committed_will_not_commit_again(): void
    {
        $this->requirePcntl();

        $this->project
            ->withWorker(
                FixtureStep::slow(30)->editing([self::EDITED => self::EDIT]),
                FixtureStep::success(['src/Second.php' => "<?php\n\n// a second go at it\n"]),
            )
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration(['limits' => ['attempts_per_task' => 2]]);

        self::assertSame(
            ExitCode::INTERRUPTED,
            $this->project->interrupt(['run'], self::WORKING)->exitCode,
        );

        $crashed = $this->givenTheCommitWasMadeAndNeverRecorded();
        $branch = (string) $this->passFor(self::TASK)->branch();

        $resumed = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::EXECUTION, $resumed->exitCode, $resumed->describe());
        self::assertStringContainsString(
            'already recorded commit ' . $crashed,
            $resumed->allOutput(),
            $resumed->describe(),
        );
        self::assertStringContainsString('A pass commits once.', $resumed->allOutput());
        self::assertStringContainsString('never the implementation', $resumed->allOutput());

        self::assertCount(1, $this->project->commitsOn($branch), 'The work was committed twice.');
        self::assertSame($crashed, $this->project->headOf($branch));
        self::assertSame([self::EDITED], $this->filesIn($branch), 'The second attempt\'s file was committed.');

        $pass = $this->passFor(self::TASK);

        self::assertSame($crashed, $pass->commitSha());
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(self::CHECKLIST, $this->project->checklist());

        // The second attempt's file is still where the worker wrote it.
        self::assertFileExists($pass->worktree()->path() . '/src/Second.php');
    }

    /**
     * A checkbox write the filesystem refuses keeps the commit, and leaves the
     * pass pending its finalization.
     *
     * Section G, item 8's first half. The checklist's directory is read-only, so
     * the replacement cannot be written at all — the real failure in the real
     * place: a replacement is written to a temporary file beside the original
     * and renamed over it, which needs permission on the directory rather than
     * on the file.
     *
     * What has to survive is everything the tick depended on. The commit is on
     * its branch, the row names it, and the planned write is still outstanding —
     * that row is what makes this recognisable afterwards as a pass needing one
     * line in a file rather than as a pass that failed. The box is the user's
     * own line, untouched, and the task is not recorded as done by anybody.
     */
    #[Test]
    public function a_checkbox_write_the_filesystem_refuses_keeps_the_commit_and_the_users_line(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration();

        $refused = $this->whileTheChecklistCannotBeWritten(
            fn (): CliResult => $this->project->ralph(['run']),
        );

        self::assertSame(ExitCode::BLOCKED, $refused->exitCode, $refused->describe());
        self::assertStringContainsString('could not be ticked', $refused->allOutput());
        self::assertStringContainsString('pending finalization', $refused->allOutput());

        $pass = $this->passFor(self::TASK);
        $branch = (string) $pass->branch();

        // The work: committed, once, and the commit holds what the agent wrote.
        self::assertNotNull($pass->commitSha());
        self::assertCount(1, $this->project->commitsOn($branch));
        self::assertSame(self::EDIT, $this->committedContentOf($branch, self::EDITED));

        // The pass: blocked with its implementation recorded, and the only
        // thing it owes is the line in the file.
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertTrue($pass->isFinalizable());
        self::assertStringContainsString('could not be ticked', (string) $pass->blocker()?->reason());

        $owed = $this->project->journal()->checklists()->outstandingFor($pass);

        self::assertNotNull($owed, 'Nothing records that the write is still owed.');
        self::assertTrue($owed->isOutstanding());

        // The file: exactly as the user wrote it, and the task done by nobody.
        self::assertSame(self::CHECKLIST, $this->project->checklist());
        self::assertSame(
            Completion::Pending,
            $this->project->journal()->tasks()->get($this->identify(self::TASK))->completion(),
        );

        // And what somebody arriving afterwards is told, in the report and in
        // the machine-readable form: a commit, an unchecked box, and a project
        // waiting on a decision rather than on more work.
        $status = $this->project->ralph(['status']);

        self::assertSame(ExitCode::BLOCKED, $status->exitCode, $status->describe());

        $document = JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput);

        self::assertSame($pass->commitSha(), $document->string('tasks.0.commit'));
        self::assertFalse($document->boolean('tasks.0.checked'));
    }

    /**
     * Section G, item 8's second half: the next run writes the box and does
     * nothing else.
     *
     * The directory is made writable, as the blocker's next action says to do,
     * and what follows is asserted as a shape rather than by its outcome — one
     * invocation of the agent in total, one attempt, one commit, one branch, one
     * checklist write. The agent is given no second step on purpose: a run that
     * went back to implementing would fail loudly rather than quietly produce a
     * second version of the work.
     */
    #[Test]
    public function a_pass_pending_finalization_is_finished_by_writing_only_the_box(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration();

        $this->whileTheChecklistCannotBeWritten(
            fn (): CliResult => $this->project->ralph(['run']),
        );

        $committed = (string) $this->passFor(self::TASK)->commitSha();
        $branch = (string) $this->passFor(self::TASK)->branch();

        $finished = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $finished->exitCode, $finished->describe());
        self::assertStringContainsString('resuming finalization', $finished->allOutput(), $finished->describe());

        // The box, written into the user's document, and nothing else changed.
        self::assertSame(str_replace('- [ ] ', '- [x] ', self::CHECKLIST), $this->project->checklist());

        // Nothing was done twice.
        self::assertCount(1, $this->project->worker()->attempts(), 'The agent was invoked again.');
        self::assertCount(1, $this->project->commitsOn($branch), 'A second commit was made.');
        self::assertSame([$branch], $this->project->taskBranches(), 'A second branch was created.');

        $pass = $this->passFor(self::TASK);

        self::assertSame($committed, $pass->commitSha());
        self::assertSame(1, $pass->attempts(), 'Retrying a write spent an attempt.');
        self::assertSame(1, $pass->generation()->number());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNull($pass->phase());
        self::assertNull($pass->blocker());

        // The write: one planned row, applied, and the task recorded as this
        // package's own verified work rather than as a box somebody ticked.
        $writes = $this->project->journal()->checklists()->forGeneration($pass);

        self::assertCount(1, $writes);
        self::assertTrue($writes[0]->isApplied());
        self::assertSame(
            Completion::RunnerVerified,
            $this->project->journal()->tasks()->get($this->identify(self::TASK))->completion(),
        );
    }

    /**
     * A checklist edited while the agent worked keeps the edit, and the box is
     * still ticked.
     *
     * Section H, item 8 from the user's side. The agent itself makes the edit —
     * it writes a task of its own into the file in the controlling checkout
     * while it is working, which is both a real thing agents do and the only way
     * to get a save that lands inside the window a run is open. The run started
     * from a reading that did not have that line.
     *
     * Two things must hold afterwards, and they pull in opposite directions:
     * the new line is still there — the write must not restore the document to
     * the reading the run began with — and the box this run earned is ticked.
     * Which means the write was planned against the file as it then read, and
     * the log says so rather than passing over an edit in silence.
     */
    #[Test]
    public function a_checklist_edited_while_the_agent_worked_keeps_the_edit_and_still_ticks_the_box(): void
    {
        $theirs = self::CHECKLIST . "- [ ] Also rename the importer's log file\n";

        $this->project
            ->withWorker(FixtureStep::success([
                self::EDITED => self::EDIT,
                self::CONTROLLING_CHECKLIST => $theirs,
            ]))
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // Their line survives, and the box this run earned is ticked. Nothing
        // is said about the edit on the log, and nothing needs to be: this run
        // had planned no write before it, so the file as it now reads is simply
        // what the write is planned against.
        self::assertSame(
            str_replace('- [ ] ' . self::TASK, '- [x] ' . self::TASK, $theirs),
            $this->project->checklist(),
        );

        $pass = $this->passFor(self::TASK);

        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertCount(1, $this->project->commitsOn((string) $pass->branch()));
    }

    /**
     * A task reworded while the agent worked stops the pass instead of ticking
     * whatever is on that line now.
     *
     * Section C, item 7. The agent rewrites the very task it was given, so the
     * work that comes back answers a description the file no longer holds —
     * which is the one case where ticking a box would record something nobody
     * asked for. The pass stops for reconciliation with its commit kept, and the
     * file is left exactly as it was found: the package does not undo an edit it
     * did not make, even one that cost it a pass.
     */
    #[Test]
    public function a_task_reworded_while_the_agent_worked_stops_the_pass_rather_than_ticking_it(): void
    {
        $reworded = "# Tasks\n\n- [ ] Add a --dry-run option to the export command instead\n";

        $this->project
            ->withWorker(FixtureStep::success([
                self::EDITED => self::EDIT,
                self::CONTROLLING_CHECKLIST => $reworded,
            ]))
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());

        $pass = $this->passFor(self::TASK);

        // The work is kept, and nothing of the edit was written over.
        self::assertNotNull($pass->commitSha());
        self::assertCount(1, $this->project->commitsOn((string) $pass->branch()));
        self::assertSame($reworded, $this->project->checklist());
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertNotNull($pass->blocker());
    }

    /**
     * An edit arriving between a failed write and its retry is kept, and the
     * work behind the box is not repeated.
     *
     * The two recoveries meeting. A pass is already pending finalization, and
     * the user edits the checklist before running again — which is a plausible
     * next act for somebody who has just read that their checklist could not be
     * written. The planned write now describes a document that no longer
     * exists, and the log says so.
     *
     * It is not a refusal. The box goes into the file as it now reads, their
     * note is still underneath it, and the agent is not invoked: the
     * implementation and its commit were durable before any of this started.
     */
    #[Test]
    public function an_edit_between_a_failed_write_and_its_retry_is_kept_and_the_work_is_not_repeated(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withChecklist(self::CHECKLIST)
            ->withConfiguration();

        $this->whileTheChecklistCannotBeWritten(
            fn (): CliResult => $this->project->ralph(['run']),
        );

        $committed = (string) $this->passFor(self::TASK)->commitSha();
        $branch = (string) $this->passFor(self::TASK)->branch();

        // A note rather than a task: a run reads the checklist fresh, so a task
        // added here would be pending work this run would rightly take on, and
        // the case is about what it does with the write it already owes.
        $theirs = self::CHECKLIST . "\nOnce this is done, tag the release.\n";
        $this->project->editChecklist($theirs);

        $finished = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $finished->exitCode, $finished->describe());
        self::assertStringContainsString('has been edited since this write was planned', $finished->allOutput());

        self::assertSame(
            str_replace('- [ ] ' . self::TASK, '- [x] ' . self::TASK, $theirs),
            $this->project->checklist(),
        );

        self::assertCount(1, $this->project->worker()->attempts(), 'The agent was invoked again.');
        self::assertCount(1, $this->project->commitsOn($branch), 'A second commit was made.');
        self::assertSame($committed, $this->passFor(self::TASK)->commitSha());
        self::assertSame(LifecycleState::Completed, $this->passFor(self::TASK)->state());
    }

    /**
     * Do what the crashed run had done and never wrote down.
     *
     * The commit is made here rather than by the run because the gap it
     * reproduces is between two statements: `git commit` has returned, the
     * `UPDATE` has not landed. It is made with the package's own committer, so
     * the branch is claimed the way a run claims it and the commit carries the
     * trailers reconciliation reads; then the column the dying process never
     * reached is cleared, and the phase is left at the one it had already
     * entered before it committed.
     *
     * @return string the commit's SHA, which no row now names
     */
    private function givenTheCommitWasMadeAndNeverRecorded(): string
    {
        $configuration = $this->configuration();
        $journal = $this->project->journal();
        $generations = $journal->generations();

        $pass = $this->passFor(self::TASK);

        self::assertSame(LifecycleState::Interrupted, $pass->state());
        self::assertNull($pass->commitSha(), 'The interrupted run committed after all.');

        $base = BaseResolver::forProject($configuration)->resolve();
        $worktree = TaskWorktrees::forProject($configuration)->prepare($pass->generation(), $base);

        $commit = TaskCommitter::forProject($configuration, $generations)->commit(
            $pass,
            $worktree,
            TaskBranches::forProject($configuration)->require(
                BranchNamer::forProject($configuration)->forRecord($pass, self::TASK),
                $pass,
                $generations->history(),
            ),
            WorkspaceStager::forProject($configuration)->stage($worktree),
            CommitMessage::forGeneration($pass, $this->snapshot()),
        );

        $journal->database()->execute(
            'UPDATE generations SET commit_sha = NULL, phase = ? WHERE id = ?',
            [Phase::Committing->value, $pass->rowId()],
        );

        return $commit->sha();
    }

    /**
     * Run something with the checklist's directory read-only.
     *
     * The directory rather than the file, for the reason the write itself is
     * done that way. The runtime directory is created first: it lives inside the
     * one being made read-only, and the state store has to be able to open.
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
        $runtime = $this->project->path('.ralph/runtime');

        if (!is_dir($runtime)) {
            self::assertTrue(mkdir($runtime, 0o700, true));
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
     * The task as the package identifies it, which comes from its words.
     */
    private function identify(string $description): TaskId
    {
        return TaskId::fromDescription($description);
    }

    /**
     * The pass recorded for a task, as the next command would read it.
     */
    private function passFor(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor($this->identify($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }

    /**
     * The files a branch adds to the project, which is the work in its commit.
     *
     * @return list<string>
     */
    private function filesIn(string $branch): array
    {
        $diff = Git::tryRun($this->project->root(), 'diff', '--name-only', 'HEAD..' . $branch);

        return $diff->exitCode === 0 ? $diff->standardOutputLines() : [];
    }

    /**
     * A file's contents as they were committed on a branch.
     */
    private function committedContentOf(string $branch, string $file): string
    {
        return Git::run($this->project->root(), 'show', $branch . ':' . $file)->standardOutput;
    }

    /**
     * The task as a commit message describes it, built from the words the run
     * read out of the checklist.
     */
    private function snapshot(): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            self::TASK,
            false,
            3,
            '- [ ] ' . self::TASK,
            [],
        )));
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->load($this->project->root());
    }

    private function requirePcntl(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped(
                'The pcntl extension is not installed, so a signalled run cannot record its interruption.',
            );
        }
    }
}
