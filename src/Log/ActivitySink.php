<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Support\Excerpt;

/**
 * What the agent is doing, on the terminal of a run that did not ask for
 * everything.
 *
 * The third answer to PROJECT.md section K, item 2, and the one that was
 * missing. The first two were both complete accounts of the *run*: a verbose
 * terminal is shown the worker's bytes as they arrive ({@see TerminalSink}),
 * and a quiet one is shown the package's own lines plus a half-minute heartbeat
 * that the work is still going ({@see ProgressSink}). What neither offers is
 * the middle thing a person actually wants from a loop working on their
 * checkout: not the agent's thousands of lines and not a tick saying bytes
 * arrived, but what it is doing right now — thinking, running this command,
 * reading that file, and what it concluded.
 *
 * So the quiet run gets the session's *shape* rather than its transcript. One
 * line per tool call, one box per command, the agent's own messages as it
 * writes them, and nothing else:
 *
 * ```
 *   claude sonnet-4-5 · session 1a2b3c4d
 *   ✻ thinking…
 *   I'll start by reading the filter and the request it is declared in.
 *
 *   ⏺ Read(src/app/Filters/OrderFilter.php)
 *     ⎿ 1→<?php declare(strict_types=1); namespace App\Filters; …
 *   ╭─ Bash ──────────────────────────────────────────────────────────────╮
 *   │ make test ARGS='--filter=OrderFilterTest'                           │
 *   ╰─────────────────────────────────────────────────────────────────────╯
 *     ⎿ PASS  Tests\Feature\Api\V1\Orders\OrderFilterTest
 *   ⏹ done · 7 turns · 42.1s · $0.1831
 * ```
 *
 * **The command gets a box because it is the one thing that is not the agent's
 * own business.** Everything else here happens inside a session; a command runs
 * a program in the user's repository, and a person watching a loop work on
 * their project should be able to find it in the scrollback without reading the
 * prose around it. A box is what makes it findable at a glance, which is the
 * whole of the reason — it is not decoration, and nothing else gets one.
 *
 * **Under `--verbose` it says nothing.** The whole feed is already on the
 * screen there ({@see TerminalSink}), rendered line by line by the adapter that
 * knows the format, and a second curated copy of it interleaved with the first
 * would be the same session told twice. The decision is taken per activity
 * rather than by leaving the sink out of the composite, for the reason
 * {@see TerminalSink} gives: the place that knows what is being echoed is the
 * place that decides whether to echo it.
 *
 * **Nothing here is evidence.** These lines are composed for a terminal that is
 * being watched, go to no file, and say nothing the attempt's log does not hold
 * in full. An activity is the worker's own account of itself
 * ({@see Activity}), and what the attempt *was* is read elsewhere.
 */
final class ActivitySink implements OutputSink
{
    /**
     * How wide the inside of a command's box is.
     *
     * Fixed rather than read off the terminal, in the same spirit as
     * {@see Excerpt::WIDTH}: the package has no terminal-width source, this
     * output is composed for a person watching rather than for a layout, and a
     * box that is 72 columns wide sits inside the 80 every terminal still has
     * with room for the two-space indent the run's other lines use.
     */
    private const int BOX_WIDTH = 70;

    /**
     * How many lines of a command may be shown.
     *
     * A command is usually one line and is occasionally a heredoc with a file
     * in it. Past this the box says there was more rather than becoming the
     * flood of output a quiet run is trying not to be; the whole command is in
     * the attempt's log.
     */
    private const int BOX_LINES = 10;

    /**
     * How much of a tool call's subject and a result's text is shown.
     *
     * Narrower than the adapter's own rendering, which has a whole terminal
     * line to itself. These sit under an indent, and what a reader needs off
     * them is which file and whether it worked.
     */
    private const int LINE_WIDTH = 90;

    public function __construct(private readonly Output $output)
    {
    }

    /**
     * A worker's bytes are not this sink's business.
     *
     * They are {@see TerminalSink}'s when a run asked for them and
     * {@see LogFile}'s always. This sink's whole subject is what an adapter was
     * able to say *about* the bytes, which arrives as an activity.
     */
    public function write(OutputStream $stream, string $chunk): void
    {
    }

    /**
     * Nor are the package's own notes.
     *
     * A note is Ralph PHP narrating a step of its own work, and what a quiet
     * run says about its own steps is decided where those lines are composed
     * ({@see \Celyes\RalphPhp\Run\TaskPass}) rather than by echoing the log.
     */
    public function note(string $line): void
    {
    }

