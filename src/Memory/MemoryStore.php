<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;

/**
 * Reads the notes under `.ralph/runtime/memory/`.
 *
 * Markdown files in a directory, rather than rows in the state store, for one
 * reason: these are the sentences an agent wrote, and keeping them where a user
 * can open, correct and delete them keeps them visibly separate from the record
 * of what actually ran. The database says a check failed; a note says what the
 * agent thinks went wrong. Only the first is evidence, and storing them apart
 * is what stops the second from being mistaken for it.
 *
 * Like the state file, the location is derived from the project root and is not
 * configurable — it is generated data inside the directory cleanup owns.
 *
 * **Which notes survive the budget.** Selection runs newest-first, so what a
 * bound takes away is always the oldest note; the result is then handed back
 * oldest-first, so an attempt reads the history forwards. The newest note is
 * the one that describes the failure being retried, which is why it is the one
 * that must never be the one dropped.
 */
final class MemoryStore
{
    /**
     * The suffix a note file has.
     *
     * Everything else in the directory is ignored rather than read: a `.tmp`
     * left by an interrupted write, or an editor's backup file, is not a note.
     */
    public const string EXTENSION = '.md';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Where a project's memory lives.
     */
    public static function directoryIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . Defaults::MEMORY_DIRECTORY);
    }

    /**
     * The memory of the project rooted at the given path.
     */
    public static function in(string $projectRoot): self
    {
        return new self(self::directoryIn($projectRoot));
    }

    /**
     * Absolute path to the memory directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The notes of one scope, within the budget.
     *
     * A missing directory is not a failure: memory is generated, so a project
     * that has not run yet, or whose runtime directory was cleaned out, simply
     * has nothing to recall.
     *
     * @throws FilesystemException when a note file exists but cannot be read.
     *                             Deliberately fatal rather than skipped: the
     *                             note that cannot be read is most likely the
     *                             newest one, and an attempt that silently
     *                             proceeded without it would repeat the failure
     *                             it describes.
     */
    public function notesFor(MemoryScope $scope, MemoryBudget $budget): MemorySelection
    {
        $directory = $scope->directoryIn($this->directory);
        $files = $this->noteFiles($directory);

        if ($files === []) {
            return MemorySelection::empty($scope);
        }

        $remaining = $budget->totalNoteCharacters();
        $floor = $budget->noteFloor();
        $selected = [];

        foreach (array_reverse($files) as $file) {
            if (count($selected) >= $budget->notes() || $remaining < $floor) {
                break;
            }

            $text = BoundedText::of(
                $this->read($directory . '/' . $file),
                min($budget->noteCharacters(), $remaining),
            );

            if ($text->isEmpty()) {
                // An empty note is a note that says nothing; it is not worth a
                // heading in the prompt, and it does not consume the budget.
                continue;
            }

            $selected[] = new MemoryNote($scope, $directory . '/' . $file, $text);
            $remaining -= $text->length();
        }

        return new MemorySelection($scope, array_reverse($selected), count($files));
    }

    /**
     * How many notes a scope holds, without reading any of them.
     *
     * What lets a scope that the budget had nothing left for still be reported
     * as having notes that were omitted, rather than as having none.
     *
     * @throws FilesystemException when the directory exists and cannot be listed
     */
    public function countFor(MemoryScope $scope): int
    {
        return count($this->noteFiles($scope->directoryIn($this->directory)));
    }

    /**
     * The scope's note files, oldest first by name.
     *
     * @return list<string> file names
     */
    private function noteFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        // Listed unsorted, and ordered below. `scandir`'s own sort runs through
        // `strcoll`, which follows the user's collation locale: the same
        // directory could be read in a different order on a machine with a
        // different LC_COLLATE, and the order of notes is what says which one
        // is newest.
        $entries = @scandir($directory, SCANDIR_SORT_NONE);
        if ($entries === false) {
            throw new FilesystemException(
                sprintf('Unable to read the memory directory %s.', $directory),
                ['Check the directory\'s permissions, or delete it: its contents are regenerated.'],
            );
        }

        $files = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            if (!str_ends_with(strtolower($entry), self::EXTENSION)) {
                continue;
            }

            if (!is_file($directory . '/' . $entry)) {
                continue;
            }

            $files[] = $entry;
        }

        // The writer's names are what say which note is newest, so the
        // comparison is the plain byte one those names were chosen for.
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @throws FilesystemException
     */
    private function read(string $file): string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new FilesystemException(
                sprintf('Unable to read the memory note %s.', $file),
                [
                    'Check the file\'s permissions.',
                    'Deleting the note loses what it recorded but lets the run continue.',
                ],
            );
        }

        return $contents;
    }
}
