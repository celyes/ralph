<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Tasks\GenerationHistory;
use Celyes\RalphPhp\Tasks\RecordedGeneration;
use InvalidArgumentException;

/**
 * Decides whether a pass may use the branch it is named after, and refuses when
 * the branch is somebody else's (PROJECT.md section F, item 4).
 *
 * {@see BranchNamer} produces a name; this asks who holds it. The two are
 * separate because a name is derived — from the task, the generation and the
 * configured prefix — and a derived name can be produced twice without anybody
 * having made a mistake: by a pass being resumed, by a second pass at a
 * reopened task, and by a person who names branches `ralph/…` as well. So a
 * branch that already exists is not evidence that it is the pass's, and the
 * three convenient responses to finding one are all destructive.
 * `git branch --force` moves someone's branch off its commit, `git reset --hard`
 * discards the tree it pointed at, `git branch -D` removes the only reference
 * to it. Each of them can destroy work a person has and the run has never seen,
 * so none of them is reachable from here: the whole class produces a
 * {@see BranchOwnership} reading, and {@see self::require()} turns an unusable
 * one into a {@see BranchException}.
 *
 * Ownership is decided from three sources, in this order, because they fail
 * independently:
 *
 *  1. **The other passes' rows.** A name another generation records is taken
 *     even before Git has a branch under it — that pass's commit is going
 *     there. Asked first, since it needs no repository at all.
 *  2. **The repository's references.** A branch nothing recorded is a person's,
 *     whatever it is called.
 *  3. **The pass's own row, against the branch's history.** A branch the pass
 *     records is the pass's only while it still contains what the row says is
 *     on it. A tip that does not contain the pass's commit — or, before it has
 *     one, does not descend from the base commit the run resolved — is a
 *     different branch wearing the same name, and continuing on it would commit
 *     onto a stranger's history.
 *
 * A branch another worktree has checked out is refused as well. Git allows a
 * branch in one worktree at a time, so this would surface eventually as a
 * failed `git switch` with the work already done; refused here it costs nothing
 * and names the directory holding it.
 *
 * Every command is a {@see Repository::query()}, so deciding ownership cannot
 * take the index lock of a repository the user has open, and nothing here
 * writes a reference.
 */
final class TaskBranches
{
    /**
     * The command that reads a branch's tip.
     *
     * The fully qualified `refs/heads/<name>` is deliberate: a branch called
     * `main` and a tag called `main` are both `main` to `git rev-parse`, and
     * asking about the wrong one is how a run would decide a branch is free
     * because the tag was deleted. `--verify` makes a missing branch a failure
     * rather than output echoing the name back, `--quiet` keeps Git's own
     * complaint off the terminal, and `--end-of-options` keeps a name from
     * being read as an option — which {@see BranchName} already refuses, making
     * this the second lock on the same door.
     *
     * @var list<string>
     */
    private const array TIP_COMMAND = ['rev-parse', '--verify', '--quiet', '--end-of-options'];

    /**
     * The command that answers whether one commit is in another's history.
     *
     * Exits 0 for yes and 1 for no, which is why {@see self::contains()} reads
     * the status rather than any output. Anything else means Git could not
     * answer — a commit that has been garbage-collected reports 128 — and is
     * raised rather than read as "no".
     *
     * @var list<string>
     */
    private const array ANCESTRY_COMMAND = ['merge-base', '--is-ancestor'];

    /**
     * Git's exit status for a reference that simply is not there.
     *
     * The same distinction {@see BaseResolver} draws, and for the same reason:
     * with `--quiet` there is nothing to show a user in this case, while any
     * other nonzero status has a reason worth quoting.
     */
    private const int REFERENCE_NOT_FOUND = 1;

    /**
     * Git's exit status for "no, it is not an ancestor".
     */
    private const int NOT_AN_ANCESTOR = 1;

    public function __construct(private readonly Repository $git)
    {
    }

    /**
     * The branches of the repository this configuration describes.
     *
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     *
     * @throws InvalidArgumentException when the configured project root is not
     *                                 a directory that exists
     */
    public static function forProject(
        Configuration $configuration,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(Repository::at($configuration->projectRoot(), $executable, $processes));
    }

    /**
     * Read who holds the branch, without judging it.
     *
     * For `status` and the diagnostics, which report a branch that is not the
     * pass's rather than stopping because of one.
     *
     * @param GenerationRecord|null $record  the pass's own row, when it has one;
     *        the only thing that can make an existing branch the pass's
     * @param GenerationHistory|null $history every recorded pass, so a name
     *        another one has reserved is recognised before the branch exists
     *
     * @throws BranchException when the repository cannot be asked
     */
    public function inspect(
        TaskBranch $branch,
        ?GenerationRecord $record = null,
        ?GenerationHistory $history = null,
    ): BranchOwnership {
        $owner = $this->recordedOwner($branch, $history);

        if ($owner !== null) {
            return BranchOwnership::unrelated(
                $branch,
                sprintf('is recorded as the branch of %s', $owner),
                $this->tipOf($branch),
                $this->worktreeHolding($branch),
                $owner,
            );
        }

        $tip = $this->tipOf($branch);

        if ($tip === null) {
            // Nothing holds the name. A pass whose recorded branch has gone is
            // here too, and is not refused: that is a branch to re-create, not
            // somebody else's work. See BranchOwnership::available().
            return BranchOwnership::available($branch);
        }

        $worktree = $this->worktreeHolding($branch);

        if ($record === null || !$record->ownsBranch($branch->value())) {
            return BranchOwnership::unrelated(
                $branch,
                'already exists and no pass at this task records it',
                $tip,
                $worktree,
            );
        }

        $expected = $record->commitSha() ?? $record->baseSha();

        if ($tip !== $expected && !$this->contains($branch, $expected)) {
            return BranchOwnership::unrelated(
                $branch,
                sprintf(
                    'no longer contains %s, which the pass records as %s, so it is not the branch the pass wrote',
                    substr($expected, 0, BranchOwnership::SHORT_LENGTH),
                    $record->commitSha() === null ? 'its base commit' : 'its commit',
                ),
                $tip,
                $worktree,
            );
        }

        if ($worktree !== null && !$record->worktree()->isAt($worktree->path())) {
            return BranchOwnership::unrelated(
                $branch,
                sprintf('is checked out in %s, which is not the pass\'s workspace', $worktree->path()),
                $tip,
                $worktree,
            );
        }

        return BranchOwnership::ours($branch, $tip, $worktree);
    }

