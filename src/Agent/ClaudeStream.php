<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\Activity;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Log\StreamFilter;
use Celyes\RalphPhp\Support\Excerpt;
use JsonException;

/**
 * Claude Code's `stream-json` output, as a session a person can watch and as
 * the text the attempt is read out of.
 *
 * PROJECT.md section K: the agent's stream reaches the terminal. The reference
 * implementation is `loop.sh`, which runs the CLI with `--output-format
 * stream-json --include-partial-messages --verbose` and renders the event feed
 * through `jq` as it arrives; this is the same reading in the package, for the
 * same reason — an attempt is routinely several minutes of work, and the
 * alternative is a terminal that shows nothing until the session has ended and
 * a user who cannot tell a working agent from a hung one.
 *
 * **Why the format changed to get there.** `--print --output-format text`
 * prints the final message and nothing before it, so there is no stream to
 * show: the bytes genuinely arrive at the end. The events are the only form in
 * which this CLI says what it is doing while it does it, which makes the format
 * a consequence of the requirement rather than a preference.
 *
 * **Two readings of one stream, which is why this class has two halves.**
 * {@see self::write()} is the live one: a filter between
 * {@see \Celyes\RalphPhp\Process\ProcessRunner} and the attempt's sinks,
 * turning each event into the line a reader wants as it arrives.
 * {@see self::transcript()} is the one of record: the captured output, reduced
 * to the agent's own words, so that {@see ReportReader} looks for the result
 * block in a message rather than in JSON, and so that a piece of failure
 * evidence quotes prose. Neither is derived from the other — the live reading
 * has to answer per line, the reading of record has to answer about the whole —
 * but both are this format's, and keeping them in one class is what stops the
 * two from drifting into disagreeing about it.
 *
 * **The chunks are not the lines.** A read off the pipe begins and ends
 * wherever the worker happened to be, so a line of the feed arrives in pieces
 * and a piece can hold the end of one line and the start of the next. What this
 * class promises about that (PROJECT.md section K, item 14) is that the output
 * does not depend on where the reads fell: an event is held until its line is
 * whole and then rendered once, a line that is not an event is written through
 * exactly as it came, and whether a line *is* an event is decided from its
 * first non-blank byte rather than from the piece that byte arrived in. Within
 * standard output nothing is reordered — the pieces of a line go out in
 * arrival order or not at all — and nothing is inserted into a worker's line or
 * taken out of it. The one ordering this cannot promise is between the two
 * pipes: a line of standard output that is still incomplete is held, so an
 * error the CLI printed meanwhile appears first. Holding it is the point, since
 * the alternative is half a JSON envelope on the terminal, and the operating
 * system does not interleave two pipes exactly either.
 *
 * **Plain text, no colour.** What a filter writes reaches both of an attempt's
 * destinations ({@see StreamFilter}), and one of them is a log file somebody
 * will grep. The glyphs are `loop.sh`'s so that a reader of both sees one
 * convention; the escape sequences are not.
 *
 * **The live reading also says what it understood.** A rendered line is the
 * session as the log will hold it, which is the whole feed in order, and that
 * is more than a run which asked for less wants on its terminal. So each moment
 * worth a line is reported as a fact as well as written as one
 * ({@see \Celyes\RalphPhp\Log\Activity}): the session opening, the model
 * thinking, each message, each call, each result, how it ended. Nothing is
 * decided here about which destination wants which — a sink that has no use for
 * a fact ignores it, and the quiet terminal's choice of how much to show is
 * made where that line is composed ({@see \Celyes\RalphPhp\Log\ActivitySink}).
 * This is why the two calls sit next to each other rather than one being
 * derived from the other: the same moment, said twice, for two readers.
 *
 * **Anything that is not an event is passed through.** A line the CLI printed
 * as plain text is not noise to be hidden: a usage limit reached before the
 * first event is reported exactly that way, and it is the only evidence there
 * will be. `loop.sh` learned this the hard way — a renderer that parsed the
 * stream strictly stopped dead at the first such line and hid everything after
 * it — so a line that does not decode is written as it came, and a stream with
 * no events in it at all is handed over untouched by both halves. That last
 * case is not only a failure: it is also a CLI invoked with a project's own
 * `--output-format text` in `agents.claude.arguments`, which stays readable
 * rather than becoming an attempt with no result.
 *
 * **It never decides anything.** No outcome, no status, no claim is read here.
 * The events say what the agent did; what the attempt *was* is
 * {@see ProcessAdapter::reported()}'s reading of the result block, against the
 * exit status the package watched — this class only chooses which text that
 * reading is given.
 */
