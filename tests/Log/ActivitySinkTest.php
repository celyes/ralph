<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Console\Verbosity;
use Celyes\RalphPhp\Log\Activity;
use Celyes\RalphPhp\Log\ActivitySink;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a quiet run is shown of the session itself.
 *
 * The third of the three sinks a terminal is made of
 * ({@see \Celyes\RalphPhp\Log\AttemptLog}), and the one a user watching a loop
 * work on their checkout actually reads. {@see TerminalSinkTest} covers the
 * verbose account and {@see ProgressSinkTest} the heartbeat that stands in for
 * one; what has to be shown here is that a quiet run can follow the work — the
 * thinking, the commands, the agent's own words — without being handed the
 * transcript it asked not to have.
 */
#[CoversClass(ActivitySink::class)]
#[CoversClass(Activity::class)]
final class ActivitySinkTest extends TestCase
{
    private CapturingOutput $terminal;

    protected function setUp(): void
    {
        $this->terminal = new CapturingOutput();
    }

    /**
     * The fact rather than the reasoning: a watcher is asking whether anything
     * is happening, and the words would be the wall of text a quiet run exists
     * to avoid.
     */
    #[Test]
    public function thinking_is_reported_as_the_fact_that_it_is_happening(): void
    {
        $this->sink()->activity(Activity::thinking());

        self::assertStringContainsString('thinking', $this->terminal->standardOutput());
    }

    /**
     * The agent's own message, which is the part a reader is waiting for.
     */
    #[Test]
    public function a_response_is_shown_in_the_agents_own_words(): void
    {
        $this->sink()->activity(Activity::response("I'll read the filter first."));

        self::assertStringContainsString("I'll read the filter first.", $this->terminal->standardOutput());
    }

    /**
     * A message is a thing said rather than a step in something being said, so
     * what follows it starts clear of it (PROJECT.md section K, item 3).
     */
    #[Test]
    public function a_blank_line_follows_each_response(): void
    {
        $sink = $this->sink();

        $sink->activity(Activity::response('Done reading.'));
        $sink->activity(Activity::toolCall('Read', 'src/Import.php'));

        self::assertStringContainsString("Done reading.\n\n", $this->terminal->standardOutput());
    }

    /**
     * Owed rather than written, so an attempt whose last activity was a
     * message does not pay for a blank line nothing follows
     * ({@see \Celyes\RalphPhp\Console\Output::separate()}).
     */
    #[Test]
    public function the_blank_line_after_the_last_response_is_not_written_twice(): void
    {
        $sink = $this->sink();

        $sink->activity(Activity::response('All done.'));
        $this->terminal->output()->finishLine();

        self::assertStringEndsWith("All done.\n\n", $this->terminal->standardOutput());
    }

    /**
     * A message of several lines keeps its own shape: it is the agent talking,
     * and re-flowing it would leave a reader wondering which part is the
     * package's.
     */
    #[Test]
    public function a_response_keeps_the_lines_the_agent_wrote(): void
    {
        $this->sink()->activity(Activity::response("First, the filter.\n\nThen the request."));

        $output = $this->terminal->standardOutput();

        self::assertStringContainsString('First, the filter.', $output);
        self::assertStringContainsString('Then the request.', $output);
        self::assertStringContainsString("First, the filter.\n\n  Then the request.", $output);
    }

    /**
     * The one thing in a session that is not the agent's own business: a
     * command runs a program in the user's repository, and a box is what makes
     * it findable in the scrollback without reading the prose around it.
     */
    #[Test]
    public function a_command_is_drawn_in_a_box(): void
    {
        $this->sink()->activity(Activity::command('Bash', 'make test'));

        $output = $this->terminal->standardOutput();

        self::assertStringContainsString('make test', $output);
        self::assertStringContainsString('╭', $output);
        self::assertStringContainsString('╰', $output);
        self::assertStringContainsString('│', $output);
    }

    /**
     * Named on the rim, so a reader knows what is running the command without
     * a second line to say so.
     */
    #[Test]
    public function the_box_names_the_tool_running_the_command(): void
    {
        $this->sink()->activity(Activity::command('Bash', 'php artisan migrate'));

        self::assertStringContainsString('Bash', $this->terminal->standardOutput());
    }

