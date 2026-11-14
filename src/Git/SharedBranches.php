<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\BranchStrategy;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\RunRecord;

/**
 * Establishes the one branch a shared-branch run commits to, once per run, and
 * answers whether a pass may commit to it (PROJECT.md section K, item 3).
 *
 * In {@see BranchStrategy::PerTask} mode a branch is a pass's: it is named
 * after the task, created from the run's base commit, and {@see TaskBranches}
 * decides ownership from the generation's own row. In
 * {@see BranchStrategy::Shared} mode none of that holds. One branch carries the
 * whole run, it is established before the first task rather than named by it,
 * and the question "may this be used?" is asked twice at different levels:
 *
 *  - **Once per run,** by {@see self::require()}, before any task is taken on.
 *    This is the half that must not be per task: a run that re-decided the
 *    branch for every task would create a second one the moment a setting
 *    changed underneath it, and a run that validated nothing up front would
 *    find somebody else's commits only after an agent had spent its time. What
 *    it finds is recorded on the run
 *    ({@see \Celyes\RalphPhp\State\RunStore::recordSharedBranch()}), which is
 *    what makes a resume continue on the branch holding the commits rather than
 *    on the branch today's configuration names.
 *  - **Once per pass,** by {@see self::forPass()}, which produces the
 *    {@see BranchOwnership} the committer takes. The run-level reading says the
 *    branch is the run's; this says the *workspace in hand* may be committed
 *    onto it, which is a different question and the one that protects the
 *    commits already there.
 *
 * **Nothing here moves, resets or deletes a reference**, the same restraint
 * {@see TaskBranches} is built around and for the same reason: every
 * convenient way of continuing on a branch holding unaccounted-for commits
 * destroys them. A run that cannot establish its branch stops instead.
 *
 * Every repository command is a {@see Repository::query()}, so establishing the
 * branch cannot take the index lock of a checkout the user has open.
 */
final class SharedBranches
{
    public function __construct(
        private readonly TaskBranches $branches,
        private readonly GenerationStore $generations,
        private readonly WorktreeLayout $layout,
    ) {
    }

    /**
     * The shared-branch reader for a project.
     *
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     */
    public static function forProject(
        Configuration $configuration,
        GenerationStore $generations,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(
            TaskBranches::forProject($configuration, $executable, $processes),
            $generations,
            WorktreeLayout::forProject($configuration),
        );
    }

    /**
     * Read what is under the run's shared branch, without judging it.
     *
     * For `status` and the diagnostics, which report a branch the run could not
     * use rather than stopping because of one.
     *
     * Four answers, in the order the questions can be asked without the
     * repository being consulted needlessly:
     *
     *  1. **No such branch** — the run will create it with its first commit.
     *  2. **Held by a worktree this project does not own** — refused, because
     *     Git allows a branch in one worktree at a time and the task workspaces
     *     are where this run's commits are made.
     *  3. **At the run's base commit** — the branch exists and holds nothing
     *     the run has not already got; continued.
     *  4. **At a commit this project recorded** — the package put it there,
     *     whether earlier in this run or in an earlier one; continued.
     *
     * Anything else is a commit nobody wrote a row for: somebody's own work, or
     * a reference the state store has lost track of. Refused, with the tip kept
     * so that `git log` is the whole remedy.
     *
     * @throws BranchException        when the repository cannot be asked, or the
     *                                run names no shared branch at all
     * @throws ConfigurationException when the configured branch is one Git
     *                                would refuse
     * @throws StateException         when the run's recorded branch is one Git
     *                                could not hold
     */
    public function establish(RunRecord $run, BaseCommit $base): SharedBranch
    {
        $established = $run->hasEstablishedSharedBranch();
        $name = $this->nameFor($run);
        $tip = $this->branches->tipOfName($name);

        if ($tip === null) {
            return SharedBranch::available($name, $established);
        }

        $worktree = $this->branches->worktrees()->forBranch($name->value());

        if ($worktree !== null && !$this->layout->contains($worktree->path())) {
            return SharedBranch::unrelated(
                $name,
                sprintf('is checked out in %s, which is not a workspace of this run', $worktree->path()),
                $tip,
                $worktree,
                $established,
            );
        }

        if ($tip === $base->sha()) {
            return SharedBranch::continuing($name, $tip, null, $worktree, $established);
        }

        $recorded = $this->generations->atCommit($tip);

        if ($recorded !== null) {
            return SharedBranch::continuing($name, $tip, $recorded->slug(), $worktree, $established);
        }

        return SharedBranch::unrelated(
            $name,
            sprintf(
                'is at %s, which is neither the base commit of this run (%s) nor a commit this project recorded',
                substr($tip, 0, BranchOwnership::SHORT_LENGTH),
                $base->shortSha(),
            ),
            $tip,
            $worktree,
            $established,
        );
    }

