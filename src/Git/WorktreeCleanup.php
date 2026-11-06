<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * What cleanup actually did to one task workspace.
 *
 * {@see WorkspaceRetention} is the decision; this is the event. They are
 * separate types because they can disagree in the one direction that matters:
 * a workspace judged removable that Git then declined to remove is reported as
 * kept, with Git's own words, and a caller that only had the decision would
 * state that a directory had gone when it had not.
 *
 * Four outcomes, and the distinction between the first two is worth keeping.
 * Removing a workspace deletes files; clearing a stale registration deletes
 * nothing at all — the directory had already gone and only Git's
 * administrative record was left, which is the leftover that makes
 * `git worktree add` refuse the path next time. Reporting them the same way
 * would mean a run that tidied up after a `rm -rf` somebody else did claimed to
 * have deleted their files.
 *
 * Returned rather than raised, in every case. Cleanup runs after a pass has
 * been verified, committed and published, so nothing it discovers is a reason
 * to withdraw any of that; the outcome is a line in the log and a row in
 * `status`, and a user decides what to do about a directory that stayed.
 */
final class WorktreeCleanup
{
    private function __construct(
        private readonly WorkspaceRetention $retention,
        private readonly bool $removed,
        private readonly bool $registrationOnly,
    ) {
    }

    /**
     * The workspace was removed: directory, registration and the recorded path.
     *
     * @throws InvalidArgumentException when the decision did not allow it,
     *                                 which would mean something removed a
     *                                 directory this package had judged
     *                                 unremovable
     */
    public static function removed(WorkspaceRetention $retention): self
    {
        if (!$retention->isRemovable()) {
            throw new InvalidArgumentException(sprintf(
                'A workspace reported as removed must have been removable; %s was %s.',
                $retention->label(),
                $retention->verdict()->value,
            ));
        }

        return new self($retention, true, false);
    }

    /**
     * Only Git's record of a workspace whose directory had already gone was
     * cleared.
     *
     * Nothing was deleted. A missing directory cannot hold work, which is what
     * makes this the one case cleanup acts on without reading the workspace
     * first — there is nothing there to read.
     *
     * @throws InvalidArgumentException when the decision did not allow it
     */
    public static function cleared(WorkspaceRetention $retention): self
    {
        if (!$retention->isRemovable()) {
            throw new InvalidArgumentException(sprintf(
                'A stale registration reported as cleared must have been removable; %s was %s.',
                $retention->label(),
                $retention->verdict()->value,
            ));
        }

        return new self($retention, true, true);
    }

    /**
     * The workspace stays, for the reasons the decision carries.
     *
     * @throws InvalidArgumentException when the decision gave no reason to keep
     *                                 it, since a workspace cannot be kept for
     *                                 no stated reason
     */
    public static function kept(WorkspaceRetention $retention): self
    {
        if (!$retention->isPreserved()) {
            throw new InvalidArgumentException(sprintf(
                'A workspace reported as kept must say why; %s was %s.',
                $retention->label(),
                $retention->verdict()->value,
            ));
        }

        return new self($retention, false, false);
    }

    /**
     * There was no workspace to do anything with.
     *
     * @throws InvalidArgumentException when the decision found one
     */
    public static function nothing(WorkspaceRetention $retention): self
    {
        if (!$retention->isAbsent()) {
            throw new InvalidArgumentException(sprintf(
                'A cleanup that did nothing must have found no workspace; %s was %s.',
                $retention->label(),
                $retention->verdict()->value,
            ));
        }

        return new self($retention, false, false);
    }

    /**
     * The decision behind this, including any reason discovered while acting.
     */
    public function retention(): WorkspaceRetention
    {
        return $this->retention;
    }

    public function label(): string
    {
        return $this->retention->label();
    }

    public function path(): ?string
    {
        return $this->retention->path();
    }

    /**
     * Whether the workspace is gone from both the filesystem and Git.
     */
    public function wasRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * Whether only a stale registration was cleared, with no file deleted.
     */
    public function clearedRegistrationOnly(): bool
    {
        return $this->registrationOnly;
    }

    /**
     * Whether a workspace is still on disk because cleanup left it there.
     */
    public function wasKept(): bool
    {
        return $this->retention->isPreserved();
    }

    /**
     * Whether a person is expected to look at what was left behind.
     */
    public function isForInspection(): bool
    {
        return $this->retention->isForInspection();
    }

    /**
     * One line for the attempt's log and the terminal.
     */
    public function describe(): string
    {
        $path = $this->retention->path();

        return match (true) {
            $this->registrationOnly => sprintf(
                'cleanup: %s had already gone; Git\'s record of it was cleared',
                $path ?? 'the workspace',
            ),
            $this->removed => sprintf('cleanup: removed %s', $path ?? 'the workspace'),
            default => 'cleanup: ' . $this->retention->describe(),
        };
    }

    /**
     * A line per reason the workspace was kept; empty when it was not.
     *
     * @return list<string>
     */
    public function details(): array
    {
        return $this->retention->details();
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->retention->hints();
    }

    /**
     * The event as `status --json` reports it.
     *
     * @return array{
     *     removed: bool,
     *     registration_only: bool,
     *     retention: array{
     *         generation: string,
     *         path: string|null,
     *         verdict: string,
     *         for_inspection: bool,
     *         reasons: array<string, string>,
     *     },
     * }
     */
    public function toArray(): array
    {
        return [
            'removed' => $this->removed,
            'registration_only' => $this->registrationOnly,
            'retention' => $this->retention->toArray(),
        ];
    }
}
