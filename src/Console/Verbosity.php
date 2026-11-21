<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

/**
 * How much of what the package is doing reaches the terminal.
 *
 * Two answers, and the quiet one is the default (PROJECT.md section K, item 2).
 * A run hands an agent a task and then has nothing to do for twenty minutes but
 * repeat what the agent is saying, and what the agent says is thousands of
 * lines of its own reasoning, tool calls and diffs. Watching that is sometimes
 * exactly what a user wants — it is the only way to see a worker that has hung
 * — and is otherwise a wall of text with the four lines that are the run's own
 * account buried in it.
 *
 * So the choice is between two complete accounts rather than between an account
 * and a truncation:
 *
 *  - {@see self::Quiet} prints the lines Ralph PHP composes about its own work:
 *    which task was taken on, what happened to it, why the run ended, and every
 *    warning and failure. One or two lines per task.
 *  - {@see self::Verbose} prints those and everything else — the agent's output
 *    as it arrives, each note about a workspace, an invocation or a check, and
 *    a stack trace when a command fails unexpectedly.
 *
 * **Nothing durable depends on this.** The attempt's log file, the event
 * stream, the state database and the checklist are written identically either
 * way ({@see \Celyes\RalphPhp\Log\AttemptLog}): a quiet run is a run whose
 * terminal said less, not a run that kept less. `ralph logs TASK-ID` prints
 * afterwards what a verbose run would have shown while it happened.
 */
enum Verbosity
{
    /**
     * Only the package's own account of what it did.
     */
    case Quiet;

    /**
     * That, plus another process's output and every note and trace.
     */
    case Verbose;

    /**
     * The short and long forms of the flag that asks for everything.
     */
    private const string SHORT = 'v';
    private const string LONG = 'verbose';

    /**
     * What the command line asks for.
     *
     * Read off the raw tokens rather than parsed input, for the reason
     * {@see ColourChoice::requestedIn()} gives: parsing a command line can
     * itself fail, and the report of that failure is output this decides the
     * shape of. Everything after `--` belongs to no option.
     *
     * The short form is looked for inside a bundle, so that `-nv` and `-vn`
     * both ask for it.
     *
     * @param list<string> $tokens argv without the script name
     */
    public static function requestedIn(array $tokens): self
    {
        foreach ($tokens as $token) {
            if ($token === '--') {
                break;
            }

            if ($token === '--' . self::LONG) {
                return self::Verbose;
            }

            if (preg_match('/^-[A-Za-z]+$/', $token) === 1 && str_contains($token, self::SHORT)) {
                return self::Verbose;
            }
        }

        return self::Quiet;
    }

    /**
     * Whether everything is to be printed.
     */
    public function isVerbose(): bool
    {
        return $this === self::Verbose;
    }
}
