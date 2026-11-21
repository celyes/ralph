<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Console\Verbosity;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Log\ProgressSink;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a quiet run is told while a worker is working.
 *
 * The counterpart of {@see TerminalSinkTest}: that sink is the whole of what a
 * verbose run repeats from somewhere else, and this one is what a quiet run
 * gets instead of it. The cases below are the two halves of being useful — a
 * beat says something that moves while the work is healthy, and it does not
 * say it so often that it becomes the transcript it replaced.
 *
 * The interval is passed in throughout, because the thing under test is what a
 * beat says and when one is due, and a test that waited the real half-minute
 * to find out would be thirty seconds of nothing.
 */
#[CoversClass(ProgressSink::class)]
final class ProgressSinkTest extends TestCase
{
    private CapturingOutput $terminal;

    protected function setUp(): void
    {
        $this->terminal = new CapturingOutput();
    }

    /**
     * The line itself: how long the attempt has been going, and how much the
     * worker has produced. Both move while the work is healthy, which is the
     * whole of what the line is for.
     */
    #[Test]
    public function a_beat_says_how_long_it_has_been_and_how_much_has_arrived(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, "reading the task\nediting src/Import.php\n");

        self::assertStringContainsString('still working:', $this->terminal->standardOutput());
        self::assertStringContainsString('0s', $this->terminal->standardOutput());
        self::assertStringContainsString('2 lines of output', $this->terminal->standardOutput());
    }

    /**
     * Chunks begin and end wherever the worker happened to be
     * ({@see \Celyes\RalphPhp\Log\OutputSink}), so the count is of what has
     * been seen across all of them rather than of what was in the last one.
     */
    #[Test]
    public function the_count_is_of_everything_the_worker_has_said(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, "one\ntw");
        $sink->write(OutputStream::Standard, "o\nthree\n");

        self::assertStringContainsString('3 lines of output', $this->terminal->standardOutput());
    }

    /**
     * A worker part-way through its first line has produced something, and
     * "0 lines of output" about it would read as a worker that has produced
     * nothing at all.
     */
    #[Test]
    public function a_worker_that_has_not_finished_a_line_is_not_reported_as_silent(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, 'thinking');

        self::assertStringContainsString('part-way through the first line', $this->terminal->standardOutput());
        self::assertStringNotContainsString('0 line', $this->terminal->standardOutput());
    }

    /**
     * Error output is the worker working too: an agent that reports its
     * progress on standard error is working as hard as one that does not.
     */
    #[Test]
    public function output_on_either_stream_is_the_worker_working(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Error, "warning: retrying\n");

        self::assertStringContainsString('1 line of output', $this->terminal->standardOutput());
    }

    /**
     * And the beat is on ordinary output whichever stream provoked it: it is a
     * line the package composed about the run, not a copy of the worker's, and
     * a run whose output is being read by something should not have the loop's
     * own account arriving on two streams.
     */
    #[Test]
    public function a_beat_is_the_packages_own_line_on_its_own_stream(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Error, "warning: retrying\n");

        self::assertSame('', $this->terminal->errorOutput());
    }

    /**
     * The half that keeps this from becoming a second transcript: a chunk that
     * arrives before the next beat is due is counted and not announced.
     */
    #[Test]
    public function chunks_between_beats_are_counted_and_not_printed(): void
    {
        $sink = new ProgressSink($this->terminal->output(), interval: 3600.0);

        $sink->write(OutputStream::Standard, "one\n");
        $sink->write(OutputStream::Standard, "two\n");

        self::assertSame('', $this->terminal->standardOutput());
    }

    /**
     * Which is also true of the first chunk, and deliberately: a task that
     * finishes in two seconds should print the phases it went through and no
     * beats at all.
     */
    #[Test]
    public function a_run_shorter_than_the_interval_beats_not_at_all(): void
    {
        $sink = new ProgressSink($this->terminal->output());

        $sink->write(OutputStream::Standard, "done\n");
        $sink->close();

        self::assertSame('', $this->terminal->standardOutput());
    }

    /**
     * A beat is driven by output arriving, so an empty chunk is not one: a read
     * that returned nothing is not evidence that the worker is working.
     */
    #[Test]
    public function an_empty_chunk_is_not_a_beat(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, '');

        self::assertSame('', $this->terminal->standardOutput());
    }

    /**
     * Under `--verbose` the transcript is already on the screen and is itself
     * the proof that the worker is working; a summary of it would be a line
     * landing in the middle of the agent's own.
     */
    #[Test]
    public function a_verbose_run_is_told_nothing(): void
    {
        $terminal = new CapturingOutput(Verbosity::Verbose);
        $sink = new ProgressSink($terminal->output(), interval: 0.0);

        $sink->write(OutputStream::Standard, "working\n");
        $sink->note('workspace /home/project');
        $sink->close();

        self::assertSame('', $terminal->standardOutput());
        self::assertSame('', $terminal->errorOutput());
    }

    /**
     * Notes are the package narrating its own steps, and what a quiet run says
     * about those is decided where those lines are composed — not here, by
     * echoing the log.
     */
    #[Test]
    public function notes_are_not_beats(): void
    {
        $sink = $this->sink();

        $sink->note('verification: tests passed');

        self::assertSame('', $this->terminal->standardOutput());
    }

    /**
     * Nothing is held open and nothing is owed: the blank line after an
     * attempt's streamed output belongs to {@see \Celyes\RalphPhp\Log\TerminalSink},
     * which asks for it once however many sinks were echoing.
     */
    #[Test]
    public function closing_prints_nothing_and_can_be_done_twice(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, "working\n");
        $before = $this->terminal->standardOutput();

        $sink->close();
        $sink->close();

        self::assertSame($before, $this->terminal->standardOutput());
    }

    /**
     * A sink that beats on every chunk, so that what a beat says is what is
     * being asserted.
     */
    private function sink(): ProgressSink
    {
        return new ProgressSink($this->terminal->output(), interval: 0.0);
    }
}
