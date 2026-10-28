<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * The task checklist cannot be read or understood: the file is missing, is not
 * readable, or its contents are not something the reader can turn into tasks.
 *
 * Kept apart from {@see ConfigurationException} because the project's settings
 * may be perfectly valid — the file they point at is the problem, and the user
 * edits a different file to fix it. Reported with the same
 * {@see ExitCode::CONFIGURATION} status: the remedy is to correct the file and
 * run the command again, and nothing has been executed when a caller sees this.
 */
final class ChecklistException extends RalphException
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
