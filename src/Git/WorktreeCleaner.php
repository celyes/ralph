<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\WorktreeOwnership;
use InvalidArgumentException;

/**
 * Keeps the workspaces of failed and interrupted passes, removes the ones a
 * finished pass has no further use for, and says which it did and why
 * (PROJECT.md section F, item 10).
 *
 * The item asks for two things at once, and the tension between them is the
 * whole design. A failed or interrupted pass's workspace is **evidence**: the
 * half-written implementation, the index as the worker left it, the file that
 * explains why verification failed. It is what `ralph logs` points a person at
 * and what a retry continues in, so it must survive a run that moves on to the
 * next task. At the same time a package that never removes anything fills
 * `.ralph/runtime/worktrees/` with a checkout per task, and the user who
 * notices is the one who ran out of disk.
 *
 * So cleanup is **narrow, stated, and biased towards leaving things alone**:
 *
 *  - A workspace is removed only when this pass **created** it, the pass is
 *    **closed** ({@see \Celyes\RalphPhp\State\LifecycleState::isClosed()}), the
 *    path is one {@see WorktreeLayout} hands out, Git has it registered and
 *    unlocked, there is **nothing uncommitted** in it, its HEAD is the commit
 *    the row records, and that commit is **held by the pass's branch**.
 *  - Anything else is kept, with {@see RetentionReason} saying which of those
 *    was not true and {@see WorkspaceRetention} carrying the observation behind
 *    it.
 *  - The only Git command here that writes is `git worktree remove` **without
 *    `--force`**, which refuses a dirty or locked worktree on its own account.
 *    Nothing in this class deletes, moves or force-creates a reference: a
 *    finished pass's branch is the deliverable, and cleanup's duty towards it
 *    is to leave it exactly where it is.
 *
 * The branch check is the part worth stating twice, because it is what
 * "protects completed branches" means in practice. After the workspace is gone,
 * the only thing keeping a task's commit reachable is the branch that points at
 * it — so the branch is asked, rather than assumed, to exist and to contain the
 * recorded SHA *before* the worktree that also referenced it is removed. A
 * commit held by nothing but a worktree HEAD is a commit `git gc` may collect
 * once that worktree goes, and this is the check that stops the package from
 * being the thing that arranged it.
 *
 * **Cleanup never fails a pass.** It runs after verification, the commit and
 * any push, so nothing it finds is a reason to withdraw work that has already
 * been earned. A Git command that refuses, a workspace that cannot be read, a
 * directory that will not go away: each becomes a reason on the returned
 * {@see WorktreeCleanup} and a line in the log. The one exception is a state
 * store that cannot record a removal it has already carried out, which is a
 * real inconsistency and is raised as {@see StateException}.
 *
 * **Boundaries, stated rather than implied.** `git worktree remove` deletes the
 * whole directory, including the files Git never tracked — the `vendor/` that
 * {@see WorkspacePreparer} installed, build caches, and any path
 * `workspace.copy` copied in. A copied credential's original is still at the
 * project root, and dependencies are reinstalled by preparation, which is what
 * makes that acceptable; it is not acceptable for anything a person put there
 * by hand, and that is the reason an untracked file is enough to keep a
 * workspace. Registered worktrees under the package's root that **no generation
 * names** are never touched by this class at all: an unrecorded directory is
 * one nothing here can reason about, and `git worktree list` is where a user
 * sees it.
 */
final class WorktreeCleaner
{
    /**
     * Seconds `git worktree remove` may take.
     *
     * The same order as a checkout, and for the same reason: removal walks and
     * unlinks a whole tree, which in a prepared PHP workspace means a `vendor/`
     * of thousands of files. Bounded rather than unlimited, because a removal
     * blocked on an unresponsive mount is something to report instead of
     * something to wait out, and bounded separately from
     * {@see TaskWorktrees::CHECKOUT_TIMEOUT_SECONDS} so that tightening one
     * does not quietly tighten the other.
     */
    public const int REMOVAL_TIMEOUT_SECONDS = 300;

    /**
     * Git's exit status for "no, it is not an ancestor".
     *
     * `merge-base --is-ancestor` answers with its status: 0 yes, 1 no, anything
     * else a failure to answer. The third case is deliberately not folded into
     * the second — "the branch does not contain the commit" and "Git could not
     * tell me" lead to different reasons.
     */
    private const int NOT_AN_ANCESTOR = 1;

    /**
     * Paths named when a refusal says what is uncommitted. Enough to recognise
     * the work, short enough to stay a message.
     */
    private const int PATHS_IN_MESSAGE = 5;

