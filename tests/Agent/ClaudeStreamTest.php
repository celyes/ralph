<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ClaudeStream;
use Celyes\RalphPhp\Log\Emphasis;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Claude Code's `stream-json` feed, read twice: as the session a watcher sees
 * and as the text the attempt is read out of.
 *
 * {@see ClaudeAdapterTest} covers both halves through a real process, which is
 * what proves the format is wired up at all. What is tested here is the reading
 * itself, against the shapes a live CLI produces that a stub session does not
 * reach: a chunk that ends mid-event, a line the CLI printed as prose, a
 * session killed before its result event, a 429 being retried. Those are
 * exactly the cases where a renderer fails by hiding output, so they are worth
 * asserting directly rather than only through an attempt.
 */
#[CoversClass(ClaudeStream::class)]
final class ClaudeStreamTest extends TestCase
{
    /**
     * A chunk is whatever came off the pipe, so an event routinely arrives in
     * pieces. Nothing may be written until the line is whole: a renderer that
     * forwarded half an event would put raw JSON on the terminal, which is the
     * one thing this class exists to prevent.
     */
    #[Test]
    public function an_event_split_across_chunks_is_rendered_once_it_is_whole(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $event = self::event(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-opus-5', 'session_id' => '0123456789abcdef']);

        $stream->write(OutputStream::Standard, substr($event, 0, 20));

        self::assertSame('', $sink->textOf(OutputStream::Standard));

        $stream->write(OutputStream::Standard, substr($event, 20) . "\n");

        self::assertSame(
            "claude claude-opus-5 · session 01234567\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * Several events in one chunk are each rendered, in order, which is the
     * common case: a pipe read holds whatever accumulated since the last one.
     */
    #[Test]
    public function every_event_in_one_chunk_is_rendered_in_order(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line(['type' => 'system', 'subtype' => 'init', 'model' => 'opus', 'session_id' => 'abcdef0123'])
            . self::line([
                'type' => 'assistant',
                'message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'src/Agent/ClaudeStream.php']]]],
            ])
            . self::line([
                'type' => 'user',
                'message' => ['content' => [['type' => 'tool_result', 'content' => 'declare(strict_types=1);']]],
            ]));

        self::assertSame(
            "claude opus · session abcdef01\n"
            . "⏺ Read(src/Agent/ClaudeStream.php)\n"
            . "  ⎿ declare(strict_types=1);\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * A line the CLI printed as plain text is written as it came.
     *
     * The case this matters for is the one `loop.sh` learned the hard way: a
     * usage limit reached before the first event is reported exactly this way,
     * and it is the only evidence there will be. A renderer that showed only
     * what it recognised would turn the one informative line of a failed
     * session into silence.
     */
    #[Test]
    public function a_line_the_cli_printed_as_prose_is_passed_through(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, "Claude usage limit reached|1793500000\n");

        self::assertSame(
            "Claude usage limit reached|1793500000\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * Being JSON is not enough to be an event, and cannot be: the briefing asks
     * the agent to report by printing a JSON object in a fenced block, so an
     * agent doing as it was told prints lines that decode perfectly. Keying on
     * the event type is what keeps a report visible instead of swallowed.
     *
     * An envelope naming a type this reading does not know goes through for the
     * same reason, which is the side this class deliberately errs on: a line
     * shown as the JSON it was is ugly, and a line hidden because a renderer
     * did not recognise it is how `loop.sh` used to lose the one message that
     * said why a session failed.
     */
    #[Test]
    public function a_json_line_that_is_not_an_event_is_passed_through(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $report = '{"outcome": "candidate", "summary": "Added the option."}';
        $unknown = self::event(['type' => 'bookkeeping', 'detail' => 'from a later CLI']);
        $stream->write(OutputStream::Standard, $report . "\n" . $unknown . "\n");

        self::assertSame($report . "\n" . $unknown . "\n", $sink->textOf(OutputStream::Standard));
    }

    /**
     * An event type this format does have but this reading has nothing to show
     * for is left out rather than guessed at — the feed carries bookkeeping a
     * watcher has no use for, and the envelope is not it.
     */
    #[Test]
    public function a_known_event_with_nothing_to_show_is_not_rendered(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line(['type' => 'system', 'subtype' => 'compact_boundary'])
            . self::line(['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'the briefing back again']]]]));

        self::assertSame('', $sink->textOf(OutputStream::Standard));
    }

    /**
     * The CLI's error output is not this format — warnings, and the failure
     * itself when a session dies before it starts — so it goes through
     * untouched, on its own stream, exactly as any other worker's would.
     */
    #[Test]
    public function error_output_is_forwarded_untouched_on_its_own_stream(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Error, 'warning: a partial line with no newline');

        self::assertSame('warning: a partial line with no newline', $sink->textOf(OutputStream::Error));
        self::assertSame('', $sink->textOf(OutputStream::Standard));
    }

    /**
     * What a killed session was in the middle of.
     *
     * A filter that buffers until a line is complete is holding the last line
     * of a worker that ended without a newline, and for a cancelled attempt
     * that line is the part that says how far it got. Flushing is how the
     * adapter says the process has ended.
     */
    #[Test]
    public function flushing_writes_out_the_line_the_session_ended_without(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, rtrim(self::line([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'composer install']]]],
        ]), "\n"));

        self::assertSame('', $sink->textOf(OutputStream::Standard));

        $stream->flush();

        self::assertSame("⏺ Bash(composer install)\n", $sink->textOf(OutputStream::Standard));

        // Safe to call twice: a run that failed part-way flushes on the way out
        // of more than one `finally`.
        $stream->flush();

        self::assertSame("⏺ Bash(composer install)\n", $sink->textOf(OutputStream::Standard));
    }

    /**
     * An attempt's log spans more than one command and is closed by whoever
     * opened it, so a filter's own close flushes and stops there.
     */
    #[Test]
    public function closing_the_filter_does_not_close_the_sink_underneath(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, 'the last word');
        $stream->close();

        self::assertSame("the last word\n", $sink->textOf(OutputStream::Standard));
        self::assertSame(0, $sink->closes);
    }

