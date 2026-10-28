<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A command could not be started at all: its executable does not exist, is not
 * executable, or its working directory is missing.
 *
 * Distinct from a command that ran and failed, which is an ordinary
 * {@see \Celyes\RalphPhp\Process\ProcessResult} with a nonzero status and is
 * the business of whoever asked for the command. This exception means nothing
 * ran, so there is no output to interpret.
 *
 * Callers that *expect* a program to be absent — preflight, which reports a
 * missing agent executable as a failed check rather than as an error — resolve
 * the executable with {@see \Celyes\RalphPhp\Process\ExecutableFinder} first,
 * and never reach this.
 */
final class ProcessException extends RalphException
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
