<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\DriftException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;

/**
 * Notices what a worker did to the repository's commits and branches, and
 * either puts it back or stops the pass (PROJECT.md section F, item 9).
 *
 * Section F hands an agent a workspace checked out detached at a known commit
 * and expects to find its work in uncommitted files: that is what
 * {@see WorkspaceStager} decides a commit from, what the verification commands
 * are run against, and what {@see TaskCommitter} turns into the one commit a
 * pass makes. A worker that commits, switches branch or starts a rebase has
 * moved the work somewhere none of those steps looks, and the failure mode
 * without this class is not an error — it is worse. Staging would find nothing,
 * the pass would be recorded as a verified no-change result (section G, item 6),
 * the box would be ticked, and the task's implementation would sit on a
 * reference nobody recorded. Which is precisely the "silently accepting them"
 * the item forbids.
 *
 * The method is a comparison, not an inspection. {@see self::observe()} is
 * called immediately before the agent is invoked and again after it returns,
 * and {@see self::audit()} compares the two {@see WorkspaceState} readings —
 * because no single reading can distinguish a worker's commit from the ordinary
 * state of a resumed pass that committed last time.
 *
 * Then exactly one of three things happens, which is what "reconcile or block"
 * means in practice:
 *
 *  - **Nothing was changed.** The ordinary case, and the one the rest of
 *    section F assumes.
 *  - **Everything found can be put back.** A `git reset --soft` to the commit
 *    the workspace was handed, which moves no file and empties no index, plus
 *    the recorded branch claim a created reference needs. The commits the
 *    worker made stay in the reflog; the work itself never left the disk.
 *  - **Something cannot.** {@see DriftException}, with the findings and the
 *    SHAs, raised before anything is staged or committed. The workspace, the
 *    references and the commits are left exactly as the worker left them.
 *
 * Nothing here forces a branch, resets a working tree or deletes a reference.
 * Those are the three convenient answers to an unexpected reference and each
 * destroys work somebody may have — the same refusal {@see TaskBranches} makes
 * about a branch it does not own, applied to the moment after a worker has run.
 * The one Git command this class can issue that writes anything is
 * `reset --soft`, and the one state store call is the branch claim.
 *
 * **Detection is bounded and the boundary is documented rather than implied.**
 * HEAD, the local branches of the whole repository, and whether an operation is
 * half-finished are covered; see {@see WorkspaceAudit} for what is not. A
 * worker determined to hide what it did can still do so, and the review a user
 * gives the resulting commit remains the thing that catches the rest.
 */
final class WorkspaceAuditor
{
    /**
     * Git's exit status for a reference that is not there, which for
     * `symbolic-ref HEAD` means a detached HEAD.
     */
    private const int NOT_A_SYMBOLIC_REF = 1;

    /**
     * Git's exit status for "no, it is not an ancestor".
     */
    private const int NOT_AN_ANCESTOR = 1;

    public function __construct(
        private readonly GenerationStore $generations,
        private readonly string $projectRoot,
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The auditor for a project.
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
        return new self($generations, $configuration->projectRoot(), $executable, $processes);
    }

    /**
     * Read where the workspace's commits and branches stand.
     *
     * Called twice per attempt, and the first call is the one that matters:
     * without a reading taken *before* the agent is invoked there is nothing to
     * compare against, and every answer afterwards is a guess. Every command is
     * a {@see Repository::query()}, so observing cannot take the index lock of
     * a repository the user has open, and nothing here writes.
     *
     * @throws WorktreeException when the workspace is not a usable task
     *                           workspace, or Git cannot answer
     */
    public function observe(TaskWorktree $worktree): WorkspaceState
    {
        $this->requireWorkspace($worktree);

        return $this->read($this->repositoryAt($worktree), $worktree->generation()->label());
    }

