<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\BranchRestorer;
use Celyes\RalphPhp\Git\CommitIdentity;
use Celyes\RalphPhp\Git\CommitReader;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;

/**
 * Makes an interrupted pass's row tell the truth about the repository, before
 * anything resumes it (PROJECT.md section H, item 5).
 *
 * A run that stopped was stopped somewhere, and the three things a pass owns —
 * its workspace, its branch, and its commit — are each recorded a moment after
 * they happen rather than at the same instant. So a row is a copy, and the
 * named case of item 5 is the gap that matters most: a crash **immediately
 * after a commit and before the database update** leaves a real commit in the
 * repository that no row names. Resume cannot see it, {@see TaskPass} would
 * invoke the agent again, and the task would be implemented and committed
 * twice — the duplicate acceptance criterion 6 rules out.
 *
 * What makes the commit findable is that {@see \Celyes\RalphPhp\Git\TaskCommitter}
 * wrote the identifiers into the commit itself and claimed the branch in the row
 * *before* creating it. So the row names the branch, the branch names the
 * commit, and the commit says which task, pass and attempt produced it
 * ({@see CommitIdentity}). Reconciliation is reading that chain and either
 * adopting what it finds or refusing to.
 *
 * **Adopting is a reading; the rest is a guess.** A commit whose trailers name
 * this pass, sitting one commit past the base the row records, is this pass's
 * commit and nothing else — so it is adopted, and the next thing to happen is a
 * push or a checklist write rather than an agent. Everything else blocks: a
 * branch holding a commit no pass recorded, a commit the row names that the
 * repository no longer has, a branch that has moved past it, a workspace
 * registered somewhere this project does not put workspaces. Each of those has
 * a convenient answer that destroys something — adopt a stranger's commit,
 * commit again over work that is already there, force a branch — and none of
 * them is a decision a runner can make (section H, item 9).
 *
 * **Nothing is undone, deleted or forced.** The two writes this can make are a
 * SHA into a column the row left null, and a branch reference re-created at a
 * commit the row already records ({@see BranchRestorer}). Worktrees, logs and
 * commits are left exactly as the stopped run left them, which is what keeps
 * `ralph retry` and a person's own inspection possible afterwards.
 *
 * **The verdict is returned, not acted on.** Blocking a pass, closing a run, or
 * carrying on to the next phase are the caller's decisions: the same reading
 * means "stop here" to `ralph resume` and "this is what you are retrying over"
 * to `ralph retry`.
 */
final class PassRecovery
{
    /**
     * How many commits past the base a finished pass leaves.
     *
     * A task workspace is checked out at the run's base commit and a pass makes
     * one commit on top of it. Two means something committed that this package
     * did not, and which of them is the pass's is not a question the trailers
     * can settle — the commit that is not the pass's may be a worker's, an
     * earlier attempt's, or a person's.
     */
    private const int COMMITS_PER_PASS = 1;

    public function __construct(
        private readonly GenerationStore $generations,
        private readonly TaskWorktrees $worktrees,
        private readonly TaskBranches $branches,
        private readonly BranchNamer $namer,
        private readonly CommitReader $commits,
        private readonly BranchRestorer $restorer,
    ) {
    }

    /**
     * Recovery for a project.
     *
     * @param GenerationStore $generations the run's own store, so that a row
     *        this writes is read back through the same instance the caller uses
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
            $generations,
            TaskWorktrees::forProject($configuration, $executable, $processes),
            TaskBranches::forProject($configuration, $executable, $processes),
            BranchNamer::forProject($configuration),
            CommitReader::forProject($configuration, $executable, $processes),
            BranchRestorer::forProject($configuration, $executable, $processes),
        );
    }

    /**
     * Reconcile one pass against the repository.
     *
     * @param GenerationRecord $record the pass as the database has it, which
     *        may be a run's own copy from before the crash
     *
     * @throws StateException     when the pass is finished with, or the row
     *                            cannot be written
     * @throws BranchException    when the repository cannot be asked about the
     *                            branch, or refused to re-create it
     * @throws CommitException    when the repository cannot be asked about a
     *                            commit
     * @throws WorktreeException  when Git cannot list the worktrees
     */
    public function reconcile(GenerationRecord $record, ?OutputSink $sink = null): Recovery
    {
        $this->requireOpen($record);

        $workspace = $this->workspace($record);
        $findings = $workspace['findings'];

        if ($workspace['blocker'] !== null) {
            return Recovery::blocked($record, $workspace['blocker'], $findings);
        }

        if ($record->hasCommit()) {
            return $this->withRecordedCommit($record, $findings, $sink);
        }

        if ($record->isNoChange()) {
            return $this->withNoChange($record, $findings);
        }

        return $this->withoutRecordedCommit($record, $findings, $sink);
    }

