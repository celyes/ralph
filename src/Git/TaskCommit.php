<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * The commit a pass produced: its SHA, the branch it landed on, what it
 * contains, and the row that now records it.
 *
 * Returned by {@see TaskCommitter} once both halves of PROJECT.md section F,
 * item 7 are true — the commit is in the repository *and* its SHA is persisted
 * — so holding one of these is the evidence that a task has an implementation
 * to be finalised. Everything a caller needs next is on it: `ralph status` and
 * the JSONL events of section I report the SHA and the branch, the push of
 * item 8 needs the branch and the row, and finalisation needs the row to decide
 * whether the box may be ticked.
 *
 * Two of the fields are here because they cannot be recovered afterwards.
 * {@see self::parent()} is what the workspace's HEAD was before the commit:
 * once the commit exists its parent is in the object database, but a *second*
 * run cannot tell whether that parent was the base this run resolved or
 * something a worker committed on top of it, which is the comparison
 * PROJECT.md section F, item 9 makes. And {@see self::wasBranchCreated()} is
 * the created/adopted distinction {@see TaskWorktree} and {@see TaskBranch}
 * draw, at the moment it stops being a question: a branch this commit created
 * is one a cleanup may remove if the pass is abandoned, and a branch it
 * continued is not.
 *
 * {@see self::changes()} is the staged summary from before the commit rather
 * than a diff read back from it. They describe the same thing, but only the
 * first also says what was deliberately left out, which is the part the
 * repository no longer knows.
 */
final class TaskCommit
{
    /**
     * Characters of a commit id shown when one is quoted to a person.
     */
    public const int SHORT_LENGTH = BaseCommit::SHORT_LENGTH;

    /**
     * @param string $sha    the full object name of the commit
     * @param string $parent the commit the workspace's HEAD was at before it
     */
    public function __construct(
        private readonly GenerationRecord $recorded,
        private readonly string $sha,
        private readonly string $parent,
        private readonly TaskBranch $branch,
        private readonly bool $branchCreated,
        private readonly CommitMessage $message,
        private readonly StagedChanges $changes,
    ) {
    }

    /**
     * The pass the commit belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->recorded->generation();
    }

    /**
     * The pass's row, as it stands with the commit recorded on it.
     *
     * Carried rather than left to be read again, because the write has already
     * happened by the time this value exists and a second reading could only
     * disagree with it.
     */
    public function recorded(): GenerationRecord
    {
        return $this->recorded;
    }

    /**
     * The commit's full object name.
     */
    public function sha(): string
    {
        return $this->sha;
    }

    /**
     * The commit as it is quoted to a person.
     */
    public function shortSha(): string
    {
        return substr($this->sha, 0, self::SHORT_LENGTH);
    }

    /**
     * What the workspace's HEAD was before the commit was made.
     */
    public function parent(): string
    {
        return $this->parent;
    }

    /**
     * The branch the commit is on.
     */
    public function branch(): TaskBranch
    {
        return $this->branch;
    }

    /**
     * Whether making this commit is what created the branch.
     */
    public function wasBranchCreated(): bool
    {
        return $this->branchCreated;
    }

    /**
     * The message the commit carries, identifiers included.
     */
    public function message(): CommitMessage
    {
        return $this->message;
    }

    /**
     * What was staged into it, and what was kept out.
     */
    public function changes(): StagedChanges
    {
        return $this->changes;
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s committed %s on %s (%s): %s',
            $this->generation()->label(),
            $this->shortSha(),
            $this->branch->value(),
            $this->branchCreated ? 'branch created by this commit' : 'existing branch',
            $this->message->subject(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation()->slug(),
            'sha' => $this->sha,
            'parent' => $this->parent,
            'branch' => $this->branch->value(),
            'branch_created' => $this->branchCreated,
            'message' => $this->message->toArray(),
            'changes' => $this->changes->toArray(),
        ];
    }
}
