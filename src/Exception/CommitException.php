<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A verified task's staged changes could not become a recorded commit.
 *
 * The sibling of {@see StagingException}, and it arrives even later: the agent
 * has run, the checks have passed, and what the commit would contain has been
 * decided and staged. So the message has the same job — say where the work is
 * rather than what to put right — and one more besides, because this is the
 * step where the repository and the state store can disagree. A commit that
 * exists in Git with no row to name it is the crash PROJECT.md section H,
 * item 5 is about; when that is the failure, the message names the SHA, since
 * at that point the SHA is the only thing that can find the work again.
 *
 * Nothing is undone when this is raised. The index keeps what was staged, the
 * branch keeps whatever reference was created for it, and the worktree is
 * preserved for inspection (PROJECT.md section F, item 10). What is never done
 * in response to one of these is a second commit: a pass commits once, and
 * resume retrying the implementation because the first commit could not be
 * recorded is precisely the duplicate work acceptance criterion 6 rules out.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "put something
 * right and run the command again". The dedicated blocking status of PROJECT.md
 * section I, item 5 does not exist yet; this class adopts it when it does.
 */
final class CommitException extends RalphException
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
        return ExitCode::CONFIGURATION;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
