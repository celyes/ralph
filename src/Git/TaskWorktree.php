<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\State\WorktreeOwnership;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use InvalidArgumentException;

/**
 * A prepared task workspace: which pass it belongs to, where it is, what commit
 * it was created from, and whether this run made it.
 *
 * The last of those is the one that cannot be recovered later. A directory at
 * `.ralph/runtime/worktrees/TASK-19E0214A` looks identical whether this run
 * created it a second ago or an interrupted run left it there yesterday with
 * uncommitted work in it, and the two must be cleaned up differently
 * (PROJECT.md section F, item 10). So ownership is settled at the moment the
 * workspace is prepared and carried rather than inferred —
 * {@see WorktreeOwnership} is the same distinction as stored, and
 * {@see self::ownership()} is what the runner writes to the generation's row.
 *
 * {@see self::base()} is the commit the workspace was *created* from, which for
 * an adopted worktree is not necessarily what is checked out in it now: the
 * pass that created it may have committed since. Reconciling that difference is
 * recovery's job (PROJECT.md section H, item 5); this records what the run
 * intended so that there is something to reconcile against.
 */
final class TaskWorktree
{
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly string $path,
        private readonly BaseCommit $base,
        private readonly bool $created,
    ) {
    }

    /**
     * A workspace this run created, and may therefore remove.
     *
     * @throws InvalidArgumentException when the path says nothing
     */
    public static function created(TaskGeneration $generation, string $path, BaseCommit $base): self
    {
        return new self($generation, self::location($path), $base, true);
    }

    /**
     * A workspace that was already there and is being continued in.
     *
     * Not this run's to delete, whoever left it: an interrupted earlier run or
     * a user who prepared the directory by hand.
     *
     * @throws InvalidArgumentException when the path says nothing
     */
    public static function adopted(TaskGeneration $generation, string $path, BaseCommit $base): self
    {
        return new self($generation, self::location($path), $base, false);
    }

    /**
     * The pass this workspace belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * The absolute directory the task is implemented in.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The commit the workspace was created from.
     */
    public function base(): BaseCommit
    {
        return $this->base;
    }

    /**
     * Whether this run created the workspace.
     */
    public function wasCreated(): bool
    {
        return $this->created;
    }

    /**
     * Whether this run found the workspace already prepared.
     */
    public function wasAdopted(): bool
    {
        return !$this->created;
    }

    /**
     * The two columns the generation's row records.
     */
    public function ownership(): WorktreeOwnership
    {
        return $this->created
            ? WorktreeOwnership::created($this->path)
            : WorktreeOwnership::adopted($this->path);
    }

    /**
     * The handle an adapter is given to run the agent in.
     *
     * Built here rather than taken in, because the invariant
     * {@see Workspace} enforces — an absolute directory that exists — is true
     * precisely once the workspace has been prepared, which is what this value
     * means.
     *
     * @throws InvalidArgumentException when the directory has gone since it was
     *                                 prepared
     */
    public function workspace(): Workspace
    {
        return Workspace::at($this->path);
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s in %s, from %s (%s)',
            $this->generation->label(),
            $this->path,
            $this->base->describe(),
            $this->created ? 'created by this run' : 'already present, left in place',
        );
    }

    /**
     * @throws InvalidArgumentException when the path names nothing
     */
    private static function location(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A task workspace must name a directory.');
        }

        return $trimmed;
    }
}
