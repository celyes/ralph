<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * The state store cannot be opened, brought up to date, or trusted.
 *
 * Separate from {@see FilesystemException}, which reports a path the
 * environment refuses: by the time this is raised the file is readable and the
 * problem is what is inside it — a database SQLite will not open, a schema
 * written by a newer Ralph PHP, a migration that failed halfway.
 *
 * Nothing has been executed on the user's behalf when this is raised, and
 * nothing in the database has been changed: migrations are applied inside a
 * transaction, so a failure leaves the recorded schema version exactly where it
 * was. That is why it shares {@see ExitCode::CONFIGURATION} with the other
 * "fix the project and run the command again" failures.
 *
 * It is deliberately loud. The state store is the only record of which commits
 * belong to which task and of which checkboxes have been earned, so a run that
 * cannot read it must stop rather than start over against an empty one and
 * repeat work that has already been committed.
 */
final class StateException extends RalphException
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
