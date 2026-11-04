<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * What the controlling checkout looked like when a run asked, and whether the
 * run may start.
 *
 * The changes are sorted into two groups by {@see ManagedPaths}: the package's
 * own, which a run reads and writes in this checkout by design, and the
 * project's, which stop it. Both groups are kept. A user told that their work
 * is in the way should be able to see the whole list, and a status report has
 * to be able to say that the only uncommitted thing in the repository is a
 * checkbox the last run ticked.
 *
 * Nothing here changes a repository, and nothing here decides to. It is a
 * reading, and the three things a tool might do with a dirty checkout —
 * stashing it, discarding it, committing it — are not expressible from this
 * type at all. {@see ControllingCheckout} turns an unrunnable reading into a
 * refusal to start, which is the whole of the package's response.
 */
final class CheckoutInspection
{
    /**
     * Changes named individually in a message.
     *
     * A checkout with hundreds of uncommitted files is a checkout whose owner
     * knows it; listing all of them would bury the remedy under the evidence.
     * The count is always exact, and `git status` is named in the hints as the
     * place to see the rest.
     */
    public const int CHANGES_IN_MESSAGE = 10;

    /**
     * @param list<WorkingTreeChange> $unrelated changes to the project's files
     * @param list<WorkingTreeChange> $managed changes to the package's files
     */
    private function __construct(
        private readonly string $workingTree,
        private readonly array $unrelated,
        private readonly array $managed,
    ) {
    }

    /**
     * Sort one reading of a working tree by whose files it concerns.
     *
     * A rename counts as the package's only when *both* of its paths do. A file
     * moved out of `.ralph/` is a change to the project however the destination
     * is spelled, and a project file moved into `.ralph/` is the same thing in
     * reverse.
     */
    public static function of(string $workingTree, WorkingTreeStatus $status, ManagedPaths $managed): self
    {
        $unrelatedChanges = [];
        $managedChanges = [];

        foreach ($status->changes() as $change) {
            $ours = true;

            foreach ($change->paths() as $path) {
                $ours = $ours && $managed->covers($path);
            }

            if ($ours) {
                $managedChanges[] = $change;
            } else {
                $unrelatedChanges[] = $change;
            }
        }

        return new self($workingTree, $unrelatedChanges, $managedChanges);
    }

    /**
     * Whether a run may start.
     *
     * The package's own uncommitted files do not stop one; the project's do.
     */
    public function isRunnable(): bool
    {
        return $this->unrelated === [];
    }

    /**
     * Whether the working tree had nothing uncommitted at all.
     */
    public function isClean(): bool
    {
        return $this->unrelated === [] && $this->managed === [];
    }

    public function workingTree(): string
    {
        return $this->workingTree;
    }

    /**
     * Uncommitted changes to the project's own files, which stop a run.
     *
     * @return list<WorkingTreeChange>
     */
    public function unrelated(): array
    {
        return $this->unrelated;
    }

    /**
     * Uncommitted changes to the package's files, which do not.
     *
     * @return list<WorkingTreeChange>
     */
    public function managed(): array
    {
        return $this->managed;
    }

    /**
     * One line saying what was found.
     */
    public function summary(): string
    {
        if ($this->isRunnable()) {
            if ($this->managed === []) {
                return sprintf('%s has no uncommitted changes', $this->workingTree);
            }

            return sprintf(
                '%s has %s, all of them to Ralph PHP\'s own files',
                $this->workingTree,
                self::countOf($this->managed),
            );
        }

        return sprintf(
            '%s has %s that are not part of Ralph PHP\'s own files',
            $this->workingTree,
            self::countOf($this->unrelated),
        );
    }

    /**
     * The blocking changes, one per line, bounded for a message.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];

        foreach (array_slice($this->unrelated, 0, self::CHANGES_IN_MESSAGE) as $change) {
            $details[] = $change->describe();
        }

        $remaining = count($this->unrelated) - self::CHANGES_IN_MESSAGE;

        if ($remaining > 0) {
            $details[] = sprintf('and %d more', $remaining);
        }

        return $details;
    }

    /**
     * What the user can do about it.
     *
     * Three remedies, all of them theirs to carry out, and the reason the run
     * stopped rather than working around them. The reason matters: a user who
     * does not know that each task is implemented in a worktree created from
     * the configured base commit has no way to see why their uncommitted work
     * is a problem rather than a starting point.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isRunnable()) {
            return [];
        }

        return [
            'Commit the changes, or set them aside yourself with "git stash".',
            'Ralph PHP will not stash, discard or commit them: a task is implemented in a worktree '
                . 'created from the configured base commit, so work that exists only in this '
                . 'checkout would be invisible to the agent and absent from the task\'s commit.',
            'Run "git status" to see the changes in full.',
            'Paths you never intend to commit can be added to .gitignore; ignored files are not '
                . 'reported here.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'working_tree' => $this->workingTree,
            'runnable' => $this->isRunnable(),
            'clean' => $this->isClean(),
            'unrelated' => array_map(static fn (WorkingTreeChange $c): array => $c->toArray(), $this->unrelated),
            'managed' => array_map(static fn (WorkingTreeChange $c): array => $c->toArray(), $this->managed),
        ];
    }

    /**
     * @param list<WorkingTreeChange> $changes
     */
    private static function countOf(array $changes): string
    {
        return count($changes) === 1 ? '1 uncommitted change' : sprintf('%d uncommitted changes', count($changes));
    }
}
