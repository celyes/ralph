<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * The branch a pass at a task owns, and where its name came from.
 *
 * The second half is the part that cannot be worked out later, which is why
 * this is a value rather than a string. A pass being continued after an
 * interruption must use the branch it already has, not the one its description
 * and the current configuration would produce now: `git.branch_prefix` may have
 * been edited between the two runs, and a run that regenerated the name would
 * quietly start a second branch while the first one still held the commit.
 * So a name is either {@see self::generated()} — this run named it, nothing is
 * yet recorded — or {@see self::retained()}, read back from the generation's row
 * exactly as stored.
 *
 * The distinction is the same shape as {@see TaskWorktree}'s created/adopted
 * pair and exists for the same reason: the runner needs to know whether it is
 * starting something or continuing something, and the two cases are
 * indistinguishable by the time you are only holding the result.
 *
 * It does not say whether the branch exists in Git. A generated name usually
 * names no branch yet — it is created when there is a commit to put on it —
 * and a retained one usually does. Asking the repository is
 * {@see TaskWorktrees}' and the runner's job; this is what to ask about.
 */
final class TaskBranch
{
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly BranchName $name,
        private readonly bool $generated,
    ) {
    }

    /**
     * A name this run produced for a pass that has none recorded.
     */
    public static function generated(TaskGeneration $generation, BranchName $name): self
    {
        return new self($generation, $name, true);
    }

    /**
     * The name a pass already owns, as it was recorded.
     */
    public static function retained(TaskGeneration $generation, BranchName $name): self
    {
        return new self($generation, $name, false);
    }

    /**
     * The pass the branch belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * The branch itself.
     */
    public function name(): BranchName
    {
        return $this->name;
    }

    /**
     * The name as Git holds it, for the common case of needing only that.
     */
    public function value(): string
    {
        return $this->name->value();
    }

    /**
     * Whether this run named the branch.
     */
    public function wasGenerated(): bool
    {
        return $this->generated;
    }

    /**
     * Whether the name came from the pass's existing record.
     */
    public function wasRetained(): bool
    {
        return !$this->generated;
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s on %s (%s)',
            $this->generation->label(),
            $this->name->value(),
            $this->generated ? 'named by this run' : 'owned from an earlier run',
        );
    }

    public function __toString(): string
    {
        return $this->name->value();
    }
}