    public function __construct(
        private readonly TaskWorktrees $worktrees,
        private readonly GenerationStore $generations,
        private readonly string $projectRoot,
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The cleaner for a project.
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
        GenerationStore $generations,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(
            TaskWorktrees::forProject($configuration, $executable, $processes),
            $generations,
            $configuration->projectRoot(),
            $executable,
            $processes,
        );
    }

    /**
     * Decide what should happen to a pass's workspace, without touching
     * anything.
     *
     * Every command issued here is a {@see Repository::query()}, so asking
     * cannot take the index lock of a repository the user has open, and nothing
     * is written. This is what `ralph status` calls to report where a preserved
     * workspace is and why it is still there.
     *
     * Raises nothing. Each question's unanswerable case is a reason to keep the
     * workspace, which is the only safe reading of silence and also the one
     * that keeps a diagnostic from failing.
     */
    public function assess(GenerationRecord $generation): WorkspaceRetention
    {
        $label = $generation->generation()->label();
        $ownership = $generation->worktree();
        $path = $ownership->path();

        if ($path === null) {
            return WorkspaceRetention::absent($label);
        }

        $reasons = [];

        if ($ownership->mustBePreserved()) {
            $reasons[RetentionReason::NotOwned->value] = sprintf(
                '%s was already there when this pass adopted it',
                $path,
            );
        }

        if ($generation->isOpen()) {
            $reasons[RetentionReason::PassUnfinished->value] = sprintf(
                'the pass is %s (%s)',
                $generation->state()->value,
                $generation->state()->describe(),
            );
        }

        // Asked before anything else is, and answered by stopping. Every
        // question after this one assumes the path is a workspace this package
        // hands out, and the one mistake with no recovery would be acting on a
        // row that named the user's own checkout.
        if (!$this->worktrees->layout()->contains($path)) {
            $reasons[RetentionReason::NotOurPath->value] = sprintf(
                '%s is not below %s, where Ralph PHP creates task workspaces',
                $path,
                $this->worktrees->layout()->root(),
            );

            return WorkspaceRetention::preserved($label, $path, $reasons);
        }

        try {
            // Looked up by the path the row records rather than by the path the
            // layout would hand out today. They are the same path for every
            // workspace this package created, and when they are not — a
            // reconfigured runtime directory — the row is the one that says
            // where the files actually are.
            $registered = $this->worktrees->registered()->at($path);
        } catch (WorktreeException $failure) {
            $reasons[RetentionReason::Unreadable->value] = sprintf(
                'the worktrees of this repository could not be listed: %s',
                $failure->getMessage(),
            );

            return WorkspaceRetention::preserved($label, $path, $reasons);
        }

        $present = is_dir($path);

        if ($registered === null) {
            if (!$present) {
                return $reasons === []
                    ? WorkspaceRetention::absent($label, $path)
                    : WorkspaceRetention::preserved($label, $path, $reasons);
            }

            $reasons[RetentionReason::NotRegistered->value] = sprintf(
                '%s exists, and "git worktree list" does not mention it',
                $path,
            );

            return WorkspaceRetention::preserved($label, $path, $reasons);
        }

        if ($registered->isMain()) {
            // Unreachable through the layout check above, and kept anyway: of
            // every directory in the repository this is the one whose removal
            // would take the user's work with it.
            $reasons[RetentionReason::NotOurPath->value] = sprintf(
                '%s is the repository\'s main working tree',
                $path,
            );

            return WorkspaceRetention::preserved($label, $path, $reasons);
        }

        if ($registered->isLocked()) {
            $reasons[RetentionReason::Locked->value] = $registered->lockReason() === ''
                ? 'Git has it locked, with no reason given'
                : sprintf('Git has it locked: %s', (string) $registered->lockReason());
        }

        if (!$present) {
            // Registered, and the directory has gone — somebody removed it by
            // hand. Nothing in it can be lost, and the leftover record is what
            // makes "git worktree add" refuse the path next time, so the
            // remaining questions are not asked: there is nowhere to ask them.
            return $reasons === []
                ? WorkspaceRetention::removable($label, $path)
                : WorkspaceRetention::preserved($label, $path, $reasons);
        }

        foreach ($this->inspect($generation, $path) as $reason => $observation) {
            $reasons[$reason] = $observation;
        }

        return $reasons === []
            ? WorkspaceRetention::removable($label, $path)
            : WorkspaceRetention::preserved($label, $path, $reasons);
    }

