<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistConflictException;
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
     * The content hash of a checklist document.
     *
     * Of the bytes, not of the tasks parsed out of them: what this answers is
     * "is this the same file I was looking at", which is the question a planned
     * write has to ask before it is applied and after it has been
     * ({@see \Celyes\RalphPhp\State\ChecklistUpdate}). A task's own hash is
     * {@see TaskSnapshot::hash()} and deliberately ignores formatting; this one
     * must not, because a byte the package did not write is exactly what it is
     * looking for.
     */
    public static function hash(string $contents): string
    {
        return hash('sha256', $contents);
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
     * An expected content hash makes the replacement a compare-and-swap, which
     * is the other half of PROJECT.md section H, item 8: the document handed in
     * was derived from bytes that were read a moment ago, so if the file no
     * longer holds those bytes somebody edited it in between and putting the
     * result back would restore the file to how it looked before their edit.
     * The comparison happens as late as it can — after the replacement is
     * written and immediately before the rename — so the window in which an
     * edit can go unnoticed is as small as a single `rename` call. It cannot be
     * closed altogether without a lock every editor of the file would have to
     * take, and a Markdown file the user opens in their own tools has no such
     * discipline; what the check does guarantee is that an edit made at any
     * point up to that last instant is seen and the write refused.
     *
     * @param string|null $expectedHash content hash the caller read the document
     *        from, as {@see self::hash()} computes it; null writes
     *        unconditionally, which is for callers that are not rewriting
     *        existing content
     *
     * @throws FilesystemException when the replacement cannot be written or
     *                             put in place
     * @throws ChecklistConflictException when the file no longer holds the
     *                             content the replacement was derived from
     */
    public static function replace(string $file, string $contents, ?string $expectedHash = null): void
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

        if ($expectedHash !== null) {
            $found = self::current($file);

            if ($found !== $expectedHash) {
                @unlink($temporary);

                throw self::conflict($file, $expectedHash, $found);
            }
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

    /**
     * The hash of what the file holds right now, or null when it holds nothing
     * readable.
     *
     * The stat cache is cleared first: within one request PHP will happily
     * answer `is_file()` from a cached stat taken before a concurrent editor
     * replaced the file, and a stale answer here is exactly the thing this
     * reading exists to rule out. Read with the plain functions rather than
     * through {@see self::read()} because a checklist that has been deleted or
     * swapped for a directory under us is a conflict rather than a missing
     * file: the caller has a document in hand that it must not write.
     */
    private static function current(string $file): ?string
    {
        clearstatcache(true, $file);

        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : self::hash($contents);
    }

    /**
     * Refuse a write whose source content is no longer on disk.
     *
     * Says plainly that nothing was overwritten, because that is the question a
     * user who has just been editing the file will have, and that the write is
     * still owed rather than lost — the implementation and the commit behind it
     * are durable by the time a box is ticked, and the retry writes the box
     * against the file as it now reads.
     */
    private static function conflict(string $file, string $expected, ?string $found): ChecklistConflictException
    {
        return new ChecklistConflictException(
            $found === null
                ? sprintf('The task checklist %s could not be read back before its checkbox was written.', $file)
                : sprintf('The task checklist %s changed while its checkbox was being written.', $file),
            $file,
            $expected,
            $found,
            [
                'The checklist holds what was found there: nothing of the edit was overwritten.',
                'The checkbox was not written. Review the file, then run the command again: the box is '
                . 'written against the checklist as it then reads, and the work behind it is not repeated.',
            ],
        );
    }
}
