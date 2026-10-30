<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\FilesystemException;

/**
 * The task checklist as a file on disk: reading its bytes, and replacing them.
 *
 * Here rather than in {@see ChecklistReader} alone because the checkbox writer
 * needs the same bytes the reader parsed and has to report a missing or
 * unreadable checklist in the same words — a user who sees one message from
 * `ralph status` and a different one from the end of a task has to work out
 * whether they are the same problem.
 *
 * Nothing in this class understands Markdown. {@see ChecklistParser} reads it,
 * {@see CheckboxEditor} rewrites one marker of it, and this moves it between
 * them and the filesystem.
 */
final class ChecklistFile
{
    /**
     * Suffix of the temporary file a replacement is written through.
     *
     * Named after the package and after the file it belongs to, so a leftover
     * from a crash is obviously ours and obviously not a task checklist.
     */
    private const string TEMPORARY_SUFFIX = '.ralph-write';

    /**
     * The checklist's contents, exactly as stored.
     *
     * @throws ChecklistException when the path is a directory, is not a file,
     *                            or cannot be read
     */
    public static function read(string $file): string
    {
        if (is_dir($file)) {
            throw new ChecklistException(
                sprintf('The task checklist %s is a directory.', $file),
                ['Point the "tasks" setting at a Markdown file.'],
            );
        }

        if (!is_file($file)) {
            throw new ChecklistException(
                sprintf('No task checklist at %s.', $file),
                [
                    'Run "ralph init" in the project to create one.',
                    'If the checklist lives elsewhere, set "tasks" in the configuration.',
                ],
            );
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ChecklistException(
                sprintf('Unable to read the task checklist %s.', $file),
                ['Check the file\'s permissions.'],
            );
        }

        return $contents;
    }

    /**
     * Replace the checklist's contents with the given document.
     *
     * Written to a temporary file beside the original and renamed over it, so
     * an interrupted write leaves the user's checklist as it was instead of
     * truncated: this file is the user's own record of what they want done, and
     * the package is the only thing that edits it without being asked to. The
     * rename is atomic within the directory, which is where the temporary file
     * deliberately goes — a system temporary directory is frequently another
     * filesystem, where the rename degrades into a copy and the guarantee is
     * lost.
     *
     * The original's permissions are carried over, because the replacement is a
     * new inode and would otherwise be created under the process umask: a
     * checklist the user had made group-writable must not come back private
     * because Ralph PHP ticked a box in it.
     *
     * Detecting that someone else edited the file in the meantime, and refusing
     * the write when they have, is PROJECT.md section H, item 8; this is the
     * part that makes the write itself survive being interrupted.
     *
     * @throws FilesystemException when the replacement cannot be written or
     *                             put in place
     */
    public static function replace(string $file, string $contents): void
    {
        $temporary = $file . self::TEMPORARY_SUFFIX;

        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            @unlink($temporary);

            throw new FilesystemException(
                sprintf('Unable to write the task checklist %s.', $file),
                [
                    sprintf('Check that %s is writable.', dirname($file)),
                    'The checklist was left unchanged.',
                ],
            );
        }

        $permissions = @fileperms($file);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0o777);
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new FilesystemException(
                sprintf('Unable to replace the task checklist %s.', $file),
                [
                    sprintf('Check the permissions of %s and of %s.', $file, dirname($file)),
                    'The checklist was left unchanged.',
                ],
            );
        }
    }
}
