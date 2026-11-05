<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\StagingException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * Decides what a verified task's commit will contain (PROJECT.md section F,
 * item 6).
 *
 * Called once, in the task's own worktree, after the verification commands have
 * passed and before the commit is made — which is the only point in a task's
 * life at which this question has an answer. Afterwards the commit is its own
 * evidence; before, nothing is settled. That timing is a requirement rather
 * than a convention: staging earlier would put the index in a state a failing
 * attempt then retries from, and the item says "after successful
 * verification" because the index is how a commit is built and a commit is the
 * thing a user is asked to review.
 *
 * `git add --all` would be a single command and the wrong one. A task workspace
 * at the moment an agent leaves it holds three kinds of change, and only the
 * first belongs in the commit:
 *
 *  - **The task's work** — what the agent did to the project. This is staged.
 *  - **Ralph PHP's own files** — the configuration, the checklist, the state
 *    database, anything under `.ralph/`. The runner ticks the checklist box
 *    itself, in the controlling checkout, after the commit; an agent's edit to
 *    it inside the workspace is a model writing its own progress report, which
 *    PROJECT.md section D, item 6 says is not verified execution state.
 *  - **Local files preparation copied in** — the `.env`, the `auth.json`, the
 *    machine-specific `phpunit.xml` that {@see WorkspacePreparer} brought from
 *    the controlling checkout so the project could be built at all. Committing
 *    one publishes a credential.
 *
 * The exclusions come from the two places that already know: {@see ManagedPaths}
 * for the package's own, and the project's `workspace.copy` setting for the
 * local files. The *setting* is consulted rather than what preparation did this
 * time, deliberately — on a resumed run a copied file is reported as "already
 * present" rather than copied, and an exclusion that depended on this run's
 * preparation would quietly stop excluding it. A path the project has declared
 * exists on this machine and in no commit is uncommittable on every run.
 *
 * Git's ignore rules are the third exclusion and need no code: `git status`
 * does not report an ignored path, so `vendor/` and the build output never
 * reach the partition at all. A file the agent force-added to the index is
 * dropped for the same reason, which is the right answer: the ignore rules are
 * the project's standing statement about what is not committable.
 *
 * Two limits of a path-based exclusion are worth stating plainly, because both
 * end in a commit containing something it should not:
 *
 *  - **A project that does not ignore what its preparation commands produce
 *    will see that output staged.** There is nothing here to tell a generated
 *    directory from a written one, and the staged diff summary is where it
 *    becomes visible.
 *  - **A move is not followed.** Once the index is cleared, Git reports a moved
 *    file as a deletion and an untracked file rather than as a rename — it
 *    pairs the two only in the index it has just been told to forget — so a
 *    file moved *out of* an excluded path is staged under its new name. The
 *    pairing below handles a rename Git does report, and no path-based rule can
 *    handle a secret an agent copies into a file of its own; what stops that is
 *    the review a user gives the commit.
 *
 * Nothing is interpreted generously. An unmerged path, which a fresh checkout
 * of the base commit cannot produce, means the workspace is in a state this
 * class does not model, and staging it would resolve somebody's conflict by
 * guessing; the task is refused with its work left where it is.
 */
final class WorkspaceStager
{
    /**
     * Paths passed to one `git add`.
     *
     * The command line has a length limit and a task can legitimately touch
     * hundreds of files, so the paths go in batches. `git add` is additive, so
     * batching is equivalent to one call; what it is not equivalent to is
     * atomic, and a batch that fails leaves the earlier ones staged. That is
     * why a failure here raises instead of returning a short summary: an index
     * holding part of a decision must not become a commit.
     */
    private const int PATHS_PER_COMMAND = 100;

