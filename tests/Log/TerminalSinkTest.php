<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Console\ColourChoice;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Log\LogFile;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Log\TerminalSink;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Showing a worker's output as it is produced.
 */
#[CoversClass(TerminalSink::class)]
final class TerminalSinkTest extends TestCase
{
    private CapturingOutput $terminal;

    private TerminalSink $sink;

    protected function setUp(): void
    {
        $this->terminal = new CapturingOutput();
        $this->sink = new TerminalSink($this->terminal->output());
    }

    /**
     * On a terminal this should look like the agent was run by hand: nothing
     * prefixed, nothing held back, and no line endings invented.
     */
    #[Test]
    public function the_workers_bytes_are_passed_through_unchanged(): void
    {
        $this->sink->write(OutputStream::Standard, 'progress: ');
        $this->sink->write(OutputStream::Standard, "40%\n");

        self::assertSame("progress: 40%\n", $this->terminal->standardOutput());
    }

    /**
     * So that redirecting a run's output separates the two exactly as running
     * the agent directly would.
     */
    #[Test]
    public function each_stream_keeps_its_destination(): void
    {
        $this->sink->write(OutputStream::Standard, 'ordinary');
        $this->sink->write(OutputStream::Error, 'a complaint');

        self::assertSame('ordinary', $this->terminal->standardOutput());
        self::assertSame('a complaint', $this->terminal->errorOutput());
    }

    #[Test]
    public function a_note_is_marked_the_way_the_log_marks_it(): void
    {
        $this->sink->note('scripted exited with status 0 after 1.2s');

        self::assertSame(
            LogFile::NOTE_PREFIX . 'scripted exited with status 0 after 1.2s' . PHP_EOL,
            $this->terminal->standardOutput(),
        );
    }

    /**
     * On a terminal, a note's prefix is dimmed and its subject is picked out,
     * so a run's own lines can be followed through the agent's output. The
     * words are untouched, which is what keeps the log and the terminal the
     * same account of the run.
     */
    #[Test]
    public function a_note_on_a_terminal_has_its_prefix_and_its_subject_marked(): void
    {
        $this->terminal->output()->useColour(ColourChoice::Always);
        $style = Style::coloured();

        $this->sink->note('commit: b4e910596012 recorded against TASK-8CA4A94A');

        self::assertSame(
            $style->muted(LogFile::NOTE_PREFIX)
                . $style->identifier('commit:')
                . ' b4e910596012 recorded against TASK-8CA4A94A'
                . PHP_EOL,
            $this->terminal->standardOutput(),
        );
    }

    /**
     * A note's indented continuation is detail under the note above it, and is
     * dimmed whole rather than having a subject picked out of it.
     */
    #[Test]
    public function an_indented_note_is_detail_and_reads_as_detail(): void
    {
        $this->terminal->output()->useColour(ColourChoice::Always);
        $style = Style::coloured();

        $this->sink->note('  check: tests passed in 0.0s');

        self::assertSame(
            $style->muted(LogFile::NOTE_PREFIX) . $style->muted('  check: tests passed in 0.0s') . PHP_EOL,
            $this->terminal->standardOutput(),
        );
    }

    /**
     * A note that opens with a whole clause is a sentence, not a subject, and
     * marking part of it up would pick out half of a line that reads as one
     * thing.
     */
    #[Test]
    public function a_note_that_is_a_sentence_is_left_as_one(): void
    {
        $this->terminal->output()->useColour(ColourChoice::Always);
        $style = Style::coloured();

        $note = 'fixture reported a candidate implementation: it compiles.';
        $this->sink->note($note);

        self::assertSame(
            $style->muted(LogFile::NOTE_PREFIX) . $note . PHP_EOL,
            $this->terminal->standardOutput(),
        );
    }

    /**
     * Otherwise the package's own note would be glued onto the end of the
     * agent's last, unfinished line.
     */
    #[Test]
    public function a_note_after_an_unfinished_line_starts_on_its_own(): void
    {
        $this->sink->write(OutputStream::Standard, 'thinking...');
        $this->sink->note('scripted was stopped after 60 seconds');

        self::assertSame(
            'thinking...' . PHP_EOL . LogFile::NOTE_PREFIX . 'scripted was stopped after 60 seconds' . PHP_EOL,
            $this->terminal->standardOutput(),
        );
    }

    #[Test]
    public function a_worker_that_left_a_line_unfinished_has_it_finished_on_the_way_out(): void
    {
        $this->sink->write(OutputStream::Standard, 'no trailing newline');
        $this->sink->close();

        self::assertSame('no trailing newline' . PHP_EOL, $this->terminal->standardOutput());
    }

    #[Test]
    public function a_worker_that_finished_its_line_gets_no_extra_one(): void
    {
        $this->sink->write(OutputStream::Standard, "done\n");
        $this->sink->close();
        $this->sink->close();

        self::assertSame("done\n", $this->terminal->standardOutput());
    }

    #[Test]
    public function an_empty_chunk_changes_nothing(): void
    {
        $this->sink->write(OutputStream::Standard, "done\n");
        $this->sink->write(OutputStream::Standard, '');
        $this->sink->close();

        self::assertSame("done\n", $this->terminal->standardOutput());
    }
}
