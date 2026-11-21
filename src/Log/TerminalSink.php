<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;

/**
 * A worker's output on the user's terminal, as it is produced.
 *
 * The visible half of PROJECT.md section E, item 4. An agent working on a task
 * can be quiet for minutes at a time, and a loop that showed nothing until the
 * process exited would be indistinguishable from a loop that had hung — which
 * is the one failure a user cannot diagnose and can only kill.
 *
 * **Bytes through, unchanged and unbuffered.** Chunks are written exactly as
 * they came off the pipe, with nothing prefixed and no line ending added. A
 * prefix per chunk would land mid-line, since a read ends wherever the worker
 * happened to be; and holding a partial line back until its newline arrives is
 * what stops a progress indicator from ever appearing. The log is where the
 * output is marked up ({@see LogFile}) — on a terminal it should look like the
 * agent was run directly.
 *
 * **The last line is still finished.** A worker whose final chunk had no
 * newline would leave whatever the CLI prints next glued onto the end of it, so
 * {@see self::close()} closes the line. Which line is open is the output's own
 * bookkeeping rather than this sink's ({@see Output::finishLine()}): the same
 * question is asked by every note, by an error report, and by the end of the
 * command, and one answer kept in one place cannot disagree with itself.
 *
 * **Each stream keeps its destination.** The worker's standard output goes to
 * ours and its error output to ours, so that redirecting a run's output
 * separates the two exactly as running the agent by hand would.
 *
 * **Nothing here is evidence.** What is on the terminal scrolls away and may be
 * redirected to /dev/null; the attempt's log file and the state store are what
 * a later command reads. This sink is allowed to fail quietly for that reason —
 * a closed pipe when a user quits a pager should not end a run.
 *
 * **And so nothing here is printed unless it was asked for.** This sink is the
 * whole of what a run repeats from somewhere else — an agent's bytes and the
 * notes the package files alongside them — which is to say it is the wall of
 * text PROJECT.md section K, item 2 makes optional. Under
 * {@see \Celyes\RalphPhp\Console\Verbosity::Quiet} it writes nothing at all,
 * and the run's own lines ({@see \Celyes\RalphPhp\Run\Runner}) are what a
 * user sees. The decision is taken per write rather than by leaving the sink
 * out of the composite, so that the one place that knows what is being echoed
 * is also the place that decides whether to echo it — and so that an attempt's
 * log is opened, written and closed identically either way.
 */
final class TerminalSink implements OutputSink
{
    public function __construct(private readonly Output $output)
    {
    }

    public function write(OutputStream $stream, string $chunk): void
    {
        if ($chunk === '' || !$this->output->isVerbose()) {
            return;
        }

        if ($stream->isError()) {
            $this->output->diagnosticText($chunk);
        } else {
            $this->output->text($chunk);
        }
    }

    /**
     * Show a line of Ralph PHP's own, on standard output.
     *
     * Marked with the same prefix the log uses, so that a user who has both in
     * front of them reads one convention rather than two, and so that a line
     * the package wrote cannot be mistaken for one the agent printed.
     *
     * On a terminal the prefix is dimmed and the note's own subject — the
     * `commit:`, `verification:`, `workspace:` a note opens with — is picked
     * out, because this is the output a user actually watches a run through and
     * a hundred identically-weighted lines is the shape nobody reads. The log
     * keeps the plain form: {@see LogFile} is written for a reader who comes
     * back to it later, and escape sequences in a file somebody is grepping are
     * a cost with no benefit.
     *
     * A quiet run shows none of them. A note is the package narrating a step of
     * its own work — a workspace, an invocation, a check — and the outcome that
     * narration arrives at is printed by the run either way.
     */
    public function note(string $line): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $style = $this->output->style();

        $this->output->line($style->muted(LogFile::NOTE_PREFIX) . $this->subject($line, $style));
    }

    /**
     * A note with its subject marked, if it has one.
     *
     * Three shapes, which is every shape the package's notes take: an indented
     * continuation of the note above it, which is detail and is dimmed whole; a
     * note that opens by naming what it is about, which is most of them; and
     * anything else, left as it is.
     */
    private function subject(string $line, Style $style): string
    {
        if (str_starts_with($line, '  ')) {
            return $style->muted($line);
        }

        // One or two lowercase words and a colon: "commit:", "checks audit:",
        // "check tests:". Bounded at two deliberately — a note that opens with
        // a whole clause and a colon is a sentence, not a subject, and marking
        // it up would pick out half of a line that reads as one thing.
        if (preg_match('/^([a-z]+(?: [a-z]+)?:)( .*)$/', $line, $matches) === 1) {
            return $style->identifier($matches[1]) . $matches[2];
        }

        return $line;
    }

    public function close(): void
    {
        $this->output->finishLine();
    }
}
