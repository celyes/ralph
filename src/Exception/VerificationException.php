<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;

/**
 * A task's configured checks could not be run at all.
 *
 * Not a failing check. A check that ran and decided against the work is a
 * verdict, and a verdict is a {@see \Celyes\RalphPhp\Verification\CheckRun}
 * inside a report the runner acts on: the task stays unchecked, the output
 * becomes the next attempt's evidence, and the run carries on. This is the
 * other case — the one where there is no verdict to be had. The program the
 * check names is not installed, the workspace it was to run in is not there, or
 * the process could not be started.
 *
 * The distinction is the whole reason this class exists. A missing `phpunit`
 * reported as a failed check would be an accusation against work that was never
 * examined, and the agent would be sent back to fix a test suite that never
 * ran; three attempts later the task would be recorded as unfixable. Raised,
 * it reads as what it is — the project cannot verify anything on this machine
 * until someone installs the program or changes the `checks` setting.
 */
final class VerificationException extends RalphException
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
