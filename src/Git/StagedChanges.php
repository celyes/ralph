<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * What is in the index of a task workspace, and what was kept out of it.
 *
 * This is the staged diff summary PROJECT.md section F, item 6 asks to be
 * recorded, and it is recorded because of when it is taken: between
 * verification passing and the commit being made, which is the only moment at
 * which "what this commit will contain" is a question with an answer. Afterwards
 * the commit is its own evidence; before, nothing has been decided. A reviewer
 * who later asks why a task's commit does not contain the file the agent talked
 * about is asking a question only this record can answer.
 *
 * Read from two commands rather than one, because Git will give either the
 * statuses or the line counts and never both:
 *
 *  - `diff --cached --name-status -z`, for what happened to each path, score
 *    digits included — `R100` is a pure rename and `R087` is not.
 *  - `diff --cached --numstat -z`, for the added and removed lines.
 *
 * Both are pinned against the repository's own configuration in the same way
 * {@see WorkingTreeStatus} pins `status`. `-z` removes the quoting, so a path
 * containing a quote or a newline arrives verbatim instead of as Git's
 * rendering of it. `--find-renames` is passed explicitly because a repository
 * with `diff.renames=false` would otherwise report every rename as a deletion
 * and an addition, which would make this summary a description of the user's
 * diff configuration rather than of the commit.
 *
 * {@see self::excluded()} is the other half, and the half that cannot be
 * reconstructed from the repository later: once the commit exists there is no
 * way to tell a file that was never changed from one that was changed and
 * deliberately left out.
 */
final class StagedChanges
{
    /**
     * The arguments producing the statuses this class parses.
     *
     * @var list<string>
     */
    public const array NAME_STATUS_COMMAND = ['diff', '--cached', '--name-status', '--find-renames', '-z'];

    /**
     * The arguments producing the line counts this class parses.
     *
     * The same diff, asked a different question, so the two outputs name the
     * same paths in the same order — which is what lets them be joined.
     *
     * @var list<string>
     */
    public const array NUMSTAT_COMMAND = ['diff', '--cached', '--numstat', '--find-renames', '-z'];

    /**
     * What Git prints instead of a line count for a file it will not read as
     * text.
     */
    private const string BINARY = '-';

    /**
     * Status letters whose entry names two paths.
     *
     * @var list<string>
     */
    private const array PAIRED_STATUSES = ['R', 'C'];

    /**
     * Paths named individually in a one-line description.
     */
    private const int PATHS_IN_SUMMARY = 10;

    /**
     * @param list<StagedFile> $files in the order Git reported them
     * @param list<ExcludedChange> $excluded in the order Git reported them
     */
    private function __construct(
        private readonly string $workspace,
        private readonly array $files,
        private readonly array $excluded,
    ) {
    }

    /**
     * Read the output of {@see self::NAME_STATUS_COMMAND} and
     * {@see self::NUMSTAT_COMMAND}, taken of the same index.
     *
     * Malformed output raises rather than being skipped, for the reason
     * {@see WorkingTreeStatus::parse()} does and with more at stake: an entry
     * this could not read is a staged path missing from the record of what the
     * commit contains, and a record that is quietly short is worse than no
     * record, because it will be believed.
     *
     * @param list<ExcludedChange> $excluded
     *
     * @throws InvalidArgumentException when an entry cannot be read, or the two
     *                                 outputs do not describe the same diff
     */
    public static function parse(
        string $workspace,
        string $nameStatus,
        string $numstat,
        array $excluded = [],
    ): self {
        $counts = self::lineCounts($numstat);
        $files = [];

        foreach (self::statuses($nameStatus) as [$status, $path, $originalPath]) {
            if (!array_key_exists($path, $counts)) {
                throw new InvalidArgumentException(sprintf(
                    'Git reported "%s" as staged but gave no line counts for it.',
                    $path,
                ));
            }

            [$insertions, $deletions] = $counts[$path];

            $files[] = StagedFile::of($status, $path, $originalPath, $insertions, $deletions);
        }

        return new self($workspace, $files, $excluded);
    }

    /**
     * An index with nothing in it, for a workspace where every change was
     * excluded or there was nothing to stage at all.
     *
     * Not the same as a failure, and not the same as an empty commit: whether
     * a verified task with nothing to commit is a no-change result is
     * PROJECT.md section G, item 6's decision, and this is the evidence it
     * needs to make it.
     *
     * @param list<ExcludedChange> $excluded
     */
    public static function nothingStaged(string $workspace, array $excluded = []): self
    {
        return new self($workspace, [], $excluded);
    }

    /**
     * The workspace whose index this describes.
     */
    public function workspace(): string
    {
        return $this->workspace;
    }

    /**
     * Everything in the index, in the order Git reported it.
     *
     * @return list<StagedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Every staged path, including both halves of a rename.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->files as $file) {
            foreach ($file->paths() as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The workspace changes staging deliberately left out.
     *
     * @return list<ExcludedChange>
     */
    public function excluded(): array
    {
        return $this->excluded;
    }

    /**
     * Whether there is anything to commit.
     */
    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    public function count(): int
    {
        return count($this->files);
    }

