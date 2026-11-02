<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use InvalidArgumentException;

/**
 * Writes the notes under `.ralph/runtime/memory/`, which {@see MemoryStore}
 * reads.
 *
 * The two halves of memory are deliberately separate classes: reading happens
 * while an attempt is being prepared and must tolerate anything it finds, while
 * writing happens once an attempt is over and is the only thing that decides
 * what a note is called. This owns the names, and the reader takes its ordering
 * from them.
 *
 * **The name is what says which note is newest.** A note is
 * `0003-20261101T093000Z-attempt-02-error.md`: a zero-padded sequence, the UTC
 * moment it was written, and what it is about. The reader sorts file names as
 * bytes, so the leading sequence is what orders them, and it is a counter
 * rather than only a timestamp because a clock corrected backwards between two
 * attempts would otherwise file the second note before the first. The timestamp
 * is in the name as well because `ls` on the directory is a legible history,
 * and the slug is there so a user can find the attempt they are looking for
 * without opening four files.
 *
 * **Known limit of that scheme.** The sequence is padded to
 * {@see self::SEQUENCE_DIGITS} digits, so past 9,999 notes in one scope the
 * numbers stop sorting as bytes in the order they were written. A task's own
 * scope cannot approach that — a task has an attempt limit in single figures —
 * and the project scope only grows by notes a user puts there. Padding wider
 * would move the problem rather than remove it, and refusing to write the note
 * would lose evidence, which is worse than filing it out of order.
 *
 * **Writes are atomic and never overwrite.** A note goes to a temporary file
 * beside its destination and is then renamed into place, so a run killed
 * mid-write leaves either nothing or a whole note — and never a half-written
 * one the next attempt would read as the truth. The temporary name ends in
 * `.tmp`, which the reader ignores. Each note gets a free name of its own; a
 * note is a record of something that happened, and nothing that happened is
 * replaced by something that happened later.
 */
final class MemoryWriter
{
    /**
     * Digits the ordering sequence is padded to.
     */
    public const int SEQUENCE_DIGITS = 4;

    /**
     * What a note being written is called until it is whole.
     */
    public const string TEMPORARY_SUFFIX = '.tmp';

    /**
     * How the UTC moment appears in a file name: compact, sortable, and
     * obviously not a local time.
     */
    private const string STAMP_FORMAT = 'Ymd\THis\Z';

    /**
     * Notes are generated runtime data, and the runtime directory is the
     * user's own.
     */
    private const int DIRECTORY_MODE = 0o700;

    private const int FILE_MODE = 0o600;

    /**
     * How many names are tried before giving up on finding a free one.
     */
    private const int NAME_ATTEMPTS = 50;

    /**
     * What a slug may contain: this is a file name the package composes, so a
     * slug outside this is a bug in the caller rather than bad user input.
     */
    private const string SLUG = '/^[a-z0-9][a-z0-9-]*$/';

    public function __construct(
        private readonly string $directory,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * The memory of the project rooted at the given path.
     */
    public static function in(string $projectRoot, ?Clock $clock = null): self
    {
        return new self(MemoryStore::directoryIn($projectRoot), $clock ?? new SystemClock());
    }

    /**
     * Absolute path to the memory directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Write up one finished attempt for the next one to read.
     *
     * Returns null when the attempt had nothing to pass on. That is not a
     * failure: an attempt that was cancelled before the agent said anything is
     * recorded in the state store, and a note repeating only that it existed
     * would cost the next attempt part of its reading budget to say nothing.
     *
     * @throws FilesystemException when the note cannot be written
     */
    public function record(AttemptSummary $summary): ?MemoryNote
    {
        if ($summary->isEmpty()) {
            return null;
        }

        return $this->write(
            $summary->scope(),
            $summary->slug(),
            $summary->toMarkdown(),
            $summary->recordedAt(),
        );
    }

    /**
     * Write a note into a scope, under the next free name.
     *
     * @param string         $slug what the note is about, as lowercase words
     *                             joined by hyphens
     * @param Timestamp|null $at   when the note is dated; now, by default
     *
     * @throws InvalidArgumentException when the slug is not a name a file can
     *                                 be called, or the note says nothing
     * @throws FilesystemException      when the directory or the file cannot be
     *                                  written
     */
    public function write(
        MemoryScope $scope,
        string $slug,
        string $contents,
        ?Timestamp $at = null,
    ): MemoryNote {
        if (preg_match(self::SLUG, $slug) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A memory note\'s slug is lowercase words joined by hyphens; "%s" is not.',
                $slug,
            ));
        }

