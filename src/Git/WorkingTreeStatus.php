<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * Everything uncommitted in a working tree, as Git last reported it.
 *
 * Read from `status --porcelain=v1 -z`, which is the one form of the command
 * with a stable contract: `--porcelain` fixes the format against Git's own
 * configuration — `status.short`, `status.branch`, `color.status` and a
 * localised build all change the human output and none of them change this —
 * and `-z` removes the quoting. Without `-z`, a path containing a space, a
 * quote or a newline is printed inside quotes with escapes, and a parser that
 * forgot to undo that would read one unusual filename as two paths or as none.
 * Under `-z` every path is verbatim and terminated by a NUL, the one byte a
 * path cannot contain.
 *
 * Two details of the request matter as much as the format:
 *
 *  - `--untracked-files=normal` is passed explicitly, because a repository
 *    configured with `status.showUntrackedFiles=no` would otherwise report a
 *    checkout full of new files as clean.
 *  - Ignored files are *not* asked for. A path the project has chosen to ignore
 *    is not work that can be lost, and Ralph PHP's own runtime directory is
 *    ignored by the rules `ralph init` writes.
 *
 * This type only reads. Deciding which of these changes stands in the way of a
 * run is {@see CheckoutInspection}'s job, because that question needs to know
 * which files belong to the package.
 */
final class WorkingTreeStatus
{
    /**
     * The arguments that produce the format this class parses.
     *
     * Declared here, beside the parser, so that the request and the reading of
     * it cannot drift apart.
     *
     * @var list<string>
     */
    public const array COMMAND = ['status', '--porcelain=v1', '-z', '--untracked-files=normal'];

    /**
     * Characters whose presence on either side means a second path follows.
     *
     * @var list<string>
     */
    private const array PAIRED_STATUSES = ['R', 'C'];

    /**
     * @param list<WorkingTreeChange> $changes in the order Git reported them
     */
    private function __construct(private readonly array $changes)
    {
    }

    /**
     * Read the output of {@see self::COMMAND}.
     *
     * Malformed output raises rather than being skipped. A record this cannot
     * read is a record whose path is unknown, and treating it as absent would
     * let the inspection call a checkout clean while something uncommitted sat
     * in it — the one mistake this whole path exists to prevent. Git producing
     * output of a shape this does not recognise should be impossible; failing
     * loudly is what makes it stay that way.
     *
     * @throws InvalidArgumentException when a record is not a status pair and a
     *                                 path, or a rename arrives without the
     *                                 path it came from
     */
    public static function parse(string $output): self
    {
        $records = explode("\0", $output);
        $changes = [];
        $index = 0;

        while ($index < count($records)) {
            $record = $records[$index];
            $index++;

            // The trailing NUL of the last entry leaves an empty final record,
            // and a clean working tree reports nothing at all.
            if ($record === '') {
                continue;
            }

            if (strlen($record) < 4 || substr($record, 2, 1) !== ' ') {
                throw new InvalidArgumentException(sprintf(
                    'Git reported a working tree change this cannot read: "%s".',
                    $record,
                ));
            }

            $indexStatus = $record[0];
            $worktreeStatus = $record[1];
            $path = substr($record, 3);
            $originalPath = null;

            if (self::isPaired($indexStatus) || self::isPaired($worktreeStatus)) {
                if (!isset($records[$index]) || $records[$index] === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Git reported "%s" as renamed or copied without saying where it came from.',
                        $path,
                    ));
                }

                $originalPath = $records[$index];
                $index++;
            }

            $changes[] = WorkingTreeChange::of($indexStatus, $worktreeStatus, $path, $originalPath);
        }

        return new self($changes);
    }

    /**
     * @return list<WorkingTreeChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function isClean(): bool
    {
        return $this->changes === [];
    }

    public function count(): int
    {
        return count($this->changes);
    }

    private static function isPaired(string $status): bool
    {
        return in_array($status, self::PAIRED_STATUSES, true);
    }
}
