<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * The kinds of thing an agent does that are worth saying out loud.
 *
 * A closed set, and deliberately a short one. What {@see Activity} exists to
 * carry is the part of a session a person watching a quiet run needs in order
 * to tell a worker that is working from one that has stopped
 * ({@see ActivitySink}), and every kind here earns its place by being something
 * a reader reacts to differently: a command about to run against their checkout
 * is not a paragraph of the agent's prose, and neither is the line that says
 * how the session ended.
 *
 * It is not a model of an agent's protocol. The CLI that reports the richest
 * feed — {@see \Celyes\RalphPhp\Agent\ClaudeStream} — has more event types than
 * this, and most of them are bookkeeping; an adapter maps what it has onto
 * these and leaves the rest out, which is what keeps one terminal convention
 * across agents that describe themselves differently.
 */
enum ActivityKind
{
    /**
     * The session opening: which model, under which id.
     */
    case Session;

    /**
     * The model reasoning before it answers.
     *
     * Reported as the fact that it is happening rather than as the reasoning
     * itself. A quiet run that printed the thinking would be the wall of text
     * {@see \Celyes\RalphPhp\Console\Verbosity::Quiet} exists to avoid, and
     * "it is thinking" is the whole of what the fact is worth to a watcher.
     */
    case Thinking;

    /**
     * The agent's own words: one message, as it said it.
     */
    case Response;

    /**
     * A command the agent is running against the user's checkout.
     *
     * Apart from {@see self::ToolCall} because of what it is, not how it
     * arrives. Everything else an agent does happens inside its own session;
     * this one runs a program in the project, and a person watching a loop work
     * on their repository is entitled to read the command before its output
     * scrolls past.
     */
    case Command;

    /**
     * Any other tool call: a file read, a search, an edit.
     */
    case ToolCall;

    /**
     * What a tool call came back with, in one line.
     */
    case ToolResult;

    /**
     * How the session ended, in the agent's own accounting.
     */
    case Outcome;

    /**
     * Something that went wrong inside the session: an API error, a failure the
     * agent reported about itself.
     */
    case Problem;

    /**
     * Whether a reader has finished reading something after this.
     *
     * The three kinds that end a thing being said rather than continuing one,
     * which is what {@see \Celyes\RalphPhp\Console\Output::separate()} is asked
     * about them. PROJECT.md section K, item 3 wants a blank line after
     * everything this package prints; applied per activity that would be a
     * double-spaced transcript, and applied only at the end of the attempt it
     * would run a paragraph of the agent's prose straight into the next tool
     * call.
     */
    public function endsSomething(): bool
    {
        return $this === self::Response || $this === self::Outcome || $this === self::Problem;
    }
}
