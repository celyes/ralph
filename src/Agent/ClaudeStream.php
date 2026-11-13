<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\Emphasis;
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
 * **Plain text, with a weight on it.** What a filter writes reaches both of an
 * attempt's destinations ({@see StreamFilter}), and one of them is a log file
 * somebody will grep — so the bytes carry no escape sequences. What each piece
 * *is* goes alongside it instead ({@see Emphasis}), and only a terminal acts on
 * that: the agent's reasoning recedes into grey, its own words come out in
 * white, a tool call reads as the bookkeeping it is, and a code block is set
 * apart by a gutter the log keeps too. This class is the only thing in the chain
 * that can make those distinctions — downstream they are all just bytes — which
 * is why they are made here and resolved as late as possible
 * ({@see \Celyes\RalphPhp\Console\Style::emphasis()}).
 *
 * **One line of prose at a time, and fragments of everything else.** Thinking
 * goes through as it arrives, fragment by fragment, because that is the output
 * that answers "is this thing alive"; the message itself is held to the end of
 * each line, because a fence is a line and a code block that is recognised a
 * fragment late is a code block rendered with its gutter in the wrong place. The
 * cost is at most one unfinished line held back, which {@see self::flush()}
 * writes out when the session ends and a note writes out before it prints.
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
     * How much output may be held waiting for a newline.
     *
     * A whole event is one line, and a tool result inside one can be large, so
     * the limit is generous. Past it the bytes are written through as they are:
     * text this size with no newline in it is not this format, and holding on
     * to it would be a renderer that silently swallowed a worker's output
     * instead of showing it.
     */
    private const int LINE_LIMIT = 8 * 1024 * 1024;

    /**
     * How much of the message may be held waiting for the end of a line.
     *
     * The message is rendered a line at a time so that a code fence is seen
     * before the line it opens is printed, and a model writing prose produces a
     * newline every sentence or two. Past this a "line" is not a line: the bytes
     * are written out as they are, and the rest of that line follows them, which
     * costs a gutter in the wrong place on pathological output and never costs
     * output a watcher does not see.
     */
    private const int PROSE_LIMIT = 64 * 1024;

    /**
     * The frame around a fenced code block the agent wrote.
     *
     * A block of code in the middle of prose is the part a reader wants to find
     * again, and the fence markers it arrives wrapped in are markdown's answer
     * to a problem a terminal does not have. So the fences become a frame: the
     * language where the opening fence named one, a gutter down the left of the
     * code, and a corner to close it. The gutter is what makes the block
     * skimmable in a scrollback and findable in the log, which keeps it for the
     * same reason it keeps the glyphs above.
     */
    private const string CODE_TOP = '  ╭─';

    private const string CODE_GUTTER = '  │ ';

    private const string CODE_END = '  ╰─';

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
     * How deep an event may nest before it is treated as something else.
     *
     * Events are shallow — an envelope, a message, its content blocks — and the
     * depth that matters is the one `json_decode` would otherwise allow by
     * default: 512 levels of a payload where an event belongs is not an event.
     */
    private const int DEPTH = 32;

    /**
     * Bytes read off standard output that have no newline after them yet.
     */
    private string $pending = '';

    /**
     * Whether what has been written out ends a line.
     *
     * Tracked because the deltas of a message arrive as fragments and the lines
     * around them — a tool call, a result, the closing summary — are whole
     * lines. Without this, a tool call would be glued onto the end of whatever
     * the agent was in the middle of saying.
     */
    private bool $atLineStart = true;

    /**
     * The message's current line, so far, with no newline after it yet.
     *
     * Held rather than written because a line that turns out to be a code fence
     * is not shown at all and the lines after it are shown with a gutter: see
     * {@see self::PROSE_LIMIT}.
     */
    private string $prose = '';

    /**
     * The run of characters the message's open code fence was opened with, or
     * an empty string when the message is not inside one.
     *
     * The marker itself rather than a flag, so that a fence quoted inside a
     * block does not end it ({@see self::closes()}). Per content block, and
     * reset with it: a message that ends mid-fence has its block closed on the
     * way out rather than leaving the next message's prose in a gutter.
     */
    private string $fence = '';

    /**
     * Whether part of the message's current line has already been written.
     *
     * The flag that keeps a line that was flushed early — by a note, by the
     * limit above — from being given a second gutter when the rest of it
     * arrives.
     */
    private bool $lineOpen = false;

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
     * Take a chunk of the session, and write out every event it completed.
     *
     * Standard output only. The CLI's error output is not this format — it is
     * warnings and, when a session fails before it starts, the failure itself —
     * so it goes through untouched, on its own stream, exactly as any other
     * worker's would.
     */
    public function write(OutputStream $stream, string $chunk, Emphasis $emphasis = Emphasis::Plain): void
    {
        if ($chunk === '') {
            return;
        }

        if ($stream->isError()) {
            $this->sink->write($stream, $chunk, $emphasis);

            return;
        }

        $this->pending .= $chunk;

        while (($break = strpos($this->pending, "\n")) !== false) {
            $line = substr($this->pending, 0, $break);
            $this->pending = substr($this->pending, $break + 1);

            $this->renderLine($line);
        }

        // A line longer than anything this format produces. Written through
        // rather than held: see the constant.
        if (strlen($this->pending) > self::LINE_LIMIT) {
            $this->writeOut($this->pending, Emphasis::Plain);
            $this->pending = '';
        }
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
            $this->pending = '';

            $this->renderLine($line);
        }

        $this->flushProse();
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
     * Show one line of the feed: an event rendered, or the line itself.
     */
    private function renderLine(string $line): void
    {
        $line = rtrim($line, "\r");
        $event = self::decode($line);

        if ($event === null) {
            // Blank lines between events are the format's, not the CLI's.
            if (trim($line) !== '') {
                // Plain: a line the CLI printed as text is another program's
                // output, and this reading has no idea what it is.
                $this->writeLine($line, Emphasis::Plain);
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
                if (self::typeOf($block) === 'tool_use') {
                    // The call reads as activity and its subject as what it is:
                    // a `Bash(composer test)` is a command, a `Read(src/…)` is
                    // the file the answer came out of, and both are worth more
                    // of a reader's attention than the brackets around them.
                    $this->writeParts([
                        ['⏺ ', Emphasis::Activity],
                        [self::textOf($block, 'name') ?? 'tool', Emphasis::Activity],
                        ['(', Emphasis::Frame],
                        [self::subject($block), Emphasis::Code],
                        [')', Emphasis::Frame],
                    ]);
                }
            }

            return;
        }

        if ($type === 'user') {
            foreach (self::blocksOf($event) as $block) {
                if (self::typeOf($block) === 'tool_result') {
                    $this->writeParts([
                        ['  ⎿ ', Emphasis::Frame],
                        [Excerpt::of(self::flatten(self::resultOf($block)), self::RESULT_WIDTH), Emphasis::Detail],
                    ]);
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

            $this->writeParts([
                ['claude ', Emphasis::Detail],
                [self::textOf($event, 'model') ?? '?', Emphasis::Activity],
                [' · session ', Emphasis::Detail],
                [$session === '' ? '?' : substr($session, 0, 8), Emphasis::Activity],
            ]);

            return;
        }

        if ($subtype !== 'api_error') {
            return;
        }

        $retry = self::numberOf($event, 'retryAttempt');
        $after = self::numberOf($event, 'retryInMs');

        $this->writeParts([
            ['⚠ api error: ', Emphasis::Warning],
            [Excerpt::of(self::flatten(self::problemOf($event)), self::RESULT_WIDTH), Emphasis::Warning],
            [
                $retry === null
                    ? ''
                    : sprintf(' · retry %d/%d', (int) $retry, (int) (self::numberOf($event, 'maxRetries') ?? 0)),
                Emphasis::Detail,
            ],
            [$after === null ? '' : sprintf(' in %ds', (int) round($after / 1000)), Emphasis::Detail],
        ]);
    }

    /**
     * The message as it is being written: the half that makes this a stream.
     *
     * Thinking goes through as it arrives, fragment by fragment, so that a
     * watcher can see the session is alive at the speed the agent is working;
     * it is marked once and then shown in grey — or, when the model does not
     * expose the words, as a tick per fragment, because "something is
     * happening" is the whole question being asked.
     *
     * The message itself is gathered into lines ({@see self::renderProse()}),
     * which is what lets a fenced code block be recognised and framed rather
     * than printed as three backticks in the middle of a sentence.
     *
     * @param array<string, mixed> $event
     */
    private function renderDelta(array $event): void
    {
        $type = self::typeOf($event);

        if ($type === 'content_block_start') {
            // A new block starts a new line, and starts it outside whatever
            // fence the last one was in the middle of.
            $this->closeProse();

            if (self::typeOf(self::objectOf($event, 'content_block')) === 'thinking') {
                $this->writeOut('✻ thinking ', Emphasis::Thinking);
            }

            return;
        }

        if ($type === 'content_block_stop') {
            $this->closeProse();

            return;
        }

        $delta = self::objectOf($event, 'delta');

        if (self::typeOf($delta) === 'thinking_delta') {
            $thinking = self::textOf($delta, 'thinking') ?? '';

            $this->writeOut($thinking === '' ? '·' : $thinking, Emphasis::Thinking);

            return;
        }

        if (self::typeOf($delta) === 'text_delta') {
            $this->renderProse(self::textOf($delta, 'text') ?? '');
        }
    }

    /**
     * A fragment of the message, shown a line at a time.
     *
     * Deltas arrive in pieces of whatever size the API produced, so the lines
     * are assembled here. A line is only shown once it is whole because the
     * next thing it might turn out to be is a code fence, and a fence is only a
     * fence as a line: three backticks mid-sentence are three backticks.
     */
    private function renderProse(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->prose .= $text;

        while (($break = strpos($this->prose, "\n")) !== false) {
            $line = substr($this->prose, 0, $break);
            $this->prose = substr($this->prose, $break + 1);

            $this->renderProseLine(rtrim($line, "\r"));
        }

        // Prose with no line ending in it for this long is not a line a fence
        // could still be hiding in. Written through rather than held: see the
        // constant.
        if (strlen($this->prose) > self::PROSE_LIMIT) {
            $this->flushProse();
        }
    }

    /**
     * One whole line of the message: prose, code, or a fence around code.
     *
     * The fences themselves are never printed. An opening one becomes the top
     * of a frame, with the language it named where a reader will look for it; a
     * closing one becomes the bottom; the lines between get a gutter, so that a
     * block of code in the middle of a paragraph can be found again in a
     * scrollback and in the log.
     */
    private function renderProseLine(string $line): void
    {
        // The rest of a line that was already written out in part: it has had
        // whatever frame it is getting, and is not a fence.
        if ($this->lineOpen) {
            $this->writeOut($line . "\n", $this->weight());
            $this->lineOpen = false;

            return;
        }

        $fence = self::fenceOf($line);

        if ($this->fence !== '') {
            if ($fence !== null && self::closes($this->fence, $fence)) {
                $this->fence = '';
                $this->writeLine(self::CODE_END, Emphasis::Frame);

                return;
            }

            // A blank line inside a block is the gutter and nothing else: a
            // trailing space would be whitespace this package invented, in a
            // file somebody diffs.
            if ($line === '') {
                $this->writeLine(rtrim(self::CODE_GUTTER), Emphasis::Frame);

                return;
            }

            $this->writeParts([
                [self::CODE_GUTTER, Emphasis::Frame],
                [$line, Emphasis::Code],
            ]);

            return;
        }

        if ($fence !== null) {
            [$marker, $language] = $fence;
            $this->fence = $marker;

            $this->writeLine(
                $language === '' ? self::CODE_TOP : self::CODE_TOP . ' ' . $language,
                Emphasis::Frame,
            );

            return;
        }

        $this->writeLine($line, Emphasis::Result);
    }

    /**
     * Write part of the message's current line, opening the line if needed.
     *
     * The gutter belongs to the line rather than to the fragment that happened
     * to start it, which is what this exists to keep straight: a code line
     * flushed early by a note and then continued must not be given a second
     * one.
     */
    private function continueProse(string $text): void
    {
        if ($this->fence !== '' && !$this->lineOpen) {
            $this->endLine();
            $this->writeOut(self::CODE_GUTTER, Emphasis::Frame);
            $this->lineOpen = true;
        }

        if ($text === '') {
            return;
        }

        $this->writeOut($text, $this->weight());
        $this->lineOpen = true;
    }

    /**
     * What the message's current line is: code, or the agent's own prose.
     */
    private function weight(): Emphasis
    {
        return $this->fence === '' ? Emphasis::Result : Emphasis::Code;
    }

    /**
     * Write out the part of a line the message has produced so far.
     *
     * For a session that ended mid-sentence, and for a note that has to print
     * without landing inside one.
     */
    private function flushProse(): void
    {
        if ($this->prose === '') {
            return;
        }

        $text = $this->prose;
        $this->prose = '';

        $this->continueProse($text);
    }

    /**
     * Finish the message's line and close the block it was in.
     *
     * A content block that ended inside a fence is closed here rather than left
     * open: the agent's next paragraph is not code, and a log in which the
     * gutter never ends is harder to read than one with a frame that was closed
     * on the agent's behalf.
     */
    private function closeProse(): void
    {
        $this->flushProse();
        $this->endLine();
        $this->lineOpen = false;

        if ($this->fence !== '') {
            $this->fence = '';
            $this->writeLine(self::CODE_END, Emphasis::Frame);
        }
    }

    /**
     * A line read as a code fence: the run of characters it is made of and the
     * language it named, or null when the line is not a fence at all.
     *
     * Both of markdown's fences, because an agent writing about markdown writes
     * the other one inside the first. The run is kept rather than reduced to a
     * flag so that {@see self::closes()} can tell a block's own end from a fence
     * quoted inside it.
     *
     * @return array{string, string}|null
     */
    private static function fenceOf(string $line): ?array
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/', $line, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * Whether this fence ends the block that fence opened.
     *
     * Markdown's own rule, and it is the rule that matters here rather than a
     * simpler one: a block closes on a bare fence of the same character, at
     * least as long as the one that opened it. An agent documenting this package
     * writes a `~~~markdown` block with ```` ``` ```` blocks inside it, and a
     * reading that closed on the first fence it saw would put the rest of that
     * block's code in the prose — half a frame, and a paragraph in a gutter.
     *
     * @param string $open the run of characters the block was opened with
     * @param array{string, string} $fence the fence being considered
     */
    private static function closes(string $open, array $fence): bool
    {
        [$marker, $language] = $fence;

        return $language === ''
            && $marker[0] === $open[0]
            && strlen($marker) >= strlen($open);
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

        $this->writeParts([
            [
                sprintf(
                    '%s%s',
                    $failed ? '✗ failed' : '✓ done',
                    $subtype === 'success' ? '' : sprintf(' (%s)', $subtype),
                ),
                $failed ? Emphasis::Failure : Emphasis::Success,
            ],
            [
                sprintf(
                    ' · %d turns · %.1fs%s',
                    (int) (self::numberOf($event, 'num_turns') ?? 0),
                    (self::numberOf($event, 'duration_ms') ?? 0.0) / 1000,
                    $cost === null ? '' : sprintf(' · $%.4f', $cost),
                ),
                Emphasis::Detail,
            ],
        ]);

        foreach (self::errorsOf($event) as $error) {
            $this->writeParts([
                ['  ', Emphasis::Frame],
                [Excerpt::of(self::flatten($error), self::RESULT_WIDTH), Emphasis::Failure],
            ]);
        }
    }

    /**
     * One line of the package's rendering, on a line of its own.
     */
    private function writeLine(string $line, Emphasis $emphasis): void
    {
        $this->endLine();
        $this->writeOut($line . "\n", $emphasis);
    }

    /**
     * One line of the package's rendering, in pieces of differing weight.
     *
     * A rendered line is rarely one thing — `⏺ Bash(composer test)` is a call,
     * a command and the brackets between them — and the weighting is per write
     * ({@see Emphasis}), so a line that reads as one sentence is written as the
     * parts it is made of. The text is exactly what joining the parts gives,
     * which is what keeps the log a line-for-line copy of the terminal.
     *
     * The line ending goes to the last part that has any text, so that it is
     * carried by the same weight as the text it ends rather than arriving as a
     * piece of its own.
     *
     * @param list<array{string, Emphasis}> $parts
     */
    private function writeParts(array $parts): void
    {
        $this->endLine();

        $written = array_values(array_filter(
            $parts,
            static fn (array $part): bool => $part[0] !== '',
        ));

        if ($written === []) {
            $this->writeOut("\n", Emphasis::Plain);

            return;
        }

        $last = count($written) - 1;
        $written[$last][0] .= "\n";

        foreach ($written as [$text, $emphasis]) {
            $this->writeOut($text, $emphasis);
        }
    }

    /**
     * End the line being written, if one is open.
     */
    private function endLine(): void
    {
        if (!$this->atLineStart) {
            $this->writeOut("\n", Emphasis::Plain);
        }
    }

    /**
     * Bytes out, and what they are.
     *
     * The weight is a tag on the write rather than a sequence in the text: the
     * sink underneath fans out to a terminal and to a file, and only one of
     * those wants the colour ({@see Emphasis}).
     */
    private function writeOut(string $text, Emphasis $emphasis): void
    {
        if ($text === '') {
            return;
        }

        $this->sink->write(OutputStream::Standard, $text, $emphasis);
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
