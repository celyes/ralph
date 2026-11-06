<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * Where a task workspace's Git state stood at one moment: what HEAD is, what
 * HEAD is attached to, where every local branch points, and whether an
 * operation is half-finished.
 *
 * Taken twice per attempt — once immediately before the agent is invoked and
 * once immediately after it returns — because PROJECT.md section F, item 9 is a
 * question about a *difference*. "Did the worker commit?" has no answer from a
 * single reading: a workspace sitting on a commit that is not the base is the
 * ordinary state of a resumed pass that committed last time, and a branch whose
 * tip is not the base commit is most of the branches in any repository. Only
 * the pair of readings says what *this worker* did.
 *
 * Four things are recorded, and each one is a way a worker can move work out of
 * the working tree, where the rest of section F expects to find it:
 *
 *  - **HEAD.** A worker that ran `git commit` has moved it, and the
 *    implementation is in a commit object rather than in uncommitted files.
 *  - **What HEAD is attached to.** A task workspace is checked out detached
 *    ({@see TaskWorktrees}), so a workspace on a branch was put there by
 *    something other than this package.
 *  - **Every local branch.** References are shared by every worktree of a
 *    repository, so this is also how a commit the worker made *elsewhere* — in
 *    the controlling checkout, onto `main` — is seen at all.
 *  - **Whether a rebase, merge, cherry-pick, revert or bisect is in progress.**
 *    A workspace in the middle of one is not in a resting state, and reading
 *    "HEAD" or "the branch" from it answers about the operation rather than
 *    about the work.
 *
 * What is deliberately *not* recorded is anything about the files: that is
 * {@see WorkingTreeStatus}'s subject, and uncommitted changes are what a worker
 * is supposed to leave behind. Nor are tags and remote-tracking references,
 * which no step of section F reads; a worker that writes one is not detected
 * here, and {@see WorkspaceAudit} says so rather than implying a completeness
 * this does not have.
 *
 * Branch tips are kept in a map sorted by name so that two readings of an
 * unchanged repository are the same value whatever order Git listed its
 * references in.
 */
final class WorkspaceState
{
    /**
     * The arguments that list every local branch and its tip.
     *
     * `%(refname)` is asked for in full rather than `%(refname:short)`, which
     * shortens a name only as far as is unambiguous: a repository holding both
     * a tag and a branch called `release` has the branch printed as
     * `heads/release`, and a parser that trusted the short form would record a
     * branch under a name no `git rev-parse refs/heads/...` would find. The
     * prefix is stripped here instead, where it is exactly one known string.
     *
     * The two fields are separated by a space and the records by a newline,
     * which is safe in the one direction it needs to be: Git refuses a
     * reference name containing a space, a newline or a control character, so
     * neither separator can appear inside a value.
     *
     * @var list<string>
     */
    public const array BRANCH_COMMAND = [
        'for-each-ref',
        '--format=%(objectname) %(refname)',
        'refs/heads/',
    ];

    /**
     * The prefix every local branch's full reference name carries.
     */
    public const string BRANCH_PREFIX = 'refs/heads/';

    /**
     * The files and directories whose presence in a worktree's Git directory
     * means an operation is half-finished, mapped to what to call it.
     *
     * Read from the worktree's own Git directory rather than the repository's
     * common one, because that is where Git keeps them: a rebase in one linked
     * worktree does not make another worktree's HEAD unreadable, and a check
     * that looked in the shared directory would report one worktree's
     * unfinished business against every other.
     *
     * `sequencer` covers the multi-commit forms — a `cherry-pick` or `revert`
     * of a range — whose `*_HEAD` file is absent between picks while the
     * operation is still unfinished.
     *
     * @var array<string, string>
     */
    public const array OPERATION_MARKERS = [
        'rebase-merge' => 'a rebase',
        'rebase-apply' => 'a rebase or an "am" session',
        'MERGE_HEAD' => 'a merge',
        'CHERRY_PICK_HEAD' => 'a cherry-pick',
        'REVERT_HEAD' => 'a revert',
        'BISECT_LOG' => 'a bisect',
        'sequencer' => 'a cherry-pick or revert of several commits',
    ];

