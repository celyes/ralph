<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use InvalidArgumentException;

/**
 * What a wipe actually did to one pass's log files.
 *
 * The event, where {@see LogRetentionReason} is the decision — the same split
 * {@see \Celyes\RalphPhp\Git\WorktreeCleanup} keeps from
 * {@see \Celyes\RalphPhp\Git\WorkspaceRetention}, and for the same reason: a
 * deletion that was wanted and a deletion that happened are not the same fact,
 * and a caller holding only the first would tell a user that files had gone
 * when a permission had kept them.
 *
 * Three outcomes.
 *
 *  - **Finished.** Every eligible log of the pass has gone, including the case
 *    where there were none to begin with. Only this one is written to the row,
 *    and {@see self::isComplete()} is what the wiper asks before it does.
 *  - **Part-way.** Something was deleted and something was not. The row is left
 *    alone, so the pass still reads as owing a wipe and the next look at it
 *    finishes the job.
 *  - **Kept.** Nothing was touched, with the reason saying which condition was
 *    not met.
 *
 * Returned rather than raised, in every case. A wipe runs after a task's
 * commit, its push, its row and the user's checklist are all durable, so
 * nothing it discovers is a reason to withdraw any of that; what it found is a
 * line in the run's output and, through the row, the answer `ralph logs` gives
 * about a file that is not there.
 */
final class LogWipe
{
    /**
     * @param list<string> $deleted paths that have gone, in the order they went
     * @param list<string> $left    eligible paths still on disk
     */
    private function __construct(
        private readonly array $deleted,
        private readonly array $left,
        private readonly ?LogRetentionReason $reason,
        private readonly bool $directoryRemoved,
    ) {
    }

    /**
     * Every eligible log of the pass has gone.
     *
     * @param list<string> $deleted may be empty: a pass whose logs were already
     *        wiped, or one that never wrote any, is finished rather than
     *        outstanding, and treating it as outstanding would have every later
     *        run look at the same empty directory again
     * @param bool $directoryRemoved whether the generation's log directory was
     *        itself removed, which happens only when the wipe left it empty
     */
    public static function wiped(array $deleted, bool $directoryRemoved = false): self
    {
        return new self($deleted, [], null, $directoryRemoved);
    }

    /**
     * Some of the pass's logs have gone and some are still there.
     *
     * @param list<string> $deleted
     * @param list<string> $left    at least one, or this would be
     *        {@see self::wiped()}
     *
     * @throws InvalidArgumentException when nothing was left, since a wipe that
     *                                 left nothing behind is a finished wipe
     *                                 and recording it as part-way would make
     *                                 every later run retry a deletion with
     *                                 nothing to delete
     */
    public static function partial(array $deleted, array $left): self
    {
        if ($left === []) {
            throw new InvalidArgumentException(
                'A part-way wipe names what it could not delete; nothing was left.',
            );
        }

        return new self($deleted, $left, LogRetentionReason::Undeletable, false);
    }

    /**
     * Nothing was deleted, and the reason says why not.
     */
    public static function kept(LogRetentionReason $reason): self
    {
        return new self([], [], $reason, false);
    }

    /**
     * The logs that have gone.
     *
     * @return list<string>
     */
    public function deleted(): array
    {
        return $this->deleted;
    }

    /**
     * The eligible logs still on disk.
     *
     * @return list<string>
     */
    public function left(): array
    {
        return $this->left;
    }

    /**
     * How many files this wipe deleted.
     */
    public function count(): int
    {
        return count($this->deleted);
    }

    /**
     * Why nothing was deleted, or why not everything was; null when the wipe
     * finished.
     */
    public function reason(): ?LogRetentionReason
    {
        return $this->reason;
    }

    /**
     * Whether a file was actually removed.
     *
     * Kept apart from {@see self::isComplete()}, because the two disagree in
     * both directions: a pass whose logs were wiped by an earlier run is
     * complete and removed nothing, and a wipe stopped by one unreadable file
     * removed something and is not complete.
     */
    public function removedAnything(): bool
    {
        return $this->deleted !== [];
    }

    /**
     * Whether the generation's own log directory was removed as well.
     *
     * Only ever true when the wipe emptied it. Nothing here deletes a directory
     * that still holds something — the completion record, a preserved diff and
     * the no-change evidence all live beside the logs and all outlive them.
     */
    public function removedDirectory(): bool
    {
        return $this->directoryRemoved;
    }

    /**
     * Whether no eligible log of this pass is left.
     *
     * The question the wiper asks before it writes the timestamp to the row:
     * the column means "there is nothing of this pass's own left to delete",
     * and anything short of that stays outstanding so a later look finishes it.
     */
    public function isComplete(): bool
    {
        return $this->left === [];
    }

    /**
     * One line for the run's output.
     */
    public function describe(): string
    {
        if ($this->reason === LogRetentionReason::Undeletable) {
            return sprintf(
                'logs: %s removed, %s kept — %s (%s)',
                $this->files(count($this->deleted)),
                $this->files(count($this->left)),
                $this->reason->describe(),
                implode(', ', array_map('basename', $this->left)),
            );
        }

        if ($this->reason !== null) {
            return 'logs: kept — ' . $this->reason->describe();
        }

        if ($this->deleted === []) {
            return 'logs: nothing left to wipe';
        }

        return sprintf(
            'logs: %s wiped after the task succeeded%s',
            $this->files(count($this->deleted)),
            $this->directoryRemoved ? ', and the empty directory removed' : '',
        );
    }

    /**
     * "1 log file", "3 log files".
     */
    private function files(int $count): string
    {
        return sprintf('%d log file%s', $count, $count === 1 ? '' : 's');
    }
}
