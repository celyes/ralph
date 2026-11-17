<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ClaudeStream;
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
     * A line given up on for its length keeps its remaining bytes.
     *
     * The hard case of PROJECT.md section K, item 14. Once the bytes held back
     * have been written through there is no going back on them, so the rest of
     * that line has to follow as it arrives: rendering its tail as though it
     * were a line of its own would put a newline in the middle of the worker's
     * line, drop the carriage return of a `\r\n` the worker sent, and hide a
     * tail that happened to be whitespace.
     */
    #[Test]
    public function the_rest_of_a_line_given_up_on_is_written_through_as_it_came(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        // Opens like an event, so it is held rather than let straight through,
        // and outgrows what this format's lines can be.
        $head = '{"type":"assistant","x":"' . str_repeat('a', 9 * 1024 * 1024);

        $stream->write(OutputStream::Standard, $head);
        $stream->write(OutputStream::Standard, 'TAIL');
        $stream->write(OutputStream::Standard, "\r\n");
        $stream->write(OutputStream::Standard, self::line(['type' => 'result', 'num_turns' => 1, 'duration_ms' => 1000]));

        $shown = $sink->textOf(OutputStream::Standard);

        self::assertSame(strlen($head) + strlen("TAIL\r\n") + strlen("✓ done · 1 turns · 1.0s\n"), strlen($shown));
        self::assertStringEndsWith("aTAIL\r\n✓ done · 1 turns · 1.0s\n", $shown);

        // The worker's line ending and the rendered event's, and nothing else:
        // what was written through carries no ending this filter invented.
        self::assertSame(2, substr_count($shown, "\n"));
    }

    /**
     * A line that cannot be an event is let through before its newline.
     *
     * The first non-blank byte of a line is enough to know: an event is a JSON
     * object, so anything else is the CLI's own output and there is nothing to
     * wait for. It matters for a session a project pointed at
     * `--output-format text`, where every line is prose — holding each one back
     * until its newline would be streaming in the letter and a terminal a line
     * behind in practice.
     */
    #[Test]
    public function a_line_that_cannot_be_an_event_is_shown_before_its_newline(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, 'Claude usage limit reac');

        self::assertSame('Claude usage limit reac', $sink->textOf(OutputStream::Standard));

        $stream->write(OutputStream::Standard, "hed|1793500000\n");

        self::assertSame("Claude usage limit reached|1793500000\n", $sink->textOf(OutputStream::Standard));
    }

    /**
     * The same bytes, read in any number of pieces, produce the same output.
     *
     * The whole of PROJECT.md section K, item 14 in one assertion. A pipe read
     * ends wherever the worker happened to be — mid-event, between a `\r` and
     * its `\n`, between two lines — and none of that is the session. So the
     * feed below is rendered once as a single chunk and then again one byte at a
     * time, which is every split there is, and the two readings have to agree.
     */
    #[Test]
    public function the_rendering_does_not_depend_on_where_the_chunks_fell(): void
    {
        $feed = self::line(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-opus-5', 'session_id' => '0123456789abcdef'])
            . self::line([
                'type' => 'stream_event',
                'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Reading the test']],
            ])
            . self::line(['type' => 'stream_event', 'event' => ['type' => 'content_block_stop']])
            . "a line the cli printed\r\n"
            . "\n"
            . '{"outcome":"candidate"}' . "\n"
            . self::line(['type' => 'result', 'num_turns' => 2, 'duration_ms' => 1500]);

        $whole = new RecordingSink();
        $stream = ClaudeStream::rendering($whole);
        $stream->write(OutputStream::Standard, $feed);
        $stream->flush();

        $byByte = new RecordingSink();
        $stream = ClaudeStream::rendering($byByte);

        foreach (str_split($feed) as $byte) {
            $stream->write(OutputStream::Standard, $byte);
        }

        $stream->flush();

        self::assertSame(
            $whole->textOf(OutputStream::Standard),
            $byByte->textOf(OutputStream::Standard),
        );

        // And it is the rendering, not two identically broken ones.
        self::assertSame(
            "claude claude-opus-5 · session 01234567\n"
            . "Reading the test\n"
            . "a line the cli printed\r\n"
            . '{"outcome":"candidate"}' . "\n"
            . "✓ done · 2 turns · 1.5s\n",
            $whole->textOf(OutputStream::Standard),
        );
    }

    /**
     * A line ending split between two reads stays one line ending.
     *
     * The `\r` arrives in one chunk and its `\n` in the next, which for an event
     * means the line is not whole yet and must not be rendered twice, and for
     * the worker's own output means the two bytes go through as the worker sent
     * them.
     */
    #[Test]
    public function a_line_ending_split_between_two_reads_is_still_one_ending(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $event = self::event(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-opus-5', 'session_id' => 'abcdefgh']);

        $stream->write(OutputStream::Standard, $event . "\r");

        self::assertSame('', $sink->textOf(OutputStream::Standard));

        $stream->write(OutputStream::Standard, "\n");
        $stream->write(OutputStream::Standard, 'prose');
        $stream->write(OutputStream::Standard, "\r");
        $stream->write(OutputStream::Standard, "\n");

        self::assertSame(
            "claude claude-opus-5 · session abcdefgh\nprose\r\n",
            $sink->textOf(OutputStream::Standard),
        );
    }

    /**
     * Error output starts on a line of its own.
     *
     * Both streams reach one terminal in the ordinary case, so a warning handed
     * over while the agent's sentence is half written would be read as part of
     * that sentence. The error bytes themselves are untouched — the line that
     * was open was this filter's own rendering, and it is the thing that gets
     * ended.
     */
    #[Test]
    public function an_error_chunk_does_not_land_on_the_end_of_an_open_line(): void
    {
        $sink = new RecordingSink();
        $stream = ClaudeStream::rendering($sink);

        $stream->write(OutputStream::Standard, self::line([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Reading the']],
        ]));
        $stream->write(OutputStream::Error, 'warning: a partial line');

        self::assertSame("Reading the\n", $sink->textOf(OutputStream::Standard));
        self::assertSame('warning: a partial line', $sink->textOf(OutputStream::Error));
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