    /**
     * Compare what the worker was handed with what it left.
     *
     * A reading only. Nothing is repaired and nothing is refused here, so a
     * caller that reports drift — `status`, the diagnostics — has the findings
     * without the pass being stopped by the act of looking.
     *
     * @param TaskBranch $branch the pass's own branch name, which is what makes
     *        a reference the pass's rather than a stranger's
     *
     * @throws WorktreeException when the row, the workspace and the branch are
     *                           not the same pass, the workspace is unusable,
     *                           or Git cannot answer
     */
    public function audit(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        TaskBranch $branch,
        WorkspaceState $before,
    ): WorkspaceAudit {
        $this->requireOnePass($generation, $worktree, $branch);
        $this->requireWorkspace($worktree);

        $git = $this->repositoryAt($worktree);
        $after = $this->read($git, $generation->slug());

        return WorkspaceAudit::of(
            $generation->generation(),
            $worktree->path(),
            $branch->value(),
            $before,
            $after,
            $this->findings($git, $generation, $branch->value(), $before, $after),
        );
    }

    /**
     * Audit the workspace and settle it: put back what can be put back, and
     * stop the pass when something cannot.
     *
     * The call a run makes, immediately after the agent returns and before
     * anything is verified, staged or committed. The two halves are separately
     * callable so that a diagnostic can look without acting, and joined here so
     * that a runner cannot accidentally look without acting — the state of the
     * workspace the verification commands then run against is the whole point
     * of the exercise.
     *
     * @throws DriftException    when something was found that this will not put
     *                           back, or when putting it back failed
     * @throws WorktreeException when the workspace cannot be read
     */
    public function require(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        TaskBranch $branch,
        WorkspaceState $before,
        ?OutputSink $sink = null,
    ): WorkspaceRepair {
        return $this->reconcile(
            $generation,
            $worktree,
            $this->audit($generation, $worktree, $branch, $before),
            $sink,
        );
    }

    /**
     * Put back what the reading says can be put back.
     *
     * Two actions, in this order, for the reason {@see TaskCommitter} records a
     * branch before creating it: the recorded claim goes first, because a
     * reference nothing records is read as unrelated work by the next run,
     * while a record naming a branch that is where the pass expects it is
     * exactly what ownership wants to find.
     *
     *  1. **The branch claim.** A worker that created or attached the pass's
     *     own branch gets that name recorded against the pass's row.
     *  2. **`git reset --soft`.** HEAD — and the pass's branch with it, when
     *     HEAD is attached to it — goes back to the commit the workspace was
     *     handed. The index and every file on disk are untouched, so the
     *     worker's changes survive as staged modifications and the commits it
     *     made stay reachable through the reflog.
     *
     * The state is read back from Git afterwards and checked rather than
     * assumed. A repair that half worked would leave a workspace the commit
     * step refuses later, with the agent's time already spent.
     *
     * @param OutputSink|null $sink where the repair is noted as it happens —
     *        the attempt's log and the terminal. Never closed here: the caller
     *        that opened the log owns it.
     *
     * @throws DriftException    when the reading is blocking, or the repair
     *                           could not be carried out
     * @throws WorktreeException when the workspace is not the audited one, or
     *                           Git cannot answer
     */
    public function reconcile(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        WorkspaceAudit $audit,
        ?OutputSink $sink = null,
    ): WorkspaceRepair {
        $this->requireTheAuditedWorkspace($generation, $worktree, $audit);
        $this->requireWorkspace($worktree);

        if ($audit->isBlocking()) {
            throw $this->blocked($audit);
        }

        if ($audit->isClean()) {
            return WorkspaceRepair::nothingToDo($audit, $generation, $audit->after());
        }

        $git = $this->repositoryAt($worktree);
        $actions = [];
        $record = $generation;

        if ($audit->has(DriftKind::BranchAttached) || $audit->has(DriftKind::BranchClaimed)) {
            [$record, $claim] = $this->claimBranch($record, $audit, $sink);

            if ($claim !== null) {
                $actions[] = $claim;
            }
        }

        if ($audit->has(DriftKind::CommitsAdded)) {
            $actions[] = $this->restoreHead($git, $audit, $sink);
        }

        $state = $this->read($git, $record->slug());

        $this->requireRestored($audit, $state);

        return WorkspaceRepair::of($audit, $record, $state, $actions);
    }

