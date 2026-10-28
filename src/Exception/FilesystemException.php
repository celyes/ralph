<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A file or directory the package must create, read or replace cannot be.
 *
 * Separate from {@see ConfigurationException} because nothing is wrong with the
 * project's settings: the environment refuses the operation. It is reported with
 * the same {@see ExitCode::CONFIGURATION} status, since the caller's remedy is
 * the same — fix the environment and run the command again — and nothing was
 * executed on the user's behalf.
 */
final class FilesystemException extends RalphException
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