    /**
     * Added lines across the staged files, binary files contributing nothing.
     */
    public function insertions(): int
    {
        return $this->total(static fn (StagedFile $file): ?int => $file->insertions());
    }

    /**
     * Removed lines across the staged files.
     */
    public function deletions(): int
    {
        return $this->total(static fn (StagedFile $file): ?int => $file->deletions());
    }

    /**
     * One line for a log header or a status table.
     */
    public function summary(): string
    {
        if ($this->isEmpty()) {
            $nothing = sprintf('%s has nothing staged', $this->workspace);

            return $this->excluded === []
                ? $nothing
                : sprintf('%s (%d change(s) excluded)', $nothing, count($this->excluded));
        }

        $line = sprintf(
            '%d file(s) staged in %s, +%d -%d',
            $this->count(),
            $this->workspace,
            $this->insertions(),
            $this->deletions(),
        );

        if ($this->excluded === []) {
            return $line;
        }

        return sprintf('%s; %d change(s) excluded', $line, count($this->excluded));
    }

    /**
     * The staged files, one per line, bounded for a message.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];

        foreach (array_slice($this->files, 0, self::PATHS_IN_SUMMARY) as $file) {
            $details[] = $file->describe();
        }

        $remaining = $this->count() - self::PATHS_IN_SUMMARY;

        if ($remaining > 0) {
            $details[] = sprintf('and %d more', $remaining);
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspace,
            'files' => array_map(static fn (StagedFile $file): array => $file->toArray(), $this->files),
            'excluded' => array_map(static fn (ExcludedChange $change): array => $change->toArray(), $this->excluded),
            'count' => $this->count(),
            'insertions' => $this->insertions(),
            'deletions' => $this->deletions(),
        ];
    }

    /**
     * The statuses and paths of {@see self::NAME_STATUS_COMMAND}.
     *
     * Each entry is a status field followed by one path, or by two when the
     * status is a rename or a copy.
     *
     * @return list<array{string, string, string|null}>
     *
     * @throws InvalidArgumentException when an entry is incomplete
     */
    private static function statuses(string $output): array
    {
        $records = explode("\0", $output);
        $entries = [];
        $index = 0;

        while ($index < count($records)) {
            $status = $records[$index];
            $index++;

            // The trailing NUL of the last entry leaves an empty final record,
            // and an empty index reports nothing at all.
            if ($status === '') {
                continue;
            }

            $paths = self::isPaired($status) ? 2 : 1;
            $named = [];

            for ($taken = 0; $taken < $paths; $taken++) {
                if (!isset($records[$index]) || $records[$index] === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Git reported a staged change as "%s" without naming %s path it concerns.',
                        $status,
                        $paths === 1 ? 'the' : 'both halves of the',
                    ));
                }

                $named[] = $records[$index];
                $index++;
            }

            // A rename names where it came from first and where it went
            // second, and the second is the path the commit contains.
            $entries[] = $paths === 1
                ? [$status, $named[0], null]
                : [$status, $named[1], $named[0]];
        }

        return $entries;
    }

    /**
     * The line counts of {@see self::NUMSTAT_COMMAND}, by the path the commit
     * contains.
     *
     * @return array<string, array{int|null, int|null}>
     *
     * @throws InvalidArgumentException when an entry is not two counts and a
     *                                 path
     */
    private static function lineCounts(string $output): array
    {
        $records = explode("\0", $output);
        $counts = [];
        $index = 0;

        while ($index < count($records)) {
            $record = $records[$index];
            $index++;

            if ($record === '') {
                continue;
            }

            $fields = explode("\t", $record, 3);

            if (count($fields) !== 3) {
                throw new InvalidArgumentException(sprintf(
                    'Git reported a staged file\'s line counts in a form this cannot read: "%s".',
                    $record,
                ));
            }

            [$added, $removed, $path] = $fields;

            // Under -z a rename leaves the path field empty and follows the
            // counts with the two paths, each terminated in its own right.
            if ($path === '') {
                if (!isset($records[$index], $records[$index + 1]) || $records[$index + 1] === '') {
                    throw new InvalidArgumentException(
                        'Git reported the line counts of a renamed file without naming both halves of it.',
                    );
                }

                $path = $records[$index + 1];
                $index += 2;
            }

            $counts[$path] = [self::countOf($added, $record), self::countOf($removed, $record)];
        }

        return $counts;
    }

    /**
     * One numstat count, or null for the `-` Git prints for a binary file.
     *
     * @throws InvalidArgumentException when it is neither
     */
    private static function countOf(string $field, string $record): ?int
    {
        if ($field === self::BINARY) {
            return null;
        }

        if (preg_match('/^\d+$/', $field) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Git reported "%s" as a staged file\'s line count, in "%s".',
                $field,
                $record,
            ));
        }

        return (int) $field;
    }

    private static function isPaired(string $status): bool
    {
        return in_array($status[0], self::PAIRED_STATUSES, true);
    }

    /**
     * @param callable(StagedFile): ?int $of
     */
    private function total(callable $of): int
    {
        $total = 0;

        foreach ($this->files as $file) {
            $total += $of($file) ?? 0;
        }

        return $total;
    }
}
