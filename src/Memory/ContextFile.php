<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\Exception\FilesystemException;

/**
 * Reads the configured context file from disk.
 *
 * The counterpart of {@see \Celyes\RalphPhp\Tasks\ChecklistFile}, and
 * deliberately more forgiving than it in one respect: a missing checklist means
 * there is no work to do and is an error, while a missing context file means
 * the user has not written any project instructions, which is a project Ralph
 * PHP can still run. The absence is carried in the result rather than thrown,
 * so the runner can say so once in its output instead of refusing to start.
 *
 * Nothing here writes. The context file is the user's, and `ralph init` is the
 * only thing that ever creates it.
 */
final class ContextFile
{
    /**
     * The context at a path, held to the budget.
     *
     * @throws FilesystemException when the path is a directory, or is a file
     *                             that cannot be read — both say the project is
     *                             configured or permissioned wrongly, which is
     *                             worth stopping for, unlike a file the user
     *                             simply has not written
     */
    public static function read(string $file, MemoryBudget $budget = new MemoryBudget()): ProjectContext
    {
        if (is_dir($file)) {
            throw new FilesystemException(
                sprintf('The project context %s is a directory.', $file),
                ['Point the "context" setting at a Markdown file.'],
            );
        }

        if (!is_file($file)) {
            return ProjectContext::absent($file);
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new FilesystemException(
                sprintf('Unable to read the project context %s.', $file),
                ['Check the file\'s permissions.'],
            );
        }

        return ProjectContext::of($file, BoundedText::of($contents, $budget->contextCharacters()));
    }
}