final class ClaudeStream implements StreamFilter
{
    /**
     * How much of a line that could still be an event may be held.
     *
     * A whole event is one line, and a tool result inside one can be large, so
     * the limit is generous. Past it the line is given up on and the rest of it
     * is written through as it arrives ({@see self::passThrough()}): text this
     * size with no newline in it is not this format, and holding on to it would
     * be a renderer that silently swallowed a worker's output instead of
     * showing it.
     */
    private const int LINE_LIMIT = 8 * 1024 * 1024;

    /**
     * The whitespace a line may begin with and still be an event.
     *
     * `trim()`'s own set, because {@see self::decode()} trims before it looks
     * at the first character, and the two have to agree about which byte that
     * is. Used with `strspn()` rather than `ltrim()`: this is asked of every
     * chunk of an undecided line and must not copy one.
     */
    private const string BLANK = " \t\n\r\0\x0B";

    /**
     * How much of a tool call's subject is shown.
     *
     * A command, a path or a pattern, long enough to recognise the call by and
     * short enough to stay on one line. The whole input is in the event the log
     * never sees; what a watcher needs is which file.
     */
    private const int SUBJECT_WIDTH = 100;

    /**
     * How much of a tool's result is shown.
     *
     * A tool result can be an entire file. One line of it says whether the call
     * worked, which is what a reader is following.
     */
    private const int RESULT_WIDTH = 120;

    /**
     * The event types this format has, and the whole of what makes a line one.
     *
     * A line is an event only if it decodes to an object naming one of these.
     * Being JSON is not enough and cannot be: the briefing asks the agent to
     * report by printing a JSON object in a fenced block, so an agent doing
     * exactly as it was told prints lines that decode perfectly and are not
     * events. Keying on the type is what keeps the two apart — and it keeps the
     * passthrough honest for anything else a CLI prints, which is JSON often
     * enough to matter.
     */
    private const array EVENTS = ['system', 'assistant', 'user', 'result', 'stream_event'];

    /**
     * The tools whose call is a command run against the user's checkout.
     *
     * Compared in lower case, and a list rather than `Bash` alone because a
     * project may point this adapter at a CLI whose shell tool is named
     * something else; what decides the reporting is what the call does, and a
     * tool that takes a `command` and is named like a shell is running one. A
     * tool not named here is reported as an ordinary call, which is a quieter
     * mistake than drawing a box round something that is not a command.
     *
     * @var list<string>
     */
    private const array COMMAND_TOOLS = ['bash', 'shell', 'local_shell', 'run_command', 'execute_command'];

    /**
     * How deep an event may nest before it is treated as something else.
     *
     * Events are shallow — an envelope, a message, its content blocks — and the
     * depth that matters is the one `json_decode` would otherwise allow by
     * default: 512 levels of a payload where an event belongs is not an event.
     */
    private const int DEPTH = 32;

    /**
     * Bytes read off standard output that have no newline after them yet.
     *
     * Always empty while {@see self::$passingThrough}: a line this filter has
     * given up on holds nothing back.
     */
    private string $pending = '';

    /**
     * Whether the line being read could still be an event: null until its first
     * non-blank byte says.
     *
     * Decided once per line, from whichever chunk that byte arrives in, so that
     * the answer cannot depend on where the reads happened to fall.
     */
    private ?bool $couldBeEvent = null;

    /**
     * Whether the rest of the current line goes straight through.
     *
     * Set when a line turns out not to be an event, or outgrows
     * {@see self::LINE_LIMIT}, and cleared by that line's newline. While it is
     * set the bytes are written as they arrive — the worker's own line, in its
     * own pieces, with nothing added to it and nothing taken away.
     */
    private bool $passingThrough = false;

    /**
     * Whether what has been written out ends a line.
     *
     * Tracked because the deltas of a message arrive as fragments and the lines
     * around them — a tool call, a result, the closing summary — are whole
     * lines. Without this, a tool call would be glued onto the end of whatever
     * the agent was in the middle of saying.
     */
    private bool $atLineStart = true;

