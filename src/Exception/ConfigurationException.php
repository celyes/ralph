<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * The project configuration cannot be used: the file is missing, is not valid
 * JSON, or a setting has the wrong shape or an unusable value.
 *
 * Reported before any task is selected, so nothing has been executed when a
 * caller sees this.
 */
final class ConfigurationException extends RalphException
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
