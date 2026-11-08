<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ChecklistConflictException;
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Tasks\ChecklistFile;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tasks\ChecklistWriter;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskList;
use Celyes\RalphPhp\Tasks\VerifiedTasks;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ticking a task in the file on disk.
 *
 * Writing to the user's checklist is the whole point of this class, so these
 * tests use real temporary projects: what matters is what the file contains
 * afterwards, that an unchanged tick does not touch it, that its permissions
 * come back, and that nothing of ours is left lying next to it.
 */
#[CoversClass(ChecklistWriter::class)]
#[CoversClass(ChecklistFile::class)]
#[CoversClass(ChecklistConflictException::class)]
final class ChecklistWriterTest extends TestCase
{
    private TemporaryProject $project;

    private ChecklistWriter $writer;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->writer = new ChecklistWriter();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_configured_checklist_is_ticked_in_place(): void
    {
        $file = $this->project->write(
            '.ralph/tasks.md',
            "# Tasks\n\n- [ ] Add a --dry-run option\n- [ ] Write the README\n",
        );

        $edit = $this->writer->tick(
            (new ConfigurationLoader())->defaults($this->project->root()),
            TaskId::fromDescription('Add a --dry-run option'),
        );

        self::assertTrue($edit->isChanged());
        self::assertSame(
            "# Tasks\n\n- [x] Add a --dry-run option\n- [ ] Write the README\n",
            (string) file_get_contents($file),
        );
    }

    #[Test]
    public function a_checklist_configured_elsewhere_is_the_one_written(): void
    {
        $this->project->write('docs/backlog.md', "- [ ] Configured elsewhere\n");
        $untouched = $this->project->write('.ralph/tasks.md', "- [ ] Configured elsewhere\n");
        $configuration = (new ConfigurationLoader())
            ->fromArray(['tasks' => 'docs/backlog.md'], $this->project->root());

        $this->writer->tick($configuration, TaskId::fromDescription('Configured elsewhere'));

        self::assertSame("- [x] Configured elsewhere\n", (string) file_get_contents($this->project->path('docs/backlog.md')));
        self::assertSame("- [ ] Configured elsewhere\n", (string) file_get_contents($untouched));
    }

    #[Test]
    public function the_ticked_task_reads_back_as_done(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] First\n- [ ] Second\n");
        $id = TaskId::fromDescription('First');

        $this->writer->tickFile($file, $id);

        $tasks = TaskList::fromChecklist(
            (new ChecklistReader())->readFile($file),
            VerifiedTasks::of($id),
        );

