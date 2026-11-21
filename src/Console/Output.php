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
 *
 * **Everything this package prints is followed by a blank line.** PROJECT.md
 * section K, item 3: whatever the package has just said — a status table, a
 * task's result, an error, the JSON of `status --json` — the next thing on the
 * screen is a blank line, so that one piece of output is never read as the
 * continuation of the one above it and a shell prompt never lands against the
 * last word of a run. The blank line is *owed* rather than written
 * ({@see self::separate()}): it is put down when something else needs the
 * space, or by {@see self::finishLine()} on the way out, which is what keeps
 * two of them from ever landing on top of each other and keeps the lines of
 * one table, one report or one help page together.
 *
 * **Some of the lines this package writes are only printed when asked for.**
 * A line composed through {@see self::detail()} belongs to the full account of
 * a run and is written only under {@see Verbosity::Verbose}; a line written
 * through {@see self::line()} is the package's own account of its work and is
 * always written. Which is which is decided where a line is composed, for the
 * reason the styling is: only the caller knows whether what it is about to say
 * is the outcome or the commentary.
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
     * Destinations left part-way along a line, keyed by {@see
     * self::destinationOf()}.
     *
     * A chunk handed to {@see self::text()} ends wherever the worker it came
     * from happened to be, so this is how the package knows whether the next
     * thing it prints would start a line or land on the end of somebody
     * else's. Entries exist only while a line is open: absence means the
     * cursor is at the start of one, which is also its state before anything
     * has been written.
     *
     * @var array<int,true>
     */
    private array $unfinished = [];

    /**
     * Destinations owed a blank line before anything else is printed there,
     * keyed by {@see self::destinationOf()}.
     *
     * The deferral is the whole of how section K, item 3 avoids becoming
     * double spacing. An output that has finished saying something says so
     * ({@see self::separate()}) and the blank line is remembered; the next
     * line printed there puts it down first, and {@see self::finishLine()}
     * puts down whatever is still owed at the end of the command. Two
     * separations in a row, or a separation followed by a caller's own blank
     * line, are therefore one blank line — the flag is set, not counted.
     *
     * @var array<int,true>
     */
    private array $owed = [];

    /**
     * Destinations something has been written to, keyed the same way.
     *
     * A blank line is only ever owed by a destination that has something above
     * it: a command whose diagnostics stayed empty must not have a newline
     * written into the file standard error was redirected to, and a leading
     * blank line is the one blank line nobody asked for.
     *
     * @var array<int,true>
     */
    private array $started = [];

    /**
     * Destinations already ended by {@see self::finishLine()}.
     *
     * So that the last write of the process is idempotent: it is called from
     * the application's `finally` block, and a second call must not add a
     * second blank line. Cleared by the next ordinary write, because a
     * destination written to again has not been ended after all.
     *
     * @var array<int,true>
     */
    private array $finished = [];

    /**
     * Whether the two streams reach the same place, once asked.
     *
     * The cursor belongs to the destination rather than to the handle: a
     * worker's half-written progress line on standard error is still on the
     * screen when the next note goes to standard output, and on a terminal
     * that note has to start a line. Redirected apart — `ralph run > out.log
     * 2> err.log` — they are two files with two cursors and neither may be
     * given a newline the other one owed.
     *
     * @var ?bool
     */
    private ?bool $sharedDestination = null;

    /**
     * How much of what the package is doing this output prints.
     *
     * Not readonly, for the reason the secrets and the colour are not: the
     * streams exist before the command line has been parsed, and `--verbose`
     * has to reach the output that is already in use ({@see
     * self::useVerbosity()}).
     */
    private Verbosity $verbosity;

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
        Verbosity $verbosity = Verbosity::Quiet,
    ) {
        $this->secrets = $secrets ?? Secrets::none();
        $this->colour = $colour;
        $this->verbosity = $verbosity;
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
     * Decide how much this output prints.
     *
     * Called once, by {@see Application}, for `--verbose`. It reaches the
     * output already in use rather than producing a second one, because the
     * output a command was handed is also the one a failure is reported
     * through, and a stack trace is detail like any other.
     */
    public function useVerbosity(Verbosity $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    /**
     * Whether this output prints the full account of what the package is doing.
     *
     * Asked by the places that have something larger than a line to decide:
     * {@see \Celyes\RalphPhp\Log\TerminalSink}, which passes another
     * process's bytes through, and {@see Application}, which has a stack trace
     * to print or to offer.
     */
    public function isVerbose(): bool
    {
        return $this->verbosity->isVerbose();
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
     *
     * Ends where a line should end, whatever the caller handed over: text that
     * already carries a trailing newline is not given a second one, and an
     * unfinished line left on the stream by {@see self::text()} is closed
     * before this line is started rather than being written onto.
     *
     * A blank line owed by whatever was printed before it is put down first,
     * and a line asked for with no text at all is itself a separation rather
     * than a byte written now ({@see self::separate()}).
     */
    public function line(string $text = ''): void
    {
        $this->writeLine($this->standard, $text);
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
     * End the thing that has just been printed.
     *
     * The call that makes section K, item 3 true without turning every report
     * into a double-spaced one: a caller that has finished saying something
     * says so here, and the blank line after it is written when the next line
     * is — or at the end of the command, by {@see self::finishLine()}. Nothing
     * is written now beyond closing a line another process left open, so the
     * cost of calling it twice, or of calling it and then printing nothing at
     * all, is nothing.
     *
     * Both destinations are separated, each only if something has been printed
     * there: a section break belongs to the output rather than to one of its
     * two streams, and a diagnostic written after one is as much a new thing
     * being said as an ordinary line is.
     */
    public function separate(): void
    {
        $this->separateStream($this->standard);
        $this->separateStream($this->diagnostic);
    }

    /**
     * Write a line that belongs to the full account of a run.
     *
     * The commentary rather than the outcome: which checks were frozen, which
     * agent a resume inherited, what a run was told about its own
     * configuration. Printed under `--verbose` and dropped otherwise, which is
     * the whole of PROJECT.md section K, item 2 as far as this package's own
     * lines are concerned — the agent's output is dropped a layer further out,
     * where it is streamed ({@see \Celyes\RalphPhp\Log\TerminalSink}).
     *
     * Dropped, not buffered: nothing comes back later to print it. What a quiet
     * run did is on the attempt's log and in the state store either way, and
     * `ralph logs TASK-ID` is where it is read.
     */
    public function detail(string $text = ''): void
    {
        if ($this->isVerbose()) {
            $this->line($text);
        }
    }

    /**
     * Write several lines of the full account.
     *
     * @param list<string> $lines
     */
    public function detailLines(array $lines): void
    {
        if ($this->isVerbose()) {
            $this->lines($lines);
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
     * The newline such a chunk is still owed is not lost, only deferred: the
     * stream remembers that it is mid-line, and the next line this package
     * writes — or {@see self::finishLine()} on the way out — closes it.
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
        $this->writeLine($this->diagnostic, $text);
    }

    /**
     * End the command's output: close any open line, and leave a blank one.
     *
     * The last thing {@see Application::run()} does. Two promises, and the
     * first is older than the second: a command cannot hand the shell a prompt
     * halfway along a line — which is what a worker whose final chunk had no
     * newline, or a log file that ends without one, would otherwise leave
     * behind — and whatever it said last is followed by one blank line
     * (PROJECT.md section K, item 3), so that the prompt that follows is not
     * sitting against the run's last word.
     *
     * A stream nothing was written to is left alone, and a second call adds
     * nothing: the blank line is the end of this command's output, not a thing
     * that happens once per call.
     */
    public function finishLine(): void
    {
        $this->finishStream($this->standard);
        $this->finishStream($this->diagnostic);
    }

    /**
     * Owe a blank line on one stream, closing an open line first.
     *
     * @param resource $stream
     */
    private function separateStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }

        $destination = $this->destinationOf($stream);

        if (!isset($this->started[$destination])) {
            return;
        }

        // Closed now rather than left open and owed, so that what is
        // outstanding is only ever "a blank line after a finished line" —
        // there is one state to put down later instead of two.
        if (!$this->atLineStart($stream)) {
            $this->write($stream, PHP_EOL);
        }

        $this->owed[$destination] = true;
    }

    /**
     * End one stream's output, once.
     *
     * @param resource $stream
     */
    private function finishStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }

        $destination = $this->destinationOf($stream);

        if (!isset($this->started[$destination]) || isset($this->finished[$destination])) {
            return;
        }

        if (!$this->atLineStart($stream)) {
            $this->write($stream, PHP_EOL);
        }

        // The blank line, whether or not one was already owed: either way the
        // output ends with exactly one, which is what the item asks for and
        // what a shell prompt needs.
        unset($this->owed[$destination]);
        $this->write($stream, PHP_EOL);
        $this->finished[$destination] = true;
    }

    /**
     * Whether the next byte written to this stream would start a line.
     *
     * True before anything has been written, and false only while a chunk
     * handed to {@see self::text()} has left the cursor part-way along one.
     *
     * @param resource $stream
     */
    private function atLineStart(mixed $stream): bool
    {
        return !isset($this->unfinished[$this->destinationOf($stream)]);
    }

    /**
     * Write one of this package's own lines, ending it exactly once.
     *
     * Four things happen here, and they are here rather than in each of the
     * hundred and fifty places that print something:
     *
     *  - an unfinished line is closed first, so a note never lands on the end
     *    of a worker's half-printed progress indicator;
     *  - a blank line owed by whatever was printed before this is put down
     *    before the text, which is how one piece of output comes to be
     *    separated from the next (PROJECT.md section K, item 3);
     *  - the text is ended, whether or not it arrived ended, so that a caller
     *    passing a string it read from somewhere else cannot produce a line
     *    with no ending or a stray blank one after it. A caller that composed
     *    its own blank line keeps it, as a separation rather than as bytes, so
     *    that it collapses with the next one instead of doubling;
     *  - a line asked for with no text is a separation and nothing else. That
     *    is both the deliberate blank line a caller wanted and the way an
     *    unfinished line is closed, and the two must not combine into two
     *    newlines where one blank line was asked for.
     *
     * @param resource $stream
     */
    private function writeLine(mixed $stream, string $text): void
    {
        $text = $this->secrets->redact($text);

        if ($text === '') {
            $this->separateStream($stream);

            return;
        }

        $body = rtrim($text, "\r\n");
        $prefix = $this->atLineStart($stream) ? '' : PHP_EOL;
        $destination = $this->destinationOf($stream);

        if (isset($this->owed[$destination])) {
            $prefix .= PHP_EOL;
            unset($this->owed[$destination]);
        }

        $this->write($stream, $prefix . $body . PHP_EOL);

        // The blank lines the caller composed onto the end of its own text,
        // kept as what they are: a separation, owed and collapsible, rather
        // than newlines already on the stream that the next separation would
        // double.
        if (self::endingsOf($text) > 1) {
            $this->separateStream($stream);
        }
    }

    /**
     * How many line endings the text carries on the end of it.
     *
     * One `\r\n` is one ending, which is why this counts newlines rather than
     * the characters {@see writeLine()} trimmed: text read out of a file
     * written on Windows asked for no more spacing than the same text written
     * here.
     */
    private static function endingsOf(string $text): int
    {
        return substr_count(substr($text, strlen(rtrim($text, "\r\n"))), "\n");
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

        // Recorded before the write rather than after it: a write that fails
        // part-way has put some of these bytes on the screen, and a stream
        // that is gone is never written to again, so the pessimistic answer is
        // the safe one in both directions.
        $destination = $this->destinationOf($stream);

        // Something has been printed here, so this destination can be owed a
        // blank line and has an end worth ending; and a destination written to
        // after it was ended has not been ended after all.
        $this->started[$destination] = true;
        unset($this->finished[$destination]);

        if (str_ends_with($text, "\n")) {
            unset($this->unfinished[$destination]);
        } else {
            $this->unfinished[$destination] = true;
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

    /**
     * The cursor a stream writes against.
     *
     * Streams that reach the same place share one, so that a line opened on
     * either of them is closed by the next thing written to either. Resolved
     * once: `stream_isatty()` is a syscall, and this is asked on every write.
     *
     * @param resource $stream
     */
    private function destinationOf(mixed $stream): int
    {
        $this->sharedDestination ??= $this->standard === $this->diagnostic
            || (is_resource($this->standard)
                && is_resource($this->diagnostic)
                && @stream_isatty($this->standard)
                && @stream_isatty($this->diagnostic));

        if ($this->sharedDestination) {
            return 0;
        }

        return get_resource_id($stream);
    }
}