    private function __construct(private readonly OutputSink $sink)
    {
    }

    /**
     * Render this CLI's events into the given sink as they arrive.
     */
    public static function rendering(OutputSink $sink): self
    {
        return new self($sink);
    }

    /**
     * Take a chunk of the session, and write out everything it completed.
     *
     * A chunk begins and ends wherever the pipe read fell, which is the whole
     * difficulty here (PROJECT.md section K, item 14): an event arrives in
     * pieces and must not be shown until it is whole, while the bytes of a line
     * that is *not* an event are the worker's own and must reach the terminal as
     * they came. So the chunk is taken a line at a time, each piece joins the
     * line it belongs to, and the one decision — is this line an event — is made
     * from its first non-blank byte rather than from the piece it arrived in.
     * Two reads or twenty produce the same output.
     *
     * Standard output only. The CLI's error output is not this format — it is
     * warnings and, when a session fails before it starts, the failure itself —
     * so it goes through untouched, on its own stream, exactly as any other
     * worker's would. The line this filter may be part-way through is ended
     * first: both streams reach one terminal in the ordinary case, and a warning
     * glued onto the end of a half-written sentence is unreadable. Nothing is
     * inserted into the error bytes themselves, and the log file would have
     * started the line for its own stream marker anyway ({@see
     * \Celyes\RalphPhp\Log\LogFile}).
     */
    public function write(OutputStream $stream, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if ($stream->isError()) {
            $this->endLine();
            $this->sink->write($stream, $chunk);

            return;
        }

        while ($chunk !== '') {
            $break = strpos($chunk, "\n");

            if ($break === false) {
                $this->take($chunk, complete: false);

                return;
            }

            $this->take(substr($chunk, 0, $break + 1), complete: true);
            $chunk = substr($chunk, $break + 1);
        }
    }

    /**
     * Take one piece of the line being read, and write out what can be.
     *
     * @param string $piece bytes belonging to the current line, ending with its
     *        newline when the line is complete
     * @param bool $complete whether that newline is on the end of this piece
     */
    private function take(string $piece, bool $complete): void
    {
        if ($this->passingThrough) {
            $this->writeOut($piece);

            if ($complete) {
                $this->startLine();
            }

            return;
        }

        $this->pending .= $piece;

        if ($complete) {
            $line = $this->pending;
            $this->startLine();
            $this->renderLine($line);

            return;
        }

        $this->couldBeEvent ??= self::opensAnEvent($piece);

        // Either this line is not an event, or it is longer than one of this
        // format's: both mean there is nothing left to wait for, and waiting is
        // how a renderer swallows a worker's output.
        if ($this->couldBeEvent === false || strlen($this->pending) > self::LINE_LIMIT) {
            $this->passThrough();
        }
    }

    /**
     * Give up on the current line and write out what is held, as it came.
     *
     * The rest of it follows byte for byte as it arrives. Our own rendering is
     * ended first, so the worker's line starts on a line of its own rather than
     * on the end of a sentence the agent was part-way through.
     */
    private function passThrough(): void
    {
        $this->passingThrough = true;

        $held = $this->pending;
        $this->pending = '';

        if ($held !== '') {
            $this->endLine();
            $this->writeOut($held);
        }
    }

    /**
     * Begin reading a new line of the feed, the one before it being finished.
     */
    private function startLine(): void
    {
        $this->pending = '';
        $this->couldBeEvent = null;
        $this->passingThrough = false;
    }

    /**
     * Whether a line beginning with these bytes could still be an event, or
     * null while it is all blank and has not said yet.
     *
     * The same precondition {@see self::decode()} applies, asked of a fragment:
     * an event is a JSON object, so a line whose first non-blank byte is not an
     * opening brace is something the CLI printed and can be let through at once
     * rather than held until its newline. That matters for a session a project
     * pointed at `--output-format text`, where the output is prose and holding a
     * line back is the difference between watching an agent work and watching
     * nothing.
     */
    private static function opensAnEvent(string $piece): ?bool
    {
        $start = strspn($piece, self::BLANK);

        if ($start === strlen($piece)) {
            return null;
        }

        return $piece[$start] === '{';
    }