        self::assertSame(Completion::RunnerVerified, $tasks->completionOf($id));
        self::assertSame('Second', $tasks->next()?->description());
    }

    #[Test]
    public function the_whole_checklist_is_worked_through_one_tick_at_a_time(): void
    {
        $file = $this->project->write(
            '.ralph/tasks.md',
            "# Backlog\n\n- [x] Imported as done\n- [ ] First\n\n## Later\n\n- [ ] Second\n",
        );
        $reader = new ChecklistReader();
        $worked = [];

        // Bounded, because the loop's exit depends on the write having landed:
        // a writer that did not tick what it was asked to would otherwise hang
        // the suite instead of failing it.
        while (($task = TaskList::fromChecklist($reader->readFile($file))->next()) !== null) {
            $worked[] = $task->description();
            $this->writer->tickFile($file, $task->id());

            self::assertLessThan(3, count($worked), 'The checklist is not running out of pending tasks.');
        }

        self::assertSame(['First', 'Second'], $worked);
        self::assertSame(
            "# Backlog\n\n- [x] Imported as done\n- [x] First\n\n## Later\n\n- [x] Second\n",
            (string) file_get_contents($file),
        );
    }

    #[Test]
    public function a_task_that_is_already_ticked_leaves_the_file_untouched(): void
    {
        $contents = "- [x] Done already\n- [ ] Not yet\n";
        $file = $this->project->write('.ralph/tasks.md', $contents);
        touch($file, 1_700_000_000);
        $before = filemtime($file);

        $edit = $this->writer->tickFile($file, TaskId::fromDescription('Done already'));

        self::assertFalse($edit->isChanged());
        self::assertSame($contents, (string) file_get_contents($file));
        self::assertSame($before, filemtime($file));
    }

    #[Test]
    public function unticking_reopens_the_task_in_the_file(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [x] Ticked too soon\n");

        $edit = $this->writer->untickFile($file, TaskId::fromDescription('Ticked too soon'));

        self::assertTrue($edit->isChanged());
        self::assertSame("- [ ] Ticked too soon\n", (string) file_get_contents($file));
    }

    #[Test]
    public function the_checklist_keeps_its_permissions(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] Mind the mode\n");
        self::assertTrue(chmod($file, 0o640));

        $this->writer->tickFile($file, TaskId::fromDescription('Mind the mode'));

        clearstatcache(true, $file);
        self::assertSame(0o640, fileperms($file) & 0o777);
    }

    #[Test]
    public function no_temporary_file_is_left_beside_the_checklist(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] Tidy up after yourself\n");

        $this->writer->tickFile($file, TaskId::fromDescription('Tidy up after yourself'));

        self::assertSame(['tasks.md'], array_values(array_diff(
            (array) scandir(dirname($file)),
            ['.', '..'],
        )));
    }

    #[Test]
    public function a_missing_checklist_is_reported_with_its_path(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('No task checklist at ' . $this->project->path('.ralph/tasks.md'));

        $this->writer->tick(
            (new ConfigurationLoader())->defaults($this->project->root()),
            TaskId::fromDescription('Anything'),
        );
    }

    #[Test]
    public function a_checklist_that_is_a_directory_is_reported(): void
    {
        $this->project->write('.ralph/tasks.md/keep', '');

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('is a directory');

        $this->writer->tickFile(
            $this->project->path('.ralph/tasks.md'),
            TaskId::fromDescription('Anything'),
        );
    }

    #[Test]
    public function a_task_the_file_no_longer_states_is_refused_and_the_file_is_left_alone(): void
    {
        $contents = "- [ ] Reworded while the task ran\n";
        $file = $this->project->write('.ralph/tasks.md', $contents);

        try {
            $this->writer->tickFile($file, TaskId::fromDescription('As it read before'));
            self::fail('Expected the missing task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('no longer states the task', $exception->getMessage());
        }

        self::assertSame($contents, (string) file_get_contents($file));
    }

    #[Test]
    public function a_checklist_in_an_unwritable_directory_reports_the_write_failure(): void
    {
        $contents = "- [ ] Cannot be ticked\n";
        $file = $this->project->write('.ralph/tasks.md', $contents);
        $directory = dirname($file);
        self::assertTrue(chmod($directory, 0o500));
        clearstatcache(true, $directory);

        if (is_writable($directory)) {
            chmod($directory, 0o700);
            self::markTestSkipped('This user can write to a directory it has no write permission on.');
        }

        try {
            $this->writer->tickFile($file, TaskId::fromDescription('Cannot be ticked'));
            self::fail('Expected the write to be refused.');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString('Unable to write the task checklist', $exception->getMessage());
            self::assertContains('The checklist was left unchanged.', $exception->hints());
        } finally {
            chmod($directory, 0o700);
        }

        self::assertSame($contents, (string) file_get_contents($file));
    }

    /**
     * A checklist edited since the write was decided on is not written to.
     *
     * The caller's half of PROJECT.md section H, item 8. A run that recorded
     * "I will tick this task in a checklist that reads thus" may not get to
     * make the write for minutes, and in that time the user may add a task or
     * save the file from an editor. Deriving the new document from the file as
     * it read before would undo that edit, so the write is refused and the
     * file is left holding exactly what was found.
     */
    #[Test]
    public function a_checklist_edited_since_the_write_was_decided_on_is_refused(): void
    {
        $decidedOn = "- [ ] Add a --dry-run option\n";
        $file = $this->project->write('.ralph/tasks.md', $decidedOn);

        $edited = "- [ ] Add a --dry-run option\n- [ ] Write the README\n";
        self::assertNotFalse(file_put_contents($file, $edited));

        try {
            $this->writer->tickFile(
                $file,
                TaskId::fromDescription('Add a --dry-run option'),
                ChecklistFile::hash($decidedOn),
            );
            self::fail('Expected the stale write to be refused.');
        } catch (ChecklistConflictException $conflict) {
            self::assertStringContainsString('has changed since this checkbox write was decided on', $conflict->getMessage());
            self::assertSame($file, $conflict->path());
            self::assertSame(ChecklistFile::hash($decidedOn), $conflict->expected());
            self::assertSame(ChecklistFile::hash($edited), $conflict->found());
            self::assertStringContainsString('Nothing was written', implode(' ', $conflict->hints()));
        }

        self::assertSame($edited, (string) file_get_contents($file), "The user's edit is kept as it was made.");
        self::assertSame(['tasks.md'], array_values(array_diff(
            (array) scandir(dirname($file)),
            ['.', '..'],
        )));
    }

    #[Test]
    public function a_checklist_that_still_reads_as_planned_is_ticked(): void
    {
        $contents = "- [ ] Add a --dry-run option\n";
        $file = $this->project->write('.ralph/tasks.md', $contents);

        $edit = $this->writer->tickFile(
            $file,
            TaskId::fromDescription('Add a --dry-run option'),
            ChecklistFile::hash($contents),
        );

        self::assertTrue($edit->isChanged());
        self::assertSame("- [x] Add a --dry-run option\n", (string) file_get_contents($file));
    }

    /**
     * The caller's check comes before the marker is looked at.
     *
     * A run resuming a write it turns out to have already made must still be
     * told the file moved: what it goes on to record about the checklist's
     * contents would otherwise describe a document that is not on disk.
     */
    #[Test]
    public function an_already_ticked_task_is_still_refused_when_the_file_has_moved(): void
    {
        $decidedOn = "- [x] Written last time\n";
        $file = $this->project->write('.ralph/tasks.md', $decidedOn);

        $edited = "- [x] Written last time\n- [ ] Added since\n";
        self::assertNotFalse(file_put_contents($file, $edited));

        try {
            $this->writer->tickFile(
                $file,
                TaskId::fromDescription('Written last time'),
                ChecklistFile::hash($decidedOn),
            );
            self::fail('Expected the stale write to be refused.');
        } catch (ChecklistConflictException $conflict) {
            self::assertSame(ChecklistFile::hash($edited), $conflict->found());
        }

        self::assertSame($edited, (string) file_get_contents($file));
    }

    /**
     * The replacement itself is a compare-and-swap.
     *
     * The other half of section H, item 8, and the narrow window the caller's
     * check cannot cover: the file is compared again after the replacement has
     * been written and immediately before the rename, so an edit arriving in
     * the moment between reading the document and putting the new one back is
     * still seen. Calling {@see ChecklistFile::replace()} directly is how that
     * instant is reached deterministically — it is the same code path a real
     * concurrent save takes.
     */
    #[Test]
    public function a_replacement_is_abandoned_when_the_file_moved_while_it_was_written(): void
    {
        $readFrom = "- [ ] One\n";
        $file = $this->project->write('.ralph/tasks.md', $readFrom);

        $concurrent = "- [ ] One\n- [ ] Saved while the box was being written\n";
        self::assertNotFalse(file_put_contents($file, $concurrent));

        try {
            ChecklistFile::replace($file, "- [x] One\n", ChecklistFile::hash($readFrom));
            self::fail('Expected the replacement to be refused.');
        } catch (ChecklistConflictException $conflict) {
            self::assertStringContainsString('changed while its checkbox was being written', $conflict->getMessage());
            self::assertSame(ChecklistFile::hash($concurrent), $conflict->found());
        }

        self::assertSame($concurrent, (string) file_get_contents($file), 'The concurrent save survived.');
        self::assertSame(['tasks.md'], array_values(array_diff(
            (array) scandir(dirname($file)),
            ['.', '..'],
        )), 'The replacement was cleaned up rather than left beside the file.');
    }

    #[Test]
    public function a_replacement_whose_file_has_been_deleted_is_abandoned(): void
    {
        $readFrom = "- [ ] Gone while it was being ticked\n";
        $file = $this->project->write('.ralph/tasks.md', $readFrom);
        self::assertTrue(unlink($file));

        try {
            ChecklistFile::replace($file, "- [x] Gone while it was being ticked\n", ChecklistFile::hash($readFrom));
            self::fail('Expected the replacement to be refused.');
        } catch (ChecklistConflictException $conflict) {
            self::assertStringContainsString('could not be read back', $conflict->getMessage());
            self::assertNull($conflict->found());
        }

        self::assertFileDoesNotExist($file, 'A deleted checklist is not recreated from a stale document.');
        self::assertSame([], array_values(array_diff(
            (array) scandir(dirname($file)),
            ['.', '..'],
        )));
    }

    #[Test]
    public function a_replacement_with_no_expected_content_is_written_unconditionally(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] Whatever is there\n");
        self::assertNotFalse(file_put_contents($file, "- [ ] Changed since\n"));

        ChecklistFile::replace($file, "- [x] Written anyway\n");

        self::assertSame("- [x] Written anyway\n", (string) file_get_contents($file));
    }
}