    /**
     * Establish the branch, and refuse the run when it cannot be used.
     *
     * @throws BranchException        when the branch holds work this project did
     *                                not put there, or the repository cannot be
     *                                asked
     * @throws ConfigurationException when the configured branch is one Git
     *                                would refuse
     * @throws StateException         when the run's recorded branch is one Git
     *                                could not hold
     */
    public function require(RunRecord $run, BaseCommit $base): SharedBranch
    {
        $shared = $this->establish($run, $base);

        if (!$shared->isUsable()) {
            throw new BranchException(
                $shared->summary() . '.',
                [...$shared->details(), ...$shared->hints()],
            );
        }

        return $shared;
    }

    /**
     * The commit a task taken on now starts its workspace from.
     *
     * The carry-forward of PROJECT.md section K, item 4, and the whole of it
     * that is a decision: a task in shared-branch mode is checked out at the
     * tip of the run's branch rather than at the run's base commit, so the
     * work earlier tasks committed is *in* the workspace the agent is given and
     * a later task can build on it. Per-task mode has no such reader, which is
     * the independence PROJECT.md section 2 describes.
     *
     * Read per task rather than once per run, because the answer moves: it is
     * the previous task's commit for the second task, and that task's for the
     * third. The run-level reading is still the one that decides whether the
     * branch may be used at all ({@see self::require()}), and it is made again
     * here, so a branch somebody moved between two tasks of the same run stops
     * the run before an agent is given a workspace built on it.
     *
     * The run's own base is returned unchanged while the branch does not exist
     * or is still at that commit — the first task of a run, and every task of a
     * run whose earlier tasks committed nothing. That keeps `git.base` as the
     * reference such a pass records, rather than relabelling the same commit
     * with a branch name it was not resolved from.
     *
     * @throws BranchException        when the branch holds work this project did
     *                                not put there, or the repository cannot be
     *                                asked
     * @throws ConfigurationException when the configured branch is one Git
     *                                would refuse
     * @throws StateException         when the run's recorded branch is one Git
     *                                could not hold
     */
    public function continuationOf(RunRecord $run, BaseCommit $base): BaseCommit
    {
        $tip = $this->require($run, $base)->tip();

        if ($tip === null || $tip === $base->sha()) {
            return $base;
        }

        return BaseCommit::of($this->nameFor($run)->value(), $tip);
    }

    /**
     * Whether the pass in hand may commit onto the run's shared branch.
     *
     * The reading is a {@see BranchOwnership} because that is what
     * {@see TaskCommitter} takes, and because the per-task and shared paths must
     * hand it the same thing: what the strategy changes is who the branch
     * belongs to, not how a commit is made.
     *
     * Two refusals matter here, and neither of them is about the name:
     *
     *  - **A pass with a commit** keeps the branch only while the branch still
     *    contains that commit. The same rule {@see TaskBranches} applies to a
     *    task branch, and it is what makes resume safe: a branch someone moved
     *    off the pass's commit is a different branch wearing the name.
     *  - **A pass without one** may commit only when the branch is where its
     *    workspace starts. A task's workspace is made from the commit its row
     *    records as its base, which in this mode is the branch tip the pass
     *    started from ({@see self::continuationOf()}), so the ordinary case is
     *    that the two agree and the commit extends the branch. A branch that
     *    has moved on *since* — a newer run committed to it, or an interrupted
     *    pass is being resumed after one did — cannot take this commit: Git
     *    would write it on top of the workspace's own history and move the
     *    branch to it, leaving the commits in between unreachable.
     *
     * What is *not* refused here, and was until the carry-forward of section K,
     * item 4: a branch another pass's row also holds. That is what a shared
     * branch is — one branch, a commit per task, every task's row naming it —
     * and the state store is told as much when the branch is claimed
     * ({@see \Celyes\RalphPhp\State\GenerationStore::assignBranch()}), so
     * the index that keeps a reopened task from resetting a per-task branch
     * still holds everywhere else.
     *
     * @throws BranchException when the repository cannot be asked
     */
    public function forPass(
        SharedBranch $shared,
        TaskBranch $branch,
        GenerationRecord $record,
    ): BranchOwnership {
        if ($branch->value() !== $shared->value()) {
            return BranchOwnership::unrelated(
                $branch,
                sprintf('is not the branch this run established, which is %s', $shared->value()),
                $this->branches->tipOfName($branch->name()),
                $this->branches->worktrees()->forBranch($branch->value()),
                null,
                [sprintf(
                    'Run `ralph status` to see the branch of this run, then start a new run to commit to %s.',
                    $branch->value(),
                )],
            );
        }

        $name = $branch->name();
        $tip = $this->branches->tipOfName($name);

        if ($tip === null) {
            // The branch was established as one to create, or has been removed
            // since. Neither is somebody else's work, and the first commit of
            // the pass creates it — the same reading TaskBranches gives a
            // missing branch, for the same reason.
            return BranchOwnership::available($branch);
        }

        $worktree = $this->branches->worktrees()->forBranch($name->value());

        if ($worktree !== null && !$record->worktree()->isAt($worktree->path())) {
            return BranchOwnership::unrelated(
                $branch,
                sprintf('is checked out in %s, which is not the pass\'s workspace', $worktree->path()),
                $tip,
                $worktree,
            );
        }

        $commit = $record->commitSha();

        if ($commit !== null) {
            if ($tip === $commit || $this->branches->nameContains($name, $commit)) {
                return BranchOwnership::ours($branch, $tip, $worktree);
            }

            return BranchOwnership::unrelated(
                $branch,
                sprintf(
                    'no longer contains %s, which the pass records as its commit, so it is not the branch the pass wrote',
                    substr($commit, 0, BranchOwnership::SHORT_LENGTH),
                ),
                $tip,
                $worktree,
            );
        }

        if ($tip === $record->baseSha()) {
            // The branch is exactly where this workspace starts, so the commit
            // extends it and loses nothing — whether the tip is the run's base
            // commit or the commit an earlier task of the run put there, which
            // is the carry-forward of section K, item 4. That an earlier pass's
            // row also names the branch is expected in this mode and is not
            // asked about: the claim records the branch as shared, and the
            // store's one-branch-one-generation index is partial on exactly
            // that.
            return BranchOwnership::ours($branch, $tip, $worktree);
        }

        return BranchOwnership::unrelated(
            $branch,
            sprintf(
                'is at %s, and this task\'s workspace was made from %s, so a commit here would leave %s '
                    . 'unreachable',
                substr($tip, 0, BranchOwnership::SHORT_LENGTH),
                substr($record->baseSha(), 0, BranchOwnership::SHORT_LENGTH),
                substr($tip, 0, BranchOwnership::SHORT_LENGTH),
            ),
            $tip,
            $worktree,
            $this->generations->atCommit($tip)?->slug(),
            [
                sprintf(
                    'A task\'s workspace is made from the tip of %s as it stood when the task was taken '
                        . 'on, so the branch has moved on since — a newer run committed to it, or this pass '
                        . 'is being resumed after one did.',
                    $shared->value(),
                ),
                sprintf(
                    'The commits on %s are intact and nothing was written. Review "git log %s", then start '
                        . 'a new run so the task\'s workspace is made from the branch tip.',
                    $shared->value(),
                    $shared->value(),
                ),
            ],
        );
    }