    /**
     * Read who holds the branch, and refuse the run if it is not the pass's.
     *
     * Returns the same reading as {@see self::inspect()} when the pass may use
     * the branch, so a caller that records what it found — the tip it is
     * continuing from, the worktree holding it — has it without asking twice.
     *
     * @throws BranchException when the branch belongs to unrelated work, or the
     *                        repository cannot be asked
     */
    public function require(
        TaskBranch $branch,
        ?GenerationRecord $record = null,
        ?GenerationHistory $history = null,
    ): BranchOwnership {
        $ownership = $this->inspect($branch, $record, $history);

        if (!$ownership->isUsable()) {
            throw new BranchException(
                $ownership->summary() . '.',
                [...$ownership->details(), ...$ownership->hints()],
                $ownership,
            );
        }

        return $ownership;
    }

    /**
     * The commit a branch points at, or null when the repository has no such
     * branch.
     *
     * @throws BranchException when Git could not answer
     */
    public function tipOf(TaskBranch $branch): ?string
    {
        $result = $this->git->query(...[...self::TIP_COMMAND, $branch->name()->reference()]);

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        if ($result->exitCode() === self::REFERENCE_NOT_FOUND) {
            return null;
        }

        throw $this->unanswered(
            sprintf('whether %s exists', $branch->value()),
            $result,
            sprintf('git rev-parse --verify %s', $branch->name()->reference()),
        );
    }

    /**
     * Every worktree Git knows about in this repository.
     *
     * Asked here rather than taken from {@see TaskWorktrees}, which asks the
     * same question earlier in a run: the task's own workspace is created
     * between the two, so a list from before that is already out of date about
     * the one worktree this most needs to recognise.
     *
     * @throws BranchException when Git cannot answer
     */
    public function worktrees(): WorktreeList
    {
        $result = $this->git->query(...WorktreeList::COMMAND);

        if (!$result->succeeded()) {
            throw $this->unanswered('which worktrees hold which branches', $result, 'git worktree list');
        }

        return WorktreeList::parse($result->standardOutput());
    }

    /**
     * The worktree with the branch checked out, when one has it.
     *
     * @throws BranchException when Git cannot answer
     */
    private function worktreeHolding(TaskBranch $branch): ?RegisteredWorktree
    {
        return $this->worktrees()->forBranch($branch->value());
    }

    /**
     * The recorded pass holding this name, when one other than this pass's
     * does.
     *
     * Compared by name, exactly — {@see RecordedGeneration::ownsBranch()} —
     * because Git references are case-sensitive and a near-match is a different
     * branch. A row belonging to the pass itself is skipped rather than
     * reported, which is what makes a resumed pass's own branch its own.
     */
    private function recordedOwner(TaskBranch $branch, ?GenerationHistory $history): ?string
    {
        if ($history === null) {
            return null;
        }

        foreach ($history->records() as $recorded) {
            if ($recorded->generation()->equals($branch->generation())) {
                continue;
            }

            if ($recorded->ownsBranch($branch->value())) {
                return $recorded->generation()->label();
            }
        }

        return null;
    }

    /**
     * Whether the branch's history contains a commit.
     *
     * @throws BranchException when Git could not answer, which includes a
     *                        recorded commit the repository no longer has
     */
    private function contains(TaskBranch $branch, string $commit): bool
    {
        $result = $this->git->query(
            ...[...self::ANCESTRY_COMMAND, '--end-of-options', $commit, $branch->name()->reference()],
        );

        if ($result->succeeded()) {
            return true;
        }

        if ($result->exitCode() === self::NOT_AN_ANCESTOR) {
            return false;
        }

        throw $this->unanswered(
            sprintf('whether %s is still on %s', substr($commit, 0, BranchOwnership::SHORT_LENGTH), $branch->value()),
            $result,
            sprintf('git merge-base --is-ancestor %s %s', $commit, $branch->name()->reference()),
        );
    }

    /**
     * A question Git did not answer.
     *
     * Refused rather than guessed, in the one direction that cannot lose work:
     * a run that does not know whose a branch is must not commit to it, and
     * must not take it either.
     */
    private function unanswered(string $question, ProcessResult $result, string $command): BranchException
    {
        return new BranchException(
            sprintf('%s could not be determined in %s: %s', $question, $this->git->workingTree(), $result->failureReason()),
            [
                'Ralph PHP will not use a branch it could not establish the ownership of.',
                sprintf('Run "%s" in %s to see what Git reports.', $command, $this->git->workingTree()),
            ],
        );
    }
}