    /**
     * @param list<string> $uncommittable paths, relative to the workspace root,
     *        that exist on this machine and in no commit
     */
    public function __construct(
        private readonly ManagedPaths $managed,
        private readonly array $uncommittable,
        private readonly string $projectRoot,
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The stager for a project, reading its managed paths and its
     * `workspace.copy` setting.
     *
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     */
    public static function forProject(
        Configuration $configuration,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(
            ManagedPaths::forProject($configuration),
            self::uncommittablePaths($configuration),
            $configuration->projectRoot(),
            $executable,
            $processes,
        );
    }

    /**
     * The `workspace.copy` paths in the form `git status` names them.
     *
     * A configured copy is relative to the project root and may be spelled with
     * a `./`, a `//` or a backslash; a path Git reports never is. The two are
     * compared literally — for the reason {@see ManagedPaths::covers()} gives:
     * a leading space and a backslash are both legal in a filename, so
     * translating one at comparison time would let an unrelated file be mistaken
     * for the configured one. So the spelling is settled once, here, against the
     * setting rather than against Git's output.
     *
     * A path that resolves outside the project is dropped rather than kept.
     * Configuration validation rejects it, and the one thing a validator that
     * had been bypassed must not do is widen what a commit contains — a path
     * outside the project cannot be reported inside the workspace, so dropping
     * it excludes nothing.
     *
     * @return list<string>
     */
    private static function uncommittablePaths(Configuration $configuration): array
    {
        $root = Path::normalize($configuration->projectRoot());
        $paths = [];

        foreach ($configuration->workspace()->copy() as $relative) {
            $resolved = Path::normalize($root . '/' . $relative);

            if ($resolved === $root || !Path::isInside($root, $resolved)) {
                continue;
            }

            $paths[] = substr($resolved, strlen(rtrim($root, '/')) + 1);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Stage the task's work, and report what the commit will contain.
     *
     * @param OutputSink|null $sink where the decisions are noted as they are
     *        made — the attempt's log and the terminal. Never closed here: the
     *        caller that opened the log owns it.
     *
     * @throws StagingException when the workspace cannot be read, cannot be
     *                          staged, or is in a state this does not model
     */
    public function stage(TaskWorktree $worktree, ?OutputSink $sink = null): StagedChanges
    {
        $path = $worktree->path();

        $this->requireWorkspace($path);

        $git = Repository::at($path, $this->executable, $this->processes);

        $this->refuseUnmergedPaths($git);
        $this->emptyTheIndex($git);

        [$included, $excluded] = $this->partition($this->changesIn($git), $sink);

        if ($included === []) {
            $sink?->note(sprintf('staging: nothing of the task\'s own to stage in %s', $path));

            return StagedChanges::nothingStaged($path, $excluded);
        }

        $this->add($git, $included);

        $changes = $this->summarise($git, $path, $excluded);

        $sink?->note(sprintf('staging: %s', $changes->summary()));

        return $changes;
    }

    /**
     * Refuse anything that is not a task workspace to stage in.
     *
     * Defence in depth, and the one check whose absence would be unrecoverable:
     * staging in the controlling checkout would put the user's own uncommitted
     * work into a task's commit, which is the thing
     * {@see ControllingCheckout} exists to prevent and this class is the last
     * opportunity to prevent.
     *
     * @throws StagingException when the path is not a usable workspace
     */
    private function requireWorkspace(string $path): void
    {
        if (Path::normalize($path) === Path::normalize($this->projectRoot)) {
            throw new StagingException(
                sprintf('A task\'s changes cannot be staged in the project directory itself, %s.', $path),
                [
                    'Ralph PHP commits from the linked worktree a task was implemented in, so that nothing '
                        . 'in your own checkout can reach a task\'s commit.',
                ],
            );
        }

        if (!is_dir($path)) {
            throw new StagingException(
                sprintf('The task workspace %s is not there, so its changes cannot be staged.', $path),
                [
                    'The implementation and the verification output are gone with it; the task is left '
                        . 'unchecked and will be attempted again.',
                ],
            );
        }
    }

    /**
     * Stop if the workspace holds a conflict.
     *
     * Asked before the index is emptied, because `git reset` resolves unmerged
     * entries into ordinary staged ones — so this is the last moment the
     * conflict is visible, and emptying first would turn "somebody's merge is
     * half finished here" into "these files are modified".
     *
     * @throws StagingException when a path is unmerged
     */
    private function refuseUnmergedPaths(Repository $git): void
    {
        $unmerged = [];

        foreach ($this->changesIn($git)->changes() as $change) {
            if ($change->isUnmerged()) {
                $unmerged[] = $change->path();
            }
        }

        if ($unmerged === []) {
            return;
        }

        throw new StagingException(
            sprintf(
                'the task workspace %s has %d unresolved conflict(s): %s.',
                $git->workingTree(),
                count($unmerged),
                implode(', ', array_slice($unmerged, 0, CheckoutInspection::CHANGES_IN_MESSAGE)),
            ),
            [
                'A task workspace is a fresh checkout of the base commit, so there is nothing in it for '
                    . 'Ralph PHP to merge: something else merged, rebased or cherry-picked here.',
                'Nothing was staged and nothing was committed. The workspace is left as it is, with the '
                    . 'conflict in it, because resolving one by staging it would be guessing at which side '
                    . 'was wanted.',
            ],
        );
    }

    /**
     * Reset the index to HEAD, so that what is staged is only what this decides.
     *
     * The index is not necessarily empty when staging begins. An interrupted
     * run may have staged and been killed before committing, and an agent is
     * free to run `git add` itself — so without this, a commit could contain
     * something nobody here chose, which is precisely what "stage only
     * task-workspace changes" rules out.
     *
     * Nothing can be lost. A mixed reset moves no files and no references: it
     * is the index alone, every change is still in the working tree
     * afterwards, and the paths that ought to be staged are staged again a
     * moment later by rules that can be read.
     *
     * @throws StagingException when Git cannot do it
     */
    private function emptyTheIndex(Repository $git): void
    {
        $result = $git->run('reset', '--quiet', '--mixed');

        if ($result->succeeded()) {
            return;
        }

        throw new StagingException(
            sprintf(
                'the index of the task workspace %s could not be cleared before staging: %s',
                $git->workingTree(),
                $result->failureReason(),
            ),
            [
                'Ralph PHP stages a task from a known index rather than adding to whatever was already '
                    . 'there, so that a commit cannot contain something nobody chose.',
                'Nothing was committed, and the implementation is still in the workspace.',
            ],
        );
    }

    /**
     * Everything uncommitted in the workspace, every untracked file named.
     *
     * @throws StagingException when Git cannot answer
     */
    private function changesIn(Repository $git): WorkingTreeStatus
    {
        $result = $git->query(...WorkingTreeStatus::COMMAND_ALL_UNTRACKED);

        if (!$result->succeeded()) {
            throw new StagingException(
                sprintf(
                    'the changes in the task workspace %s could not be read: %s',
                    $git->workingTree(),
                    $result->failureReason(),
                ),
                [
                    'Ralph PHP will not stage a workspace it cannot list: the alternative is a commit whose '
                        . 'contents nobody decided.',
                    sprintf('Run "git status" in %s to see what Git reports.', $git->workingTree()),
                ],
            );
        }

        try {
            return WorkingTreeStatus::parse($result->standardOutput());
        } catch (InvalidArgumentException $exception) {
            throw $this->unreadable($git->workingTree(), $exception);
        }
    }

    /**
     * Sort the workspace's changes into what the commit gets and what it does
     * not.
     *
     * A rename Git has paired is excluded when *either* of its paths is, which
     * is the strict reading and the right one: staging half a rename would
     * commit a deletion with nothing to replace it. See the class note for why
     * a move in a task workspace is usually not a pair at all.
     *
     * @return array{list<string>, list<ExcludedChange>}
     */
    private function partition(WorkingTreeStatus $status, ?OutputSink $sink): array
    {
        $included = [];
        $excluded = [];

        foreach ($status->changes() as $change) {
            $reason = $this->reasonToExclude($change);

            if ($reason === null) {
                foreach ($change->paths() as $path) {
                    $included[] = $path;
                }

                continue;
            }

            $exclusion = ExcludedChange::of($change, $reason);
            $excluded[] = $exclusion;
            $sink?->note(sprintf('staging: left out %s', $exclusion->describe()));
        }

        return [array_values(array_unique($included)), $excluded];
    }

    /**
     * Why this change stays out of the commit, or null when it belongs in it.
     */
    private function reasonToExclude(WorkingTreeChange $change): ?ExclusionReason
    {
        foreach ($change->paths() as $path) {
            if ($this->managed->covers($path)) {
                return ExclusionReason::Runtime;
            }

            if (in_array($path, $this->uncommittable, true)) {
                return ExclusionReason::Local;
            }
        }

        return null;
    }

    /**
     * Stage the chosen paths, in batches.
     *
     * `--all` says that a removed file is a change to stage, so a task that
     * deleted something commits the deletion; with a pathspec it applies to
     * those paths and no others. It is redundant on every supported Git — `git
     * add` has staged removals by default since 2.0 — and passed anyway, so
     * that the intention is in the command rather than in a note about which
     * version changed its mind.
     *
     * `--literal-pathspecs` is not redundant, and is the one flag here that
     * prevents a leak. A pathspec is a pattern, so `[` and `*` in a filename
     * are syntax; Git prefers an exact match when the named file exists, which
     * is almost always, but a *deleted* path has no file to match — and there
     * it falls back to the glob. A task that deleted `.en?` would stage the
     * `.env` beside it. Every path here came from Git in the first place, so
     * reading one back as a pattern can only ever be wrong.
     *
     * @param list<string> $paths
     *
     * @throws StagingException when Git refuses a path
     */
    private function add(Repository $git, array $paths): void
    {
        foreach (array_chunk($paths, self::PATHS_PER_COMMAND) as $batch) {
            $result = $git->run('--literal-pathspecs', 'add', '--all', '--', ...$batch);

            if ($result->succeeded()) {
                continue;
            }

            throw new StagingException(
                sprintf(
                    'the task\'s changes in %s could not be staged: %s',
                    $git->workingTree(),
                    $result->failureReason(),
                ),
                [
                    sprintf(
                        'Git was asked to stage %d path(s), beginning with %s.',
                        count($batch),
                        $batch[0],
                    ),
                    'Nothing was committed. The implementation is still in the workspace, which is left as '
                        . 'it is for inspection.',
                ],
            );
        }
    }

    /**
     * Read what is now in the index.
     *
     * @param list<ExcludedChange> $excluded
     *
     * @throws StagingException when Git cannot answer, or answers in a form
     *                          this cannot read
     */
    private function summarise(Repository $git, string $path, array $excluded): StagedChanges
    {
        $outputs = [];

        foreach ([StagedChanges::NAME_STATUS_COMMAND, StagedChanges::NUMSTAT_COMMAND] as $command) {
            $result = $git->query(...$command);

            if (!$result->succeeded()) {
                throw new StagingException(
                    sprintf(
                        'what was staged in the task workspace %s could not be read: %s',
                        $git->workingTree(),
                        $result->failureReason(),
                    ),
                    [
                        'The staged changes are the record of what a task\'s commit contains, so Ralph PHP '
                            . 'will not commit without them.',
                        'Nothing was committed, and the implementation is still in the workspace.',
                    ],
                );
            }

            $outputs[] = $result->standardOutput();
        }

        try {
            return StagedChanges::parse($path, $outputs[0], $outputs[1], $excluded);
        } catch (InvalidArgumentException $exception) {
            throw $this->unreadable($git->workingTree(), $exception);
        }
    }

    /**
     * Git said something this cannot read.
     *
     * Wrapped rather than left to surface as an unexpected error, which is what
     * {@see ControllingCheckout} does with the same failure, because of where
     * in a task this one happens: the agent has run and the checks have passed,
     * so the message has to say where the work is rather than only that
     * something is wrong.
     */
    private function unreadable(string $workspace, InvalidArgumentException $exception): StagingException
    {
        return new StagingException(
            sprintf(
                'Git described the task workspace %s in a form Ralph PHP cannot read: %s',
                $workspace,
                $exception->getMessage(),
            ),
            [
                'Nothing was staged into a commit. The implementation and the verification output are '
                    . 'preserved in the workspace.',
                'This is a defect in Ralph PHP rather than something to put right in the repository; the '
                    . 'message above is what to report.',
            ],
        );
    }
}