    /**
     * Show one thing the agent did.
     */
    public function activity(Activity $activity): void
    {
        if ($this->output->isVerbose()) {
            return;
        }

        $style = $this->output->style();

        match ($activity->kind()) {
            ActivityKind::Session => $this->line($style->muted($activity->text())),
            ActivityKind::Thinking => $this->line($style->muted('✻ thinking…')),
            ActivityKind::Response => $this->said($activity->text()),
            ActivityKind::Command => $this->box($activity->name(), $activity->text(), $style),
            ActivityKind::ToolCall => $this->call($activity, $style),
            ActivityKind::ToolResult => $this->result($activity->text(), $style),
            ActivityKind::Outcome => $this->line($style->muted('⏹ ' . $activity->text())),
            ActivityKind::Problem => $this->line($style->warn('⚠ ' . $activity->text())),
        };

        // A paragraph of the agent's prose, the line that ends a session and
        // the report of something that went wrong are each a thing said rather
        // than a step in something being said, so what follows starts clear of
        // them (PROJECT.md section K, item 3). Owed rather than written, so
        // that an attempt whose last activity this was costs the terminal
        // nothing ({@see Output::separate()}).
        if ($activity->kind()->endsSomething()) {
            $this->output->separate();
        }
    }

    /**
     * Nothing held open.
     *
     * The blank line owed after an attempt's output is asked for once, by
     * {@see TerminalSink::close()}, however many sinks wrote to the terminal.
     */
    public function close(): void
    {
    }

    /**
     * The agent's own words, as a paragraph of the run's output.
     *
     * Indented with the run's other lines and otherwise unchanged: this is the
     * agent talking, and rewrapping what it wrote would make a reader wonder
     * which parts are the package's. Blank lines inside the message are kept,
     * because a message whose paragraphs ran together would be harder to read
     * than one that takes four lines.
     */
    private function said(string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $this->output->line(trim($line) === '' ? '' : '  ' . $line);
        }
    }

    /**
     * A tool call, named with what it is about.
     */
    private function call(Activity $activity, Style $style): void
    {
        $subject = Excerpt::of($activity->text(), self::LINE_WIDTH);

        $this->line(sprintf(
            '%s %s(%s)',
            $style->muted('⏺'),
            $style->identifier($activity->name()),
            $style->muted($subject),
        ));
    }

    /**
     * What a call came back with, under the call it answers.
     */
    private function result(string $text, Style $style): void
    {
        $text = Excerpt::of($text, self::LINE_WIDTH);

        if ($text === '') {
            return;
        }

        $this->output->line('    ' . $style->muted('⎿ ' . $text));
    }

    /**
     * A command, in a box with the tool that is running it on the rim.
     *
     * Drawn rather than quoted because of what a command is here: the one thing
     * in a session that touches the user's own checkout. The box is built from
     * the plain text and coloured afterwards — padding measured on a string
     * with escape sequences in it would be padding measured on bytes nobody
     * can see, and the box would come out ragged on exactly the terminals that
     * can draw it.
     */
    private function box(string $tool, string $command, Style $style): void
    {
        $tool = Excerpt::of($tool === '' ? 'command' : $tool, self::BOX_WIDTH - 4);
        $lines = self::wrap($command);

        $title = '─ ' . $tool . ' ';
        $rule = str_repeat('─', max(0, self::BOX_WIDTH + 2 - self::width($title)));

        $this->line($style->muted('╭' . $title . $rule . '╮'));

        foreach ($lines as $line) {
            $this->line(
                $style->muted('│ ')
                . $line . str_repeat(' ', max(0, self::BOX_WIDTH - self::width($line)))
                . $style->muted(' │'),
            );
        }

        $this->line($style->muted('╰' . str_repeat('─', self::BOX_WIDTH + 2) . '╯'));
    }

    /**
     * A command as the lines of a box: its own lines, each wrapped to fit.
     *
     * Its own first, because a command written as a heredoc means the breaks it
     * came with, and re-flowing them would show the user something other than
     * what is about to run.
     *
     * @return list<string>
     */
    private static function wrap(string $command): array
    {
        $command = trim($command);

        if ($command === '') {
            return [''];
        }

        $lines = [];

        foreach (preg_split('/\r?\n/', $command) ?: [] as $line) {
            $line = rtrim($line);

            // Long words are broken rather than allowed out of the box: a
            // command's long word is a path or a URL, and a box with one line
            // hanging out of it reads as a broken box rather than as a long
            // path.
            foreach (explode("\n", wordwrap($line, self::BOX_WIDTH, "\n", true)) as $wrapped) {
                $lines[] = $wrapped;

                if (count($lines) > self::BOX_LINES) {
                    return [...array_slice($lines, 0, self::BOX_LINES), '…'];
                }
            }
        }

        return $lines;
    }

    /**
     * One line of this sink's own output, under the run's indent.
     */
    private function line(string $text): void
    {
        $this->output->line('  ' . $text);
    }

    /**
     * How wide a piece of plain text is on a terminal.
     *
     * Characters rather than bytes, so that a command with a non-Latin path in
     * it is padded to the width it occupies. The box glyphs are single-width
     * and so is everything this is asked about; a command containing an emoji
     * would be out by a column, which is a rendering nobody is making a
     * decision from.
     */
    private static function width(string $text): int
    {
        return mb_strlen($text);
    }
}
