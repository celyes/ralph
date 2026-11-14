<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * The one branch a shared-branch run commits to, and what was found under its
 * name when the run established it (PROJECT.md section K, item 3).
 *
 * The run-level counterpart of {@see TaskBranch} and {@see BranchOwnership},
 * and it is a separate value for one reason: in shared-branch mode the branch
 * belongs to the run and not to any pass, so there is no generation to name it
 * after, no generation to report a refusal against, and no per-task row that
 * could record it before the run's first commit. Everything a pass asks about
 * the branch afterwards is still a {@see BranchOwnership}, because that is what
 * the committer takes; this is what the run asked once, before any of it.
 *
 * Three readings, the same three {@see BranchClaim} draws, for the same reason
 * — a branch name is derived from configuration and can therefore be produced
 * by something other than this run:
 *
 *  - **Available.** Git holds no branch under the name. The run will create it
 *    with the first commit it has to put there, not before: a branch created
 *    ahead of the work would be a reference to the base commit that a stopped
 *    run left behind with nothing on it.
 *  - **Established.** The branch exists and holds either the run's base commit
 *    or a commit this project recorded. The run continues it.
 *  - **Unrelated.** The branch exists and holds something nobody recorded, or
 *    is checked out somewhere the run does not own. The run stops, with the tip
 *    kept so the user can go and look at it.
 *
 * Like {@see BranchOwnership}, this cannot express moving, resetting or
 * deleting a branch, which is what keeps those three out of the shared-branch
 * path: a run that finds somebody else's commits on the branch it was told to
 * share has to stop, because every way of continuing loses them.
 */
final class SharedBranch
{
    /**
     * @param string|null             $tip          the commit the branch points at, null when there is no such branch
     * @param RegisteredWorktree|null $checkedOutIn the worktree holding it, when one does
     * @param string|null             $reason       why the run may not use it, phrased to follow "it …"
     * @param string|null             $recordedBy   the pass whose commit the tip is, when the store knows it
     * @param bool                    $established  whether the name came off the
     *        run's own row rather than from the configuration it froze
     */
    private function __construct(
        private readonly BranchName $name,
        private readonly BranchClaim $claim,
        private readonly ?string $tip,
        private readonly ?RegisteredWorktree $checkedOutIn,
        private readonly ?string $reason,
        private readonly ?string $recordedBy,
        private readonly bool $established,
    ) {
    }

    /**
     * Nothing holds the name: the run's first commit will create the branch.
     */
    public static function available(BranchName $name, bool $established = false): self
    {
        return new self($name, BranchClaim::Available, null, null, null, null, $established);
    }

    /**
     * The branch is there and the run may continue it.
     *
     * @param string|null $recordedBy the pass whose commit the tip is, when it
     *        is one of this project's rather than the run's base commit
     */
    public static function continuing(
        BranchName $name,
        string $tip,
        ?string $recordedBy = null,
        ?RegisteredWorktree $checkedOutIn = null,
        bool $established = false,
    ): self {
        return new self($name, BranchClaim::Ours, $tip, $checkedOutIn, null, $recordedBy, $established);
    }

    /**
     * The branch holds work this project did not put there.
     */
    public static function unrelated(
        BranchName $name,
        string $reason,
        ?string $tip = null,
        ?RegisteredWorktree $checkedOutIn = null,
        bool $established = false,
    ): self {
        return new self($name, BranchClaim::Unrelated, $tip, $checkedOutIn, $reason, null, $established);
    }

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

    public function claim(): BranchClaim
    {
        return $this->claim;
    }

    /**
     * Whether the run may commit to the branch.
     */
    public function isUsable(): bool
    {
        return $this->claim->isUsable();
    }

    /**
     * Whether the branch still has to be created, which the first commit does.
     */
    public function needsCreating(): bool
    {
        return $this->claim->needsCreating();
    }

    /**
     * Whether the repository holds a branch under the name.
     */
    public function exists(): bool
    {
        return $this->tip !== null;
    }

