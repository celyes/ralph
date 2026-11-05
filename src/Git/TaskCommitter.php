<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;

/**
 * Turns a verified task's staged index into a commit on the pass's branch, and
 * records its SHA (PROJECT.md section F, item 7).
 *
 * The last step of section F that produces something durable, and the only one
 * that writes to two places which can disagree: the repository, and the pass's
 * row. Everything about the order of operations here follows from that, because
 * a crash between the two is not hypothetical — it is PROJECT.md section H,
 * item 5's named recovery case:
 *
 *  1. **The branch is claimed in the row before it exists in Git.** A branch
 *     reference with no row to name it is read by {@see TaskBranches} as
 *     unrelated work, and refused, which would leave a pass permanently unable
 *     to use the name it was always going to use. A row naming a branch that
 *     does not exist is the harmless direction: ownership reports it as
 *     available and the branch is created on the next pass through.
 *  2. **The commit is made.** From here the work is safe whatever happens, and
 *     the message is what finds it: {@see CommitMessage} puts the task, run and
 *     attempt identifiers in the commit itself precisely so that a database
 *     that never learned about this commit is not the only record of it.
 *  3. **The SHA is persisted.** {@see GenerationStore::recordCommit()} refuses
 *     to replace one, so this cannot quietly accept a second commit for a pass
 *     that already has one.
 *
 * A pass that arrives here with a commit already recorded is refused before
 * anything is written. That is what keeps a resumed run from implementing or
 * committing twice (acceptance criterion 6): a pass with a commit and an
 * outstanding push needs publication, not another commit, and the one thing
 * this must never do is create a second commit that the row cannot even
 * record.
 *
 * **An empty index is refused rather than committed.** A verified task that
 * changed nothing is a real outcome, and PROJECT.md section G, item 6 says what
 * it is: a no-change result with its verification evidence
 * ({@see GenerationStore::recordNoChange()}), not an empty commit. So this
 * refuses the case rather than deciding it, because the evidence that makes it
 * legitimate is the runner's to hold.
 *
 * **The project's hooks and signing configuration are honoured.** `git commit`
 * is run without `--no-verify` and without `--no-gpg-sign`, so a repository
 * whose `pre-commit` hook rejects the change does not get the change committed,
 * and a repository that signs commits signs this one. Both can fail *after* the
 * verification commands have passed, which is why the failure names the
 * workspace the implementation is still sitting in — and why a signing key that
 * needs a passphrase cannot work here at all: nothing this package runs has a
 * terminal to ask on.
 */
final class TaskCommitter
{
    /**
     * Seconds `git commit` may take.
     *
     * Generous because it is not only writing an object: `pre-commit` and
     * `commit-msg` hooks run inside it, and a project's hook may well be a
     * linter over every staged file. Still bounded, so a hook that waits on
     * input nobody can give ends the attempt instead of the run.
     */
    public const int COMMIT_TIMEOUT_SECONDS = 120;

    /**
     * Git's exit status for a reference that is not there, which for
     * `symbolic-ref HEAD` means a detached HEAD.
     */
    private const int NOT_A_SYMBOLIC_REF = 1;

