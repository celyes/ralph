<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * What a piece of streamed output is, so a terminal can weight it.
 *
 * An attempt puts minutes of somebody else's output on the screen, and not all
 * of it is worth the same. The agent's reasoning is worth seeing — it is how a
 * watcher knows the session is alive — and is not worth reading; the agent's
 * conclusions are the part a person is actually waiting for; a tool call is
 * bookkeeping that locates the work; a tool's output is detail. A terminal that
 * prints all four in one weight is a terminal nobody reads, which is the same
 * failure as printing nothing.
 *
 * **It travels with the chunk, and only the terminal acts on it.** The escape
 * sequences cannot be put in where the line is composed, because what a filter
 * writes reaches both of an attempt's destinations and one of them is a log file
 * somebody will grep ({@see StreamFilter}). So a renderer says what a chunk *is*
 * and {@see TerminalSink} decides how that reads ({@see \Celyes\RalphPhp\Console\Style::emphasis()}),
 * while {@see LogFile} ignores it and keeps the file plain.
 *
 * **{@see self::Plain} is the default, and means "not ours".** A worker's own
 * bytes are passed through unweighted: they are a copy of what another program
 * printed, escapes and all, and a package that coloured them would be
 * overwriting the program's own choices ({@see OutputSink::write()}).
 */
enum Emphasis
{
    /**
     * Bytes that are not this package's to interpret: a worker's own output.
     */
    case Plain;

    /**
     * The agent's reasoning, as it is produced.
     *
     * Light, deliberately. A watcher skims it to see that something is
     * happening and reads it only when something has gone wrong.
     */
    case Thinking;

    /**
     * The agent's own words: what it decided, what it is reporting.
     *
     * The heaviest weight in the palette, because it is the thing the run was
     * started for.
     */
    case Result;

    /**
     * A line inside a fenced code block the agent wrote, or a command it ran.
     *
     * As important as {@see self::Result} — it is usually the answer — and
     * marked out from the prose around it by its frame rather than by colour.
     */
    case Code;

    /**
     * The package's own scaffolding around something else: a gutter, a bracket,
     * the language tag over a code block.
     *
     * It has to be visible to do its job and must not compete with what it
     * frames.
     */
    case Frame;

    /**
     * Something the agent is doing: a tool call, the session it opened.
     */
    case Activity;

    /**
     * The result of something the agent did, in one line.
     */
    case Detail;

    /**
     * A session that ended the way it was supposed to.
     */
    case Success;

    /**
     * Something that needs a person's attention but has not failed: an API
     * error being retried, a limit being approached.
     */
    case Warning;

    /**
     * A session that failed, and the errors it failed with.
     */
    case Failure;
}
