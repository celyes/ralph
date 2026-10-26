<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A documented command exists but its implementation has not landed yet.
 *
 * Reported separately from an unknown command so that users of a development
 * build get an accurate answer instead of "command not found".
 */
final class CommandUnavailableException extends RalphException
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
        return ExitCode::UNAVAILABLE;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
