<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use RuntimeException;

/**
 * Base class for failures Ralph PHP recognises and can explain.
 *
 * The CLI prints these as a single readable line without a stack trace, and
 * uses {@see self::exitCode()} as the process exit status. Anything that is not
 * a RalphException is treated as a bug and reported as an unexpected error.
 */
abstract class RalphException extends RuntimeException
{
    /**
     * Process exit status for this failure.
     */
    abstract public function exitCode(): int;

    /**
     * Optional lines printed after the message: suggestions, next actions, or
     * the command that would resolve the problem.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return [];
    }
}
