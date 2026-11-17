<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Console\ColourChoice;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Support\Secrets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;

/**
 * The line the CLI's output draws between its own text and a worker's bytes.
 *
 * PROJECT.md section I, item 6 is a decision about *which* output is the
 * package's to rewrite, and this is where that decision lives: a line the
 * package composed is redacted, and a chunk passed through from another process
 * is not. Both halves are asserted here, because getting either one wrong is a
 * defect — a terminal that leaked a key, or a terminal that no longer shows
 * what the agent actually printed.
 */
#[CoversClass(Output::class)]
final class OutputTest extends TestCase
{
    private const string SECRET = 'sk-ralph-0123456789-never-printed';

    #[Test]
    public function nothing_is_redacted_until_a_command_says_what_the_secrets_are(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->line('ran with ' . self::SECRET);

        self::assertTrue($capture->output()->secrets()->isEmpty());
        self::assertSame('ran with ' . self::SECRET . PHP_EOL, $capture->standardOutput());
    }

    #[Test]
    public function the_lines_the_package_composes_are_redacted_on_both_streams(): void
    {
        $capture = new CapturingOutput();
        $capture->output()->redact($this->secrets());

        $capture->output()->line('the agent said ' . self::SECRET);
        $capture->output()->lines(['first', 'and then ' . self::SECRET]);
        $capture->output()->diagnosticLine('ralph: the agent printed ' . self::SECRET);

        self::assertSame(
            'the agent said [redacted TEST_KEY]' . PHP_EOL
                . 'first' . PHP_EOL
                . 'and then [redacted TEST_KEY]' . PHP_EOL,
            $capture->standardOutput(),
        );
        self::assertSame(
            'ralph: the agent printed [redacted TEST_KEY]' . PHP_EOL,
            $capture->errorOutput(),
        );
    }

    /**
     * The passthrough, and the reason it exists: a chunk off a pipe begins and
     * ends wherever the worker happened to be, so a replacement here would
     * catch some occurrences and not others while making the terminal disagree
     * with the attempt's log — which is the copy a reader is sent to.
     */
    #[Test]
    public function a_workers_own_bytes_are_passed_through_unchanged(): void
    {
        $capture = new CapturingOutput();
        $capture->output()->redact($this->secrets());

        $capture->output()->text('authenticating with ' . self::SECRET);
        $capture->output()->diagnosticText('error: bad key ' . self::SECRET);

        self::assertSame('authenticating with ' . self::SECRET, $capture->standardOutput());
        self::assertSame('error: bad key ' . self::SECRET, $capture->errorOutput());
    }

    /**
     * Learning a second set of secrets must never un-learn the first: the one
     * output object is handed around a whole command, and output that was
     * redacted a moment ago cannot stop being redacted.
     */
    #[Test]
    public function telling_the_output_more_secrets_keeps_the_ones_it_had(): void
    {
        $capture = new CapturingOutput();
        $capture->output()->redact($this->secrets());
        $capture->output()->redact(Secrets::none());

        $capture->output()->line('still ' . self::SECRET);

        self::assertSame(['TEST_KEY'], $capture->output()->secrets()->names());
        self::assertSame('still [redacted TEST_KEY]' . PHP_EOL, $capture->standardOutput());
    }

    /**
     * An output constructed with its secrets, for a caller that knows them
     * before it opens the streams.
     */
    #[Test]
    public function an_output_can_be_built_already_redacting(): void
    {
        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);

        $output = new Output($stream, $stream, $this->secrets());
        $output->line('key ' . self::SECRET);

