<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

/**
 * Process exit statuses.
 *
 * The values follow the conventional BSD sysexits ranges so that shell callers
 * and CI can distinguish failure kinds. `bin/ralph` uses {@see self::CONFIGURATION}
 * for environment failures it detects before this class can be loaded, so the
 * two stay consistent.
 *
 * The complete documented set, including the runner-specific statuses for
 * execution failure, limit exhaustion, blocking and interruption, is finalised
 * with the diagnostics work in PROJECT.md section I; the codes defined here are
 * the ones the bootstrap can already produce.
 */
final class ExitCode
{
    /**
     * The requested work completed.
     */
    public const int SUCCESS = 0;

    /**
     * The command line was wrong: unknown command, unknown option, missing
     * option value. Nothing was executed.
     */
    public const int USAGE = 64;

    /**
     * A documented command is not implemented in this build.
     */
    public const int UNAVAILABLE = 69;

    /**
     * An unexpected internal error. Reported with a stack trace in verbose mode
     * and treated as a bug in Ralph PHP.
     */
    public const int SOFTWARE = 70;

    /**
     * The environment or project configuration prevents the command from
     * running: unsupported runtime, missing autoloader, invalid configuration.
     */
    public const int CONFIGURATION = 78;

    private function __construct()
    {
    }
}
