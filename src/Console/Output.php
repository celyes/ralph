<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\Support\Secrets;

/**
 * Writes CLI output to a pair of streams.
 *
 * Normal output goes to the first stream and diagnostics to the second, so that
 * `ralph status` output can be piped while errors stay visible. Tests construct
 * this with in-memory streams and read back what was written.
 *
 * **The lines this package writes are redacted; a worker's bytes are not.**
 * Once a command has read the project's configuration it tells its output which
 * secrets the project declared ({@see self::redact()}), and from then on every
 * line the package composes — a status table, a run's notes, an error message,
 * the JSON of `status --json` — has those values replaced before it is written.
 * {@see self::text()} and {@see self::diagnosticText()} are deliberately
 * outside that: they exist to pass another process's output through unchanged
 * ({@see \Celyes\RalphPhp\Log\TerminalSink}), a chunk off a pipe may end
 * mid-value, and a terminal showing something different from the attempt's log
 * would be worse than either. That split is PROJECT.md section I, item 6, and
 * the limits of it are documented in `ralph logs --help`.
 *
 * **The lines this package writes may be coloured; a worker's bytes are passed
 * through as they came.** The same split, for the same reason. Styling is
 * decided per stream and per invocation ({@see self::style()},
 * {@see ColourChoice}) and is applied where a line is composed rather than
 * here: by the time a string reaches {@see self::line()} its labels and its
 * values are one string and nothing can tell them apart again.
 */
final class Output
{
    /**
     * Known secrets, kept out of the lines this package composes.
     *
     * Not readonly and not a constructor argument: the streams exist before
     * anything has read a configuration file, and the one object a command was
     * handed is also the one {@see Application::report()} prints a failure
     * through — so learning the secrets later has to reach the output that is
     * already in use rather than produce a second one.
     */
    private Secrets $secrets;

    /**
     * Streams that have stopped accepting bytes, keyed by resource id.
     *
     * Keyed rather than a pair of flags because the two streams can be the same
     * one — `ralph status 2>&1`, and the tests that hand both to a single
     * in-memory handle — and because a stream that is gone is gone whichever of
     * the two names it arrived under.
     *
     * @var array<int,true>
     */
    private array $closed = [];

    /**
     * Whether this output may colour the lines it composes.
     *
     * Not readonly, for the same reason the secrets are not: the streams exist
     * before the command line has been parsed, and `--no-color` has to reach
     * the output that is already in use ({@see self::useColour()}).
     */
    private ColourChoice $colour;

    /**
     * The resolved styling for each stream, once asked for.
     *
     * Cached because `Auto` means a syscall per stream, and a status table asks
     * for its styling once per row; dropped whenever the choice changes.
     */
    private ?Style $style = null;

    private ?Style $diagnosticStyle = null;

    /**
     * @param resource $standard stream for ordinary output
     * @param resource $diagnostic stream for errors and warnings
     */
    public function __construct(
        private readonly mixed $standard,
        private readonly mixed $diagnostic,
        ?Secrets $secrets = null,
        ColourChoice $colour = ColourChoice::Auto,
    ) {
        $this->secrets = $secrets ?? Secrets::none();
        $this->colour = $colour;
    }

    /**
     * Output bound to the process's own STDOUT and STDERR.
     */
    public static function toStandardStreams(): self
    {
        return new self(STDOUT, STDERR);
    }

    /**
     * Keep these secrets out of everything written from here on.
     *
     * Called by a command once it has the project's configuration, which is
     * the first moment the declared variables are known. Additive: a second
     * call adds to what is already being redacted and can never take a value
     * back out, because output that was redacted a moment ago must not stop
     * being redacted.
     */
    public function redact(Secrets $secrets): void
    {
        $this->secrets = $this->secrets->merge($secrets);
    }

    /**
     * The secrets being kept out of this output's own lines.
     */
    public function secrets(): Secrets
    {
        return $this->secrets;
    }

    /**
     * Decide colour explicitly, overriding what the streams would have said.
     *
     * Called once, by {@see Application}, for `--color` and `--no-color`. It
     * reaches the output already in use rather than producing a second one,
     * because the output a command was handed is also the one a failure is
     * reported through.
     */
    public function useColour(ColourChoice $colour): void
    {
        $this->colour = $colour;
        $this->style = null;
        $this->diagnosticStyle = null;
    }

    /**
     * How to style a line going to ordinary output.
     *
     * Asked of the stream it will be written to, not of the process: `ralph
     * status > out.txt` writes its table to a file and its problems to a
     * terminal, and the file must not have escape sequences in it
     * ({@see ColourChoice}).
     */
    public function style(): Style
    {
        return $this->style ??= Style::on($this->standard, $this->colour);
    }

    /**
     * How to style a line going to diagnostic output.
     */
    public function diagnosticStyle(): Style
    {
        return $this->diagnosticStyle ??= Style::on($this->diagnostic, $this->colour);
    }

    /**
     * Write a line of ordinary output.
     */
    public function line(string $text = ''): void
    {
        $this->write($this->standard, $this->secrets->redact($text) . PHP_EOL);
    }

    /**
     * Write several lines of ordinary output.
     *
     * @param list<string> $lines
     */
    public function lines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * Write text exactly as given, with no line ending added.
     *
     * For output that is not ours to format: a worker's bytes, streamed to the
     * terminal as they arrive ({@see \Celyes\RalphPhp\Log\TerminalSink}).
     * A chunk off a pipe begins and ends wherever the worker happened to be, so
     * anything appended here would insert a line break into the middle of the
     * worker's own line.
     *
     * Not redacted, for the reason the class description gives: a chunk can end
     * in the middle of a value, so replacing inside one would catch some
     * occurrences and not others while making the terminal disagree with the
     * log it is a copy of.
     */
    public function text(string $text): void
    {
        $this->write($this->standard, $text);
    }

    /**
     * Write diagnostic text exactly as given, with no line ending added.
     */
    public function diagnosticText(string $text): void
    {
        $this->write($this->diagnostic, $text);
    }

    /**
     * Write a line of diagnostic output.
     */
    public function diagnosticLine(string $text = ''): void
    {
        $this->write($this->diagnostic, $this->secrets->redact($text) . PHP_EOL);
    }

    /**
     * Write bytes, and stop writing to a stream that has stopped taking them.
     *
     * `ralph run | head` closes the read end of the pipe as soon as `head` has
     * its lines, and `ralph status | less` does the same when the pager is quit
     * early. Every write after that fails with `errno=32 Broken pipe`, which
     * PHP raises as a warning and {@see Application::convertErrorsToExceptions()}
     * turns into an exception — ending a run that was doing nothing wrong, with
     * its remaining tasks unstarted, because somebody read only the first screen
     * of its output. A terminal is a copy of what the package is doing, never
     * the thing itself, so losing it is not a reason to stop: the write is
     * suppressed, the stream is remembered as gone, and the run carries on
     * writing its logs, its events and its commits as before.
     *
     * The loop covers a partial write, which a non-blocking stream can return
     * without being broken at all; a write that makes no progress is the end of
     * the stream rather than a slow one, because every stream this class is
     * given is blocking.
     *
     * @param resource $stream
     */
    private function write(mixed $stream, string $text): void
    {
        if ($text === '' || !is_resource($stream)) {
            return;
        }

        $id = get_resource_id($stream);

        if (isset($this->closed[$id])) {
            return;
        }

        $remaining = $text;

        while ($remaining !== '') {
            $written = @fwrite($stream, $remaining);

            if ($written === false || $written <= 0) {
                $this->closed[$id] = true;

                return;
            }

            $remaining = substr($remaining, $written);
        }
    }
}
