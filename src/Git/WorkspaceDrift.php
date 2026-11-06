<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * One difference between the repository as a worker was handed it and the
 * repository as the worker left it.
 *
 * A finding, not a decision and not an action: {@see DriftKind} says whether
 * the kind of difference can be put back, {@see WorkspaceAudit} collects them,
 * and {@see WorkspaceAuditor} is the only thing that may act. The same division
 * {@see BranchOwnership} and {@see TaskBranches} make, for the same reason — a
 * value that cannot express `git reset --hard` is not a place where one can be
 * accidentally reached for.
 *
 * Every finding carries the SHAs it is about, in full, and that is the detail
 * worth being deliberate about. A pass that blocks here leaves a user looking
 * for commits whose only remaining name may be in this message: a soft reset
 * takes HEAD off them, a branch this refuses to touch may be days from being
 * garbage-collected, and "the worker committed something" without a SHA is not
 * recoverable advice. So the subject of a finding is always something that can
 * be pasted after `git log`.
 *
 * Factories rather than a public constructor, one per thing that can be found,
 * so that a finding's kind and the sentence describing it are written in the
 * same place and cannot drift apart.
 */
final class WorkspaceDrift
{
    /**
     * Characters of a commit id shown when one is quoted to a person. The same
     * width as everywhere else a SHA is printed, so two lines of a report are
     * comparable at a glance.
     */
    public const int SHORT_LENGTH = BaseCommit::SHORT_LENGTH;

    /**
     * @param string       $summary  one sentence, phrased to stand alone in a list
     * @param list<string> $commits  SHAs this finding is about, in full
     * @param string|null  $branch   the branch concerned, when one is
     */
    private function __construct(
        private readonly DriftKind $kind,
        private readonly string $summary,
        private readonly array $commits,
        private readonly ?string $branch,
    ) {
        if (trim($summary) === '') {
            throw new InvalidArgumentException('A workspace finding must say what was found.');
        }
    }

    /**
     * The worker committed on top of the commit the workspace was handed.
     *
     * @param int $count how many commits were added, counted rather than
     *        assumed: a worker that committed eleven times is a different
     *        conversation from one that committed once
     */
    public static function commitsAdded(string $from, string $to, int $count): self
    {
        return new self(
            DriftKind::CommitsAdded,
            sprintf(
                'the worker left %d commit%s in the workspace, moving it from %s to %s',
                $count,
                $count === 1 ? '' : 's',
                self::brief($from),
                self::brief($to),
            ),
            [$from, $to],
            null,
        );
    }

    /**
     * HEAD is on a commit that does not descend from the one the workspace
     * started at.
     */
    public static function headMoved(string $from, string $to): self
    {
        return new self(
            DriftKind::HeadMoved,
            sprintf(
                'the workspace is on %s, which does not descend from %s, the commit it was handed',
                self::brief($to),
                self::brief($from),
            ),
            [$from, $to],
            null,
        );
    }

    /**
     * The workspace was put on the pass's own branch.
     */
    public static function branchAttached(string $branch, string $tip): self
    {
        return new self(
            DriftKind::BranchAttached,
            sprintf(
                'the workspace was attached to %s, the pass\'s own branch, at %s',
                $branch,
                self::brief($tip),
            ),
            [$tip],
            $branch,
        );
    }

    /**
     * A branch under the pass's own name appeared, where the pass's commit was
     * going to go.
     */
    public static function branchClaimed(string $branch, string $tip): self
    {
        return new self(
            DriftKind::BranchClaimed,
            sprintf(
                '%s, the pass\'s own branch, was created at %s, the commit the workspace was handed',
                $branch,
                self::brief($tip),
            ),
            [$tip],
            $branch,
        );
    }

    /**
     * The workspace is on a branch that is not the pass's.
     */
    public static function foreignBranch(string $branch, string $expected, string $tip): self
    {
        return new self(
            DriftKind::ForeignBranch,
            sprintf(
                'the workspace is on %s at %s, which is not the pass\'s branch %s',
                $branch,
                self::brief($tip),
                $expected,
            ),
            [$tip],
            $branch,
        );
    }

    /**
     * The workspace was on the pass's branch and is now attached to nothing.
     */
    public static function headDetached(string $branch, string $head): self
    {
        return new self(
            DriftKind::HeadDetached,
            sprintf(
                'the workspace was on %s and is now detached at %s',
                $branch,
                self::brief($head),
            ),
            [$head],
            $branch,
        );
    }

    /**
     * A branch appeared that this pass does not account for.
     */
    public static function branchCreated(string $branch, string $tip): self
    {
        return new self(
            DriftKind::BranchCreated,
            sprintf('the branch %s was created at %s', $branch, self::brief($tip)),
            [$tip],
            $branch,
        );
    }

    /**
     * An existing branch's tip changed.
     */
    public static function branchMoved(string $branch, string $from, string $to): self
    {
        return new self(
            DriftKind::BranchMoved,
            sprintf(
                'the branch %s moved from %s to %s',
                $branch,
                self::brief($from),
                self::brief($to),
            ),
            [$from, $to],
            $branch,
        );
    }

    /**
     * A branch that existed before the worker ran is gone.
     */
    public static function branchDeleted(string $branch, string $tip): self
    {
        return new self(
            DriftKind::BranchDeleted,
            sprintf('the branch %s was deleted; it was at %s', $branch, self::brief($tip)),
            [$tip],
            $branch,
        );
    }

    /**
     * The workspace is in the middle of a Git operation.
     *
     * @param string $operation phrased to follow "in the middle of …"
     */
    public static function operationInProgress(string $operation, string $head): self
    {
        return new self(
            DriftKind::OperationInProgress,
            sprintf(
                'the workspace is in the middle of %s, with HEAD at %s',
                $operation,
                self::brief($head),
            ),
            [$head],
            null,
        );
    }

    public function kind(): DriftKind
    {
        return $this->kind;
    }

    /**
     * One sentence saying what was found, phrased to stand alone in a list.
     */
    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * Whether this package may put this difference back by itself.
     */
    public function isReconcilable(): bool
    {
        return $this->kind->isReconcilable();
    }

    /**
     * Whether finding this stops the pass.
     */
    public function isBlocking(): bool
    {
        return $this->kind->isBlocking();
    }

    /**
     * The branch this finding concerns, when it concerns one.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * Every commit this finding names, in full, so that what a refusal leaves
     * behind can be found again.
     *
     * @return list<string>
     */
    public function commits(): array
    {
        return $this->commits;
    }

    /**
     * The Git command that shows what this finding is about.
     *
     * Part of the finding rather than of the message, because the right command
     * depends on what was found: a moved branch is read with `git log` on the
     * branch, a commit nothing points at any more with `git show` on its SHA.
     */
    public function inspectionCommand(): string
    {
        $last = $this->commits === [] ? null : $this->commits[count($this->commits) - 1];

        if ($this->branch !== null && $this->kind !== DriftKind::BranchDeleted) {
            return sprintf('git log --oneline -5 %s', $this->branch);
        }

        return $last === null ? 'git log --oneline -5' : sprintf('git log --oneline -5 %s', $last);
    }

    /**
     * One line for a log or a status table.
     */
    public function describe(): string
    {
        return sprintf('%s: %s', $this->kind->value, $this->summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'reconcilable' => $this->isReconcilable(),
            'branch' => $this->branch,
            'commits' => $this->commits,
        ];
    }

    /**
     * A SHA shortened for reading, never for passing to Git.
     */
    private static function brief(string $sha): string
    {
        return substr($sha, 0, self::SHORT_LENGTH);
    }
}
