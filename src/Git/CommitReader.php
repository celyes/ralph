<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * Reads commits back out of a repository: what one says about itself, and how
 * it sits in a branch's history.
 *
 * The questions recovery has to ask (PROJECT.md section H, item 5). A run that
 * stopped between making a commit and recording its SHA left the commit in the
 * repository and nothing in the state database pointing at it, so the only way
 * to find that work is to look at the branch the row claims and read what the
 * commit there says it is ({@see CommitIdentity}). Two further questions decide
 * whether it may be adopted: whether it is the only commit past the recorded
 * base, and whether a commit the row already names is still in the branch's
 * history.
 *
 * Every command is a {@see Repository::query()}, so nothing here takes an index
 * lock of a repository the user has open and nothing here writes. A question
 * Git could not answer is raised rather than read as a no — the answers decide
 * whether a commit is adopted into a pass's row, and guessing in either
 * direction either duplicates work or abandons it.
 */
final class CommitReader
{
    /**
     * Resolve a reference and insist it is a commit.
     *
     * `--verify` makes a reference that is not there a failure rather than
     * output echoing the name back, `--quiet` keeps Git's own complaint off the
     * terminal, and `--end-of-options` keeps a reference from being read as an
     * option. The `^{commit}` the caller's reference is peeled with is what
     * refuses a tag or a tree: a branch and a tag of the same name are both
     * that name to `git rev-parse`, and a tree is not something a pass can have
     * committed.
     *
     * @var list<string>
     */
    private const array RESOLVE_COMMAND = ['rev-parse', '--verify', '--quiet', '--end-of-options'];

    /**
     * Read one commit's whole message, subject and trailers together.
     *
     * `%B` is the raw body as it was written, rather than the subject and body
     * Git would reflow, because the trailer block is what is being read and a
     * reformatted copy of it is not the thing the commit says. The trailing
     * `--` is the revision/path separator: the argument before it is already a
     * resolved object name, and this is the second lock on the same door.
     *
     * @var list<string>
     */
    private const array MESSAGE_COMMAND = ['log', '--no-patch', '--max-count=1', '--format=%B'];

    /**
     * Whether one commit is in another's history.
     *
     * Exits 0 for yes and 1 for no, which is why the status is read rather than
     * any output.
     *
     * @var list<string>
     */
    private const array ANCESTRY_COMMAND = ['merge-base', '--is-ancestor', '--end-of-options'];

    /**
     * How many commits one reference has that another does not.
     *
     * @var list<string>
     */
    private const array COUNT_COMMAND = ['rev-list', '--count', '--end-of-options'];

    /**
     * Git's exit status for a reference that simply is not there.
     *
     * The same distinction {@see TaskBranches} draws: with `--quiet` this case
     * has nothing to show a user, while any other nonzero status has a reason
     * worth quoting.
     */
    private const int REFERENCE_NOT_FOUND = 1;

    /**
     * Git's exit status for "no, it is not an ancestor".
     */
    private const int NOT_AN_ANCESTOR = 1;

    public function __construct(private readonly Repository $git)
    {
    }

    /**
     * The reader for a project's own repository.
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
     * What the commit at a reference says about itself, or null when the
     * repository has no such commit.
     *
     * Null covers both "no such reference" and "that reference is not a
     * commit", because a recovery reading them differently would have nothing
     * different to do about them. What it does not cover is a repository that
     * could not be asked, which is raised.
     *
     * @throws CommitException when Git could not answer
     */
    public function identify(string $reference): ?CommitIdentity
    {
        $sha = $this->resolve($reference);

        if ($sha === null) {
            return null;
        }

        return CommitIdentity::of($sha, $this->messageOf($sha));
    }

