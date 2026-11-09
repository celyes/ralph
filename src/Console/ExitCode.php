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
     * The command did what it was asked and stopped with something waiting for
     * a person: a task blocked on a decision, a task whose attempts were given
     * up on, or a run that could not carry on.
     *
     * Not a failure — whatever was verified is committed and recorded, and the
     * run wrote its reason and its next action to its own row. It is a status
     * of its own because a caller that cannot tell "the checklist is done" from
     * "the run stopped short" would have to read the summary line to find out.
     *
     * Which endings those are is {@see \Celyes\RalphPhp\State\StopKind::needsAttention()}
     * and nothing else: a run that reached a configured limit and a run
     * somebody stopped are both runs that did exactly what they were asked, so
     * they end successfully with their next action printed. A command deciding
     * that again for itself is how a status and a summary line come to disagree.
     *
     * One status for all three endings, which is the provisional part: a code
     * for each of them, documented together, is PROJECT.md section I, item 5.
     * The value is the conventional sysexits "temporary failure, the user is
     * invited to retry", which is what each of the three is.
     */
    public const int ATTENTION = 75;

    /**
     * The environment or project configuration prevents the command from
     * running: unsupported runtime, missing autoloader, invalid configuration.
     */
    public const int CONFIGURATION = 78;

    private function __construct()
    {
    }
}