    /**
     * Decide, and then do it.
     *
     * The call a run makes when it has finished with a task.
     * {@see self::assess()} stays separately callable so that a diagnostic can
     * look without acting, and they are joined here so that a runner cannot act
     * without having looked.
     *
     * @param OutputSink|null $sink where the decision is noted as it is made —
     *        the attempt's log and the terminal. Never closed here: the caller
     *        that opened the log owns it.
     *
     * @throws StateException when the workspace was removed and the state store
     *                        would not record that, which leaves a row pointing
     *                        at a directory that is gone
     */
    public function clean(GenerationRecord $generation, ?OutputSink $sink = null): WorktreeCleanup
    {
        $retention = $this->assess($generation);

        if ($retention->isAbsent()) {
            return $this->report(WorktreeCleanup::nothing($retention), $sink);
        }

        if ($retention->isPreserved()) {
            return $this->report(WorktreeCleanup::kept($retention), $sink);
        }

        $path = (string) $retention->path();
        $stale = !is_dir($path);
        $removal = $this->remove($path);

        if (!$removal->succeeded()) {
            return $this->report(
                WorktreeCleanup::kept($retention->withReason(
                    RetentionReason::RemovalRefused,
                    $removal->failureReason(),
                )),
                $sink,
            );
        }

        $this->forget($generation);

        return $this->report(
            $stale ? WorktreeCleanup::cleared($retention) : WorktreeCleanup::removed($retention),
            $sink,
        );
    }

    /**
     * Clean up after every pass given, in the order given.
     *
     * One decision per generation, independent of the others: a workspace that
     * is kept does not stop the next from being removed, which is what makes
     * this safe to call with a run's whole history.
     *
     * @param list<GenerationRecord> $generations
     *
     * @return list<WorktreeCleanup> one outcome per pass, including the passes
     *         that had no workspace, so that a caller reporting a sweep can
     *         account for all of them
     *
     * @throws StateException when a removal cannot be recorded
     */
    public function sweep(array $generations, ?OutputSink $sink = null): array
    {
        $outcomes = [];

        foreach ($generations as $generation) {
            $outcomes[] = $this->clean($generation, $sink);
        }

        return $outcomes;
    }

    /**
     * Ask the workspace itself the three questions that can only be answered
     * inside it.
     *
     * @return array<string, string> {@see RetentionReason} value to the
     *         observation behind it
     */
    private function inspect(GenerationRecord $generation, string $path): array
    {
        try {
            $git = Repository::at($path, $this->executable, $this->processes);
        } catch (InvalidArgumentException $failure) {
            return [RetentionReason::Unreadable->value => sprintf(
                '%s could not be opened as a working tree: %s',
                $path,
                $failure->getMessage(),
            )];
        }

        $reasons = [];
        $uncommitted = $this->uncommitted($git);

        if ($uncommitted !== null) {
            $reasons[RetentionReason::UncommittedWork->value] = $uncommitted;
        }

        $unrecorded = $this->unrecordedCommit($git, $generation);

        if ($unrecorded !== null) {
            $reasons[RetentionReason::UnrecordedCommit->value] = $unrecorded;
        }

        $unreferenced = $this->unreferencedCommit($git, $generation);

        if ($unreferenced !== null) {
            $reasons[RetentionReason::CommitUnreferenced->value] = $unreferenced;
        }

        return $reasons;
    }

    /**
     * What is uncommitted in the workspace, or null when it is clean.
     *
     * Read with {@see WorkingTreeStatus::COMMAND}, whose `normal` untracked
     * setting reports a wholly new directory as one entry. That is the right
     * resolution for this question — unlike staging, which has to name every
     * path it might commit, this only has to know whether *anything* of
     * anybody's is there — and a directory reported whole is still a reason to
     * keep the workspace.
     *
     * Files the project ignores are not reported and are **not** a reason to
     * keep anything, which is the deliberate boundary the class note records:
     * `vendor/` and a copied `.env` go with the directory.
     */
    private function uncommitted(Repository $git): ?string
    {
        $result = $git->query(...WorkingTreeStatus::COMMAND);

        if (!$result->succeeded()) {
            return sprintf('its changes could not be listed: %s', $result->failureReason());
        }

        try {
            $status = WorkingTreeStatus::parse($result->standardOutput());
        } catch (InvalidArgumentException $failure) {
            return sprintf('Git reported changes in a form this could not read: %s', $failure->getMessage());
        }

        if ($status->isClean()) {
            return null;
        }

        $paths = [];

        foreach ($status->changes() as $change) {
            $paths[] = $change->path();
        }

        $named = array_slice($paths, 0, self::PATHS_IN_MESSAGE);

        if (count($paths) > self::PATHS_IN_MESSAGE) {
            $named[] = sprintf('and %d more', count($paths) - self::PATHS_IN_MESSAGE);
        }

        return sprintf(
            '%d uncommitted change(s): %s',
            count($paths),
            implode(', ', $named),
        );
    }