    /**
     * The commit the branch points at, when it exists.
     */
    public function tip(): ?string
    {
        return $this->tip;
    }

    public function checkedOutIn(): ?RegisteredWorktree
    {
        return $this->checkedOutIn;
    }

    /**
     * The pass whose commit the branch's tip is, when this project recorded it.
     */
    public function recordedBy(): ?string
    {
        return $this->recordedBy;
    }

    /**
     * Why the run may not use the branch, phrased to follow "it …".
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Whether the name was read back off the run's row.
     *
     * The same distinction {@see TaskBranch::wasRetained()} draws, one level up:
     * a resumed run continues on the branch it recorded, whatever the settings
     * would name today.
     */
    public function wasEstablished(): bool
    {
        return $this->established;
    }

    /**
     * One line saying what was found.
     */
    public function summary(): string
    {
        return match ($this->claim) {
            BranchClaim::Available => sprintf('%s will be created by the first task to commit', $this->value()),
            BranchClaim::Ours => sprintf('%s is at %s, which this run continues', $this->value(), $this->shortTip()),
            BranchClaim::Unrelated => sprintf(
                '%s is not a branch this run may commit to: it %s',
                $this->value(),
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

        $details[] = $this->tip === null
            ? sprintf('No branch named %s exists in the repository.', $this->value())
            : sprintf('Tip: %s.', $this->shortTip());

        if ($this->recordedBy !== null) {
            $details[] = sprintf('That commit is recorded as the work of %s.', $this->recordedBy);
        }

        if ($this->checkedOutIn !== null) {
            $details[] = sprintf('Checked out in %s.', $this->checkedOutIn->path());
        }

        $details[] = sprintf(
            'The branch was %s.',
            $this->established
                ? 'read back from this run\'s own record'
                : 'named by the project configuration',
        );

        return $details;
    }

    /**
     * What the user can do about a branch the run may not use.
     *
     * The settings named are the ones that decide a shared branch —
     * `git.shared_branch` and, when it is absent, `git.branch_prefix` — rather
     * than the per-task advice {@see BranchOwnership::hints()} gives, because a
     * run stopped here was never naming a branch after a task.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isUsable()) {
            return [];
        }

        $hints = [
            'Ralph PHP will not reset, move or delete the shared branch: a commit on it is work '
                . 'somebody has, and committing over it would put that work out of reach.',
        ];

        if ($this->checkedOutIn !== null) {
            $hints[] = sprintf(
                'Git allows a branch in one worktree at a time, and %s has it; switch that worktree to '
                    . 'something else to free the name.',
                $this->checkedOutIn->path(),
            );
        }

        if ($this->tip !== null) {
            $hints[] = sprintf('Run "git log --oneline -5 %s" to see what is on it.', $this->value());
        }

        $hints[] = sprintf(
            'Merge, rename or remove %s yourself, or name a different branch with "git.shared_branch" '
                . 'in .ralph/config.json, then run the command again.',
            $this->value(),
        );

        return $hints;
    }

    /**
     * One phrase for a log header or the run's opening lines.
     */
    public function describe(): string
    {
        return match ($this->claim) {
            BranchClaim::Available => sprintf('%s (new)', $this->value()),
            BranchClaim::Ours => sprintf('%s (continuing %s)', $this->value(), $this->shortTip()),
            BranchClaim::Unrelated => sprintf('%s (%s)', $this->value(), $this->claim->describe()),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->value(),
            'claim' => $this->claim->value,
            'usable' => $this->isUsable(),
            'exists' => $this->exists(),
            'tip' => $this->tip,
            'checked_out_in' => $this->checkedOutIn?->path(),
            'recorded_by' => $this->recordedBy,
            'established' => $this->established,
            'reason' => $this->reason,
        ];
    }

    /**
     * The tip shortened for reading, never for passing to Git.
     */
    private function shortTip(): string
    {
        return $this->tip === null ? '' : substr($this->tip, 0, BranchOwnership::SHORT_LENGTH);
    }
}
