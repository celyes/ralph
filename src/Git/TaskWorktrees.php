<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use InvalidArgumentException;

/**
 * Creates the isolated workspace a task is implemented in (PROJECT.md section
 * F, item 2).
 *
 * One linked worktree per generation, checked out from the commit the run
 * recorded, at the path {@see WorktreeLayout} names. Linked rather than a copy:
 * it shares the repository's object database, so creating one costs a checkout
 * rather than a clone, the commit a task produces is already in the repository
 * the user is sitting in, and the controlling checkout is never the directory
 * the agent writes to — which is the promise
 * {@see ControllingCheckout} makes and this is the other half of.
 *
 * Preparing a workspace has exactly three outcomes, and the whole class is the
 * decision between them:
 *
 *  - **Created.** Nothing is at the path and Git has never heard of it. The
 *    ordinary case.
 *  - **Adopted.** A worktree is registered there and the directory is present.
 *    Almost always an interrupted run's, and continuing in it is how resume
 *    reaches the work already done instead of repeating it. It is *not* this
 *    run's to remove, which {@see TaskWorktree::ownership()} records.
 *  - **Refused.** Something else occupies the path. The run stops with
 *    {@see WorktreeException} rather than clearing it, because deleting files
 *    nobody asked to lose is what PROJECT.md section F, item 10 forbids, and
 *    because a directory under the package's own runtime path that Git does not
 *    know is a situation worth a person's attention rather than a guess.
 *
 * The one case that *is* cleaned up automatically is a registration whose
 * directory has gone — a `rm -rf` of a task workspace, which leaves Git's
 * administrative record behind and makes it refuse to add the path again. That
 * is the package's own leftover at the package's own path with nothing in it, so
 * `--force` recovers it. It is the narrowest possible use of that flag: a
 * missing directory cannot contain work.
 */
final class TaskWorktrees
{
    /**
     * Seconds `git worktree add` may take.
     *
     * Longer than {@see Repository::TIMEOUT_SECONDS}, because this one writes a
     * whole tree to disk and runs whatever `post-checkout` hook the repository
     * configures. Still bounded: a checkout that has not finished in five
     * minutes is a problem to report rather than to wait out, and it is bounded
     * independently of the task timeout so that a stuck checkout is not charged
     * to the agent.
     */
    public const int CHECKOUT_TIMEOUT_SECONDS = 300;

    /**
     * Mode for the directory task workspaces are created under.
     *
     * The same 0700 `ralph init` gives the runtime directory, for the same
     * reason: a worktree holds the project's source, and on a shared machine
     * the package should not be the thing that widens access to it.
     */
    private const int ROOT_MODE = 0o700;

    /**
     * Names quoted when a refusal says what occupies a workspace path. Enough
     * for a person to recognise the directory, short enough not to bury the
     * message in a listing.
     */
    private const int ENTRIES_IN_MESSAGE = 5;

    public function __construct(
        private readonly Repository $git,
        private readonly WorktreeLayout $layout,
    ) {
    }

    /**
     * The workspaces of the project this configuration describes.
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
        return new self(
            Repository::at($configuration->projectRoot(), $executable, $processes),
            WorktreeLayout::forProject($configuration),
        );
    }

    /**
     * Where workspaces are placed.
     */
    public function layout(): WorktreeLayout
    {
        return $this->layout;
    }

    /**
     * Where this pass's workspace is, or would be.
     */
    public function pathFor(TaskGeneration $generation): string
    {
        return $this->layout->pathFor($generation);
    }

    /**
     * Every worktree Git knows about in this repository.
     *
     * @throws WorktreeException when Git cannot answer
     */
    public function registered(): WorktreeList
    {
        $result = $this->git->query(...WorktreeList::COMMAND);

        if (!$result->succeeded()) {
            throw new WorktreeException(
                sprintf(
                    'the worktrees of %s could not be listed: %s',
                    $this->git->workingTree(),
                    $result->failureReason(),
                ),
                [
                    'Ralph PHP will not create a task workspace without knowing which worktrees already exist.',
                    sprintf('Run "git worktree list" in %s to see what Git reports.', $this->git->workingTree()),
                ],
            );
        }

        return WorktreeList::parse($result->standardOutput());
    }

    /**
     * The worktree already registered for this pass, or null when there is
     * none.
     *
     * For `status` and for recovery, which report what is there rather than
     * preparing it.
     *
     * @throws WorktreeException when Git cannot answer
     */
    public function existing(TaskGeneration $generation): ?RegisteredWorktree
    {
        return $this->registered()->at($this->pathFor($generation));
    }

    /**
     * Give this pass a workspace, creating one if it has none.
     *
     * Idempotent by design: calling it twice for the same generation creates
     * once and adopts afterwards, which is what makes a resumed run able to ask
     * for its workspace without first working out whether it already has one.
     *
     * @throws WorktreeException  when the path holds something that is not this
     *                            pass's worktree, or Git refuses to create one
     * @throws FilesystemException when the directory workspaces live in cannot
     *                            be created
     */
    public function prepare(TaskGeneration $generation, BaseCommit $base): TaskWorktree
    {
        $path = $this->pathFor($generation);
        $registered = $this->registered()->at($path);

        if ($registered !== null) {
            if (is_dir($path)) {
                return TaskWorktree::adopted($generation, $path, $base);
            }

            // Registered but gone. See the class note: the record is ours, the
            // directory is not there, so there is nothing to lose by replacing
            // it — and without --force Git refuses the path outright.
            return $this->add($generation, $path, $base, force: true);
        }

        $this->requireFree($generation, $path);
        $this->createRoot();

        return $this->add($generation, $path, $base, force: false);
    }