    /**
     * An activity of somebody else's, passed on untouched.
     *
     * Nothing composes one upstream of this filter today, and passing it on
     * rather than dropping it is what keeps a filter a thing that can be put in
     * front of a sink without changing what reaches it.
     */
    public function activity(Activity $activity): void
    {
        $this->sink->activity($activity);
    }

    /**
     * A note of Ralph PHP's own, passed on as its own.
     *
     * Nothing to render and nothing to buffer — but the line the filter may be
     * in the middle of is ended first, so that a note cannot land halfway
     * through a sentence the agent was saying.
     */
    public function note(string $line): void
    {
        $this->flush();
        $this->sink->note($line);
    }

    /**
     * Write out whatever the session ended without a newline after.
     *
     * For a session that was killed this is the part worth keeping: the event
     * it was in the middle of, or the line the CLI was printing when it stopped.
     */
    public function flush(): void
    {
        if ($this->pending !== '') {
            $line = $this->pending;
            $this->startLine();

            $this->renderLine($line);
        }

        $this->startLine();
        $this->endLine();
    }

    /**
     * Flush, and leave the sink underneath open: it is not this filter's.
     */
    public function close(): void
    {
        $this->flush();
    }

    /**
     * The agent's own words, out of a captured session.
     *
     * What the attempt is read out of, and deliberately not the whole feed. The
     * final message is the CLI's `result`, which is the same text `--output-
     * format text` would have printed on its own, so the reading downstream is
     * the one it always was: one fenced result block in the agent's last
     * message ({@see ProcessAdapter::reported()}).
     *
     * Three things join it, in the order a reader needs them:
     *
     * - **Lines the CLI printed as plain text**, first, because a session that
     *   failed before it produced an event says so there and nowhere else.
     * - **The errors the result event carried**, which name a failure the final
     *   message may not mention.
     * - **The assistant's messages**, when there is no result event at all —
     *   a stream cut short by a timeout or a Ctrl+C, where what the agent had
     *   said is the only account of how far it got.
     *
     * A stream with no events in it is returned unchanged. The agent's words
     * are all that output held, which is the case for a CLI a project pointed
     * at another output format.
     */
    public static function transcript(string $output): string
    {
        $events = 0;
        $plain = [];
        $errors = [];
        $messages = [];
        $final = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $event = self::decode($line);

            if ($event === null) {
                if (trim($line) !== '') {
                    $plain[] = $line;
                }

                continue;
            }

            $events++;

            if (self::typeOf($event) === 'assistant') {
                $said = self::said($event);

                if ($said !== '') {
                    $messages[] = $said;
                }

                continue;
            }

            if (self::typeOf($event) !== 'result') {
                continue;
            }

            // The last result event wins. There is one per session, but a
            // reading that assumed so would be a reading that broke on output
            // two sessions were appended to.
            $final = self::textOf($event, 'result');

            foreach (self::errorsOf($event) as $error) {
                $errors[] = $error;
            }
        }

        if ($events === 0) {
            return $output;
        }

        $parts = [
            implode("\n", $plain),
            $errors === [] ? '' : implode("\n", $errors),
            $final !== null && trim($final) !== '' ? $final : implode("\n\n", $messages),
        ];

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * Show one whole line of the feed: an event rendered, or the line itself.
     *
     * A line that is not an event is written as it came, ending and all, which
     * is the same thing {@see self::passThrough()} does with the line it gave up
     * on. One rule for both, because otherwise a prose line the CLI printed
     * would look different depending on whether the read that carried it
     * happened to include its newline.
     *
     * @param string $line the line as it arrived, carrying its own ending unless
     *        the session stopped before it had one
     */
    private function renderLine(string $line): void
    {
        $event = self::decode($line);

        if ($event === null) {
            // Blank lines between events are the format's, not the CLI's.
            if (trim($line) !== '') {
                $this->endLine();
                $this->writeOut($line);
            }

            return;
        }

        $this->render($event);
    }