    /**
     * A box whose lines are different lengths is a broken box. Every line of
     * one is the same width, whatever the command did.
     */
    #[Test]
    public function the_box_is_square(): void
    {
        $this->sink()->activity(Activity::command('Bash', "cd src\nvendor/bin/pint --dirty"));

        $widths = [];

        // Trailing newlines only: trimming the whole output would take the
        // indent off the first line and measure a box this sink never drew.
        foreach (explode("\n", rtrim($this->terminal->standardOutput(), "\n")) as $line) {
            $widths[] = mb_strlen($line);
        }

        self::assertCount(4, $widths, 'a two-line command is a top, two lines and a bottom');
        self::assertCount(1, array_unique($widths), 'every line of a box is the same width');
    }

    /**
     * A command longer than the box is wrapped into it rather than hanging out
     * of it: a long word in a command is a path or a URL, and a box with one
     * line sticking out reads as a broken box rather than as a long path.
     */
    #[Test]
    public function a_long_command_is_wrapped_inside_the_box(): void
    {
        $this->sink()->activity(Activity::command('Bash', str_repeat('a', 200)));

        $lines = explode("\n", rtrim($this->terminal->standardOutput(), "\n"));
        $widths = array_map(static fn (string $line): int => mb_strlen($line), $lines);

        self::assertGreaterThan(3, count($lines), 'a 200-character command does not fit on one line');
        self::assertCount(1, array_unique($widths), 'every line of a box is the same width');
    }

    /**
     * A tool call is one line, named with what it is about: which file is what
     * a reader needs off it.
     */
    #[Test]
    public function a_tool_call_is_one_line_naming_its_subject(): void
    {
        $this->sink()->activity(Activity::toolCall('Read', 'src/app/Filters/OrderFilter.php'));

        $output = $this->terminal->standardOutput();

        self::assertStringContainsString('Read', $output);
        self::assertStringContainsString('src/app/Filters/OrderFilter.php', $output);
        self::assertSame(1, substr_count(trim($output), "\n") + 1);
    }

    /**
     * And a result is one line under it, which is what says whether the call
     * worked.
     */
    #[Test]
    public function a_result_is_shown_under_the_call_it_answers(): void
    {
        $sink = $this->sink();

        $sink->activity(Activity::toolCall('Read', 'src/Import.php'));
        $sink->activity(Activity::toolResult('1→<?php declare(strict_types=1);'));

        self::assertStringContainsString('1→<?php', $this->terminal->standardOutput());
    }

    /**
     * How the session ended, and something that went wrong in it: both are
     * worth a line, and neither decides anything about the attempt.
     */
    #[Test]
    public function the_outcome_and_the_problems_are_shown(): void
    {
        $sink = $this->sink();

        $sink->activity(Activity::outcome('done · 7 turns · 42.1s'));
        $sink->activity(Activity::problem('api error: overloaded · retry 1/3'));

        $output = $this->terminal->standardOutput();

        self::assertStringContainsString('done · 7 turns · 42.1s', $output);
        self::assertStringContainsString('api error: overloaded', $output);
    }

    /**
     * The whole feed is already on a verbose terminal, rendered by the adapter
     * that knows the format, so a curated second telling of it would be the
     * session twice over.
     */
    #[Test]
    public function a_verbose_run_is_told_none_of_it(): void
    {
        $terminal = new CapturingOutput(Verbosity::Verbose);
        $sink = new ActivitySink($terminal->output());

        $sink->activity(Activity::thinking());
        $sink->activity(Activity::response('Reading the filter.'));
        $sink->activity(Activity::command('Bash', 'make test'));

        self::assertSame('', $terminal->standardOutput());
    }

    /**
     * A worker's bytes are not this sink's business — they are
     * {@see \Celyes\RalphPhp\Log\TerminalSink}'s when a run asked for them and
     * the log file's always — and neither are the package's own notes.
     */
    #[Test]
    public function bytes_and_notes_are_left_to_the_sinks_they_belong_to(): void
    {
        $sink = $this->sink();

        $sink->write(OutputStream::Standard, "{\"type\":\"assistant\"}\n");
        $sink->write(OutputStream::Error, "warning: retrying\n");
        $sink->note('workspace /home/user/project');
        $sink->close();

        self::assertSame('', $this->terminal->standardOutput());
        self::assertSame('', $this->terminal->errorOutput());
    }

    private function sink(): ActivitySink
    {
        return new ActivitySink($this->terminal->output());
    }
}
