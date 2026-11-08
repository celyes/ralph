<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\ChecklistConflictException;
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\FilesystemException;

/**
 * Ticks and unticks a task in the project's checklist on disk.
 *
 * The one place that writes to the user's task file. {@see CheckboxEditor}
 * decides what the document should say and guarantees it differs by one marker;
 * this reads the file, hands it over and puts the result back, so no command
 * can alter the checklist except by coming through here.
 *
 * A marker that already reads the way it was asked to is not written at all:
 * the file keeps its modification time and, if it is tracked, stays out of the
 * user's `git status`. That makes retrying the tick after a crash harmless,
 * which is what PROJECT.md section G, item 8 needs — the implementation and its
 * commit survive a failed checkbox write, and resume only has the checklist
 * left to finish.
 *
 * Nothing here consults the state store or decides *whether* a task has earned
 * its tick. Verification, the commit and any required push are the runner's
 * conditions (PROJECT.md section G, item 7); this is the last step once they
 * hold.
 *
 * **The write is checked against the content it came from.** Ticking a box is a
 * read-modify-write of somebody else's document, so every byte that goes back
 * to disk except the one marker came out of the read. If the file moved in
 * between — the user added a task, an editor saved, a second tool rewrote it —
 * writing the result would quietly undo that edit. So the content is hashed as
 * it is read and the replacement is refused unless the file still holds it, and
 * the caller may also name the content it planned the write against: a run that
 * recorded "I will tick this task in a checklist that reads thus" has that
 * reading in its state store ({@see \Celyes\RalphPhp\State\ChecklistUpdate}),
 * and a file that no longer reads that way is a concurrent edit rather than the
 * state the write was decided on. Either way nothing is written, the edit stays
 * exactly as it was found, and the write is still owed — PROJECT.md section H,
 * item 8.
 *
 * The refusal is not retried here. A checklist being edited while a box is
 * ticked is a person at a keyboard, and reading-and-writing in a loop until it
 * lands would race against them; the run stops instead and says what it found,
 * and the next one writes the box against the file as it then reads.
 */
final class ChecklistWriter
{
    public function __construct(private readonly CheckboxEditor $editor = new CheckboxEditor())
    {
    }

    /**
     * Mark a task done in the configured checklist.
     *
     * @param string|null $expectedHash the checklist's content hash as the
     *        caller last read it, which the write is refused unless the file
     *        still holds; null writes against whatever is there
     *
     * @throws ChecklistException when the checklist cannot be read, cannot be
     *                            parsed, or no longer states the task
     * @throws ChecklistConflictException when the file no longer holds the
     *                            content the write was decided on
     * @throws FilesystemException when the file cannot be replaced
     */
    public function tick(Configuration $configuration, TaskId $id, ?string $expectedHash = null): CheckboxEdit
    {
        return $this->tickFile($configuration->tasksFile(), $id, $expectedHash);
    }

    /**
     * Mark a task as still to do in the configured checklist.
     *
     * @throws ChecklistException
     * @throws ChecklistConflictException
     * @throws FilesystemException
     */
    public function untick(Configuration $configuration, TaskId $id, ?string $expectedHash = null): CheckboxEdit
    {
        return $this->untickFile($configuration->tasksFile(), $id, $expectedHash);
    }

    /**
     * Mark a task done in a checklist at an absolute path.
     *
     * @throws ChecklistException
     * @throws ChecklistConflictException
     * @throws FilesystemException
     */
    public function tickFile(string $file, TaskId $id, ?string $expectedHash = null): CheckboxEdit
    {
        return $this->update($file, $id, true, $expectedHash);
    }

    /**
     * Mark a task as still to do in a checklist at an absolute path.
     *
     * @throws ChecklistException
     * @throws ChecklistConflictException
     * @throws FilesystemException
     */
    public function untickFile(string $file, TaskId $id, ?string $expectedHash = null): CheckboxEdit
    {
        return $this->update($file, $id, false, $expectedHash);
    }

    /**
     * Read, edit one marker, and put the result back against the bytes it came
     * from.
     *
     * The hash taken here is what the replacement is conditional on, so the
     * document that lands is always the document that was parsed plus one
     * character. A hash the caller brought is compared first and before
     * anything is parsed: it answers a different question — whether the file is
     * still the one the write was decided on, which may have been several
     * minutes and a failed write ago — and a file that has moved since should
     * not be reported as though the task had been reworded.
     *
     * A marker that already reads as asked is returned without a write at all,
     * and deliberately *after* the caller's check: a run resuming a write it
     * already made must still be told the file moved, because what it goes on
     * to record about the file's contents would otherwise be wrong.
     *
     * @throws ChecklistException
     * @throws ChecklistConflictException
     * @throws FilesystemException
     */
    private function update(string $file, TaskId $id, bool $checked, ?string $expectedHash): CheckboxEdit
    {
        $markdown = ChecklistFile::read($file);
        $source = ChecklistFile::hash($markdown);

        if ($expectedHash !== null && $source !== $expectedHash) {
            throw $this->moved($file, $expectedHash, $source);
        }

        $edit = $checked
            ? $this->editor->tick($markdown, $id, $file)
            : $this->editor->untick($markdown, $id, $file);

        if ($edit->isChanged()) {
            ChecklistFile::replace($file, $edit->markdown(), $source);
        }

        return $edit;
    }

    /**
     * Refuse a write whose checklist is not the one it was decided on.
     *
     * Worded for the person who made the edit: their file is as they left it,
     * the work the box would have recorded is still there, and the remedy is to
     * look at the file rather than to undo anything.
     */
    private function moved(string $file, string $expected, string $found): ChecklistConflictException
    {
        return new ChecklistConflictException(
            sprintf('The task checklist %s has changed since this checkbox write was decided on.', $file),
            $file,
            $expected,
            $found,
            [
                'Nothing was written: the checklist holds the edit exactly as it was made.',
                'Review the file, then run the command again. The box is written against the checklist '
                . 'as it then reads, and the work behind it is not repeated.',
            ],
        );
    }
}