    /**
     * Refuse a path that holds anything other than this pass's worktree.
     *
     * An *empty* directory is allowed through, because `git worktree add`
     * accepts one and because that is what a user who created the path in
     * advance, or an interrupted `mkdir`, leaves behind: refusing it would stop
     * a run over a directory with nothing in it.
     *
     * @throws WorktreeException when something else is there
     */
    private function requireFree(TaskGeneration $generation, string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (!is_dir($path)) {
            throw new WorktreeException(
                sprintf(
                    '%s already exists and is not a directory, so the workspace for %s cannot be created there.',
                    $path,
                    $generation->label(),
                ),
                [
                    'Ralph PHP will not delete or overwrite it.',
                    sprintf('Move or remove %s, then run the command again.', $path),
                ],
            );
        }

        $entries = $this->entriesIn($path);

        if ($entries === []) {
            return;
        }

        throw new WorktreeException(
            sprintf(
                '%s already holds files and is not a worktree Git knows about, so the workspace for %s cannot be created there.',
                $path,
                $generation->label(),
            ),
            [
                sprintf('It contains: %s.', implode(', ', $entries)),
                'Ralph PHP will not delete or overwrite them: a directory it did not create may hold work.',
                sprintf(
                    'If an earlier run left it, "git worktree list" in %s will say so; otherwise move or remove %s and run the command again.',
                    $this->git->workingTree(),
                    $path,
                ),
            ],
        );
    }

    /**
     * Create the worktree.
     *
     * `--detach` is deliberate at this stage. The workspace is checked out at
     * the recorded base commit with no branch, which is a complete and
     * committable state; generating the task's branch name and taking ownership
     * of it is PROJECT.md section F, item 3, and doing it here would mean
     * naming a branch before anything can be recorded about who owns it.
     *
     * The base is passed as a full SHA rather than as the configured reference,
     * so that every task in a run is created from the one commit the run
     * resolved — see {@see BaseCommit}. `--end-of-options` keeps a path or a
     * commit from being read as an option; neither can be here, since the path
     * is absolute and the SHA is hexadecimal, which is exactly why it costs
     * nothing to say so.
     *
     * @throws WorktreeException when Git refuses
     */
    private function add(
        TaskGeneration $generation,
        string $path,
        BaseCommit $base,
        bool $force,
    ): TaskWorktree {
        $arguments = ['worktree', 'add', '--detach'];

        if ($force) {
            $arguments[] = '--force';
        }

        $result = $this->git->runWithin(
            self::CHECKOUT_TIMEOUT_SECONDS,
            ...[...$arguments, '--end-of-options', $path, $base->sha()],
        );

        if (!$result->succeeded()) {
            throw new WorktreeException(
                sprintf(
                    'the workspace for %s could not be created at %s: %s',
                    $generation->label(),
                    $path,
                    $result->failureReason(),
                ),
                [
                    sprintf('Base commit: %s.', $base->describe()),
                    sprintf('Run "git worktree list" in %s to see the worktrees Git knows about.', $this->git->workingTree()),
                ],
            );
        }

        return TaskWorktree::created($generation, $path, $base);
    }

    /**
     * Make sure the directory workspaces live in exists.
     *
     * `git worktree add` creates leading directories itself, which would do the
     * job and would do it with the process umask. This creates the runtime path
     * explicitly so that the mode is the one the package chose, and so that a
     * runtime directory the user has made unwritable is reported as that rather
     * than as a failed Git command.
     *
     * @throws FilesystemException when it cannot be created
     */
    private function createRoot(): void
    {
        $root = $this->layout->root();

        if (is_dir($root)) {
            return;
        }

        if (!@mkdir($root, self::ROOT_MODE, true) && !is_dir($root)) {
            throw new FilesystemException(
                sprintf('The directory task workspaces are created in, %s, could not be created.', $root),
                ['Check that the runtime directory is writable, then run the command again.'],
            );
        }
    }

    /**
     * What is in a directory, for the refusal message.
     *
     * A handful of names, not a listing: the message exists to let a person
     * recognise whose files those are.
     *
     * @return list<string>
     */
    private function entriesIn(string $path): array
    {
        $names = @scandir($path);

        if ($names === false) {
            // Unreadable is not empty. Reporting it as occupied is the safe
            // direction, and the message says so without naming anything.
            return ['contents that could not be listed'];
        }

        $entries = array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));

        sort($entries);

        if (count($entries) > self::ENTRIES_IN_MESSAGE) {
            return [
                ...array_slice($entries, 0, self::ENTRIES_IN_MESSAGE),
                sprintf('and %d more', count($entries) - self::ENTRIES_IN_MESSAGE),
            ];
        }

        return $entries;
    }
}
