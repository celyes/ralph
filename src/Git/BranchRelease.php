<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * What cleanup did about a kept workspace holding the run's shared branch
 * (PROJECT.md section K, item 9).
 *
 * In per-task mode a workspace that stays behind keeps a branch nobody else
 * wants: the branch is the pass's own, and a retry continuing in that very
 * directory is the only thing that will ever commit to it. In **shared-branch
 * mode the branch belongs to the run**, and Git lets one branch be checked out
 * in one worktree at a time — so a workspace kept for inspection after its
 * commit holds the branch the *next* task has to commit to, and that task
 * stops before it starts:
 *
 * ```
 * ralph/shared is not the branch of TASK-FBE89BE5 to use: it is checked out in
 * …/worktrees/TASK-4794E74E, which is not the pass's workspace.
 * ```
 *
 * The answer is to detach the kept workspace's HEAD. It is the narrowest thing
 * that works and the only one that loses nothing: `git switch --detach` with no
 * start point moves HEAD from `refs/heads/<branch>` to the commit it already
 * points at, so **no file is written, no index entry is dropped, and the branch
 * stays exactly where it is** — the work being kept for a person to look at is
 * still there, and the commits the branch holds are still on it. What goes is
 * only Git's record that *this* worktree is the one using the name.
 *
 * Three readings, because all three have to be reportable:
 *
 *  - **Unnecessary.** Nothing to release — per-task mode, a workspace that was
 *    removed, or one whose HEAD was already detached. Says nothing in a log.
 *  - **Released.** The workspace was on the shared branch and now is not, with
 *    the branch named and its tip recorded so the line a user reads proves the
 *    commits survived.
 *  - **Refused.** The attempt did not happen or did not hold. Reported and not
 *    raised, like every other cleanup outcome: the pass it belongs to has
 *    already been verified, committed and published, and the consequence is
 *    that the next task will stop on the branch — which is a thing to say in
 *    the log rather than a reason to withdraw finished work.
 *
 * @see WorktreeCleaner::release() for the conditions that have to hold first
 */
final class BranchRelease
{
    private function __construct(
        private readonly ?string $branch,
        private readonly ?string $tip,
        private readonly ?string $refusal,
    ) {
    }

    /**
     * There was no branch to release.
     */
    public static function unnecessary(): self
    {
        return new self(null, null, null);
    }

    /**
     * The workspace was detached, and the branch still holds its commit.
     *
     * @param string $tip the commit the branch is at, read back *after* the
     *        detach: the whole promise of this operation is that the commits
     *        outlive the worktree, and a tip taken before the command would be
     *        a record of what was meant to happen rather than of what did
     *
     * @throws InvalidArgumentException when the branch or the commit is unnamed
     */
    public static function released(string $branch, string $tip): self
    {
        return new self(self::required($branch, 'branch'), self::required($tip, 'commit'), null);
    }

    /**
     * The workspace still holds the branch, for the stated reason.
     *
     * @throws InvalidArgumentException when the branch or the reason is empty
     */
    public static function refused(string $branch, string $reason): self
    {
        return new self(
            self::required($branch, 'branch'),
            null,
            self::required($reason, 'reason'),
        );
    }

    /**
     * Whether a branch was actually let go of.
     */
    public function happened(): bool
    {
        return $this->branch !== null && $this->refusal === null;
    }

    /**
     * Whether the workspace is still holding the branch when it should not be.
     */
    public function wasRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * The branch this is about, or null when there was none.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * The commit the released branch is at, or null when nothing was released.
     */
    public function tip(): ?string
    {
        return $this->tip;
    }

    /**
     * Why the workspace still holds the branch, or null when it does not.
     */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * One line for a log, or null when there is nothing worth saying.
     */
    public function describe(): ?string
    {
        if ($this->branch === null) {
            return null;
        }

        if ($this->refusal !== null) {
            return sprintf(
                'the workspace still holds the shared branch %s: %s',
                $this->branch,
                $this->refusal,
            );
        }

        return sprintf(
            'released the shared branch %s, still at %s, for the next task',
            $this->branch,
            substr((string) $this->tip, 0, TaskCommit::SHORT_LENGTH),
        );
    }

    /**
     * The outcome as `status --json` reports it.
     *
     * @return array{branch: string|null, tip: string|null, released: bool, refusal: string|null}
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'tip' => $this->tip,
            'released' => $this->happened(),
            'refusal' => $this->refusal,
        ];
    }

    /**
     * @throws InvalidArgumentException when the value names nothing
     */
    private static function required(string $value, string $what): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf(
                'A branch release must name the %s it is about.',
                $what,
            ));
        }

        return $trimmed;
    }
}
