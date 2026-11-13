<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * A sink that reads a worker's output on the way past and writes something a
 * person can follow instead.
 *
 * The one case PROJECT.md section E, item 4 does not cover by streaming bytes
 * through: an agent whose non-interactive output is a machine format. Claude
 * Code's `stream-json` is a JSON object per line, and forwarding those verbatim
 * is streaming in the letter — output arrives as it is produced — while
 * delivering nothing section E, item 4 asked for, because a wall of envelopes
 * is not a session anybody can watch. A filter sits between
 * {@see \Celyes\RalphPhp\Process\ProcessRunner} and the attempt's real sinks
 * and renders the events as they arrive ({@see \Celyes\RalphPhp\Agent\ClaudeStream}).
 *
 * **It is a sink, so none of the rules relax.** Chunks arrive as they came off
 * the pipe and may end mid-line, a filter must not throw, and what it writes
 * reaches both of an attempt's destinations — so a filter writes plain text
 * rather than escape sequences, the log file being a file somebody will grep.
 * What it *does* get to say is what each piece is ({@see Emphasis}): a filter
 * is the only thing in the chain that knows the difference between the agent
 * thinking out loud and the agent answering, and the terminal at the far end is
 * the only thing that may act on it.
 *
 * **It does not own what it writes to.** An attempt's log spans more than one
 * command and is closed by whoever opened it ({@see OutputSink}), while a
 * filter is built per invocation by the adapter that knows the format. So
 * {@see self::flush()} is how an adapter says the process has ended and
 * whatever is still held should come out, and a filter's own `close()` flushes
 * without closing the sink underneath it.
 */
interface StreamFilter extends OutputSink
{
    /**
     * Write out anything still held back, the worker having finished.
     *
     * A filter that buffers until a line is complete is holding the last line
     * of a worker that ended without a newline — which, for a process that was
     * killed, is the part that says what it was doing. Must be safe to call
     * twice and must leave the sink underneath open.
     */
    public function flush(): void;
}
