<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * Another runner holds this repository, or the one that held it cannot be
 * confirmed to have stopped.
 *
 * The refusal of PROJECT.md section H, item 1. It is raised before anything is
 * read, inspected or written, so nothing in the project has been touched when a
 * command fails this way — which is the whole point of a lock taken first.
 *
 * Separate from {@see StateException} even though both end a command the same
 * way, because the two mean opposite things to the person reading them. A state
 * failure says the project needs fixing; this says the project is in use, and
 * the next action is usually to wait or to look at what is already running
 * rather than to change anything.
 *
 * {@see ExitCode::CONFIGURATION}, with the other "nothing ran, resolve this and
 * try again" failures, and settled there by PROJECT.md section I, item 5: a
 * repository another runner holds is the environment not being available, which
 * is what that status covers. It gets no code of its own because nothing a
 * caller would do differs — the lock is released by the other runner finishing
 * or by the user dealing with it, and this command has to be run again either
 * way. It is not the blocking status, which is for a run of this package's own
 * that stopped with work in hand.
 */
final class LockException extends RalphException
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