    /**
     * Refuse a pass that is finished with.
     *
     * A completed or skipped pass is not resumed: its commit is made, its box
     * is written and its worktree may legitimately have been cleaned up, so
     * every reading below would report the absence of things nothing owes. A
     * task whose box a user unticks is reopened as a new generation
     * (PROJECT.md section C, item 8), which arrives here as a different row.
     *
     * @throws StateException when the pass is closed
     */
    private function requireOpen(GenerationRecord $record): void
    {
        if ($record->state()->isOpen()) {
            return;
        }

        throw new StateException(
            sprintf(
                'Generation %s is %s, so there is nothing of it to reconcile.',
                $record->slug(),
                $record->state()->value,
            ),
            [
                'Reconciliation puts an interrupted pass\'s row back in step with the repository; a pass '
                    . 'that finished has nothing outstanding to put back.',
                'A completed task that is unticked is taken on as a new pass, with its own branch, rather '
                    . 'than by continuing this one.',
            ],
        );
    }

    /**
     * What the pass's workspace is, and whether it can be reasoned about.
     *
     * Read first and on its own, because the three later answers do not depend
     * on it: a pass with a commit owes nothing to its workspace, and a pass
     * without one needs a workspace it can be given rather than the one it had.
     * What is refused is a workspace this project would not have created, since
     * preparing a second one for the same pass would hand the agent an empty
     * checkout while its work sat in a directory nothing looked at.
     *
     * @return array{findings: list<string>, blocker: ?Blocker}
     *
     * @throws WorktreeException when Git cannot list the worktrees
     */
    private function workspace(GenerationRecord $record): array
    {
        $recorded = $record->worktree();
        $path = $this->worktrees->pathFor($record->generation());

        if (!$recorded->exists()) {
            return ['findings' => ['no workspace was recorded for this pass'], 'blocker' => null];
        }

        if (!$recorded->isAt($path)) {
            return [
                'findings' => [sprintf('its recorded workspace is %s', (string) $recorded->path())],
                'blocker' => Blocker::raised(
                    sprintf(
                        '%s recorded its workspace at %s, and this project puts task workspaces in %s, so '
                            . 'which directory holds its work cannot be established.',
                        $record->slug(),
                        (string) $recorded->path(),
                        $path,
                    ),
                    sprintf(
                        'Resume from the directory the interrupted run was started in. If the project itself '
                            . 'moved, inspect %s for work worth keeping and retry the task, which starts it '
                            . 'again from the base commit.',
                        (string) $recorded->path(),
                    ),
                ),
            ];
        }

        $registered = $this->worktrees->existing($record->generation()) !== null;
        $present = is_dir($path);

        if ($registered && $present) {
            return ['findings' => [sprintf('its workspace %s is still in place', $path)], 'blocker' => null];
        }

        if ($registered) {
            return [
                'findings' => ['its workspace is registered with Git and its directory has gone; '
                    . 'it will be checked out again from the base commit'],
                'blocker' => null,
            ];
        }

        if (!$present) {
            return [
                'findings' => [sprintf(
                    'its workspace %s has gone, %s',
                    $path,
                    $record->hasImplementation()
                        ? 'and its recorded work does not need it'
                        : 'so whatever the agent had written in it is lost',
                )],
                'blocker' => null,
            ];
        }

        // A directory in the right place that Git does not list is the one
        // workspace state that cannot simply be re-created: `git worktree add`
        // refuses a path holding files it did not create, and this package will
        // not delete a directory it cannot account for. A pass whose work is
        // already committed does not need it, so only the other case stops.
        if ($record->hasImplementation()) {
            return [
                'findings' => [sprintf(
                    '%s is no longer a worktree Git knows about, and the pass\'s recorded work does not need it',
                    $path,
                )],
                'blocker' => null,
            ];
        }

        return [
            'findings' => [],
            'blocker' => Blocker::raised(
                sprintf(
                    '%s is the workspace %s recorded, and Git no longer lists it as a worktree, so it cannot '
                        . 'be committed from and will not be replaced.',
                    $path,
                    $record->slug(),
                ),
                sprintf(
                    'Inspect %s for work worth keeping. "git worktree repair %s" restores the registration '
                        . 'where the repository still has it; otherwise move the directory aside and resume, '
                        . 'which checks the task out again from the base commit.',
                    $path,
                    $path,
                ),
            ),
        ];
    }

