<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * Someone else changed the task checklist while Ralph PHP was ticking a box in
 * it, so the write was refused and their edit is still there.
 *
 * PROJECT.md section H, item 8. A checkbox write is read-modify-write: the file
 * is read, one marker in those bytes is rewritten, and the result is put back.
 * Every byte of the document the package did not mean to touch therefore comes
 * from the read — so if the file moved in between, putting the result back
 * would silently restore the file to how it looked before the user's edit and
 * lose it. The document is the user's own record of what they want done and
 * they may well have it open in an editor, so the write is checked against the
 * content it was derived from and abandoned when that content is no longer what
 * is on disk.
 *
 * **Nothing is written when this is raised, and nothing is undone.** The
 * checklist holds exactly the bytes that were found, the implementation and its
 * commit are already durable, and the planned write stays outstanding — it is
 * retried against the file as it now reads, which is why this is kept apart
 * from {@see ChecklistException}. That one means the plan itself no longer
 * makes sense, because the task was reworded or removed; this one means the
 * plan is fine and the moment was wrong.
 *
 * Kept apart from {@see FilesystemException} for the opposite reason: that is
 * the environment refusing a write the package is entitled to make, and the
 * remedy is `chmod`. Here the write could have been made and should not have
 * been.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "look at a file
 * and run the command again", until section I, item 5 defines the blocking
 * status.
 */
final class ChecklistConflictException extends RalphException
{
    /**
     * @param string $path the checklist that changed, as named to the caller
     * @param string $expected content hash the write was derived from
     * @param string|null $found content hash the file holds now, or null when it
     *        could not be read back at all
     * @param list<string> $hints
     */
    public function __construct(
        string $message,
        private readonly string $path,
        private readonly string $expected,
        private readonly ?string $found,
        private readonly array $hints = [],
    ) {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return ExitCode::CONFIGURATION;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * The checklist whose contents moved.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The content hash the refused write was derived from.
     */
    public function expected(): string
    {
        return $this->expected;
    }

    /**
     * The content hash on disk instead, or null when the file could not be read.
     */
    public function found(): ?string
    {
        return $this->found;
    }
}
