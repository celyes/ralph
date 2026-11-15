<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * One reason a task workspace is kept rather than removed.
 *
 * PROJECT.md section F, item 10 asks for two things that pull in opposite
 * directions: failed and interrupted workspaces must survive for a person to
 * look at, and cleanup must still happen. This enum is the whole of the
 * difference, written down once instead of being decided at each call site —
 * {@see WorktreeCleaner} collects the cases that apply and removes nothing when
 * any of them does.
 *
 * The bias is deliberate and it is not symmetric. Leaving a directory behind
 * costs disk and a line in `git worktree list`; removing one costs whatever was
 * in it, and `git worktree remove` deletes the whole directory including the
 * files Git never tracked — a prepared `vendor/`, a build cache, the `.env`
 * that {@see WorkspacePreparer} copied in. So every case here is a reason to
 * stop, there is no case that argues for removal, and a workspace is removable
 * only when none of them is true.
 *
 * Three of the cases are about **ownership** rather than contents
 * ({@see self::NotOwned}, {@see self::NotOurPath}, {@see self::NotRegistered}):
 * a directory this package did not create is never its to delete, however
 * clean it looks. Three are about **work that would be lost**
 * ({@see self::UncommittedWork}, {@see self::UnrecordedCommit},
 * {@see self::CommitUnreferenced}). One is the item's headline
 * ({@see self::PassUnfinished}). The last four are Git saying no, not
 * answering at all, or answering and being wrong
 * ({@see self::RemovalUnconfirmed}).
 */
enum RetentionReason: string
{
    /**
     * The pass has not finished, so its workspace is the evidence.
     *
     * The case PROJECT.md section F, item 10 names first. A pass that failed,
     * blocked or was interrupted left its files, its index and its partial
     * implementation in the workspace, and that is what `ralph logs` points a
     * person at and what a retry continues in. Removing it would make the
     * failure unexaminable and the retry start over.
     *
     * Read from {@see \Celyes\RalphPhp\State\LifecycleState::isOpen()}, so it
     * covers a pass that is *running* too: a workspace a runner is holding is
     * no more removable than one it abandoned.
     *
     * The one reason a person can waive, which is the other half of PROJECT.md
     * section K, item 7: `ralph clean TASK-ID` asks for a stopped pass's
     * workspace by name and this reason does not apply to it
     * ({@see \Celyes\RalphPhp\Git\CleanupRequest::Explicit}). Nothing waives it
     * for a pass that is running or has not begun, and nothing waives any of
     * the reasons below — so an explicit cleanup still cannot take uncommitted
     * work.
     */
    case PassUnfinished = 'pass-unfinished';

    /**
     * The workspace was already there when the pass adopted it.
     *
     * {@see \Celyes\RalphPhp\State\WorktreeOwnership} records this at the
     * moment the workspace is prepared, because nothing about a directory says
     * later who made it. An earlier run's worktree being continued in, or one a
     * user prepared by hand, is not this pass's to remove even once the pass is
     * finished with it.
     */
    case NotOwned = 'not-owned';

    /**
     * The recorded path is not one {@see WorktreeLayout} hands out.
     *
     * Defence in depth against the only truly unrecoverable mistake available
     * here. If a row named the project directory, the main working tree or
     * anywhere else outside `.ralph/runtime/worktrees/`, removing it would take
     * the user's own checkout with it. A path below that root is the package's
     * to reason about; every other path is somebody else's, and a row that
     * names one is a bug to report rather than a directory to delete.
     */
    case NotOurPath = 'not-our-path';

    /**
     * Something is at the path, and Git does not know it as a worktree.
     *
     * The mirror of the refusal {@see TaskWorktrees::prepare()} makes. A
     * registration is the only evidence that this directory is a linked
     * worktree the package created; without one, the files there arrived some
     * other way, and `rm -rf` on them is exactly what this item forbids.
     */
    case NotRegistered = 'not-registered';

    /**
     * Git has the worktree locked.
     *
     * `git worktree lock` is a person saying "leave this alone" — typically
     * about a worktree on removable media, and typically with a reason
     * attached. Git itself refuses `worktree remove` on one without `-f -f`,
     * and this is the package declining to look for that flag.
     */
    case Locked = 'locked';

    /**
     * There are changes in the workspace that are in no commit.
     *
     * Modified tracked files, staged changes, or untracked files. Any of them
     * may be the implementation of a pass that did not get as far as
     * committing, or a person's notes written while looking at a failure, and
     * neither has another copy anywhere.
     */
    case UncommittedWork = 'uncommitted-work';

    /**
     * The workspace's HEAD is not the commit its row records.
     *
     * A commit the state store does not name is reachable from nothing but
     * this worktree's HEAD, so removing the worktree leaves it findable only
     * through a reflog that `git gc` is entitled to drop. Whatever is in that
     * commit, somebody meant it; it is reported with both SHAs so that it can
     * be recovered by hand.
     */
    case UnrecordedCommit = 'unrecorded-commit';

    /**
     * The commit this pass made is not held by its branch.
     *
     * The other half of "protects completed branches". A pass commits on its
     * own branch, and the branch is what keeps the commit reachable after the
     * workspace is gone — so this check asks Git, rather than assuming, that
     * the branch exists and contains the recorded SHA before the workspace that
     * also referenced it is removed. Nothing here ever creates, moves or
     * deletes that branch: a finished pass's branch is the deliverable, and
     * cleanup's duty towards it is to leave it alone.
     */
    case CommitUnreferenced = 'commit-unreferenced';