    /**
     * Reconcile a pass whose row already names a commit.
     *
     * The row is the claim and the repository is the authority, so the commit
     * is read back rather than trusted. What this must never do is let a push
     * or a ticked box rest on a commit that is not there, or on a branch that
     * has moved on to something else.
     *
     * @param list<string> $findings
     *
     * @throws RalphException when the repository cannot be asked, or the
     *         reference cannot be put back
     */
    private function withRecordedCommit(GenerationRecord $record, array $findings, ?OutputSink $sink): Recovery
    {
        $sha = (string) $record->commitSha();
        $identity = $this->commits->identify($sha);

        if ($identity === null) {
            return Recovery::blocked(
                $record,
                Blocker::raised(
                    sprintf(
                        '%s records commit %s, and the repository does not have it.',
                        $record->slug(),
                        $sha,
                    ),
                    sprintf(
                        'Nothing was changed. Look for the commit in "git reflog" and in any clone it was '
                            . 'pushed to; if it is really gone, "ralph retry %s" implements the task again '
                            . 'from the base commit.',
                        $record->task()->value(),
                    ),
                ),
                $findings,
            );
        }

        if (!$record->hasBranch()) {
            return Recovery::blocked(
                $record,
                Blocker::raised(
                    sprintf(
                        '%s records commit %s and no branch, so there is no reference for it to be published '
                            . 'from.',
                        $record->slug(),
                        $identity->shortSha(),
                    ),
                    sprintf(
                        'A pass records its branch before creating it, so this row cannot have been written '
                            . 'by a complete commit. The commit is intact: "git show %s". Report this, and '
                            . 'use "git branch <name> %s" to name it before publishing it by hand.',
                        $sha,
                        $sha,
                    ),
                ),
                $findings,
                $identity,
            );
        }

        $branch = $this->branchOf($record);
        $tip = $this->branches->tipOf($branch);

        if ($tip === null) {
            return $this->restoreBranch($record, $branch, $identity, $findings, $sink);
        }

        if ($tip === $identity->sha()) {
            $findings[] = sprintf('%s holds its commit %s', $branch->value(), $identity->shortSha());

            return Recovery::consistent($record, $findings, $identity);
        }

        return Recovery::blocked(
            $record,
            Blocker::raised(
                sprintf(
                    '%s records commit %s on %s, and that branch now points at %s, so what would be '
                        . 'published is not what the pass committed.',
                    $record->slug(),
                    $identity->shortSha(),
                    $branch->value(),
                    substr($tip, 0, CommitIdentity::SHORT_LENGTH),
                ),
                sprintf(
                    'Nothing was changed, and the pass\'s commit is still in the repository: "git show %s". '
                        . 'Decide what the branch should hold — %s — then resume.',
                    $identity->sha(),
                    $this->commits->contains($tip, $identity->sha())
                        ? sprintf(
                            'it has moved %s past the pass\'s, and none of that is this pass\'s work',
                            self::commits($this->commits->countBetween($identity->sha(), $tip)),
                        )
                        : 'it no longer contains the pass\'s commit at all',
                ),
            ),
            $findings,
            $identity,
        );
    }