    /**
     * One event, as whatever a reader should see of it.
     *
     * The shapes are `loop.sh`'s, which is where they were established against
     * the real CLI: the session's model and id when it starts, an API error
     * while it is being retried, the message as it is written, each tool call
     * with its subject, each result in one line, and a closing summary with the
     * turns, the duration and the cost. Anything else is not shown — the feed
     * carries bookkeeping a watcher has no use for, and an event type this
     * version of the CLI did not have is better left out than guessed at.
     *
     * @param array<string, mixed> $event
     */
    private function render(array $event): void
    {
        $type = self::typeOf($event);

        if ($type === 'system') {
            $this->renderSystem($event);

            return;
        }

        if ($type === 'stream_event') {
            $this->renderDelta(self::objectOf($event, 'event'));

            return;
        }

        if ($type === 'assistant') {
            foreach (self::blocksOf($event) as $block) {
                if (self::typeOf($block) === 'text') {
                    $said = self::textOf($block, 'text') ?? '';

                    if (trim($said) !== '') {
                        $this->report(Activity::response($said));
                    }

                    continue;
                }

                if (self::typeOf($block) === 'tool_use') {
                    $tool = self::textOf($block, 'name') ?? 'tool';

                    $this->writeLine(sprintf('⏺ %s(%s)', $tool, self::subject($block)));
                    $this->report(self::calling($tool, $block));
                }
            }

            return;
        }

        if ($type === 'user') {
            foreach (self::blocksOf($event) as $block) {
                if (self::typeOf($block) === 'tool_result') {
                    $excerpt = Excerpt::of(self::flatten(self::resultOf($block)), self::RESULT_WIDTH);

                    $this->writeLine('  ⎿ ' . $excerpt);
                    $this->report(Activity::toolResult($excerpt));
                }
            }

            return;
        }

        if ($type === 'result') {
            $this->renderResult($event);
        }
    }

    /**
     * The session starting, or the API refusing to answer while it runs.
     *
     * The second is the one worth the line. A retried 429 or an overloaded API
     * is minutes of an attempt's budget spent waiting, and without it the
     * session looks idle for no reason — which is also how a usage limit
     * reached mid-attempt first becomes visible.
     *
     * @param array<string, mixed> $event
     */
    private function renderSystem(array $event): void
    {
        $subtype = self::textOf($event, 'subtype');

        if ($subtype === 'init') {
            $session = self::textOf($event, 'session_id') ?? '';

            $opening = sprintf(
                'claude %s · session %s',
                self::textOf($event, 'model') ?? '?',
                $session === '' ? '?' : substr($session, 0, 8),
            );

            $this->writeLine($opening);
            $this->report(Activity::session($opening));

            return;
        }

        if ($subtype !== 'api_error') {
            return;
        }

        $retry = self::numberOf($event, 'retryAttempt');
        $after = self::numberOf($event, 'retryInMs');

        $problem = sprintf(
            'api error: %s%s%s',
            Excerpt::of(self::flatten(self::problemOf($event)), self::RESULT_WIDTH),
            $retry === null
                ? ''
                : sprintf(' · retry %d/%d', (int) $retry, (int) (self::numberOf($event, 'maxRetries') ?? 0)),
            $after === null ? '' : sprintf(' in %ds', (int) round($after / 1000)),
        );

        $this->writeLine('⚠ ' . $problem);
        $this->report(Activity::problem($problem));
    }

    /**
     * The message as it is being written: the half that makes this a stream.
     *
     * Text goes through as it arrives, fragment by fragment, so that output
     * appears at the speed the agent produces it rather than a message behind.
     * Thinking is marked once and then shown the same way — or, when the model
     * does not expose the words, as a tick per fragment, because "something is
     * happening" is the whole question a watcher is asking.
     *
     * @param array<string, mixed> $event
     */
    private function renderDelta(array $event): void
    {
        $type = self::typeOf($event);

        if ($type === 'content_block_start') {
            if (self::typeOf(self::objectOf($event, 'content_block')) === 'thinking') {
                $this->endLine();
                $this->writeOut('✻ thinking ');

                // Once per block of reasoning, and without the reasoning: the
                // fact is what a watcher of a quiet run is asking about, and
                // the words are the wall of text it asked not to have
                // ({@see \Celyes\RalphPhp\Log\ActivityKind::Thinking}).
                $this->report(Activity::thinking());
            }

            return;
        }

        if ($type === 'content_block_stop') {
            $this->endLine();

            return;
        }

        $delta = self::objectOf($event, 'delta');

        if (self::typeOf($delta) === 'thinking_delta') {
            $thinking = self::textOf($delta, 'thinking') ?? '';

            $this->writeOut($thinking === '' ? '·' : $thinking);

            return;
        }

        if (self::typeOf($delta) === 'text_delta') {
            $this->writeOut(self::textOf($delta, 'text') ?? '');
        }
    }