        if (trim($contents) === '') {
            throw new InvalidArgumentException('A memory note must say something.');
        }

        $directory = $scope->directoryIn($this->directory);
        $this->createDirectory($directory);

        $stamp = ($at ?? Timestamp::now($this->clock))->moment()->format(self::STAMP_FORMAT);
        $sequence = $this->nextSequence($directory);

        for ($tries = 0; $tries < self::NAME_ATTEMPTS; ++$tries, ++$sequence) {
            $path = $directory . '/' . $this->name($sequence, $stamp, $slug);

            if (file_exists($path) || is_link($path)) {
                continue;
            }

            $this->replace($path, rtrim($contents) . "\n");

            return new MemoryNote($scope, $path, BoundedText::of($contents, mb_strlen($contents)));
        }

        throw new FilesystemException(
            sprintf('Unable to find a free note name in %s.', $directory),
            ['The directory holds notes the package did not write; move them aside.'],
        );
    }

    /**
     * The file name of a note.
     */
    private function name(int $sequence, string $stamp, string $slug): string
    {
        return sprintf(
            '%s-%s-%s%s',
            str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT),
            $stamp,
            $slug,
            MemoryStore::EXTENSION,
        );
    }

    /**
     * The number the next note in a directory takes.
     *
     * Read off the existing names rather than from a counter in the database,
     * so that the directory is self-describing: a user who copies a task's notes
     * somewhere else, or deletes the newest one, gets a directory that still
     * makes sense. Names the package did not write are ignored for numbering —
     * they take no sequence, and the reader will sort them wherever their names
     * put them.
     *
     * @throws FilesystemException when the directory cannot be listed
     */
    private function nextSequence(string $directory): int
    {
        $entries = @scandir($directory, SCANDIR_SORT_NONE);

        if ($entries === false) {
            throw new FilesystemException(
                sprintf('Unable to read the memory directory %s.', $directory),
                ['Check the directory\'s permissions, or delete it: its contents are regenerated.'],
            );
        }

        $highest = 0;
        foreach ($entries as $entry) {
            if (preg_match('/^(\d+)-/', $entry, $match) !== 1) {
                continue;
            }

            $highest = max($highest, (int) $match[1]);
        }

        return $highest + 1;
    }

    /**
     * Create a scope's directory, and the memory directory above it.
     *
     * @throws FilesystemException when it cannot be created, or something that
     *                             is not a directory is in the way
     */
    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory) || is_link($directory)) {
            throw new FilesystemException(
                sprintf('A file exists at %s, where Ralph PHP keeps its notes.', $directory),
                ['Move the file out of the way; the memory directory holds generated notes.'],
            );
        }

        if (!@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            throw new FilesystemException(
                sprintf('Unable to create the memory directory %s.', $directory),
                ['Check the permissions of ' . dirname($directory) . '.'],
            );
        }
    }

    /**
     * Put the note in place in one step.
     *
     * The temporary file is written beside the destination rather than in the
     * system temporary directory, so the rename stays within one filesystem and
     * is therefore atomic.
     *
     * @throws FilesystemException when the note cannot be written or moved into
     *                             place
     */
    private function replace(string $path, string $contents): void
    {
        $temporary = $path . self::TEMPORARY_SUFFIX;

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new FilesystemException(
                sprintf('Unable to write the memory note %s.', $path),
                ['Check the permissions of ' . dirname($path) . '.'],
            );
        }

        @chmod($temporary, self::FILE_MODE);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new FilesystemException(
                sprintf('Unable to move the memory note into place at %s.', $path),
                [
                    'Check the permissions of ' . dirname($path) . '.',
                    'Nothing was left behind: the note was written to a temporary file first.',
                ],
            );
        }
    }
}
