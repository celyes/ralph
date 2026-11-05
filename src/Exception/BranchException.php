<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\BranchOwnership;

/**
 * A task's branch belongs to something other than the pass that would use it,
 * and the run stopped rather than taking it.
 *
 * Raised for two readings of the same question, exactly as
 * {@see CheckoutException} is. Either a branch under the name the pass would
 * use is held by unrelated work — another recorded pass, another worktree, or a
 * history that has nothing to do with the run's base commit — or the repository
 * could not be asked at all, which is refused in the same breath: a run that
 * does not know whose a branch is cannot promise not to overwrite it.
 *
 * Nothing in the repository has been changed when this is raised, and the
 * branch in particular is exactly as it was. That is the whole point of the
 * failure (PROJECT.md section F, item 4): the convenient responses — `git
 * branch --force`, `git reset --hard`, `git branch -D` — each discard commits
 * that nobody asked to lose, and a generated branch name can collide with a
 * person's branch without anybody having made a mistake. So the remedy is the
 * user's: rename their branch, remove it, or configure a different prefix.
 *
 * {@see self::ownership()} carries the reading, so a caller can record which
 * commit it refused to touch rather than only the sentence about it.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "put something
 * right and run the command again", which is accurate about the remedy. The
 * dedicated blocking status of PROJECT.md section I, item 5 does not exist yet;
 * this class adopts it when it does.
 */
final class BranchException extends RalphException
{
    /**
     * @param list<string>         $hints
     * @param BranchOwnership|null $ownership what was found, or null when the
     *        repository could not be asked
     */
    public function __construct(
        string $message,
        private readonly array $hints = [],
        private readonly ?BranchOwnership $ownership = null,
    ) {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return ExitCode::CONFIGURATION;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * The reading that caused the refusal, for a caller that records the branch
     * and its tip rather than only the message.
     */
    public function ownership(): ?BranchOwnership
    {
        return $this->ownership;
    }
}