    /**
     * Whether the workspace sits on a commit nothing recorded.
     *
     * The expected HEAD is the commit the row names, or — for a pass that
     * produced none, which a verified no-change result and a skipped task both
     * are — the base commit the workspace was created from. Anything else is a
     * commit that exists in the repository and that no row, branch or tag is
     * known to name, and the reason carries both SHAs so it can be recovered by
     * hand.
     */
    private function unrecordedCommit(Repository $git, GenerationRecord $generation): ?string
    {
        $result = $git->query('rev-parse', '--verify', 'HEAD');

        if (!$result->succeeded()) {
            return sprintf('its HEAD could not be read: %s', $result->failureReason());
        }

        $head = $result->trimmedOutput();
        $recorded = $generation->commitSha() ?? $generation->baseSha();

        if ($head === $recorded) {
            return null;
        }

        return sprintf(
            'its HEAD is %s, and the recorded %s is %s',
            $head,
            $generation->hasCommit() ? 'commit' : 'base commit',
            $recorded,
        );
    }

    /**
     * Whether the commit this pass made would survive the workspace going.
     *
     * Only asked of a pass that produced a commit. The branch is read from the
     * row rather than from the worktree's HEAD, because the row is what
     * `ralph status` reports and what a user is told to merge — a commit on a
     * branch nobody recorded is no more findable than one on no branch at all.
     */
    private function unreferencedCommit(Repository $git, GenerationRecord $generation): ?string
    {
        if (!$generation->hasCommit()) {
            return null;
        }

        $commit = (string) $generation->commitSha();
        $branch = $generation->branch();

        if ($branch === null) {
            return sprintf(
                'commit %s is recorded with no branch, so nothing but this workspace refers to it',
                $commit,
            );
        }

        $reference = 'refs/heads/' . $branch;
        $tip = $git->query('rev-parse', '--verify', '--end-of-options', $reference);

        if (!$tip->succeeded()) {
            return sprintf(
                'branch %s is not in the repository, so nothing but this workspace refers to commit %s',
                $branch,
                $commit,
            );
        }

        $contains = $git->query('merge-base', '--is-ancestor', $commit, $reference);

        if ($contains->succeeded()) {
            return null;
        }

        if ($contains->exitCode() === self::NOT_AN_ANCESTOR) {
            return sprintf(
                'branch %s is at %s, which does not contain commit %s',
                $branch,
                $tip->trimmedOutput(),
                $commit,
            );
        }

        return sprintf(
            'whether branch %s contains commit %s could not be established: %s',
            $branch,
            $commit,
            $contains->failureReason(),
        );
    }

    /**
     * Remove the worktree.
     *
     * Run from the project directory rather than from inside the workspace,
     * because a process whose working directory is being deleted is a problem
     * on every platform, and because the path has already been checked to be
     * one this package hands out.
     *
     * **No `--force`.** Git declines a dirty or locked worktree on its own
     * account, and keeping that refusal is a second opinion on the decision
     * this class has just made rather than an obstacle to it: the flag that
     * would override it is the flag that deletes somebody's files.
     * `--end-of-options` keeps an absolute path from being read as an option,
     * which it cannot be here, which is exactly why saying so costs nothing.
     */
    private function remove(string $path): ProcessResult
    {
        return $this->repository()->runWithin(
            self::REMOVAL_TIMEOUT_SECONDS,
            'worktree',
            'remove',
            '--end-of-options',
            $path,
        );
    }

    /**
     * Stop the row pointing at a directory that is no longer there.
     *
     * The branch, the commit SHA, the push result and the verification evidence
     * all stay: they are what a finished pass is reviewed from. Only the
     * workspace columns are cleared, and only after Git has confirmed the
     * removal, so a row that still names a path always means a directory that
     * still exists.
     *
     * @throws StateException when the row is gone
     */
    private function forget(GenerationRecord $generation): void
    {
        $this->generations->recordWorktree($generation, WorktreeOwnership::none());
    }

    /**
     * Note what happened, and why when it is worth saying.
     */
    private function report(WorktreeCleanup $cleanup, ?OutputSink $sink): WorktreeCleanup
    {
        if ($sink === null) {
            return $cleanup;
        }

        $sink->note($cleanup->describe());

        foreach ($cleanup->details() as $detail) {
            $sink->note('cleanup: ' . $detail);
        }

        return $cleanup;
    }

    /**
     * The repository the removal is issued from: the project directory, which
     * is the one working tree that cannot be the one being deleted.
     */
    private function repository(): Repository
    {
        return Repository::at($this->projectRoot, $this->executable, $this->processes);
    }
}