    /**
     * A note is Ralph PHP's own observation and is passed on as one — but the
     * line the agent was in the middle of is ended first, so that a note cannot
     * land halfway through a sentence.
     */
    #[Test]
    public function a_note_ends_the_agents_open_line_before_it_is_passed_on(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Reading the']],
        ]));
        $stream->note('claude exited with status 0');

        self::assertSame("Reading the\n", $sink->textOf(OutputStream::Standard));
        self::assertSame(['claude exited with status 0'], $sink->notes);
    }

    /**
     * The message as it is written: fragments go through as they arrive, so
     * output appears at the speed the agent produces it rather than a message
     * behind. Thinking is marked once, and a model that does not expose the
     * words gets a tick per fragment, because "something is happening" is the
     * whole question a watcher is asking.
     */
    #[Test]
    public function thinking_is_marked_once_and_wordless_fragments_tick(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_start', 'content_block' => ['type' => 'thinking']],
        ]));
        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Which file ']],
        ]));
        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'thinking_delta', 'thinking' => '']],
        ]));
        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_stop'],
        ]));

        self::assertSame("✻ thinking Which file ·\n", $sink->textOf(OutputStream::Standard));
        // One write per fragment: the point of the exercise.
        self::assertSame(4, $sink->chunkCountOf(OutputStream::Standard));
    }

    /**
     * The weights, which are the point of rendering this feed at all: an hour
     * of somebody else's session goes past, and the half-sentence that says
     * what was decided has to be findable in it.
     *
     * Asserted as what each piece was *said to be* rather than as colour. The
     * sequences are added at the terminal and nowhere nearer the work
     * ({@see \Celyes\RalphPhp\Log\TerminalSink}), because the same bytes are on
     * their way to a log file somebody will grep.
     */
    #[Test]
    public function the_agents_reasoning_and_the_agents_answer_are_weighted_apart(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_start', 'content_block' => ['type' => 'thinking']],
        ]) . self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Which file owns this?']],
        ]) . self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_stop'],
        ]) . self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "I added the option.\n"]],
        ]));

        self::assertSame('✻ thinking Which file owns this?', $sink->textWith(OutputStream::Standard, Emphasis::Thinking));
        self::assertSame("I added the option.\n", $sink->textWith(OutputStream::Standard, Emphasis::Result));
    }

    /**
     * A tool call is bookkeeping with something important inside it: the command
     * a `Bash` call ran is the work, and reads as code rather than as part of
     * the envelope around it. The text is exactly what it always was.
     */
    #[Test]
    public function a_tool_call_reads_as_activity_and_its_subject_as_code(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'composer test']]]],
        ]) . self::line([
            'type' => 'user',
            'message' => ['content' => [['type' => 'tool_result', 'content' => 'OK (1 test)']]],
        ]));

        self::assertSame("⏺ Bash(composer test)\n  ⎿ OK (1 test)\n", $sink->textOf(OutputStream::Standard));
        self::assertSame('⏺ Bash', $sink->textWith(OutputStream::Standard, Emphasis::Activity));
        self::assertSame('composer test', $sink->textWith(OutputStream::Standard, Emphasis::Code));
        self::assertSame("OK (1 test)\n", $sink->textWith(OutputStream::Standard, Emphasis::Detail));
    }

    /**
     * A fenced code block becomes a frame.
     *
     * The fences themselves are markdown's answer to a problem a terminal does
     * not have, so they are not printed: the language goes where a reader looks
     * for it, the code gets a gutter, and the block stays findable in a
     * scrollback and in the log — which is the one thing three backticks in the
     * middle of a paragraph do not do.
     *
     * The fence arrives split across deltas on purpose. It is why the message is
     * rendered a line at a time: a renderer that wrote each fragment as it came
     * would have put the gutter in the middle of a word.
     */
    #[Test]
    public function a_fenced_code_block_is_framed_with_its_language_and_a_gutter(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        foreach (["Run it with:\n\n```ba", "sh\ncomposer test\nvendor/bin/phpstan\n``", "`\n\nThat is all.\n"] as $fragment) {
            $stream->write(OutputStream::Standard, self::line([
                'type' => 'stream_event',
                'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $fragment]],
            ]));
        }

        self::assertSame(
            "Run it with:\n"
            . "\n"
            . "  ╭─ bash\n"
            . "  │ composer test\n"
            . "  │ vendor/bin/phpstan\n"
            . "  ╰─\n"
            . "\n"
            . "That is all.\n",
            $sink->textOf(OutputStream::Standard),
        );

        // The code is the answer and is weighted as one; the frame around it is
        // the package's own scaffolding and recedes.
        self::assertSame("composer test\nvendor/bin/phpstan\n", $sink->textWith(OutputStream::Standard, Emphasis::Code));
        self::assertSame(
            "  ╭─ bash\n" . '  │ ' . '  │ ' . "  ╰─\n",
            $sink->textWith(OutputStream::Standard, Emphasis::Frame),
        );
    }

    /**
     * A block the agent never closed is closed for it when the message ends.
     *
     * Otherwise the gutter would run on into the next paragraph — and, for a
     * session cut short mid-block, to the end of the log.
     */
    #[Test]
    public function a_code_block_left_open_is_closed_when_the_message_ends(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "```json\n{\"outcome\": \"candidate\"}"]],
        ]) . self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_stop'],
        ]) . self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "Done.\n"]],
        ]));

        self::assertSame(
            "  ╭─ json\n"
            . "  │ {\"outcome\": \"candidate\"}\n"
            . "  ╰─\n"
            . "Done.\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * A fence *with* a language inside a block does not close it: that is
     * markdown being quoted in markdown, which an agent writing about a README
     * does, and closing on it would put the rest of the block's code in the
     * prose.
     */
    #[Test]
    public function a_quoted_fence_inside_a_block_is_code_rather_than_its_end(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "~~~markdown\n```bash\nralph run\n```\n~~~\nthe README now says that.\n"]],
        ]));

        self::assertSame(
            "  ╭─ markdown\n"
            . "  │ ```bash\n"
            . "  │ ralph run\n"
            . "  │ ```\n"
            . "  ╰─\n"
            . "the README now says that.\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * The line held for a fence is written out when the session ends, and the
     * rest of it is not framed twice when it was flushed mid-line.
     */
    #[Test]
    public function the_half_line_a_session_ended_on_is_written_out(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "```php\n\$config = [" ]],
        ]));
        $stream->note('claude was stopped after 1800 seconds');

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "];\n"]],
        ]));
        $stream->flush();

        self::assertSame(
            "  ╭─ php\n"
            . "  │ \$config = [\n"
            . "];\n",
            $sink->textOf(OutputStream::Standard),
        );
        self::assertSame(['claude was stopped after 1800 seconds'], $sink->notes);
    }

    /**
     * A retried 429 or an overloaded API is minutes of an attempt's budget
     * spent waiting. Without the line the session looks idle for no reason,
     * which is also how a usage limit reached mid-attempt first becomes visible.
     */
    #[Test]
    public function an_api_error_is_shown_with_its_retry_and_its_delay(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'system',
            'subtype' => 'api_error',
            'error' => ['message' => "rate_limit_error: too many requests\nretry later"],
            'retryAttempt' => 2,
            'maxRetries' => 5,
            'retryInMs' => 8000,
        ]));

        self::assertSame(
            "⚠ api error: rate_limit_error: too many requests retry later · retry 2/5 in 8s\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * How the session ended, in the terms the CLI reports it in — and the
     * errors, because a failed session's reason is in them. None of it decides
     * the attempt's outcome: that is the result block's, read later.
     */
    #[Test]
    public function the_closing_summary_reports_the_turns_the_time_and_the_cost(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'result',
            'subtype' => 'error_during_execution',
            'is_error' => true,
            'num_turns' => 12,
            'duration_ms' => 94500,
            'total_cost_usd' => 0.1234,
            'errors' => ['tool use limit reached'],
        ]));

        self::assertSame(
            "✗ failed (error_during_execution) · 12 turns · 94.5s · \$0.1234\n"
            . "  tool use limit reached\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * A tool call is never shown as an empty pair of brackets: a tool naming
     * its subject some other way is shown as its input, flattened onto the one
     * line, and a long subject is cut rather than wrapped.
     */
    #[Test]
    public function a_tool_call_without_a_known_subject_field_is_shown_as_its_input(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'assistant',
            'message' => ['content' => [
                ['type' => 'tool_use', 'name' => 'TodoWrite', 'input' => ['todos' => ['first', 'second']]],
                ['type' => 'tool_use', 'name' => 'Grep', 'input' => ['pattern' => str_repeat('x', 200)]],
                ['type' => 'text', 'text' => 'not a call'],
            ]],
        ]));

        $shown = $sink->textOf(OutputStream::Standard);

        self::assertStringContainsString('⏺ TodoWrite({"todos":["first","second"]})', $shown);
        self::assertStringContainsString('⏺ Grep(' . str_repeat('x', 99) . '…)', $shown);
        self::assertStringNotContainsString('not a call', $shown);
    }

    /**
     * Text with no newline in it at all, past the point where this format could
     * still be producing one, is written through rather than held: holding on
     * would be a renderer that silently swallowed a worker's output.
     */
    #[Test]
    public function output_too_long_to_be_a_line_of_this_format_is_written_through(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, str_repeat('x', 9 * 1024 * 1024));

        self::assertSame(9 * 1024 * 1024, $sink->byteCountOf(OutputStream::Standard));
    }

    /**
     * A message with no line ending in it for longer than any line could be is
     * written out as it is, and the rest of that line follows it.
     *
     * The same side this class errs on everywhere else: holding output back to
     * finish a rendering is a renderer that swallowed what a watcher was waiting
     * for, and the gutter is worth less than the bytes.
     */
    #[Test]
    public function a_message_line_too_long_to_hold_is_written_through(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $written = str_repeat('x', 64 * 1024 + 1);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $written]],
        ]));

        self::assertSame($written, $sink->textOf(OutputStream::Standard));

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "y\n"]],
        ]));

        self::assertSame($written . "y\n", $sink->textOf(OutputStream::Standard));
    }

    /**
     * The reading of record: the attempt is read out of the session's final
     * message, which the stream carries as its own field — the same text
     * `--output-format text` would have printed on its own.
     */
    #[Test]
    public function the_transcript_is_the_sessions_final_message(): void
    {
        $transcript = ClaudeStream::transcript(
            self::line(['type' => 'system', 'subtype' => 'init', 'model' => 'opus'])
            . self::line(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Looking at the adapter.']]]])
            . self::line(['type' => 'result', 'subtype' => 'success', 'result' => "Added the option.\n\n```json\n{}\n```"]),
        );

        self::assertSame("Added the option.\n\n```json\n{}\n```", $transcript);
    }

    /**
     * What a session cut short had said.
     *
     * A timeout or a Ctrl+C ends the stream before its result event, and the
     * assistant's messages are then the only account of how far the agent got
     * — which is what the next attempt's failure evidence quotes.
     */
    #[Test]
    public function a_session_with_no_result_event_is_read_as_what_the_agent_said(): void
    {
        $transcript = ClaudeStream::transcript(
            self::line(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Reading the import command.']]]])
            . self::line(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Writing the test now.']]]]),
        );

        self::assertSame("Reading the import command.\n\nWriting the test now.", $transcript);
    }

    /**
     * Everything a reader of a failed attempt needs, in the order they need it:
     * the lines the CLI printed as prose first, because a session that failed
     * before it produced an event says so there and nowhere else; then the
     * errors the result event carried, which name a failure the final message
     * may not mention; then the message itself.
     */
    #[Test]
    public function the_transcript_keeps_the_cli_prose_and_the_reported_errors(): void
    {
        $transcript = ClaudeStream::transcript(
            "the CLI is deprecating this flag\n"
            . self::line([
                'type' => 'result',
                'subtype' => 'error_during_execution',
                'is_error' => true,
                'result' => 'I could not run the suite.',
                'errors' => ['tool use limit reached'],
            ]),
        );

        self::assertSame(
            "the CLI is deprecating this flag\n\ntool use limit reached\n\nI could not run the suite.",
            $transcript,
        );
    }

    /**
     * There is one result event per session, but a reading that assumed so
     * would be a reading that broke on output two sessions were appended to.
     */
    #[Test]
    public function the_last_result_event_is_the_one_the_transcript_reports(): void
    {
        $transcript = ClaudeStream::transcript(
            self::line(['type' => 'result', 'subtype' => 'success', 'result' => 'the first session'])
            . self::line(['type' => 'result', 'subtype' => 'success', 'result' => 'the second session']),
        );

        self::assertSame('the second session', $transcript);
    }

    /**
     * Output with no events in it is handed over untouched.
     *
     * Not only a failure: it is also a CLI invoked with a project's own
     * `--output-format text` in `agents.claude.arguments`, which stays readable
     * rather than becoming an attempt with no result.
     */
    #[Test]
    public function output_with_no_events_in_it_is_returned_unchanged(): void
    {
        $plain = "Added the option.\n\n```ralph-result\n{\"outcome\": \"candidate\"}\n```\n";

        self::assertSame($plain, ClaudeStream::transcript($plain));
        self::assertSame('', ClaudeStream::transcript(''));
    }

    /**
     * One event as the CLI writes it: a single line of JSON, no trailing
     * newline. A fixture that pretty-printed it would be exercising a format
     * nothing produces.
     *
     * @param array<string, mixed> $event
     */
    private static function event(array $event): string
    {
        return (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The same, as the feed carries it: one object per line.
     *
     * @param array<string, mixed> $event
     */
    private static function line(array $event): string
    {
        return self::event($event) . "\n";
    }
}