    /**
     * @param array<string, string> $branches branch name to tip SHA, sorted by
     *        name
     * @param string|null $operation what is half-finished, phrased to follow
     *        "is in the middle of …", or null when the workspace is at rest
     */
    private function __construct(
        private readonly string $head,
        private readonly ?string $attachedBranch,
        private readonly array $branches,
        private readonly ?string $operation,
    ) {
    }

    /**
     * One reading.
     *
     * @param array<string, string> $branches branch name to tip SHA, in any
     *        order
     *
     * @throws InvalidArgumentException when HEAD names no commit, or a branch
     *                                 is recorded without a name or a tip
     */
    public static function of(
        string $head,
        ?string $attachedBranch = null,
        array $branches = [],
        ?string $operation = null,
    ): self {
        $commit = trim($head);

        if ($commit === '') {
            throw new InvalidArgumentException('A workspace state must say which commit HEAD is on.');
        }

        $branch = $attachedBranch === null ? null : trim($attachedBranch);
        $tips = [];

        foreach ($branches as $name => $tip) {
            $name = trim((string) $name);
            $tip = trim($tip);

            if ($name === '' || $tip === '') {
                throw new InvalidArgumentException(
                    'A branch in a workspace state needs both a name and a tip.',
                );
            }

            $tips[$name] = $tip;
        }

        ksort($tips);

        $pending = $operation === null ? null : trim($operation);

        return new self(
            $commit,
            $branch === '' ? null : $branch,
            $tips,
            $pending === '' ? null : $pending,
        );
    }

    /**
     * The commit HEAD is on.
     */
    public function head(): string
    {
        return $this->head;
    }

    /**
     * The branch HEAD is attached to, or null when the workspace is detached.
     */
    public function attachedBranch(): ?string
    {
        return $this->attachedBranch;
    }

    /**
     * Whether HEAD is attached to no branch, which is how a task workspace is
     * prepared.
     */
    public function isDetached(): bool
    {
        return $this->attachedBranch === null;
    }

    /**
     * Whether HEAD is attached to exactly this branch.
     *
     * Compared as text, because Git reference names are case-sensitive and a
     * near-match is a different branch.
     */
    public function isOn(string $branch): bool
    {
        return $this->attachedBranch === $branch;
    }

    /**
     * Every local branch and its tip, sorted by name.
     *
     * @return array<string, string>
     */
    public function branches(): array
    {
        return $this->branches;
    }

    /**
     * The names of every local branch, sorted.
     *
     * @return list<string>
     */
    public function branchNames(): array
    {
        return array_keys($this->branches);
    }

    /**
     * The commit a branch points at, or null when the repository has no branch
     * under that name.
     */
    public function tipOf(string $branch): ?string
    {
        return $this->branches[$branch] ?? null;
    }

    public function hasBranch(string $branch): bool
    {
        return array_key_exists($branch, $this->branches);
    }

    /**
     * What the workspace is in the middle of, phrased to follow "is in the
     * middle of …", or null when it is at rest.
     */
    public function operation(): ?string
    {
        return $this->operation;
    }

    /**
     * Whether no Git operation is half-finished.
     */
    public function isResting(): bool
    {
        return $this->operation === null;
    }

    /**
     * Whether this reading and another are the same state.
     */
    public function equals(self $other): bool
    {
        return $this->head === $other->head
            && $this->attachedBranch === $other->attachedBranch
            && $this->branches === $other->branches
            && $this->operation === $other->operation;
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s at %s%s%s',
            $this->attachedBranch ?? 'detached HEAD',
            substr($this->head, 0, BaseCommit::SHORT_LENGTH),
            sprintf(', %d local branch%s', count($this->branches), count($this->branches) === 1 ? '' : 'es'),
            $this->operation === null ? '' : sprintf(', in the middle of %s', $this->operation),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'head' => $this->head,
            'attached_branch' => $this->attachedBranch,
            'branches' => $this->branches,
            'operation' => $this->operation,
        ];
    }
}