    /**
     * The commit a reference names, or null when the repository has no such
     * commit.
     *
     * @throws CommitException when Git could not answer
     */
    public function resolve(string $reference): ?string
    {
        $name = trim($reference);

        if ($name === '') {
            throw new InvalidArgumentException('A commit cannot be read from an empty reference.');
        }

        $result = $this->git->query(...[...self::RESOLVE_COMMAND, $name . '^{commit}']);

        if ($result->succeeded()) {
            return $result->trimmedOutput();
        }

        if ($result->exitCode() === self::REFERENCE_NOT_FOUND) {
            return null;
        }

        throw $this->unanswered(
            sprintf('whether %s is a commit', $name),
            $result,
            sprintf('git rev-parse --verify %s^{commit}', $name),
        );
    }

    /**
     * Whether the repository has this commit.
     *
     * @throws CommitException when Git could not answer
     */
    public function exists(string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }

    /**
     * Whether one commit is in another's history.
     *
     * A commit is its own ancestor, which is Git's answer and the useful one
     * here: a branch whose tip *is* the recorded commit still contains it.
     *
     * @throws CommitException when Git could not answer, which includes either
     *                         reference not being a commit the repository has
     */
    public function contains(string $within, string $commit): bool
    {
        $result = $this->git->query(...[...self::ANCESTRY_COMMAND, trim($commit), trim($within)]);

        if ($result->succeeded()) {
            return true;
        }

        if ($result->exitCode() === self::NOT_AN_ANCESTOR) {
            return false;
        }

        throw $this->unanswered(
            sprintf('whether %s is still in the history of %s', self::quote($commit), self::quote($within)),
            $result,
            sprintf('git merge-base --is-ancestor %s %s', trim($commit), trim($within)),
        );
    }

    /**
     * How many commits a reference has that an ancestor does not.
     *
     * One is what a finished pass leaves: a task workspace is checked out at
     * the run's base commit and a pass makes a single commit on top of it. Zero
     * means nothing was committed, and more than one means something committed
     * that this package did not — which is a reconciliation rather than a
     * reading, and why this returns the figure instead of a verdict.
     *
     * @throws CommitException when Git could not answer
     */
    public function countBetween(string $ancestor, string $descendant): int
    {
        $range = sprintf('%s..%s', trim($ancestor), trim($descendant));
        $result = $this->git->query(...[...self::COUNT_COMMAND, $range]);

        if (!$result->succeeded() || preg_match('/^\d+$/', $result->trimmedOutput()) !== 1) {
            throw $this->unanswered(
                sprintf('how far %s is past %s', self::quote($descendant), self::quote($ancestor)),
                $result,
                'git rev-list --count ' . $range,
            );
        }

        return (int) $result->trimmedOutput();
    }

    /**
     * One commit's message, as it was written.
     *
     * @throws CommitException when Git could not answer
     */
    private function messageOf(string $sha): string
    {
        $result = $this->git->query(...[...self::MESSAGE_COMMAND, $sha, '--']);

        if (!$result->succeeded()) {
            throw $this->unanswered(
                sprintf('what commit %s says', self::quote($sha)),
                $result,
                sprintf('git log -1 --format=%%B %s', $sha),
            );
        }

        return $result->standardOutput();
    }

    /**
     * A commit as it is quoted to a person.
     */
    private static function quote(string $reference): string
    {
        $name = trim($reference);

        return preg_match('/^[0-9a-f]{40}$/', $name) === 1
            ? substr($name, 0, CommitIdentity::SHORT_LENGTH)
            : $name;
    }

    /**
     * A question Git did not answer.
     *
     * Refused rather than guessed, in the one direction that cannot lose work:
     * a recovery that does not know what a commit is must neither adopt it nor
     * leave the work it may hold behind.
     */
    private function unanswered(string $question, ProcessResult $result, string $command): CommitException
    {
        return new CommitException(
            sprintf(
                '%s could not be determined in %s: %s',
                $question,
                $this->git->workingTree(),
                $result->failureReason(),
            ),
            [
                'Ralph PHP will not reconcile a pass against a repository it could not read.',
                sprintf('Run "%s" in %s to see what Git reports.', $command, $this->git->workingTree()),
            ],
        );
    }
}