        rewind($stream);
        self::assertSame('key [redacted TEST_KEY]' . PHP_EOL, (string) stream_get_contents($stream));
    }

    /**
     * PROJECT.md section K, item 13: a line this package writes ends a line,
     * and does not end it twice.
     *
     * The second half is what makes the guarantee safe to apply everywhere.
     * Callers print strings they composed, strings they read out of a file and
     * strings another layer handed back, and a rule that blindly appended
     * would put a blank line after every one of the latter.
     */
    #[Test]
    public function a_line_is_ended_once_whether_or_not_it_arrived_ended(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->line('composed here');
        $capture->output()->line("read from somewhere else\n");
        $capture->output()->line("and from a file written on Windows\r\n");
        $capture->output()->diagnosticLine("ralph: that did not work\n");

        self::assertSame(
            'composed here' . PHP_EOL
                . 'read from somewhere else' . PHP_EOL
                . 'and from a file written on Windows' . PHP_EOL,
            $capture->standardOutput(),
        );
        self::assertSame('ralph: that did not work' . PHP_EOL, $capture->errorOutput());
    }

    /**
     * A caller that composed its own blank line keeps it.
     *
     * Only one ending is taken off before one is put on, so "a paragraph\n\n"
     * is a paragraph and the blank line the caller asked for — the normalising
     * is of the ending, not of the caller's spacing.
     */
    #[Test]
    public function a_blank_line_the_caller_composed_survives(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->line("a paragraph\n\n");
        $capture->output()->line('the next one');

        self::assertSame(
            'a paragraph' . PHP_EOL . PHP_EOL . 'the next one' . PHP_EOL,
            $capture->standardOutput(),
        );
    }

    /**
     * An empty call is a blank line, and nothing else is added around it.
     */
    #[Test]
    public function an_empty_line_is_a_single_blank_one(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->line('above');
        $capture->output()->line();
        $capture->output()->line('below');

        self::assertSame(
            'above' . PHP_EOL . PHP_EOL . 'below' . PHP_EOL,
            $capture->standardOutput(),
        );
    }

    /**
     * The case the passthrough creates: a worker's chunk ends mid-line, and
     * the next thing the package prints must not be glued onto the end of it.
     *
     * The chunk itself is still written the moment it arrives — holding it
     * back until its newline turned up is what would stop a progress
     * indicator from ever appearing — so the newline it is owed is written
     * when something else needs the line.
     */
    #[Test]
    public function a_package_line_after_an_unfinished_chunk_starts_on_its_own(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->text('thinking...');
        $capture->output()->line('ralph: the agent was stopped after 60 seconds');

        self::assertSame(
            'thinking...' . PHP_EOL . 'ralph: the agent was stopped after 60 seconds' . PHP_EOL,
            $capture->standardOutput(),
        );
    }

    /**
     * And the duplicate that rule could produce: closing an unfinished line is
     * a newline, a deliberate blank line is a newline, and asking for the
     * blank line while one is open must not write both.
     */
    #[Test]
    public function closing_an_unfinished_line_is_not_also_a_blank_line(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->text('no trailing newline');
        $capture->output()->line();
        $capture->output()->line('after');

        self::assertSame(
            'no trailing newline' . PHP_EOL . 'after' . PHP_EOL,
            $capture->standardOutput(),
        );
    }

    /**
     * A chunk that ended its own line leaves nothing owed.
     */
    #[Test]
    public function a_chunk_that_ended_its_line_is_not_given_a_second_ending(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->text("done\n");
        $capture->output()->line('ralph: the agent exited with status 0');

        self::assertSame(
            "done\n" . 'ralph: the agent exited with status 0' . PHP_EOL,
            $capture->standardOutput(),
        );
    }

    /**
     * The last write of a command, so the shell prompt does not land halfway
     * along a line a worker or a printed log file left open.
     *
     * Idempotent, and a no-op in the ordinary case: it is called at the end of
     * every run, and most runs end with a line that ended itself.
     */
    #[Test]
    public function an_unfinished_line_is_closed_on_the_way_out(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->text('a log file that ends without one');
        $capture->output()->diagnosticText('and a complaint that does too');

        $capture->output()->finishLine();
        $capture->output()->finishLine();

        self::assertSame('a log file that ends without one' . PHP_EOL, $capture->standardOutput());
        self::assertSame('and a complaint that does too' . PHP_EOL, $capture->errorOutput());
    }

    #[Test]
    public function finishing_adds_nothing_when_every_line_ended_itself(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->line('a complete line');
        $capture->output()->finishLine();

        self::assertSame('a complete line' . PHP_EOL, $capture->standardOutput());
        self::assertSame('', $capture->errorOutput());
    }

    /**
     * Streams redirected apart have a cursor each.
     *
     * `ralph run > out.log 2> err.log` is two files, and a newline owed by one
     * of them must not be written into the other — which would both corrupt
     * the file that received it and leave the line that was open still open.
     */
    #[Test]
    public function a_line_left_open_on_one_stream_is_not_closed_on_the_other(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->diagnosticText('a worker complaint with no newline');
        $capture->output()->line('ralph: carrying on');

        self::assertSame('ralph: carrying on' . PHP_EOL, $capture->standardOutput());
        self::assertSame('a worker complaint with no newline', $capture->errorOutput());

        $capture->output()->finishLine();

        self::assertSame('a worker complaint with no newline' . PHP_EOL, $capture->errorOutput());
    }

    /**
     * Streams that reach the same place share one cursor.
     *
     * The common case on a terminal, where the worker's standard error and the
     * package's own notes are on the same screen: a half-written progress line
     * from one is still under the cursor when the other prints, and the note
     * has to start a line. Asserted through a single handle given as both
     * streams, which is also `ralph run 2>&1`.
     */
    #[Test]
    public function one_destination_given_as_both_streams_keeps_one_cursor(): void
    {
        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);

        $output = new Output($stream, $stream);

        $output->diagnosticText('authenticating...');
        $output->line('ralph: the agent is working');

        rewind($stream);
        self::assertSame(
            'authenticating...' . PHP_EOL . 'ralph: the agent is working' . PHP_EOL,
            (string) stream_get_contents($stream),
        );
    }

    /**
     * The same split as the redaction above, in the other direction: the
     * package's own lines may be coloured, and a worker's bytes go through as
     * they came.
     */
    #[Test]
    public function colour_is_decided_per_stream_rather_than_per_process(): void
    {
        $capture = new CapturingOutput();

        // What an in-memory stream answers, which is what the whole of this
        // suite depends on: a driven command produces plain text.
        self::assertFalse($capture->output()->style()->isEnabled());
        self::assertFalse($capture->output()->diagnosticStyle()->isEnabled());

        $capture->output()->useColour(ColourChoice::Always);

        self::assertTrue($capture->output()->style()->isEnabled());
        self::assertTrue($capture->output()->diagnosticStyle()->isEnabled());
    }

    /**
     * `--no-color` arrives after the streams exist, so the decision has to
     * reach the output already in use rather than produce a second one.
     */
    #[Test]
    public function a_later_choice_replaces_the_one_the_streams_would_have_made(): void
    {
        $capture = new CapturingOutput();

        $capture->output()->useColour(ColourChoice::Always);
        self::assertTrue($capture->output()->style()->isEnabled());

        $capture->output()->useColour(ColourChoice::Never);
        self::assertFalse($capture->output()->style()->isEnabled());
    }

    /**
     * Styling is applied around a field and redaction replaces a value inside
     * one, so the two must not interfere: a coloured line still has the key
     * taken out of it.
     */
    #[Test]
    public function a_styled_line_is_still_redacted(): void
    {
        $capture = new CapturingOutput();
        $capture->output()->redact($this->secrets());
        $capture->output()->useColour(ColourChoice::Always);

        $style = $capture->output()->style();
        $capture->output()->line($style->warn('the agent was given ' . self::SECRET));

        $written = $capture->standardOutput();

        self::assertStringNotContainsString(self::SECRET, $written);
        self::assertStringContainsString('[redacted TEST_KEY]', $written);
        self::assertStringContainsString("[", $written);
    }

    /**
     * A consumer that walks away does not take the command with it.
     *
     * `ralph run | head`: once `head` has its lines the pipe's read end is
     * gone, and every write after that fails with `errno=32 Broken pipe` — a
     * warning, which the application turns into an exception, which used to end
     * a run that was doing nothing wrong. The terminal is a copy of the work,
     * so the write is dropped and the command carries on.
     *
     * The second write asserts the other half: the stream is remembered as
     * gone rather than retried, so a long run does not raise once per line for
     * the rest of its life. `error_get_last()` is the evidence, since a failed
     * `fwrite()` sets it and a write that never happened cannot.
     */
    #[Test]
    public function writing_to_a_stream_whose_reader_has_gone_is_dropped_rather_than_raised(): void
    {
        $stream = $this->streamWithNoReader();
        $output = new Output($stream, $stream);

        $output->line('the first line nobody reads');

        self::assertNotNull(error_get_last(), 'The write was expected to fail, and quietly.');

        error_clear_last();
        $output->line('and the second');
        $output->text('and a chunk of the agent output');

        self::assertNull(error_get_last(), 'A stream known to be gone was written to again.');
    }

    /**
     * Losing one stream does not silence the other.
     *
     * `ralph run > /dev/full`, or a pipeline whose reader quit: the failure
     * belongs to the stream it happened on, and an error still has somewhere to
     * go.
     */
    #[Test]
    public function losing_one_stream_leaves_the_other_one_writing(): void
    {
        $diagnostic = fopen('php://memory', 'rb+');
        self::assertIsResource($diagnostic);

        $output = new Output($this->streamWithNoReader(), $diagnostic);

        $output->line('ordinary output nobody will see');
        $output->diagnosticLine('ralph: the run could not carry on');

        rewind($diagnostic);
        self::assertSame(
            'ralph: the run could not carry on' . PHP_EOL,
            (string) stream_get_contents($diagnostic),
        );
    }

    /**
     * A stream that fails every write, the way a pipe does once its reader is
     * gone.
     *
     * A socket pair gives both ends to this process, so closing the read end
     * here is the whole event — no child process, no race with a buffer, and
     * the same `errno=32` the CLI meets in a pipeline.
     *
     * @return resource
     */
    private function streamWithNoReader(): mixed
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$reader, $writer] = $pair;
        fclose($reader);

        return $writer;
    }

    private function secrets(): Secrets
    {
        return AgentEnvironment::of(
            new AgentDefinition('fixture', 'codex', 'codex', environment: ['TEST_KEY']),
            static fn (string $name): string|false => $name === 'TEST_KEY' ? self::SECRET : false,
        )->secrets();
    }
}
