<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * What the repository and the state store said about a task's branch, and
 * whether the pass may use it.
 *
 * The reading, not the decision to act on it: {@see TaskBranches} turns an
 * unusable one into a refusal, and nothing here can reset, move or delete a
 * branch. That is the same division {@see CheckoutInspection} and
 * {@see ControllingCheckout} make, for the same reason — a value that cannot
 * express `git branch --force` cannot be the place where it is accidentally
 * reached for.
 *
 * Both halves of the evidence are kept whatever the verdict. The tip is kept
 * because it is the one thing that lets a user find the work the run refused to
 * touch — `git log <tip>` is the whole remedy — and because a run that recorded
 * only "branch was not ours" could not later say which commit it meant. The
 * worktree is kept because a branch Git has checked out somewhere cannot be
 * committed to from anywhere else, which is a different problem from the same
 * verdict with a different remedy.
 *
 * {@see self::owner()} names a *recorded* pass, not a person: it is set only
 * when another generation's row in the state store already holds the name, a
 * claim that is true before the branch exists and therefore checked before the
 * repository is asked at all.
 */
final class BranchOwnership
{
    /**
     * Characters of a commit id shown when one is quoted to a person.
     *
     * The same width {@see BaseCommit::SHORT_LENGTH} uses, so a SHA printed by
     * this class and one printed beside it in status output are comparable at a
     * glance.
     */
    public const int SHORT_LENGTH = BaseCommit::SHORT_LENGTH;

    /**
     * @param string|null            $tip          the commit the branch points at, null when there is no such branch
     * @param RegisteredWorktree|null $checkedOutIn the worktree holding the branch, when one does
     * @param string|null            $reason       why the branch is not the pass's, phrased to follow "it …"
     * @param string|null            $owner        the recorded pass that holds the name, when another one does
     */
    private function __construct(
        private readonly TaskBranch $branch,
        private readonly BranchClaim $claim,
        private readonly ?string $tip,
        private readonly ?RegisteredWorktree $checkedOutIn,
        private readonly ?string $reason,
        private readonly ?string $owner,
    ) {
    }

    /**
     * Nothing holds the name: the pass may create the branch.
     *
     * Covers two situations a run cannot tell apart and does not need to — a
     * first pass whose branch has never existed, and a pass whose recorded
     * branch is not in the repository. Neither is somebody else's work, and the
     * second is reconciliation's business rather than ownership's (PROJECT.md
     * section F, item 9 and section H, item 5), which is why
     * {@see self::details()} reports it instead of refusing it.
     */
    public static function available(TaskBranch $branch): self
    {
        return new self($branch, BranchClaim::Available, null, null, null, null);
    }

    /**
     * The branch is the pass's own, as its row records it.
     */
    public static function ours(TaskBranch $branch, string $tip, ?RegisteredWorktree $checkedOutIn = null): self
    {
        return new self($branch, BranchClaim::Ours, $tip, $checkedOutIn, null, null);
    }

    /**
     * The branch belongs to something other than this pass.
     *
     * @param string $reason phrased to follow "it …", because it is read inside
     *        a sentence naming the branch
     */
    public static function unrelated(
        TaskBranch $branch,
        string $reason,
        ?string $tip = null,
        ?RegisteredWorktree $checkedOutIn = null,
        ?string $owner = null,
    ): self {
        return new self($branch, BranchClaim::Unrelated, $tip, $checkedOutIn, $reason, $owner);
    }

    /**
     * The branch the reading is about.
     */
    public function branch(): TaskBranch
    {
        return $this->branch;
    }

    /**
     * The branch's name, for the common case of needing only that.
     */
    public function name(): string
    {
        return $this->branch->value();
    }

    /**
     * The verdict.
     */
    public function claim(): BranchClaim
    {
        return $this->claim;
    }

    /**
     * Whether the pass may commit on the branch.
     */
    public function isUsable(): bool
    {
        return $this->claim->isUsable();
    }

    public function isAvailable(): bool
    {
        return $this->claim === BranchClaim::Available;
    }

