<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\CheckoutInspection;

/**
 * The run did not start because of the state of the user's checkout.
 *
 * Raised for two readings of the same question. Either the checkout holds
 * uncommitted changes to the project's own files, which a task's worktree would
 * not contain and a task's commit would not include; or the checkout could not
 * be inspected at all, which is refused for the same reason in reverse — a run
 * that cannot see what is uncommitted cannot promise not to work around it.
 *
 * Nothing has happened when this is raised. No worktree exists, no agent has
 * run, and in particular the uncommitted changes are exactly as they were: this
 * is the failure that exists so that the package does not stash, discard or
 * commit them (PROJECT.md section F, item 1). The remedy is entirely the
 * user's, which is why the hints name three ways to deal with their own work
 * and the package takes none of them.
 *
 * Reported with {@see ExitCode::CONFIGURATION}, the status for "put something
 * right and run the command again", which is accurate about the remedy. The
 * dedicated blocking status of PROJECT.md section I, item 5 does not exist yet;
 * this class adopts it when it does.
 */
final class CheckoutException extends RalphException
{
    /**
     * @param list<string> $hints
     * @param CheckoutInspection|null $inspection what was found, or null when
     *        the checkout could not be read at all
     */
    public function __construct(
        string $message,
        private readonly array $hints = [],
        private readonly ?CheckoutInspection $inspection = null,
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
     * The reading that caused the refusal, for a caller that records the
     * changes rather than only their message.
     */
    public function inspection(): ?CheckoutInspection
    {
        return $this->inspection;
    }
}
