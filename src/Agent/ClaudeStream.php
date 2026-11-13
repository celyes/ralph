<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

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
 * **Plain text, no colour.** What a filter writes reaches both of an attempt's
 * destinations ({@see StreamFilter}), and one of them is a log file somebody
 * will grep. The glyphs are `loop.sh`'s so that a reader of both sees one
 * convention; the escape sequences are not.
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
    public function write(OutputStream $stream, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if ($stream->isError()) {
            $this->sink->write($stream, $chunk);

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
            $this->writeOut($this->pending);
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
                $this->writeLine($line);
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
                    $this->writeLine(sprintf(
                        '⏺ %s(%s)',
                        self::textOf($block, 'name') ?? 'tool',
                        self::subject($block),
                    ));
                }
            }

            return;
        }

        if ($type === 'user') {
            foreach (self::blocksOf($event) as $block) {
                if (self::typeOf($block) === 'tool_result') {
                    $this->writeLine('  ⎿ ' . Excerpt::of(self::flatten(self::resultOf($block)), self::RESULT_WIDTH));
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

            $this->writeLine(sprintf(
                'claude %s · session %s',
                self::textOf($event, 'model') ?? '?',
                $session === '' ? '?' : substr($session, 0, 8),
            ));

            return;
        }

        if ($subtype !== 'api_error') {
            return;
        }

        $retry = self::numberOf($event, 'retryAttempt');
        $after = self::numberOf($event, 'retryInMs');

        $this->writeLine(sprintf(
            '⚠ api error: %s%s%s',
            Excerpt::of(self::flatten(self::problemOf($event)), self::RESULT_WIDTH),
            $retry === null
                ? ''
                : sprintf(' · retry %d/%d', (int) $retry, (int) (self::numberOf($event, 'maxRetries') ?? 0)),
            $after === null ? '' : sprintf(' in %ds', (int) round($after / 1000)),
        ));
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

        $this->writeLine(sprintf(
            '%s%s · %d turns · %.1fs%s',
            $failed ? '✗ failed' : '✓ done',
            $subtype === 'success' ? '' : sprintf(' (%s)', $subtype),
            (int) (self::numberOf($event, 'num_turns') ?? 0),
            (self::numberOf($event, 'duration_ms') ?? 0.0) / 1000,
            $cost === null ? '' : sprintf(' · $%.4f', $cost),
        ));

        foreach (self::errorsOf($event) as $error) {
            $this->writeLine('  ' . Excerpt::of(self::flatten($error), self::RESULT_WIDTH));
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