    /**
     * How the session ended, in the terms the CLI reports it in.
     *
     * The turns and the duration because they say what the attempt cost, the
     * cost when the CLI knows it — PROJECT.md section 7 records execution cost
     * only where the backend reports it accurately, and this is where it does —
     * and the errors because a failed session's reason is in them. None of it
     * decides the attempt's outcome: that is the result block's, read later.
     *
     * @param array<string, mixed> $event
     */
    private function renderResult(array $event): void
    {
        $subtype = self::textOf($event, 'subtype') ?? 'success';
        $failed = ($event['is_error'] ?? false) === true;
        $cost = self::numberOf($event, 'total_cost_usd');

        $summary = sprintf(
            '%s%s · %d turns · %.1fs%s',
            $failed ? 'failed' : 'done',
            $subtype === 'success' ? '' : sprintf(' (%s)', $subtype),
            (int) (self::numberOf($event, 'num_turns') ?? 0),
            (self::numberOf($event, 'duration_ms') ?? 0.0) / 1000,
            $cost === null ? '' : sprintf(' · $%.4f', $cost),
        );

        $this->writeLine(($failed ? '✗ ' : '✓ ') . $summary);
        $this->report(Activity::outcome($summary));

        foreach (self::errorsOf($event) as $error) {
            $excerpt = Excerpt::of(self::flatten($error), self::RESULT_WIDTH);

            $this->writeLine('  ' . $excerpt);
            $this->report(Activity::problem($excerpt));
        }
    }

    /**
     * One line of the package's rendering, on a line of its own.
     */
    private function writeLine(string $line): void
    {
        $this->endLine();
        $this->writeOut($line . "\n");
    }

    /**
     * End the line being written, if one is open.
     */
    private function endLine(): void
    {
        if (!$this->atLineStart) {
            $this->writeOut("\n");
        }
    }

    private function writeOut(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->sink->write(OutputStream::Standard, $text);
        $this->atLineStart = str_ends_with($text, "\n");
    }

    /**
     * One line of the feed as an event, or null when it is not one.
     *
     * Objects of a type this format has, and no exception on the way out:
     * everything about this reading is of text a program somewhere else wrote,
     * and a line that is not an event has somewhere else to go.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $line): ?array
    {
        $line = trim($line);

        // Cheap enough to be worth it: most of a long session's lines are
        // events, and the ones that are not are usually prose.
        if ($line === '' || !str_starts_with($line, '{')) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $type = self::typeOf($decoded);

        return $type !== null && in_array($type, self::EVENTS, true) ? $decoded : null;
    }

    /**
     * What a tool call is being made about: its command, its file, its pattern.
     *
     * The fields `loop.sh` looks at, in its order — the ones the shipped tools
     * actually name their subject with — and the whole input as JSON when a
     * tool names it some other way, so a call is never shown as an empty pair
     * of brackets.
     *
     * @param array<string, mixed> $block
     */
    private static function subject(array $block): string
    {
        $input = self::objectOf($block, 'input');

        foreach (['command', 'file_path', 'pattern', 'path', 'url', 'prompt', 'description'] as $field) {
            $value = self::textOf($input, $field);

            if ($value !== null && trim($value) !== '') {
                return Excerpt::of(self::flatten($value), self::SUBJECT_WIDTH);
            }
        }

        if ($input === []) {
            return '';
        }

        return Excerpt::of(self::flatten((string) json_encode($input)), self::SUBJECT_WIDTH);
    }

    /**
     * One tool call, as the fact a terminal is told about it.
     *
     * A call that runs a program in the user's checkout is reported as the
     * command it is running, with the whole command rather than an excerpt of
     * it: a destination that shows a command is showing it so that a person can
     * read what is about to happen to their repository, and a truncated command
     * cannot be read that way. The width that reading needs is the reader's,
     * not this format's ({@see \Celyes\RalphPhp\Log\ActivitySink}).
     *
     * Anything else is the call and its subject, which is what the rendered
     * line says too.
     *
     * @param array<string, mixed> $block
     */
    private static function calling(string $tool, array $block): Activity
    {
        $command = self::commandOf(self::objectOf($block, 'input'));

        if ($command !== null && in_array(strtolower($tool), self::COMMAND_TOOLS, true)) {
            return Activity::command($tool, $command);
        }

        return Activity::toolCall($tool, self::subject($block));
    }