    /**
     * Put back a branch reference whose commit the row still records.
     *
     * The name is the pass's: the schema allows one generation per branch, so a
     * name this row holds is a name no other row can, and a reference under it
     * would have been read as the branch's tip rather than as nothing. What is
     * left is the reference being created between that reading and this write,
     * and {@see BranchRestorer} refuses that rather than forcing it — raised
     * instead of returned, because an ownership conflict that appears inside a
     * recovery is not a state the recovery can describe.
     *
     * @param list<string> $findings
     *
     * @throws BranchException when Git refused to create the reference
     */
    private function restoreBranch(
        GenerationRecord $record,
        TaskBranch $branch,
        CommitIdentity $identity,
        array $findings,
        ?OutputSink $sink,
    ): Recovery {
        $this->restorer->restore($branch, $identity->sha(), $sink);

        return Recovery::reconciled(
            $record,
            [sprintf(
                're-created the branch %s at the recorded commit %s',
                $branch->value(),
                $identity->shortSha(),
            )],
            $findings,
            $identity,
        );
    }

    /**
     * Reconcile a pass whose outcome was a verified no change.
     *
     * PROJECT.md section G, item 6's outcome has an implementation and no
     * commit on purpose, so the reconciliation is the opposite of every other
     * case: what would contradict the row is a commit, not the absence of one.
     * A branch that has acquired one is refused rather than adopted — the
     * evidence that nothing needed changing was collected and recorded against
     * an empty index, and a commit on that branch means one of the two is wrong.
     *
     * @param list<string> $findings
     *
     * @throws RalphException when the repository cannot be asked
     */
    private function withNoChange(GenerationRecord $record, array $findings): Recovery
    {
        if (!$record->hasBranch()) {
            $findings[] = 'it was recorded as changing nothing, and claimed no branch';

            return Recovery::consistent($record, $findings);
        }

        $branch = $this->branchOf($record);
        $tip = $this->branches->tipOf($branch);

        if ($tip === null || $tip === $record->baseSha()) {
            $findings[] = sprintf(
                'it was recorded as changing nothing, and %s holds no commit of its own',
                $branch->value(),
            );

            return Recovery::consistent($record, $findings);
        }

        $identity = $this->commits->identify($tip);

        return Recovery::blocked(
            $record,
            Blocker::raised(
                sprintf(
                    '%s was recorded as leaving the task unchanged, and the branch it claimed, %s, points at '
                        . '%s rather than at the base commit.',
                    $record->slug(),
                    $branch->value(),
                    $identity === null
                        ? substr($tip, 0, CommitIdentity::SHORT_LENGTH)
                        : $identity->describe(),
                ),
                sprintf(
                    'Nothing was changed. A no-change outcome rests on there having been nothing to commit, '
                        . 'so a commit on that branch contradicts the evidence it was recorded with. Inspect '
                        . '"git log %s" and the pass\'s satisfaction record, then retry the task if the work '
                        . 'is wanted.',
                    $branch->value(),
                ),
            ),
            $findings,
            $identity,
        );
    }

