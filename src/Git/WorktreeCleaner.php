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
 *  - The only Git command here that writes is `git worktree remove`, and on a
 *    first attempt it is issued **without `--force`**, so Git refuses a dirty
 *    or locked worktree on its own account. Nothing in this class deletes,
 *    moves or force-creates a reference, and nothing in it deletes a directory
 *    itself: a finished pass's branch is the deliverable, and cleanup's duty
 *    towards it is to leave it exactly where it is. The only other command here
 *    that writes anything is `git worktree repair`
 *    ({@see self::relink()}), which rewrites the two files linking a workspace
 *    to its repository and deletes nothing.
 *
 * **A person can ask anyway** (PROJECT.md section K, item 7). Preserving a
 * failed workspace for inspection is only half of what the item asks for; the
 * other half is a way to clear one up once it has been inspected, so that the
 * answer to a full `.ralph/runtime/worktrees/` is not `rm -rf`. That is
 * `ralph clean TASK-ID`, which reaches this class as
 * {@see CleanupRequest::Explicit} and buys exactly one thing: the "pass has not
 * finished" reason is waived, for a pass that has **stopped** — failed, blocked
 * or interrupted, never one a runner is working on. Every other reason above
 * stands, so the explicit action removes a clean preserved workspace and
 * refuses one holding uncommitted work just as firmly as the automatic one
 * does. There is no flag anywhere in the package that overrides that refusal:
 * a person who has decided those changes are expendable discards them with
 * their own Git, which is a deletion they can see the extent of.
 *
 * **Cleanup is idempotent, and finishes itself** (PROJECT.md section K,
 * item 7). Every decision is taken afresh from the row and the repository, the
 * row is marked before the removal is issued and completed only once Git and
 * the filesystem both confirm the workspace has gone, and the completion is a
 * single statement. Between them those make three interruptions recoverable
 * rather than permanent: one before the command, which leaves a workspace to
 * remove as usual; one during it, which leaves a part-deleted directory that
 * the mark on the row identifies as a husk and a resumed removal finishes with
 * `--force`; and one after it, which leaves a row naming a directory that has
 * gone and whose last step — clearing that row — is all a later run has left to
 * do. {@see self::clean()} sets all of that out in order.
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
     *
     * **A removal already under way changes which questions are worth
     * asking.** When the row says a removal was issued and never confirmed
     * ({@see \Celyes\RalphPhp\State\CleanupState::Removing}), the directory
     * at the recorded path is one Git was already told to delete, having agreed
     * at the time that it was clean — so what is left of it has to be read as
     * the remains of that deletion rather than as evidence. A part-deleted
     * checkout reports its own deletions as uncommitted changes, reports a HEAD
     * it can no longer resolve, or cannot be opened as a working tree at all,
     * and treating any of that as work to protect would preserve a husk for
     * ever.
     *
     * What is still asked is everything that does not depend on the directory
     * having survived — whose it is, where it is, whether Git has since locked
     * it, and whether the pass's commit is held by its branch, which is put to
     * the project's repository because the workspace may no longer be able to
     * answer — and one question that does: whether anything in the workspace is
     * something the removal **could not have produced**
     * ({@see self::writtenSinceTheRemoval()}). A modification or an untracked
     * file is somebody's work, and keeps the directory exactly as it would have
     * on the first attempt.
     *
     * @param CleanupRequest $request who is asking. The default is a run's own
     *        housekeeping, which never removes an unfinished pass's workspace;
     *        {@see CleanupRequest::Explicit} is `ralph clean TASK-ID` and
     *        waives {@see RetentionReason::PassUnfinished} for a pass that has
     *        stopped — and nothing else, so an explicit request is still
     *        refused by uncommitted work, an unrecorded commit, a commit its
     *        branch does not hold, a locked worktree or a directory that is not
     *        this package's
     */
    public function assess(
        GenerationRecord $generation,
        CleanupRequest $request = CleanupRequest::Finalization,
    ): WorkspaceRetention {
        $label = $generation->generation()->label();
        $ownership = $generation->worktree();
        $path = $ownership->path();

        if ($path === null) {
            return $generation->cleanupIsFinished()
                ? WorkspaceRetention::cleaned($label)
                : WorkspaceRetention::absent($label);
        }

        $resuming = $generation->cleanupIsUnfinished();
        $reasons = [];

        if ($ownership->mustBePreserved()) {
            $reasons[RetentionReason::NotOwned->value] = sprintf(
                '%s was already there when this pass adopted it',
                $path,
            );
        }

        // The one reason an explicit request waives, and only for a pass that
        // has stopped (PROJECT.md section K, item 7). A run's own cleanup never
        // gets here with anything but Finalization, so the default behaviour is
        // unchanged: an unfinished pass keeps its workspace.
        if ($generation->isOpen() && !$request->waivesPreservation($generation->state())) {
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
                // Nothing is at the path and Git has no record of it, so the
                // only thing left of this workspace is the row that names it.
                // Removable rather than absent, because clearing that row is
                // the last step of a cleanup, and this is where an interruption
                // between Git confirming a removal and the state store hearing
                // about it is finished off (PROJECT.md section K, item 7). The
                // same reading covers a workspace a user removed by hand and
                // pruned: there is nothing to lose and a row to tidy.
                return $reasons === []
                    ? WorkspaceRetention::removable($label, $path)
                    : WorkspaceRetention::preserved($label, $path, $reasons);
            }

            // Something is at the path and Git has no record of it. Kept even
            // when a removal was under way: the registration is the only thing
            // `git worktree remove` can act on, and with it gone this class has
            // no way to finish the job — it does not delete directories itself.
            // Git deletes the worktree directory before its own record of it,
            // so reaching this state means the directory removal reported a
            // failure and left part of the tree, which is a path for a person
            // to look at rather than one to delete unseen.
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

        foreach ($this->inspect($generation, $path, $resuming) as $reason => $observation) {
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
     * **Safe to call again, and able to finish what an interruption started**
     * (PROJECT.md section K, item 7). Four facts make that work, and they are
     * worth reading in order:
     *
     *  1. The decision is taken from the row and the repository every time,
     *     never from anything remembered, so a second call on a workspace that
     *     has gone finds nothing to do and a second call on one that was kept
     *     re-asks why.
     *  2. {@see GenerationStore::beginWorktreeCleanup()} marks the row
     *     **before** the Git command runs. That mark is the only thing that can
     *     tell a part-deleted workspace from one somebody has been editing, and
     *     it is what licenses the forced retry below.
     *  3. Nothing is called complete on the strength of an exit status. Git is
     *     asked again — `worktree list` must no longer mention the path, and
     *     the directory must no longer be there — and a removal that cannot be
     *     confirmed leaves the row naming the workspace so that a later run
     *     tries again.
     *  4. The completion is one statement
     *     ({@see GenerationStore::finishWorktreeCleanup()}), so a row can never
     *     both name a directory and claim to have removed it.
     *
     * The **forced retry** is the one escalation here, and its gate is narrow.
     * `git worktree remove` deletes the worktree directory and then Git's
     * record of it, so an interruption in the middle leaves a tree Git will
     * refuse to remove — its own deletions read as modifications — and the
     * husk would otherwise be preserved for ever. A removal that is being
     * resumed may therefore pass `--force`, which finishes a deletion Git
     * began after Git itself had agreed the workspace was clean. A *first*
     * attempt never passes it: Git's refusal of a dirty or locked worktree is a
     * second opinion on the decision this class has just made, and overriding
     * it is what deletes somebody's files.
     *
     * @param OutputSink|null $sink where the decision is noted as it is made —
     *        the attempt's log and the terminal. Never closed here: the caller
     *        that opened the log owns it.
     * @param CleanupRequest  $request who is asking; see {@see self::assess()},
     *        which is where the one difference between a run's housekeeping and
     *        the action a person asked for is made
     *
     * @throws StateException when the state store will not record the start or
     *                        the end of a removal, which would leave a row that
     *                        cannot be reconciled with the directory
     */
    public function clean(
        GenerationRecord $generation,
        ?OutputSink $sink = null,
        CleanupRequest $request = CleanupRequest::Finalization,
    ): WorktreeCleanup {
        $retention = $this->assess($generation, $request);

        if ($retention->isAbsent()) {
            return $this->report(WorktreeCleanup::nothing($retention), $sink);
        }

        if ($retention->isPreserved()) {
            return $this->report(WorktreeCleanup::kept($retention), $sink);
        }

        $path = (string) $retention->path();
        $resuming = $generation->cleanupIsUnfinished();
        $stale = !is_dir($path);

        // Written before the command and before anything is deleted, so that
        // whatever an interruption leaves behind can be recognised afterwards.
        $generation = $this->generations->beginWorktreeCleanup($generation);

        $registered = $this->isRegistered($path);

        if ($registered !== null) {
            $removal = $this->remove($path, $resuming);

            if (!$removal->succeeded() && $resuming) {
                // A removal Git stopped part way through may have taken the
                // workspace's own `.git` file with it, and Git declines to
                // remove a worktree it can no longer validate even with
                // `--force`. `worktree repair` writes that link back — see
                // self::relink() — and then the removal can finish.
                $this->relink($path);
                $removal = $this->remove($path, true);
            }

            if (!$removal->succeeded()) {
                return $this->report(
                    WorktreeCleanup::kept($retention->withReason(
                        RetentionReason::RemovalRefused,
                        $removal->failureReason(),
                    )),
                    $sink,
                );
            }
        }

        $unconfirmed = $this->unconfirmed($path);

        if ($unconfirmed !== null) {
            return $this->report(
                WorktreeCleanup::kept($retention->withReason(
                    RetentionReason::RemovalUnconfirmed,
                    $unconfirmed,
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
     * @param CleanupRequest         $request who is asking, applied to each of
     *        them alike; `ralph clean --all` is this with
     *        {@see CleanupRequest::Explicit}
     *
     * @return list<WorktreeCleanup> one outcome per pass, including the passes
     *         that had no workspace, so that a caller reporting a sweep can
     *         account for all of them
     *
     * @throws StateException when a removal cannot be recorded
     */
    public function sweep(
        array $generations,
        ?OutputSink $sink = null,
        CleanupRequest $request = CleanupRequest::Finalization,
    ): array {
        $outcomes = [];

        foreach ($generations as $generation) {
            $outcomes[] = $this->clean($generation, $sink, $request);
        }

        return $outcomes;
    }

    /**
     * Ask the workspace itself the three questions that can only be answered
     * inside it.
     *
     * Two of the three are about the workspace's contents and one is about the
     * repository's references, which is why `$resuming` does not simply skip
     * the whole step: a removal that is being finished off has already had the
     * contents questions answered — Git agreed the directory was clean when the
     * first attempt was issued — but the promise that the pass's commit
     * survives the directory is one this class re-checks every time, and it can
     * be checked from the project's own repository, which a half-deleted
     * workspace cannot be.
     *
     * @param bool $resuming whether a removal of this workspace was already
     *        issued, in which case what is left of the directory is the remains
     *        of that removal and is not read as evidence
     *
     * @return array<string, string> {@see RetentionReason} value to the
     *         observation behind it
     */
    private function inspect(GenerationRecord $generation, string $path, bool $resuming = false): array
    {
        if ($resuming) {
            $reasons = [];
            $written = $this->writtenSinceTheRemoval($path);

            if ($written !== null) {
                $reasons[RetentionReason::UncommittedWork->value] = $written;
            }

            $unreferenced = $this->unreferencedCommit($this->repository(), $generation);

            if ($unreferenced !== null) {
                $reasons[RetentionReason::CommitUnreferenced->value] = $unreferenced;
            }

            return $reasons;
        }

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
     * What is in a part-removed workspace that the removal cannot account for,
     * or null when there is nothing.
     *
     * The narrowing that keeps a resumed removal from being a licence. A
     * workspace whose removal was issued and never confirmed may be anywhere
     * between untouched and nearly gone, and both ends are safe to finish:
     * Git agreed the directory was clean when the command went out, and every
     * difference a part-way deletion can produce is a tracked file that is no
     * longer there ({@see WorkingTreeChange::isDeletion()}). A **modification
     * or an untracked file** is something the removal could not have made, so
     * it is somebody's — a person who went to look at the path the run told
     * them about, most likely, since a removal that reports success without
     * removing anything leaves the workspace whole and the mark in place until
     * the next run. That is work, and it stops the cleanup exactly as it would
     * have stopped the first attempt.
     *
     * A workspace that cannot answer at all is not a reason to keep anything:
     * a removal that has taken the `.git` file leaves a directory that is no
     * longer a working tree, which is evidence of the deletion rather than of
     * anybody's work. The ownership, path and lock questions still stand, and
     * so does the branch holding the commit.
     */
    private function writtenSinceTheRemoval(string $path): ?string
    {
        try {
            $git = Repository::at($path, $this->executable, $this->processes);
        } catch (InvalidArgumentException) {
            return null;
        }

        $result = $git->query(...WorkingTreeStatus::COMMAND);

        if (!$result->succeeded()) {
            return null;
        }

        try {
            $status = WorkingTreeStatus::parse($result->standardOutput());
        } catch (InvalidArgumentException) {
            return null;
        }

        $paths = [];

        foreach ($status->changes() as $change) {
            if (!$change->isDeletion()) {
                $paths[] = $change->describe();
            }
        }

        if ($paths === []) {
            return null;
        }

        $named = array_slice($paths, 0, self::PATHS_IN_MESSAGE);

        if (count($paths) > self::PATHS_IN_MESSAGE) {
            $named[] = sprintf('and %d more', count($paths) - self::PATHS_IN_MESSAGE);
        }

        return sprintf(
            '%d change(s) the interrupted removal cannot account for: %s',
            count($paths),
            implode(', ', $named),
        );
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
     * **No `--force` on a first attempt.** Git declines a dirty or locked
     * worktree on its own account, and keeping that refusal is a second opinion
     * on the decision this class has just made rather than an obstacle to it:
     * the flag that would override it is the flag that deletes somebody's
     * files.
     *
     * A **resumed** removal is the one exception, and the difference is that
     * Git has already agreed once. `$resuming` is true only when the row
     * records a removal of this very path that was issued and never confirmed
     * ({@see \Celyes\RalphPhp\State\CleanupState::Removing}), which means
     * the workspace was clean when the command went out and that what is left
     * of the directory is what that command had got through. Git refuses such a
     * tree — the deletions it made itself read as modifications — so without
     * the flag the husk could never be finished off, which is what PROJECT.md
     * section K, item 7 asks for. The flag is reachable no other way.
     *
     * `--end-of-options` keeps an absolute path from being read as an option,
     * which it cannot be here, which is exactly why saying so costs nothing.
     *
     * @param bool $resuming whether this is finishing a removal that was
     *        already issued against this path
     */
    private function remove(string $path, bool $resuming = false): ProcessResult
    {
        $arguments = ['worktree', 'remove'];

        if ($resuming) {
            $arguments[] = '--force';
        }

        return $this->repository()->runWithin(
            self::REMOVAL_TIMEOUT_SECONDS,
            ...[...$arguments, '--end-of-options', $path],
        );
    }

    /**
     * Write back the link between a part-deleted workspace and its repository.
     *
     * `git worktree remove` deletes the worktree directory before Git's
     * administrative record of it, and within the directory it deletes entries
     * in whatever order the filesystem hands them over — so an interruption may
     * well have taken the `.git` file that says which repository the workspace
     * belongs to. Git then refuses to remove the worktree *even with*
     * `--force`, because it cannot validate what it is being asked to delete,
     * and the husk would be unremovable by the package that made it.
     *
     * `worktree repair` is Git's own answer to that: given a path, it rewrites
     * the `.git` file in the workspace and the `gitdir` file in
     * `.git/worktrees/<id>` to point at each other again. It creates and
     * deletes nothing else, which is why it is the one other writing command
     * this class issues.
     *
     * Its exit status is deliberately **not** consulted. Repair reports the
     * breakage it found and can exit non-zero having repaired enough for the
     * removal to succeed, and the authority on whether that happened is the
     * removal itself, which is attempted once more either way. It is also
     * newer than {@see \Celyes\RalphPhp\Ralph::MINIMUM_GIT_VERSION} — Git
     * 2.30 — so on an older Git this is an unknown subcommand and the step
     * simply contributes nothing, leaving the workspace kept with Git's own
     * refusal for a person to clear up. That is the documented limit of what
     * cleanup can finish by itself.
     */
    private function relink(string $path): void
    {
        $this->repository()->runWithin(
            self::REMOVAL_TIMEOUT_SECONDS,
            'worktree',
            'repair',
            '--end-of-options',
            $path,
        );
    }

    /**
     * Whether Git still has a worktree registered at the path.
     *
     * Asked again rather than taken from {@see self::assess()}, because the
     * answer decides whether there is a command to run at all: a path Git has
     * no record of has nothing for `worktree remove` to act on, and asking it
     * anyway would turn the last step of a recovered cleanup — clearing the row
     * — into a Git failure. Null for "no record", which is also the reading of
     * a list that cannot be read: the command is skipped, and
     * {@see self::unconfirmed()} is then the thing that refuses to call the
     * cleanup finished.
     */
    private function isRegistered(string $path): ?RegisteredWorktree
    {
        try {
            return $this->worktrees->registered()->at($path);
        } catch (WorktreeException) {
            return null;
        }
    }

    /**
     * Why the workspace cannot be called gone, or null when it is.
     *
     * The check PROJECT.md section K, item 7 asks for in as many words: a zero
     * exit status is Git's account of what it did, and what is recorded is
     * checked against the repository and the filesystem instead. Both have to
     * agree — `worktree list` no longer mentioning the path, and no directory
     * there — because they fail in different ways: a deletion the filesystem
     * refused part of leaves the directory, and a concurrent `worktree add`, or
     * a `remove` that reported success while leaving its administrative record,
     * leaves the registration.
     *
     * A list that cannot be read is not a confirmation either. Unconfirmed is
     * the safe direction in every case: the row goes on naming the workspace,
     * so the next run tries again, and nothing has been lost but a directory
     * that is still there.
     */
    private function unconfirmed(string $path): ?string
    {
        if (is_dir($path)) {
            return sprintf('%s is still there after the removal ran', $path);
        }

        try {
            $registered = $this->worktrees->registered()->at($path);
        } catch (WorktreeException $failure) {
            return sprintf(
                'whether Git still has %s registered could not be established: %s',
                $path,
                $failure->getMessage(),
            );
        }

        if ($registered !== null) {
            return sprintf('"git worktree list" still reports %s after the removal ran', $path);
        }

        return null;
    }

    /**
     * Stop the row pointing at a directory that is no longer there, and record
     * that the cleanup finished.
     *
     * The branch, the commit SHA, the push result and the verification evidence
     * all stay: they are what a finished pass is reviewed from. Only the
     * workspace columns are cleared, they are cleared in the same statement
     * that marks the cleanup complete, and both happen only after Git and the
     * filesystem have confirmed the removal — so a row that still names a path
     * means either a directory that still exists or a removal that is being
     * retried, and a row marked complete never names one at all.
     *
     * @throws StateException when the row is gone
     */
    private function forget(GenerationRecord $generation): void
    {
        $this->generations->finishWorktreeCleanup($generation);
    }

    /**
     * Note what happened, and why when it is worth saying.
     *
     * The one place the outcome reaches a log, which is why the caller is not
     * expected to note it as well: a pass that both let the cleaner write here
     * and wrote its own line said everything twice.
     */
    private function report(WorktreeCleanup $cleanup, ?OutputSink $sink): WorktreeCleanup
    {
        if ($sink === null) {
            return $cleanup;
        }

        $sink->note('cleanup: ' . $cleanup->describe());

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
