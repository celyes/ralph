<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * Puts back a pass's branch reference when the commit it pointed at is still in
 * the repository.
 *
 * One of the three states recovery can find and put right (PROJECT.md
 * section H, item 5). A pass whose row records a commit has work that was
 * verified and committed; if the branch naming it has since gone — deleted by
 * hand, or lost with a repository that was repaired — the commit is still
 * there, reachable by SHA and named by nothing. Publication needs a branch to
 * push, and a reference created at a commit that already exists moves nothing,
 * discards nothing and changes no file.
 *
 * **Creating is all it does.** `git branch <name> <commit>` with no `--force`:
 * Git itself refuses a name something already holds, which is the case this
 * must never win. {@see TaskBranches} decides whether the pass may use the name
 * at all and is the reading this is called after; between them the destructive
 * answers to a branch that is in the way — forcing it, deleting it, resetting
 * it — are not reachable from either.
 *
 * Kept out of {@see TaskBranches} on purpose. That class reads ownership and
 * writes no reference, which is what makes it safe to call anywhere; a repair
 * is a deliberate, separate act and reads as one at the call site.
 */
final class BranchRestorer
{
    public function __construct(private readonly Repository $git)
    {
    }

    /**
     * The restorer for a project's own repository.
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
        return new self(Repository::at($configuration->projectRoot(), $executable, $processes));
    }

    /**
     * Create the branch at a commit, and report what it was created at.
     *
     * @param string $commit the commit the reference is created at, which the
     *        caller has already established the repository still has
     *
     * @throws BranchException when Git refused, which includes a name
     *                         something else already holds
     */
    public function restore(TaskBranch $branch, string $commit, ?OutputSink $sink = null): string
    {
        $sha = trim($commit);

        if ($sha === '') {
            throw new InvalidArgumentException(
                sprintf('A branch cannot be restored without the commit %s is to be created at.', $branch->value()),
            );
        }

        $result = $this->git->run('branch', '--end-of-options', $branch->value(), $sha);

        if (!$result->succeeded()) {
            throw $this->refused($branch, $sha, $result);
        }

        $sink?->note(sprintf(
            'recovery: re-created the branch %s at %s',
            $branch->value(),
            substr($sha, 0, CommitIdentity::SHORT_LENGTH),
        ));

        return $sha;
    }

    /**
     * A branch Git would not create.
     */
    private function refused(TaskBranch $branch, string $commit, ProcessResult $result): BranchException
    {
        return new BranchException(
            sprintf(
                'the branch %s could not be re-created at %s: %s',
                $branch->value(),
                substr($commit, 0, CommitIdentity::SHORT_LENGTH),
                $result->failureReason(),
            ),
            [
                sprintf(
                    'The commit is still in the repository; nothing was lost, and "git branch %s %s" is what '
                        . 'this was attempting.',
                    $branch->value(),
                    $commit,
                ),
                'Ralph PHP creates the reference and never moves or deletes one, so a name something else '
                    . 'holds is reported rather than taken.',
            ],
        );
    }
}
