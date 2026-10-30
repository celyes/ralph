<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Config\Configuration;
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
 */
final class ChecklistWriter
{
    public function __construct(private readonly CheckboxEditor $editor = new CheckboxEditor())
    {
    }

    /**
     * Mark a task done in the configured checklist.
     *
     * @throws ChecklistException when the checklist cannot be read, cannot be
     *                            parsed, or no longer states the task
     * @throws FilesystemException when the file cannot be replaced
     */
    public function tick(Configuration $configuration, TaskId $id): CheckboxEdit
    {
        return $this->tickFile($configuration->tasksFile(), $id);
    }

    /**
     * Mark a task as still to do in the configured checklist.
     *
     * @throws ChecklistException
     * @throws FilesystemException
     */
    public function untick(Configuration $configuration, TaskId $id): CheckboxEdit
    {
        return $this->untickFile($configuration->tasksFile(), $id);
    }

    /**
     * Mark a task done in a checklist at an absolute path.
     *
     * @throws ChecklistException
     * @throws FilesystemException
     */
    public function tickFile(string $file, TaskId $id): CheckboxEdit
    {
        return $this->update($file, $id, true);
    }

    /**
     * Mark a task as still to do in a checklist at an absolute path.
     *
     * @throws ChecklistException
     * @throws FilesystemException
     */
    public function untickFile(string $file, TaskId $id): CheckboxEdit
    {
        return $this->update($file, $id, false);
    }

    /**
     * @throws ChecklistException
     * @throws FilesystemException
     */
    private function update(string $file, TaskId $id, bool $checked): CheckboxEdit
    {
        $markdown = ChecklistFile::read($file);

        $edit = $checked
            ? $this->editor->tick($markdown, $id, $file)
            : $this->editor->untick($markdown, $id, $file);

        if ($edit->isChanged()) {
            ChecklistFile::replace($file, $edit->markdown());
        }

        return $edit;
    }
}
