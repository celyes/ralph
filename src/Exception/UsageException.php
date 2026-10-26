<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * The command line itself was wrong: an unknown command, an unknown option, or
 * a missing option value. Nothing was executed.
 */
final class UsageException extends RalphException
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
        return ExitCode::USAGE;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