    public function isOurs(): bool
    {
        return $this->claim === BranchClaim::Ours;
    }

    public function isUnrelated(): bool
    {
        return $this->claim === BranchClaim::Unrelated;
    }

    /**
     * Whether the repository holds a branch under the name.
     */
    public function exists(): bool
    {
        return $this->tip !== null;
    }

    /**
     * The commit the branch points at, when there is a branch.
     */
    public function tip(): ?string
    {
        return $this->tip;
    }

    /**
     * The worktree that has the branch checked out, when one has.
     */
    public function checkedOutIn(): ?RegisteredWorktree
    {
        return $this->checkedOutIn;
    }

    /**
     * The recorded pass holding the name, when another pass does.
     */
    public function owner(): ?string
    {
        return $this->owner;
    }

    /**
     * Why the branch is not the pass's, phrased to follow "it …".
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * One line saying what was found.
     */
    public function summary(): string
    {
        return match ($this->claim) {
            BranchClaim::Available => sprintf(
                '%s is available for %s',
                $this->name(),
                $this->branch->generation()->label(),
            ),
            BranchClaim::Ours => sprintf(
                '%s is the branch %s already owns, at %s',
                $this->name(),
                $this->branch->generation()->label(),
                $this->shortTip(),
            ),
            BranchClaim::Unrelated => sprintf(
                '%s is not the branch of %s to use: it %s',
                $this->name(),
                $this->branch->generation()->label(),
                (string) $this->reason,
            ),
        };
    }

    /**
     * The evidence, one item per line.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];

        if ($this->tip !== null) {
            $details[] = sprintf('Tip: %s.', $this->shortTip());
        } else {
            $details[] = sprintf('No branch named %s exists in the repository.', $this->name());
        }

        if ($this->owner !== null) {
            $details[] = sprintf('Recorded as the branch of %s.', $this->owner);
        }

        if ($this->checkedOutIn !== null) {
            $details[] = sprintf('Checked out in %s.', $this->checkedOutIn->path());
        }

        $details[] = sprintf(
            'The name was %s.',
            $this->branch->wasGenerated() ? 'generated by this run' : 'read back from the pass\'s own record',
        );

        return $details;
    }

    /**
     * What the user can do about it.
     *
     * Every remedy is theirs to carry out. The package says what it will not do
     * first, because a user who expects a tool to take a branch it wants has to
     * be told that this one does not before the rest of the list makes sense.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isUsable()) {
            return [];
        }

        $hints = [
            'Ralph PHP will not reset, move or delete a branch it does not own: a commit on it is '
                . 'work somebody has, and reusing the name would put it out of reach.',
        ];

        if ($this->owner !== null) {
            $hints[] = sprintf(
                '%s is where that pass\'s work lives; retry or inspect that pass rather than reusing its branch.',
                $this->owner,
            );
        }

        if ($this->checkedOutIn !== null) {
            $hints[] = sprintf(
                'Git allows a branch in one worktree at a time, and %s has it; switch that worktree to '
                    . 'something else to free the name.',
                $this->checkedOutIn->path(),
            );
        }

        if ($this->tip !== null) {
            $hints[] = sprintf('Run "git log --oneline -1 %s" to see what is on it.', $this->name());
        }

        $hints[] = 'Rename or remove the branch yourself, or set a different "git.branch_prefix", then '
            . 'run the command again.';

        return $hints;
    }

    /**
     * One phrase for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf('%s: %s', $this->name(), $this->claim->describe());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->name(),
            'generation' => $this->branch->generation()->slug(),
            'claim' => $this->claim->value,
            'usable' => $this->isUsable(),
            'exists' => $this->exists(),
            'tip' => $this->tip,
            'checked_out_in' => $this->checkedOutIn?->path(),
            'owner' => $this->owner,
            'reason' => $this->reason,
        ];
    }

    /**
     * The tip shortened for reading, never for passing to Git.
     */
    private function shortTip(): string
    {
        return $this->tip === null ? '' : substr($this->tip, 0, self::SHORT_LENGTH);
    }
}