    /**
     * Everything one reading is made of.
     *
     * @throws WorktreeException when Git cannot answer
     */
    private function read(Repository $git, string $pass): WorkspaceState
    {
        return WorkspaceState::of(
            $this->headOf($git, $pass),
            $this->attachedBranchOf($git, $pass),
            $this->branchesOf($git, $pass),
            $this->operationIn($git, $pass),
        );
    }

    /**
     * The commit the workspace is on.
     *
     * @throws WorktreeException when Git cannot answer
     */
    private function headOf(Repository $git, string $pass): string
    {
        $result = $git->query('rev-parse', '--verify', '--end-of-options', 'HEAD');

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        throw $this->unanswered($git, $pass, 'which commit its workspace is on', $result);
    }

    /**
     * The branch HEAD is attached to, or null when the workspace is detached.
     *
     * @throws WorktreeException when Git cannot answer
     */
    private function attachedBranchOf(Repository $git, string $pass): ?string
    {
        $result = $git->query('symbolic-ref', '--quiet', '--short', 'HEAD');

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        if ($result->exitCode() === self::NOT_A_SYMBOLIC_REF) {
            return null;
        }

        throw $this->unanswered($git, $pass, 'which branch its workspace is on', $result);
    }

    /**
     * Every local branch of the repository and its tip.
     *
     * Read repository-wide rather than per worktree, which is not a widening of
     * scope but the point: references are shared, so this is the only reading
     * in which a commit the worker made in the controlling checkout, or onto
     * `main`, is visible at all.
     *
     * @return array<string, string>
     *
     * @throws WorktreeException when Git cannot answer, or answers in a shape
     *                           this cannot read
     */
    private function branchesOf(Repository $git, string $pass): array
    {
        $result = $git->query(...WorkspaceState::BRANCH_COMMAND);

        if (!$result->succeeded()) {
            throw $this->unanswered($git, $pass, 'which branches its repository holds', $result);
        }

        $branches = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $result->standardOutput())) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $separator = strpos($line, ' ');

            if ($separator === false) {
                throw $this->unreadable($git, $pass, $line);
            }

            $tip = substr($line, 0, $separator);
            $reference = substr($line, $separator + 1);

            if (!str_starts_with($reference, WorkspaceState::BRANCH_PREFIX)) {
                throw $this->unreadable($git, $pass, $line);
            }

            $branches[substr($reference, strlen(WorkspaceState::BRANCH_PREFIX))] = $tip;
        }

        return $branches;
    }

    /**
     * What the workspace is in the middle of, or null when it is at rest.
     *
     * Asked of the *worktree's* own Git directory, which is where Git keeps the
     * markers: a rebase in the controlling checkout leaves `rebase-merge` under
     * the main repository's directory and nothing under a linked worktree's, so
     * a check against the shared directory would report one worktree's
     * unfinished business against every other.
     *
     * @throws WorktreeException when Git cannot say where that directory is
     */
    private function operationIn(Repository $git, string $pass): ?string
    {
        $result = $git->query('rev-parse', '--absolute-git-dir');

        if (!$result->succeeded()) {
            throw $this->unanswered($git, $pass, 'where its Git directory is', $result);
        }

        $directory = rtrim($result->trimmedOutput(), '/\\');

        foreach (WorkspaceState::OPERATION_MARKERS as $marker => $operation) {
            if (file_exists($directory . '/' . $marker)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Every difference between the two readings.
     *
     * The unfinished-operation finding comes first because it changes how
     * everything after it should be read; the rest follow the order a reader
     * would ask about them — HEAD, what HEAD is on, then the references.
     *
     * @return list<WorkspaceDrift>
     *
     * @throws WorktreeException when Git cannot answer a question about the
     *                           relationship between two commits
     */
    private function findings(
        Repository $git,
        GenerationRecord $generation,
        string $branch,
        WorkspaceState $before,
        WorkspaceState $after,
    ): array {
        // The ordinary case, and the only one worth short-circuiting: a worker
        // that left the repository alone needs no ancestry questions asked
        // about it. A workspace that was already in the middle of an operation
        // is not this case even when nothing moved — staging and committing in
        // one is refused however it got that way.
        if ($before->equals($after) && $after->isResting()) {
            return [];
        }

        $drifts = [];
        $advanced = false;

        if (!$after->isResting()) {
            $drifts[] = WorkspaceDrift::operationInProgress((string) $after->operation(), $after->head());
        }

        if ($after->head() !== $before->head()) {
            if ($this->descends($git, $generation, $before->head(), $after->head())) {
                $drifts[] = WorkspaceDrift::commitsAdded(
                    $before->head(),
                    $after->head(),
                    $this->countBetween($git, $generation, $before->head(), $after->head()),
                );
                $advanced = true;
            } else {
                $drifts[] = WorkspaceDrift::headMoved($before->head(), $after->head());
            }
        }

        if ($before->attachedBranch() !== $after->attachedBranch()) {
            $attached = $after->attachedBranch();

            if ($attached === null) {
                $drifts[] = WorkspaceDrift::headDetached((string) $before->attachedBranch(), $after->head());
            } elseif ($attached === $branch) {
                $drifts[] = WorkspaceDrift::branchAttached($branch, $after->tipOf($branch) ?? $after->head());
            } else {
                $drifts[] = WorkspaceDrift::foreignBranch(
                    $attached,
                    $branch,
                    $after->tipOf($attached) ?? $after->head(),
                );
            }
        }

        // Whether the pass's own branch is simply following the workspace's
        // HEAD, which is the one branch movement a soft reset puts back: HEAD
        // is on it, it is at HEAD, it was at the commit the workspace was
        // handed, and HEAD itself only moved forward. Anything short of all
        // four is reported as a branch that moved.
        $followsHead = $after->isOn($branch)
            && $after->tipOf($branch) === $after->head()
            && ($after->head() === $before->head() || $advanced)
            && (!$before->hasBranch($branch) || $before->tipOf($branch) === $before->head());

        foreach ($this->namesIn($before, $after) as $name) {
            $was = $before->tipOf($name);
            $is = $after->tipOf($name);

            if ($was !== null && $is === null) {
                $drifts[] = WorkspaceDrift::branchDeleted($name, $was);

                continue;
            }

            if ($is === null) {
                continue;
            }

            if ($was === null) {
                if ($name !== $branch) {
                    $drifts[] = WorkspaceDrift::branchCreated($name, $is);

                    continue;
                }

                // The pass's own name. Already covered when HEAD is on it; at
                // the commit the workspace was handed it is where this pass's
                // commit was going anyway, and needs only the recorded claim.
                // Anywhere else it names commits this cannot account for.
                if ($followsHead) {
                    continue;
                }

                $drifts[] = $is === $before->head()
                    ? WorkspaceDrift::branchClaimed($name, $is)
                    : WorkspaceDrift::branchCreated($name, $is);

                continue;
            }

            if ($was === $is || ($name === $branch && $followsHead)) {
                continue;
            }

            $drifts[] = WorkspaceDrift::branchMoved($name, $was, $is);
        }

        return $drifts;
    }

    /**
     * Every branch name either reading knows, sorted, so that a report of a
     * repository with many branches is in a stable order.
     *
     * @return list<string>
     */
    private function namesIn(WorkspaceState $before, WorkspaceState $after): array
    {
        $names = array_values(array_unique([...$before->branchNames(), ...$after->branchNames()]));
        sort($names);

        return $names;
    }

    /**
     * Whether one commit is in another's history.
     *
     * The question that separates "the worker committed" from "the worker did
     * something to this history that cannot be undone from here", and the only
     * reason the first of those is reconcilable at all.
     *
     * @throws WorktreeException when Git cannot answer, which includes a commit
     *                           the repository no longer has
     */
    private function descends(Repository $git, GenerationRecord $generation, string $ancestor, string $descendant): bool
    {
        $result = $git->query('merge-base', '--is-ancestor', '--end-of-options', $ancestor, $descendant);

        if ($result->succeeded()) {
            return true;
        }

        if ($result->exitCode() === self::NOT_AN_ANCESTOR) {
            return false;
        }

        throw $this->unanswered(
            $git,
            $generation->slug(),
            sprintf(
                'whether %s descends from %s',
                substr($descendant, 0, WorkspaceDrift::SHORT_LENGTH),
                substr($ancestor, 0, WorkspaceDrift::SHORT_LENGTH),
            ),
            $result,
        );
    }

    /**
     * How many commits the worker added.
     *
     * Counted rather than assumed to be one, because the number is the first
     * thing a person reading the report wants: a worker that committed eleven
     * times was doing something different from one that committed once.
     *
     * @throws WorktreeException when Git cannot answer
     */
    private function countBetween(
        Repository $git,
        GenerationRecord $generation,
        string $from,
        string $to,
    ): int {
        $result = $git->query('rev-list', '--count', '--end-of-options', sprintf('%s..%s', $from, $to));

        if (!$result->succeeded()) {
            throw $this->unanswered($git, $generation->slug(), 'how many commits its workspace gained', $result);
        }

        return (int) $result->trimmedOutput();
    }

    /**
     * Record the pass's claim on the branch a worker created.
     *
     * @return array{0: GenerationRecord, 1: string|null} the row as it now
     *         stands, and what was done when anything was
     *
     * @throws DriftException when the row names another branch, or cannot be
     *                        written
     */
    private function claimBranch(
        GenerationRecord $generation,
        WorkspaceAudit $audit,
        ?OutputSink $sink,
    ): array {
        $branch = $audit->branch();

        if ($generation->ownsBranch($branch)) {
            return [$generation, null];
        }

        if ($generation->hasBranch()) {
            throw new DriftException(
                sprintf(
                    '%s records %s as its branch, so the %s the worker left cannot be put back.',
                    $generation->slug(),
                    (string) $generation->branch(),
                    $branch,
                ),
                $audit,
                [
                    'The branch a pass owns is what resume uses to find its work, so it is recorded once '
                        . 'and kept.',
                    'Nothing was staged and nothing was committed; the workspace is left as the worker '
                        . 'left it.',
                ],
            );
        }

        try {
            $claimed = $this->generations->assignBranch($generation, $branch);
        } catch (StateException $exception) {
            throw new DriftException(
                sprintf(
                    '%s could not claim the branch %s the worker created: %s',
                    $generation->slug(),
                    $branch,
                    $exception->getMessage(),
                ),
                $audit,
                [
                    'A branch reference that no pass records is read as unrelated work by the next run, so '
                        . 'the claim is recorded before the pass goes on.',
                    'Nothing was staged and nothing was committed; the workspace is left as the worker '
                        . 'left it.',
                ],
            );
        }

        $action = sprintf('recording %s as the branch of %s', $branch, $generation->slug());

        $sink?->note('workspace: ' . $action);

        return [$claimed, $action];
    }

    /**
     * Move HEAD back to the commit the workspace was handed, keeping the index
     * and the files exactly as the worker left them.
     *
     * `--soft` is the whole of why this is a repair rather than a loss. It
     * writes one reference — HEAD, or the branch HEAD is on — and touches
     * nothing else: no file is restored, no staged change is dropped, and the
     * commits the worker made remain reachable through the reflog for as long
     * as the repository keeps it. `--mixed` would unstage, `--hard` would
     * delete the work outright, and neither is reachable from here.
     *
     * @return string what was done
     *
     * @throws DriftException when Git refuses
     */
    private function restoreHead(Repository $git, WorkspaceAudit $audit, ?OutputSink $sink): string
    {
        $expected = $audit->expectedHead();
        $result = $git->run('reset', '--soft', $expected);

        if (!$result->succeeded()) {
            throw new DriftException(
                sprintf(
                    'the workspace %s of %s could not be put back to %s: %s',
                    $audit->workspace(),
                    $audit->generation()->label(),
                    substr($expected, 0, WorkspaceDrift::SHORT_LENGTH),
                    $result->failureReason(),
                ),
                $audit,
                [
                    'The worker\'s commits are still in the workspace and its changes are still on disk; '
                        . 'nothing was staged and nothing was committed.',
                    ...$audit->details(),
                ],
            );
        }

        $action = sprintf(
            'moving HEAD back to %s, keeping every change the worker made',
            substr($expected, 0, WorkspaceDrift::SHORT_LENGTH),
        );

        $sink?->note('workspace: ' . $action);

        return $action;
    }

    /**
     * Check the repair against Git rather than against the intention.
     *
     * The invariants the rest of section F depends on: the workspace is at
     * rest, it is on the commit it was handed, it is either detached or on the
     * pass's own branch, and the pass's branch — if there is one — is at that
     * same commit. A workspace failing any of these would be refused later by
     * {@see TaskCommitter} with the agent's time already spent, so it is
     * refused here, where the message can still say what was being attempted.
     *
     * @throws DriftException when the workspace is not in the state the repair
     *                        was supposed to leave it in
     */
    private function requireRestored(WorkspaceAudit $audit, WorkspaceState $state): void
    {
        $expected = $audit->expectedHead();
        $branch = $audit->branch();
        $tip = $state->tipOf($branch);

        $wrong = match (true) {
            !$state->isResting() => sprintf('it is in the middle of %s', (string) $state->operation()),
            $state->head() !== $expected => sprintf(
                'HEAD is at %s rather than %s',
                substr($state->head(), 0, WorkspaceDrift::SHORT_LENGTH),
                substr($expected, 0, WorkspaceDrift::SHORT_LENGTH),
            ),
            !$state->isDetached() && !$state->isOn($branch) => sprintf(
                'it is on %s rather than on %s',
                (string) $state->attachedBranch(),
                $branch,
            ),
            $tip !== null && $tip !== $expected => sprintf(
                '%s is at %s rather than %s',
                $branch,
                substr($tip, 0, WorkspaceDrift::SHORT_LENGTH),
                substr($expected, 0, WorkspaceDrift::SHORT_LENGTH),
            ),
            default => null,
        };

        if ($wrong === null) {
            return;
        }

        throw new DriftException(
            sprintf(
                'the workspace %s of %s was put back, but %s.',
                $audit->workspace(),
                $audit->generation()->label(),
                $wrong,
            ),
            $audit,
            [
                'Nothing was staged and nothing was committed. Every change the worker made is still on '
                    . 'disk.',
                'Something changed the workspace while the pass was being reconciled, or this is a defect '
                    . 'in Ralph PHP; the message above is what to report if nothing else is using the '
                    . 'repository.',
            ],
        );
    }

    /**
     * The refusal a blocking reading becomes.
     */
    private function blocked(WorkspaceAudit $audit): DriftException
    {
        return new DriftException(
            $audit->summary() . '.',
            $audit,
            [...$audit->details(), ...$audit->hints()],
        );
    }

    /**
     * Refuse a row, a workspace and a branch that are not the same pass.
     *
     * A programming error rather than a user's, and reported as a refusal
     * anyway, because of what it would otherwise be: one task's workspace reset
     * against another task's expectations, and a third one's branch recorded.
     *
     * @throws WorktreeException when the three do not agree
     */
    private function requireOnePass(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        TaskBranch $branch,
    ): void {
        $slugs = [
            'row' => $generation->slug(),
            'workspace' => $worktree->generation()->slug(),
            'branch' => $branch->generation()->slug(),
        ];

        if (count(array_unique($slugs)) === 1) {
            return;
        }

        throw new WorktreeException(
            sprintf(
                'a workspace audit was asked for with the row of %s, the workspace of %s and the branch '
                    . 'of %s, which are not the same pass.',
                $slugs['row'],
                $slugs['workspace'],
                $slugs['branch'],
            ),
            [
                'Nothing was read, nothing was put back and nothing was recorded.',
                'This is a defect in Ralph PHP rather than something to put right in the repository; the '
                    . 'message above is what to report.',
            ],
        );
    }

    /**
     * Refuse to reconcile against a reading of somewhere else.
     *
     * @throws WorktreeException when the reading is not of this pass's
     *                           workspace
     */
    private function requireTheAuditedWorkspace(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        WorkspaceAudit $audit,
    ): void {
        $sameWorkspace = Path::normalize($audit->workspace()) === Path::normalize($worktree->path());

        if ($audit->generation()->slug() === $generation->slug()
            && $worktree->generation()->slug() === $generation->slug()
            && $sameWorkspace
        ) {
            return;
        }

        throw new WorktreeException(
            sprintf(
                'the reading offered for %s was taken of %s of %s, so it cannot be acted on in %s.',
                $generation->slug(),
                $audit->workspace(),
                $audit->generation()->slug(),
                $worktree->path(),
            ),
            [
                'A repair moves a reference, so the reading that justifies it has to be of the repository '
                    . 'it is applied to.',
                'Nothing was put back and nothing was recorded.',
            ],
        );
    }

    /**
     * Refuse anything that is not a task workspace.
     *
     * Defence in depth, and the one check whose absence would be
     * unrecoverable: `git reset --soft` in the controlling checkout would move
     * the branch the user is sitting on.
     *
     * @throws WorktreeException when the path is not a usable workspace
     */
    private function requireWorkspace(TaskWorktree $worktree): void
    {
        $path = $worktree->path();

        if (Path::normalize($path) === Path::normalize($this->projectRoot)) {
            throw new WorktreeException(
                sprintf('a task\'s workspace cannot be the project directory itself, %s.', $path),
                [
                    'Ralph PHP reads and repairs the linked worktree a task was implemented in, so that '
                        . 'nothing in your own checkout is moved by a task\'s reconciliation.',
                ],
            );
        }

        if (!is_dir($path)) {
            throw new WorktreeException(
                sprintf(
                    'the workspace %s of %s is not there, so its commits and branches cannot be read.',
                    $path,
                    $worktree->generation()->label(),
                ),
                [
                    'The implementation and the verification output are gone with it; the task is left '
                        . 'unchecked and will be attempted again.',
                ],
            );
        }
    }

    private function repositoryAt(TaskWorktree $worktree): Repository
    {
        return Repository::at($worktree->path(), $this->executable, $this->processes);
    }

    /**
     * Git could not answer a question about the workspace.
     *
     * Refused rather than guessed, in the one direction that cannot lose work:
     * a run that cannot read what a worker did to a repository must not stage,
     * commit or reset anything in it.
     */
    private function unanswered(
        Repository $git,
        string $pass,
        string $question,
        ProcessResult $result,
    ): WorktreeException {
        return new WorktreeException(
            sprintf(
                'Git could not say %s for %s in %s: %s',
                $question,
                $pass,
                $git->workingTree(),
                $result->failureReason(),
            ),
            [
                'Ralph PHP will not stage, commit or reset in a repository whose commits and branches it '
                    . 'cannot read: the alternative is accepting whatever a worker did to it without '
                    . 'looking.',
                'Nothing was staged, nothing was committed and nothing was put back.',
            ],
        );
    }

    /**
     * Git listed a reference in a shape this cannot read.
     *
     * Refused rather than skipped, for the reason
     * {@see WorkingTreeStatus::parse()} refuses: a branch this silently dropped
     * would be a branch reported as created or deleted on the next reading, or
     * one whose movement went unnoticed.
     */
    private function unreadable(Repository $git, string $pass, string $line): WorktreeException
    {
        return new WorktreeException(
            sprintf(
                'Git listed a branch this cannot read for %s in %s: "%s".',
                $pass,
                $git->workingTree(),
                $line,
            ),
            [
                'Every local branch is read as a tip and a full reference name, so that a worker moving '
                    . 'one is noticed.',
                'This is a defect in Ralph PHP rather than something to put right in the repository; the '
                    . 'message above is what to report.',
            ],
        );
    }
}