    public function __construct(
        private readonly GenerationStore $generations,
        private readonly string $projectRoot,
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The committer for a project.
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
     * Commit what is staged in the task's workspace, and record the SHA.
     *
     * @param GenerationRecord $generation the pass's row, which is both what
     *        says the pass has no commit yet and what the SHA is written to
     * @param BranchOwnership  $ownership  the reading {@see TaskBranches::require()}
     *        returned for this pass's branch; an unusable one is refused rather
     *        than committed onto
     * @param StagedChanges    $staged     what {@see WorkspaceStager} decided
     *        the commit would contain, taken of this workspace
     * @param OutputSink|null  $sink       where the commit is noted as it is
     *        made. Never closed here: the caller that opened the log owns it.
     *
     * @throws CommitException when the pass, the workspace or the branch is not
     *                         in a state that may be committed, when Git
     *                         refuses, or when the commit cannot be recorded
     */
    public function commit(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        BranchOwnership $ownership,
        StagedChanges $staged,
        CommitMessage $message,
        ?OutputSink $sink = null,
    ): TaskCommit {
        $branch = $ownership->branch();

        $this->requireOnePass($generation, $worktree, $branch);
        $this->requireNoCommitYet($generation);
        $this->requireSomethingToCommit($generation, $worktree, $staged);
        $this->requireWorkspace($generation, $worktree);
        $this->requireUsableBranch($generation, $ownership);

        $git = Repository::at($worktree->path(), $this->executable, $this->processes);
        $parent = $this->headOf($git, $generation);

        $generation = $this->claimBranch($generation, $branch, $sink);
        $created = $this->attachTo($git, $generation, $ownership, $parent, $sink);

        $sha = $this->write($git, $generation, $message, $staged, $parent, $sink);

        return new TaskCommit(
            $this->record($generation, $sha, $branch, $sink),
            $sha,
            $parent,
            $branch,
            $created,
            $message,
            $staged,
        );
    }

    /**
     * Refuse a row, a workspace and a branch that are not the same pass.
     *
     * A programming error rather than a user's, and reported as a refusal
     * anyway, because of what it would otherwise be: one task's work committed
     * onto another task's branch and recorded against a third one's row.
     *
     * @throws CommitException when the three do not agree
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

        throw new CommitException(
            sprintf(
                'a commit was asked for with the row of %s, the workspace of %s and the branch of %s, '
                    . 'which are not the same pass.',
                $slugs['row'],
                $slugs['workspace'],
                $slugs['branch'],
            ),
            [
                'Nothing was committed and nothing was recorded.',
                'This is a defect in Ralph PHP rather than something to put right in the repository; the '
                    . 'message above is what to report.',
            ],
        );
    }

    /**
     * Refuse a pass that has committed already.
     *
     * The guard that makes resume safe. A pass whose commit is recorded has its
     * implementation; what may still be owed is a push (PROJECT.md section H,
     * item 7) or a checklist write (section G, item 8), and neither of those is
     * reached by committing again.
     *
     * @throws CommitException when a commit is recorded
     */
    private function requireNoCommitYet(GenerationRecord $generation): void
    {
        if (!$generation->hasCommit()) {
            return;
        }

        throw new CommitException(
            sprintf(
                '%s already recorded commit %s, so it will not be committed again.',
                $generation->slug(),
                (string) $generation->commitSha(),
            ),
            [
                'A pass commits once. Resuming one that has committed retries only what it still owes — '
                    . 'the push, or the checklist write — never the implementation.',
                sprintf('Run "git show %s" to see what it contains.', (string) $generation->commitSha()),
            ],
        );
    }

    /**
     * Refuse an index with nothing in it.
     *
     * @throws CommitException when nothing is staged
     */
    private function requireSomethingToCommit(
        GenerationRecord $generation,
        TaskWorktree $worktree,
        StagedChanges $staged,
    ): void {
        if (Path::normalize($staged->workspace()) !== Path::normalize($worktree->path())) {
            throw new CommitException(
                sprintf(
                    'the staged changes offered for %s were taken of %s rather than of its workspace %s.',
                    $generation->slug(),
                    $staged->workspace(),
                    $worktree->path(),
                ),
                [
                    'The staged summary is the record of what a commit contains, so one describing another '
                        . 'directory cannot be committed from this one.',
                    'Nothing was committed, and the implementation is still in the workspace.',
                ],
            );
        }

        if (!$staged->isEmpty()) {
            return;
        }

        throw new CommitException(
            sprintf('%s has nothing staged, so there is no commit to make.', $generation->slug()),
            [
                sprintf('Staging reported: %s.', $staged->summary()),
                'A verified task that changed nothing is recorded as a no-change result with its '
                    . 'verification evidence, not as an empty commit.',
            ],
        );
    }

    /**
     * Refuse anything that is not a task workspace to commit in.
     *
     * Defence in depth, and the one check whose absence would be
     * unrecoverable: committing in the controlling checkout would put the
     * user's own staged work into a task's commit, on a branch the user is
     * sitting on.
     *
     * @throws CommitException when the path is not a usable workspace
     */
    private function requireWorkspace(GenerationRecord $generation, TaskWorktree $worktree): void
    {
        $path = $worktree->path();

        if (Path::normalize($path) === Path::normalize($this->projectRoot)) {
            throw new CommitException(
                sprintf('a task\'s commit cannot be made in the project directory itself, %s.', $path),
                [
                    'Ralph PHP commits from the linked worktree a task was implemented in, so that nothing '
                        . 'in your own checkout can reach a task\'s commit.',
                ],
            );
        }

        if (!is_dir($path)) {
            throw new CommitException(
                sprintf(
                    'the workspace %s of %s is not there, so its changes cannot be committed.',
                    $path,
                    $generation->slug(),
                ),
                [
                    'The implementation and the verification output are gone with it; the task is left '
                        . 'unchecked and will be attempted again.',
                ],
            );
        }
    }

    /**
     * Refuse a branch the pass may not use.
     *
     * {@see TaskBranches::require()} has already refused an unusable reading by
     * the time a run reaches here, so this is the second lock on the same door
     * — and the one that matters, because this is the only step that writes a
     * reference.
     *
     * @throws CommitException when the branch is not the pass's
     */
    private function requireUsableBranch(GenerationRecord $generation, BranchOwnership $ownership): void
    {
        if ($ownership->isUsable()) {
            return;
        }

        throw new CommitException(
            sprintf('%s cannot commit, because %s.', $generation->slug(), $ownership->summary()),
            [...$ownership->details(), ...$ownership->hints()],
        );
    }

    /**
     * The commit the workspace is sitting on.
     *
     * Read before anything is written, and kept: it is the commit the task's
     * own commit will have as its parent, and the only chance to know what it
     * was.
     *
     * @throws CommitException when Git cannot answer
     */
    private function headOf(Repository $git, GenerationRecord $generation): string
    {
        $result = $git->query('rev-parse', '--verify', '--end-of-options', 'HEAD');

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        throw new CommitException(
            sprintf(
                'the workspace %s of %s has no commit checked out: %s',
                $git->workingTree(),
                $generation->slug(),
                $result->failureReason(),
            ),
            [
                'A task workspace is created from the run\'s base commit, so one with no HEAD is not the '
                    . 'workspace this run prepared.',
                'Nothing was committed, and the implementation is still in the workspace.',
            ],
        );
    }

    /**
     * Record the branch on the pass's row, before Git has a reference for it.
     *
     * The order the class note explains: a reference nothing records is
     * refused as unrelated work on the next run, while a record with no
     * reference is simply a branch to create.
     *
     * A row that already names a different branch is refused rather than
     * rewritten. Ownership is what resume depends on, so a pass that moved
     * branch between commits could never be reconciled.
     *
     * @throws CommitException when the row names another branch, or cannot be
     *                         written
     */
    private function claimBranch(
        GenerationRecord $generation,
        TaskBranch $branch,
        ?OutputSink $sink,
    ): GenerationRecord {
        $recorded = $generation->branch();

        if ($recorded === $branch->value()) {
            return $generation;
        }

        if ($recorded !== null) {
            throw new CommitException(
                sprintf(
                    '%s records %s as its branch and cannot commit to %s.',
                    $generation->slug(),
                    $recorded,
                    $branch->value(),
                ),
                [
                    'The branch a pass owns is what resume uses to find its work, so it is recorded once '
                        . 'and kept.',
                    'Nothing was committed, and the implementation is still in the workspace.',
                ],
            );
        }

        try {
            $claimed = $this->generations->assignBranch($generation, $branch->value());
        } catch (StateException $exception) {
            throw new CommitException(
                sprintf(
                    '%s could not claim the branch %s: %s',
                    $generation->slug(),
                    $branch->value(),
                    $exception->getMessage(),
                ),
                [
                    'Ralph PHP records the branch before creating it, so that a run interrupted between the '
                        . 'two leaves a name to re-create rather than a reference nobody owns.',
                    'Nothing was committed, and the implementation is still in the workspace.',
                ],
            );
        }

        $sink?->note(sprintf('commit: %s owns the branch %s', $generation->slug(), $branch->value()));

        return $claimed;
    }

    /**
     * Put HEAD on the pass's branch, creating it when it does not exist.
     *
     * A task workspace is checked out detached ({@see TaskWorktrees}), because
     * naming a branch before anything records who owns it is how unrelated work
     * gets reset. So attaching is this step's job, and it is done with
     * `git switch` rather than `git branch --force` or `git reset`: creating a
     * branch at the commit HEAD already points at moves no file and discards
     * nothing, and the staged index survives it untouched.
     *
     * The case that is refused is a detached workspace whose branch exists at
     * some *other* commit. Nothing here can tell whether that commit is an
     * earlier attempt's, a worker's, or a person's, and the three convenient
     * answers — force the branch, reset the workspace, commit onto a
     * mismatched history — are all destructive. It is the reconciliation
     * PROJECT.md section F, item 9 and section H, item 5 own.
     *
     * @return bool whether the branch was created here
     *
     * @throws CommitException when Git refuses, or the workspace is on a branch
     *                         that is not the pass's
     */
    private function attachTo(
        Repository $git,
        GenerationRecord $generation,
        BranchOwnership $ownership,
        string $head,
        ?OutputSink $sink,
    ): bool {
        $branch = $ownership->branch();
        $attached = $this->attachedBranch($git, $generation);

        if ($attached === $branch->value()) {
            return false;
        }

        if ($attached !== null) {
            throw new CommitException(
                sprintf(
                    'the workspace %s of %s is on the branch %s, which is not the pass\'s branch %s.',
                    $git->workingTree(),
                    $generation->slug(),
                    $attached,
                    $branch->value(),
                ),
                [
                    'Ralph PHP checks a task workspace out detached and attaches it to the pass\'s own '
                        . 'branch when there is a commit to put on it, so a workspace on another branch was '
                        . 'moved by something else.',
                    'Nothing was committed. The workspace is left as it is, with the implementation in it.',
                ],
            );
        }

        $tip = $ownership->tip();

        if ($tip !== null && $tip !== $head) {
            throw new CommitException(
                sprintf(
                    'the branch %s of %s is at %s while its workspace is at %s, so the two have diverged.',
                    $branch->value(),
                    $generation->slug(),
                    substr($tip, 0, TaskCommit::SHORT_LENGTH),
                    substr($head, 0, TaskCommit::SHORT_LENGTH),
                ),
                [
                    'Committing would either move the branch off the commit it is on or put this work on a '
                        . 'history it was not written against, and which of those is wanted is not something '
                        . 'Ralph PHP can decide.',
                    sprintf('Run "git log %s" to see what the branch holds.', $branch->value()),
                    'Nothing was committed. The workspace is left as it is, with the implementation in it.',
                ],
            );
        }

        $create = $tip === null;

        $result = $create
            ? $git->run('switch', '--create', $branch->value())
            : $git->run('switch', '--no-guess', $branch->value());

        if (!$result->succeeded()) {
            throw new CommitException(
                sprintf(
                    'the workspace %s of %s could not be put on the branch %s: %s',
                    $git->workingTree(),
                    $generation->slug(),
                    $branch->value(),
                    $result->failureReason(),
                ),
                [
                    sprintf(
                        'The branch is recorded as the pass\'s, so it is %s.',
                        $create ? 'created at the commit the workspace is on' : 'the branch to continue on',
                    ),
                    'Nothing was committed. The workspace is left as it is, with the implementation in it.',
                ],
            );
        }

        $sink?->note(sprintf(
            'commit: %s the branch %s at %s',
            $create ? 'created' : 'continued on',
            $branch->value(),
            substr($head, 0, TaskCommit::SHORT_LENGTH),
        ));

        return $create;
    }

    /**
     * The branch HEAD is on, or null when the workspace is detached.
     *
     * @throws CommitException when Git cannot answer
     */
    private function attachedBranch(Repository $git, GenerationRecord $generation): ?string
    {
        $result = $git->query('symbolic-ref', '--quiet', '--short', 'HEAD');

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        if ($result->exitCode() === self::NOT_A_SYMBOLIC_REF) {
            return null;
        }

        throw $this->unanswered($git, $generation, 'which branch its workspace is on', $result);
    }

    /**
     * Make the commit, and read back the object name Git gave it.
     *
     * `--cleanup=whitespace` is pinned rather than left to the repository's
     * `commit.cleanup`, for the reason {@see StagedChanges} pins
     * `--find-renames`: a project configured for `scissors` or `strip` would
     * get a different message from the same task, and a trailer block that a
     * local setting can edit is not an identifier.
     *
     * The SHA is read with a second command rather than parsed out of what
     * `git commit` printed. Its output is for people — it names a short SHA, a
     * branch and a diffstat, in a format that is explicitly not an interface —
     * while `rev-parse HEAD` is, and the thing being recorded has to be exact.
     *
     * @throws CommitException when Git refuses the commit, or the commit did
     *                         not move HEAD
     */
    private function write(
        Repository $git,
        GenerationRecord $generation,
        CommitMessage $message,
        StagedChanges $staged,
        string $parent,
        ?OutputSink $sink,
    ): string {
        $result = $git->runWithin(
            self::COMMIT_TIMEOUT_SECONDS,
            'commit',
            '--cleanup=whitespace',
            '--message',
            $message->text(),
        );

        if (!$result->succeeded()) {
            throw new CommitException(
                sprintf(
                    'the staged changes of %s in %s could not be committed: %s',
                    $generation->slug(),
                    $git->workingTree(),
                    $result->failureReason(),
                ),
                [
                    sprintf('Staged: %s.', $staged->summary()),
                    'The verification commands had already passed, so the implementation is sound as far '
                        . 'as the checks go; it is still staged in the workspace, which is left for '
                        . 'inspection.',
                    'A project hook that refuses the change, a missing Git author identity, and a signing '
                        . 'key that needs a passphrase all end here — nothing Ralph PHP runs has a terminal '
                        . 'to answer a prompt on.',
                ],
            );
        }

        $sha = $this->headOf($git, $generation);

        if ($sha === $parent) {
            throw new CommitException(
                sprintf(
                    'Git reported a commit for %s in %s but left HEAD at %s.',
                    $generation->slug(),
                    $git->workingTree(),
                    substr($parent, 0, TaskCommit::SHORT_LENGTH),
                ),
                [
                    'Nothing was recorded, because there is no new commit to record.',
                    'This is a defect in Ralph PHP rather than something to put right in the repository; '
                        . 'the message above is what to report.',
                ],
            );
        }

        $sink?->note(sprintf(
            'commit: %s %s — %s',
            substr($sha, 0, TaskCommit::SHORT_LENGTH),
            $message->subject(),
            $staged->summary(),
        ));

        return $sha;
    }

    /**
     * Persist the SHA on the pass's row.
     *
     * The commit exists by the time this runs, so a failure here is the one
     * place in section F where the repository knows something the state store
     * does not. The message says the SHA and the branch for that reason: they
     * are what finds the work, and what a reconciled resume needs to be told.
     *
     * @throws CommitException when the row cannot be written
     */
    private function record(
        GenerationRecord $generation,
        string $sha,
        TaskBranch $branch,
        ?OutputSink $sink,
    ): GenerationRecord {
        try {
            $recorded = $this->generations->recordCommit($generation, $sha);
        } catch (StateException $exception) {
            throw new CommitException(
                sprintf(
                    'commit %s of %s was made but could not be recorded: %s',
                    substr($sha, 0, TaskCommit::SHORT_LENGTH),
                    $generation->slug(),
                    $exception->getMessage(),
                ),
                [
                    sprintf('The commit is in the repository, on %s, as %s.', $branch->value(), $sha),
                    'The task is left unchecked. Resume reconciles a commit the database never learned '
                        . 'about rather than making a second one.',
                ],
            );
        }

        $sink?->note(sprintf('commit: %s recorded against %s', substr($sha, 0, TaskCommit::SHORT_LENGTH), $generation->slug()));

        return $recorded;
    }

    /**
     * Git could not answer a question about the workspace.
     */
    private function unanswered(
        Repository $git,
        GenerationRecord $generation,
        string $question,
        ProcessResult $result,
    ): CommitException {
        return new CommitException(
            sprintf(
                'Git could not say %s for %s in %s: %s',
                $question,
                $generation->slug(),
                $git->workingTree(),
                $result->failureReason(),
            ),
            [
                'Ralph PHP will not commit into a repository it cannot read: the alternative is a commit '
                    . 'on a branch nobody chose.',
                'Nothing was committed, and the implementation is still in the workspace.',
            ],
        );
    }
}