    /**
     * Reconcile a pass whose row names no commit.
     *
     * The named case of section H, item 5 is in here: the row was never
     * written, so the only evidence that the pass committed is on the branch it
     * claimed. A commit whose trailers name this pass, one commit past the base
     * the row records, is that commit and is adopted; anything else on that
     * branch stops the pass.
     *
     * @param list<string> $findings
     *
     * @throws RalphException when the repository cannot be asked, or the row
     *         cannot be written
     */
    private function withoutRecordedCommit(GenerationRecord $record, array $findings, ?OutputSink $sink): Recovery
    {
        if (!$record->hasBranch()) {
            $findings[] = 'it had claimed no branch, so it had committed nothing';

            return Recovery::consistent($record, $findings);
        }

        $branch = $this->branchOf($record);
        $tip = $this->branches->tipOf($branch);

        if ($tip === null) {
            $findings[] = sprintf('it claimed %s, which was never created', $branch->value());

            return Recovery::consistent($record, $findings);
        }

        if ($tip === $record->baseSha()) {
            $findings[] = sprintf('%s is still at the base commit, so nothing was committed', $branch->value());

            return Recovery::consistent($record, $findings);
        }

        $identity = $this->commits->identify($tip);

        if ($identity === null || !$identity->names($record->generation())) {
            return Recovery::blocked(
                $record,
                Blocker::raised(
                    sprintf(
                        '%s records no commit, and the branch it claimed, %s, points at %s, which %s.',
                        $record->slug(),
                        $branch->value(),
                        substr($tip, 0, CommitIdentity::SHORT_LENGTH),
                        $this->whose($identity, $record),
                    ),
                    sprintf(
                        'Nothing was changed. A commit is only taken on when it says in its own trailers '
                            . 'that this pass made it, since adopting any other would publish work no check '
                            . 'of this run ran against. Inspect it with "git log %s" and either move the '
                            . 'branch aside or retry the task.',
                        $branch->value(),
                    ),
                ),
                $findings,
                $identity,
            );
        }

        $past = $this->commits->countBetween($record->baseSha(), $tip);

        if ($past !== self::COMMITS_PER_PASS) {
            return Recovery::blocked(
                $record,
                Blocker::raised(
                    sprintf(
                        '%s claimed %s, whose tip %s says it is this pass\'s commit, and the branch holds %s '
                            . 'past the base commit %s rather than one.',
                        $record->slug(),
                        $branch->value(),
                        $identity->shortSha(),
                        self::commits($past),
                        substr($record->baseSha(), 0, CommitIdentity::SHORT_LENGTH),
                    ),
                    sprintf(
                        'Nothing was changed. A pass commits once, so which of those commits is the pass\'s '
                            . 'work cannot be established from the branch. Inspect "git log %s..%s" and '
                            . 'either move the branch aside or retry the task.',
                        $record->baseSha(),
                        $branch->value(),
                    ),
                ),
                $findings,
                $identity,
            );
        }

        $against = $identity->base();

        if ($against !== null && $against !== $record->baseSha()) {
            return Recovery::blocked(
                $record,
                Blocker::raised(
                    sprintf(
                        '%s claimed %s, whose tip %s says it was written against the base commit %s, and the '
                            . 'pass records %s as its base.',
                        $record->slug(),
                        $branch->value(),
                        $identity->shortSha(),
                        substr($against, 0, CommitIdentity::SHORT_LENGTH),
                        substr($record->baseSha(), 0, CommitIdentity::SHORT_LENGTH),
                    ),
                    'Nothing was changed. The commit is this pass\'s but belongs to a run started from a '
                        . 'different base, so resuming on it would publish work verified against something '
                        . 'else. Retry the task to implement it against the recorded base.',
                ),
                $findings,
                $identity,
            );
        }

        // The crash of PROJECT.md section H, item 5: the commit was made, the
        // process stopped before the row could say so. Recorded now, which is
        // what makes the pass's next step a push or a checklist write rather
        // than another invocation of the agent.
        $recorded = $this->generations->recordCommit($record, $identity->sha());

        $sink?->note(sprintf(
            'recovery: adopted the commit %s, which %s made and did not record',
            $identity->shortSha(),
            $record->slug(),
        ));

        return Recovery::reconciled(
            $recorded,
            [sprintf(
                'adopted the commit %s from %s, which the interrupted run made and did not record',
                $identity->shortSha(),
                $branch->value(),
            )],
            $findings,
            $identity,
            adopted: true,
        );
    }

    /**
     * A number of commits, counted in words a person reads.
     */
    private static function commits(int $count): string
    {
        return sprintf('%d commit%s', $count, $count === 1 ? '' : 's');
    }

    /**
     * Whose a commit on the pass's branch is, said as a clause.
     */
    private function whose(?CommitIdentity $identity, GenerationRecord $record): string
    {
        if ($identity === null) {
            return 'the repository cannot read as a commit';
        }

        if (!$identity->isRalphCommit()) {
            return sprintf('no pass recorded: "%s"', $identity->subject());
        }

        if ($identity->namesTask($record->task())) {
            return sprintf(
                'names generation %d of this task rather than generation %d',
                (int) $identity->generation(),
                $record->number(),
            );
        }

        return sprintf('names the task %s', (string) $identity->task());
    }

    /**
     * The branch the pass owns, from its row.
     *
     * The recorded name, never a freshly generated one: {@see BranchNamer}
     * returns what the row holds when it holds anything, and the description
     * that would be needed to generate the readable half is not something
     * recovery has — nor should it matter, since a pass keeps the name it
     * claimed.
     *
     * @throws StateException when the recorded name is not one Git could hold
     */
    private function branchOf(GenerationRecord $record): TaskBranch
    {
        return $this->namer->forRecord($record);
    }
}