    /**
     * Git could not be asked, or did not answer in a form this could read.
     *
     * An unreadable workspace is not an empty one. Every question this asks is
     * a question whose "no" is the only safe reading, so a failed query keeps
     * the directory and says which command failed.
     */
    case Unreadable = 'unreadable';

    /**
     * Git refused the removal.
     *
     * Reported rather than raised. Cleanup runs after a pass is verified,
     * committed and published, and a directory that would not go away is no
     * reason to discard any of that — so the pass stands, the line says what
     * Git said, and the directory waits for a person.
     *
     * Note what this does *not* promise. `git worktree remove` deletes what it
     * can before it reports a failure, so a refused removal may have got part
     * of the way: the hint sends a person to look at the path rather than
     * telling them the workspace is as it was. The pass, its commit and its
     * branch are untouched either way, which is why this is a line in a log
     * and not a failure.
     */
    case RemovalRefused = 'removal-refused';

    /**
     * Git ran the removal and the workspace is still there.
     *
     * The reason PROJECT.md section K, item 7's "do not mark cleanup complete
     * until Git confirms the worktree is removed" needs a name. A command that
     * exits zero is Git's account of what it did, and cleanup's record is
     * checked against the repository instead: `git worktree list` must no
     * longer mention the path and the directory must no longer be there. When
     * either still is — a deletion the filesystem refused part of, a
     * registration a concurrent `worktree add` wrote back — the cleanup is not
     * finished, so it is not recorded as finished, and the row goes on naming
     * the workspace so that a later run tries again.
     *
     * The pass itself is unaffected, as with every other reason here: its
     * commit, its branch and its tick were durable before cleanup began.
     */
    case RemovalUnconfirmed = 'removal-unconfirmed';

    /**
     * Every reason, in the order {@see WorktreeCleaner} asks about them:
     * ownership first, then contents, then Git's own answers.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Whether this is a workspace kept deliberately for a person to inspect,
     * as opposed to one cleanup was not entitled to touch.
     *
     * The distinction is for the message. "Kept for inspection" tells a user
     * where to look; "not ours to remove" tells them why a directory they may
     * not recognise is still there.
     */
    public function isInspection(): bool
    {
        return $this === self::PassUnfinished;
    }

    /**
     * Whether the reason is Git declining or failing, rather than a judgement
     * this package made.
     */
    public function isGitRefusal(): bool
    {
        return match ($this) {
            self::Locked, self::Unreadable, self::RemovalRefused, self::RemovalUnconfirmed => true,
            self::PassUnfinished, self::NotOwned, self::NotOurPath, self::NotRegistered,
            self::UncommittedWork, self::UnrecordedCommit, self::CommitUnreferenced => false,
        };
    }

    /**
     * The reason as a status line says it.
     */
    public function describe(): string
    {
        return match ($this) {
            self::PassUnfinished => 'the pass has not finished, so its workspace is kept for inspection',
            self::NotOwned => 'the workspace was already there and is not this pass\'s to remove',
            self::NotOurPath => 'the recorded path is not one Ralph PHP creates workspaces at',
            self::NotRegistered => 'the directory is not a worktree Git knows about',
            self::Locked => 'Git has the worktree locked',
            self::UncommittedWork => 'the workspace holds changes that are in no commit',
            self::UnrecordedCommit => 'the workspace is on a commit nothing has recorded',
            self::CommitUnreferenced => 'the commit this pass made is not held by its branch',
            self::Unreadable => 'the workspace could not be read',
            self::RemovalRefused => 'Git refused to remove the workspace',
            self::RemovalUnconfirmed => 'the removal ran and the workspace is still there',
        };
    }

    /**
     * What a person can do about it, when there is anything to do.
     */
    public function hint(): string
    {
        return match ($this) {
            self::PassUnfinished => 'Run `ralph logs TASK-ID` to read what happened, `ralph retry TASK-ID` to continue in it, or `ralph clean TASK-ID` to remove it once you are done looking.',
            self::NotOwned => 'Remove it yourself once you are sure nothing in it is wanted.',
            self::NotOurPath => 'Nothing was removed. Report this: a workspace row should only ever name a directory under the runtime worktrees path.',
            self::NotRegistered => 'Check what is in it, then move or remove it yourself; Ralph PHP will not delete a directory Git has no record of.',
            self::Locked => 'Run `git worktree unlock` on it first if the lock is no longer wanted.',
            self::UncommittedWork => 'Commit, copy or discard those changes yourself, then remove the workspace with `git worktree remove`.',
            self::UnrecordedCommit => 'Give the commit a branch of your own if you want to keep it, then remove the workspace with `git worktree remove`.',
            self::CommitUnreferenced => 'The commit is still in the repository; point a branch at the SHA above before removing the workspace.',
            self::Unreadable => 'Run `git status` and `git worktree list` yourself to see what Git reports.',
            self::RemovalRefused => 'The pass, its commit and its branch are unaffected. Look at the path: a removal that fails part way through leaves whatever it could not delete.',
            self::RemovalUnconfirmed => 'Nothing was recorded as cleaned up, so the next run will try again. Run `git worktree list` and look at the path to see what is still there.',
        };
    }
}
