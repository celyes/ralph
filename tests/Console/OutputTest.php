<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\AgentDefinition;
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
