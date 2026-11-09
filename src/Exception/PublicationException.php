<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A pass could not be published, and not because the remote refused it.
 *
 * The distinction this class exists to draw is the whole of PROJECT.md section
 * F, item 8's second half. A push that is *attempted and rejected* — a
 * non-fast-forward, a remote that is not there, a credential that was not
 * accepted — is an ordinary outcome and not an exception: it is recorded on the
 * pass as a failed publication with its reason
 * ({@see \Celyes\RalphPhp\State\PushResult::failed()}), the local commit is
 * left exactly as it was, and resume retries the push and nothing before it
 * (section H, item 7). Throwing there would lose the record that makes that
 * retry possible.
 *
 * What is left for this is the case where a push must not be attempted at all:
 * a pass with no commit to publish, a row that names no branch, a branch that
 * is not in the repository, or a branch whose tip is not the commit the row
 * recorded. Each of those means the repository and the state store disagree
 * about what this pass produced, and publishing under that disagreement would
 * put a commit nobody here has seen onto a shared remote — the one failure in
 * section F that other people can fetch.
 *
 * Nothing is undone when this is raised, and nothing is pushed. The commit, the
 * branch and the worktree are all left for inspection.
 *
 * Reported with {@see ExitCode::BLOCKED}, the blocking status of PROJECT.md
 * section I, item 5. The commit exists and is unpublished, and the disagreement
 * about what the pass produced is a person's to settle before anything is
 * pushed under it — which is what the status says and what "fix a setting and
 * run it again" would not.
 */
final class PublicationException extends RalphException
{
    /**
     * @param list<string> $hints
     */
    public function __construct(string $message, private readonly array $hints = [])
    {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return ExitCode::BLOCKED;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