    /**
     * The command a call is making, in whichever shape its tool names it.
     *
     * A string for the shipped shell tools, and a list for one that passes argv
     * — joined rather than rejected, because a reader wants the command either
     * way and the alternative is a box with JSON in it.
     *
     * @param array<string, mixed> $input
     */
    private static function commandOf(array $input): ?string
    {
        /** @var mixed $command */
        $command = $input['command'] ?? null;

        if (is_string($command)) {
            return trim($command) === '' ? null : $command;
        }

        if (!is_array($command)) {
            return null;
        }

        $arguments = [];

        foreach ($command as $argument) {
            if (is_string($argument)) {
                $arguments[] = $argument;
            }
        }

        return $arguments === [] ? null : implode(' ', $arguments);
    }

    /**
     * Pass a fact about the session on to whatever is listening for one.
     *
     * Beside {@see self::writeLine()} rather than instead of it: the rendered
     * line is the session as a reader of the log will read it, and an activity
     * is the same moment named so that a terminal can decide how much of it to
     * show. Both or neither — a destination that takes one and not the other is
     * the point of there being two calls.
     */
    private function report(Activity $activity): void
    {
        $this->sink->activity($activity);
    }

    /**
     * A tool result's text, whichever shape the block carries it in.
     *
     * @param array<string, mixed> $block
     */
    private static function resultOf(array $block): string
    {
        /** @var mixed $content */
        $content = $block['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];

        foreach ($content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return implode(' ', $texts);
    }

    /**
     * An API error's message, which arrives either as an object or as text.
     *
     * @param array<string, mixed> $event
     */
    private static function problemOf(array $event): string
    {
        /** @var mixed $error */
        $error = $event['error'] ?? '';

        if (is_string($error)) {
            return $error;
        }

        if (!is_array($error)) {
            return '';
        }

        /** @var array<string, mixed> $error */
        $message = self::textOf($error, 'message');

        if ($message !== null) {
            return $message;
        }

        return (string) json_encode($error);
    }

    /**
     * Everything the assistant said in one message, as text.
     *
     * @param array<string, mixed> $event
     */
    private static function said(array $event): string
    {
        $texts = [];

        foreach (self::blocksOf($event) as $block) {
            if (self::typeOf($block) === 'text') {
                $text = self::textOf($block, 'text');

                if ($text !== null && trim($text) !== '') {
                    $texts[] = $text;
                }
            }
        }

        return implode("\n", $texts);
    }

    /**
     * The content blocks of a message event.
     *
     * @param array<string, mixed> $event
     *
     * @return list<array<string, mixed>>
     */
    private static function blocksOf(array $event): array
    {
        $content = self::objectOf($event, 'message')['content'] ?? null;

        if (!is_array($content)) {
            return [];
        }

        $blocks = [];

        foreach ($content as $block) {
            if (is_array($block)) {
                /** @var array<string, mixed> $block */
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The strings a result event's `errors` holds.
     *
     * @param array<string, mixed> $event
     *
     * @return list<string>
     */
    private static function errorsOf(array $event): array
    {
        /** @var mixed $errors */
        $errors = $event['errors'] ?? null;

        if (!is_array($errors)) {
            return [];
        }

        $lines = [];

        foreach ($errors as $error) {
            $line = is_string($error) ? $error : (string) json_encode($error);

            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function typeOf(array $event): ?string
    {
        return self::textOf($event, 'type');
    }

    /**
     * A string field, or null when it is absent or is something else.
     *
     * @param array<string, mixed> $event
     */
    private static function textOf(array $event, string $field): ?string
    {
        /** @var mixed $value */
        $value = $event[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A numeric field, or null when it is absent or is something else.
     *
     * @param array<string, mixed> $event
     */
    private static function numberOf(array $event, string $field): ?float
    {
        /** @var mixed $value */
        $value = $event[$field] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * A nested object, or an empty array when it is absent or is something else.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private static function objectOf(array $event, string $field): array
    {
        /** @var mixed $value */
        $value = $event[$field] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Whitespace collapsed, so a value with newlines in it stays on one line.
     */
    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
