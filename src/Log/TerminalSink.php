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
 * **Except in weight, when the renderer asked for it.** A chunk that arrives
 * tagged ({@see Emphasis}) is wrapped in that tag's colour on the way out, and
 * nothing else about it changes: the agent's reasoning recedes into grey, its
 * answer comes out in white, and the tool calls between them read as the
 * bookkeeping they are. Only a sink that rendered the bytes itself tags them
 * ({@see \Celyes\RalphPhp\Agent\ClaudeStream}); a worker's own output is
 * {@see Emphasis::Plain} and is passed through as before, escapes and all. With
 * colour off, or on a stream that is not a terminal, every tag resolves to the
 * identity and the output is the same bytes it always was
 * ({@see \Celyes\RalphPhp\Console\ColourChoice}).
 *
 * **Each stream keeps its destination.** The worker's standard output goes to
 * ours and its error output to ours, so that redirecting a run's output
 * separates the two exactly as running the agent by hand would.
 *
 * **Nothing here is evidence.** What is on the terminal scrolls away and may be
 * redirected to /dev/null; the attempt's log file and the state store are what
 * a later command reads. This sink is allowed to fail quietly for that reason —
 * a closed pipe when a user quits a pager should not end a run.
 */
final class TerminalSink implements OutputSink
{
    /**
     * Whether the last thing written ended a line.
     *
     * Tracked so that {@see self::close()} can finish an unterminated one. A
     * worker whose last output has no trailing newline would otherwise leave
     * the next thing the CLI prints glued onto the end of it.
     */
    private bool $atLineStart = true;

    public function __construct(private readonly Output $output)
    {
    }

    public function write(OutputStream $stream, string $chunk, Emphasis $emphasis = Emphasis::Plain): void
    {
        if ($chunk === '') {
            return;
        }

        if ($stream->isError()) {
            $this->output->diagnosticText($this->weighted($chunk, $emphasis, $this->output->diagnosticStyle()));
        } else {
            $this->output->text($this->weighted($chunk, $emphasis, $this->output->style()));
        }

        $this->atLineStart = str_ends_with($chunk, "\n");
    }

    /**
     * A chunk as it should read on this stream.
     *
     * The one place an agent's output is coloured, and the reason the tag
     * travels with the chunk rather than the sequences: the same bytes are on
     * their way to a log file that must stay greppable ({@see Emphasis}).
     * {@see Emphasis::Plain} and a stream that is not a terminal both come back
     * byte for byte, which keeps a redirected run identical to the file beside
     * it.
     *
     * The line ending is left outside the sequence. A chunk ends wherever the
     * renderer finished, and a reset *after* the newline is an escape sequence
     * at the start of the next line — invisible, until the line it opens is the
     * one somebody copies out of the scrollback.
     */
    private function weighted(string $chunk, Emphasis $emphasis, Style $style): string
    {
        if ($emphasis === Emphasis::Plain) {
            return $chunk;
        }

        $text = rtrim($chunk, "\n");

        return $style->emphasis($emphasis, $text) . substr($chunk, strlen($text));
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
     */
    public function note(string $line): void
    {
        $this->startLine();

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
        $this->startLine();
    }

    /**
     * End the worker's unterminated line, if it left one.
     */
    private function startLine(): void
    {
        if ($this->atLineStart) {
            return;
        }

        $this->output->line();
        $this->atLineStart = true;
    }
}
