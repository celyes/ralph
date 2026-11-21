<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
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
 * PROJECT.md section J, item 7, as it stands since section K, item 1 removed
 * the package's Git work: a failed checkbox write and a concurrent edit to the
 * task file, with the thing both have to guarantee asserted in each of them —
 * that the implementation is not asked for twice (acceptance criterion 6). The
 * post-commit crash the item also named cannot happen any more, because nothing
 * commits: the work is in the user's working tree the moment the agent writes
 * it, and there is no second place for it to be recorded.
 *
 * The two moments left are the gaps between a durable act and the record of it.
 * A box is ticked after the checks have passed. The file holding that box
 * belongs to the user, who may be editing it while the agent works.
 * {@see RunnerTest} drives the loop over an unwritable checklist in process.
 * What only a subprocess can answer is what the *next command* does: whether
 * `ralph run` finishes the work, says what happened, and exits with something a
 * script can read.
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
     * The checklist, as the worker sees it from its working directory.
     *
     * Which is the project root since section K, item 1, so the path is the
     * configured one. Relative because the edit is written by the worker in its
     * working directory, and a fixture that accepted absolute paths would let a
     * test write anywhere on the machine running it.
     */
    private const string CONTROLLING_CHECKLIST = '.ralph/tasks.md';

    /**
     * The checklist these cases start from, written byte for byte so that what
     * an edit did to it is assertable.
     */
    private const string CHECKLIST = "# Tasks\n\n- [ ] " . self::TASK . "\n";

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the project a run works in is a repository.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * A checkbox write the filesystem refuses keeps the work, and leaves the
     * pass pending its finalization.
     *
     * Section G, item 8's first half. The checklist's directory is read-only, so
     * the replacement cannot be written at all — the real failure in the real
     * place: a replacement is written to a temporary file beside the original
     * and renamed over it, which needs permission on the directory rather than
     * on the file.
     *
     * What has to survive is everything the tick depended on. The agent's work
     * is in the working tree, the checks passed against it, and the planned
     * write is still outstanding — that row is what makes this recognisable
     * afterwards as a pass needing one line in a file rather than as a pass
     * that failed. The box is the user's own line, untouched, and the task is
     * not recorded as done by anybody.
     */
    #[Test]
    public function a_checkbox_write_the_filesystem_refuses_keeps_the_work_and_the_users_line(): void
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

        // The work: in the working tree, where the agent wrote it.
        self::assertSame(self::EDIT, $this->contentInTheCheckout(self::EDITED));

        // The pass: blocked at its last phase, and the only thing it owes is
        // the line in the file.
        self::assertSame(LifecycleState::Blocked, $pass->state());
        self::assertSame(Phase::Finalizing, $pass->phase());
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
        // the machine-readable form: an unchecked box, and a project waiting on
        // a decision rather than on more work.
        $status = $this->project->ralph(['status']);

        self::assertSame(ExitCode::BLOCKED, $status->exitCode, $status->describe());

        $document = JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput);

        self::assertSame('blocked', $document->string('tasks.0.state'));
        self::assertFalse($document->boolean('tasks.0.checked'));
    }

    /**
     * Section G, item 8's second half: the next run writes the box and does
     * nothing else.
     *
     * The directory is made writable, as the blocker's next action says to do,
     * and what follows is asserted as a shape rather than by its outcome — one
     * invocation of the agent in total, one attempt, one checklist write. The
     * agent is given no second step on purpose: a run that went back to
     * implementing would fail loudly rather than quietly produce a second
     * version of the work.
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

        // Verbose, because what the second run is doing instead of starting
        // the task over is noted beside the agent's output, which a quiet run
        // does not echo (section K, item 2). The note is on the attempt's log
        // either way, and the rows asserted below are what the item turns on.
        $finished = $this->project->ralph(['run', '--verbose']);

        self::assertSame(ExitCode::SUCCESS, $finished->exitCode, $finished->describe());
        self::assertStringContainsString('resuming finalization', $finished->allOutput(), $finished->describe());

        // The box, written into the user's document, and nothing else changed.
        self::assertSame(str_replace('- [ ] ', '- [x] ', self::CHECKLIST), $this->project->checklist());

        // Nothing was done twice.
        self::assertCount(1, $this->project->worker()->attempts(), 'The agent was invoked again.');
        self::assertSame(self::EDIT, $this->contentInTheCheckout(self::EDITED));

        $pass = $this->passFor(self::TASK);

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
        self::assertSame(self::EDIT, $this->contentInTheCheckout(self::EDITED));
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
        self::assertSame(self::EDIT, $this->contentInTheCheckout(self::EDITED));
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
     * note is still underneath it, and the agent is not invoked: the work was
     * done and verified before any of this started.
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

        // A note rather than a task: a run reads the checklist fresh, so a task
        // added here would be pending work this run would rightly take on, and
        // the case is about what it does with the write it already owes.
        $theirs = self::CHECKLIST . "\nOnce this is done, tag the release.\n";
        $this->project->editChecklist($theirs);

        // Verbose, for the reason the case above gives.
        $finished = $this->project->ralph(['run', '--verbose']);

        self::assertSame(ExitCode::SUCCESS, $finished->exitCode, $finished->describe());
        self::assertStringContainsString('has been edited since this write was planned', $finished->allOutput());

        self::assertSame(
            str_replace('- [ ] ' . self::TASK, '- [x] ' . self::TASK, $theirs),
            $this->project->checklist(),
        );

        self::assertCount(1, $this->project->worker()->attempts(), 'The agent was invoked again.');
        self::assertSame(self::EDIT, $this->contentInTheCheckout(self::EDITED));
        self::assertSame(LifecycleState::Completed, $this->passFor(self::TASK)->state());
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
     * A file as the user's checkout now holds it, which is where the work is.
     */
    private function contentInTheCheckout(string $relative): string
    {
        return (string) file_get_contents($this->project->path($relative));
    }
}