    /**
     * The pass's reading, refused as a {@see BranchException} when unusable.
     *
     * @throws BranchException when the pass may not commit onto the shared
     *                        branch, or the repository cannot be asked
     */
    public function requireForPass(
        SharedBranch $shared,
        TaskBranch $branch,
        GenerationRecord $record,
    ): BranchOwnership {
        $ownership = $this->forPass($shared, $branch, $record);

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
     * The branch the run commits to, as a name Git could hold.
     *
     * The run's record first and the run's frozen configuration second — which
     * is {@see RunRecord::sharedBranch()}'s own order and the whole point of
     * the column. An unusable name is reported against whichever of the two it
     * came from, because they have different remedies: a configured one is a
     * setting to correct, and a recorded one is a row this package did not
     * write, which must not be quietly replaced with a generated name while the
     * branch it names may hold the run's commits.
     *
     * @throws BranchException        when the run names no shared branch
     * @throws ConfigurationException when the configured branch is one Git
     *                                would refuse
     * @throws StateException         when the recorded branch is one Git could
     *                                not hold
     */
    private function nameFor(RunRecord $run): BranchName
    {
        $recorded = $run->establishedSharedBranch();
        $name = $run->sharedBranch();

        if ($name === null) {
            throw new BranchException(
                sprintf('Run %s records no branch for its tasks to share.', $run->runId()),
                [
                    sprintf(
                        'Its recorded strategy is "%s"; only a "%s" run commits every task to one branch.',
                        $run->branchStrategy()->value,
                        BranchStrategy::Shared->value,
                    ),
                    'Set "git.strategy" and, if the derived name is not wanted, "git.shared_branch" in '
                        . '.ralph/config.json, then start a new run.',
                ],
            );
        }

        $problem = BranchName::problemWith($name);

        if ($problem === null) {
            return BranchName::fromString($name);
        }

        if ($recorded !== null) {
            throw new StateException(
                sprintf(
                    'Run %s records the shared branch "%s", which Git could not hold: it %s.',
                    $run->runId(),
                    $recorded,
                    $problem,
                ),
                [
                    'Ralph PHP will not move the run onto a different branch, because the recorded one may '
                        . 'hold its commits.',
                    'Check "git branch --list" for the run\'s branch, then start a new run with a branch '
                        . 'Git can hold.',
                ],
            );
        }

        throw new ConfigurationException(
            sprintf(
                'Run %s is configured to commit every task to the branch "%s", and Git would refuse it: it %s.',
                $run->runId(),
                $name,
                $problem,
            ),
            [
                'Correct "git.shared_branch" — or "git.branch_prefix", which the name is derived from when '
                    . '"git.shared_branch" is absent — in .ralph/config.json, then run the command again.',
            ],
        );
    }
}
