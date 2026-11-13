<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\StopKind;

/**
 * The colours and weights this package writes its own text in.
 *
 * A run can take an hour and print a thousand lines of somebody else's output
 * in the middle of it, so the few lines that are Ralph PHP's own have to be
 * findable at a glance: which task is being worked on, whether a check passed,
 * which words are a label and which are the answer. That is all this does —
 * nine named intentions over the ordinary SGR codes, and one switch that turns
 * every one of them into the identity function.
 *
 * **Plain is the same text, byte for byte.** When colour is off every method
 * returns its argument unchanged, which is what makes it safe to style at the
 * point a line is composed rather than to keep two versions of every report:
 * a redirected `ralph status`, a `--json` document, the lines the test suite
 * reads back and the lines on a terminal are one implementation
 * ({@see ColourChoice}, {@see Output::style()}).
 *
 * **Style the whole field, and do not nest.** Every code here is closed by a
 * full reset, so a styled string inside another styled string ends the outer
 * one early — `label(identifier('T-1') . ' ran')` leaves ` ran` unstyled. The
 * call sites therefore style leaves: a field, a word, a padded column.
 *
 * **Pad before styling.** A styled string is longer than it looks — nine or ten
 * bytes longer — so `sprintf('%-11s', $style->pass('ok'))` pads to eleven
 * *bytes* and produces a column that is nine characters narrow. Everything
 * aligned in this package pads the visible text and styles the result
 * ({@see self::column()}).
 */
final class Style
{
    /**
     * Ends every sequence this class opens.
     */
    private const string RESET = "\033[0m";

    private const string BOLD = '1';
    private const string DIM = '2';
    private const string RED = '31';
    private const string GREEN = '32';
    private const string YELLOW = '33';
    private const string CYAN = '36';

    private function __construct(private readonly bool $enabled)
    {
    }

    /**
     * Styling that changes nothing.
     */
    public static function plain(): self
    {
        return new self(false);
    }

    /**
     * Styling that colours.
     */
    public static function coloured(): self
    {
        return new self(true);
    }

    /**
     * Styling for a stream, under the given choice.
     *
     * @param resource|mixed $stream
     */
    public static function on(mixed $stream, ColourChoice $choice = ColourChoice::Auto): self
    {
        return new self($choice->appliesTo($stream));
    }

    /**
     * Whether this styling colours anything.
     *
     * For the one decision that is not "wrap this text": whether to use a
     * character a plain terminal may not have a glyph for.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * A heading: the line that says what the block under it is.
     */
    public function heading(string $text): string
    {
        return $this->wrap(self::BOLD, $text);
    }

    /**
     * A field name, in the two-column reports and above a table's columns.
     *
     * Dimmed rather than coloured, which is the point of it: the label is the
     * part of the line the reader already knows, and taking weight *off* it is
     * what leaves the value as the thing their eye lands on.
     */
    public function label(string $text): string
    {
        return $this->wrap(self::DIM, $text);
    }

    /**
     * Secondary text: a note under a row, an aside, something already said.
     *
     * The same dimming as {@see self::label()} and deliberately a second name
     * for it. They are one decision today and two different intentions, and a
     * later change to how labels read should not have to find out which of the
     * dimmed strings in this package were labels.
     */
    public function muted(string $text): string
    {
        return $this->wrap(self::DIM, $text);
    }

    /**
     * Something nameable: a task ID, a run ID, a branch, a commit, a path.
     *
     * The strings a user copies out of the output and types into the next
     * command.
     */
    public function identifier(string $text): string
    {
        return $this->wrap(self::CYAN, $text);
    }

    /**
     * A good outcome: a check that passed, a task that completed.
     */
    public function pass(string $text): string
    {
        return $this->wrap(self::GREEN, $text);
    }

    /**
     * Something that needs a person, but has not failed: a warning, a block, an
     * interruption, a run that stopped short of its work.
     */
    public function warn(string $text): string
    {
        return $this->wrap(self::YELLOW, $text);
    }

    /**
     * A bad outcome: a check that failed, a task given up on.
     */
    public function fail(string $text): string
    {
        return $this->wrap(self::RED, $text);
    }

    /**
     * The one line that says the command could not do what it was asked.
     *
     * Heavier than {@see self::fail()} because it is competing with whatever
     * scrolled past above it, which in a failed run is a great deal.
     */
    public function alert(string $text): string
    {
        return $this->wrap(self::BOLD . ';' . self::RED, $text);
    }

    /**
     * Text padded to a column width, then styled.
     *
     * The order matters and is the reason this exists rather than each call
     * site reaching for `str_pad`: padding a string that already carries
     * escape sequences counts them, and a table whose first column is coloured
     * would have every later column out of line by the length of the sequence.
     *
     * @param callable(string): string $as one of this class's styles
     */
    public function column(string $text, int $width, callable $as): string
    {
        return $as(str_pad($text, max($width, 0)));
    }

    /**
     * How a task or a run in this state should read.
     *
     * The mapping lives with the palette rather than at the two places that
     * need it — the table `ralph status` prints and the line a run writes when
     * a task ends — because those two are answering the same question about the
     * same column, and a `failed` that is red in one and yellow in the other
     * would be read as a difference in the outcome.
     */
    public function state(LifecycleState $state, string $text): string
    {
        return match ($state) {
            LifecycleState::Completed => $this->pass($text),
            LifecycleState::Failed => $this->fail($text),
            LifecycleState::Blocked, LifecycleState::Interrupted => $this->warn($text),
            LifecycleState::Running => $this->identifier($text),
            LifecycleState::Pending, LifecycleState::Skipped => $this->muted($text),
        };
    }

    /**
     * How a run that ended this way should read.
     *
     * The companion to {@see self::state()}, and here for the same reason: the
     * line `ralph run` prints when it stops and the heading `ralph status`
     * prints afterwards are the same sentence about the same run.
     *
     * The grouping is the one the exit statuses already make
     * ({@see ExitCode::forStop()}) — finished is good, a decision, a spent
     * budget and a stop from outside all want a person, and attempts and
     * failure are work that did not succeed — so the colour of a line and the
     * status of the process that printed it cannot disagree.
     */
    public function stop(StopKind $kind, string $text): string
    {
        return match ($kind) {
            StopKind::Finished => $this->pass($text),
            StopKind::Decision, StopKind::Limit, StopKind::Interrupted => $this->warn($text),
            StopKind::Attempts, StopKind::Failed => $this->fail($text),
        };
    }

    /**
     * Help text, with its headings and the names it documents picked out.
     *
     * Help is written as plain lines by the command that owns it — it is
     * documentation, and reads as documentation in the source — so this styles
     * it afterwards, by shape: a line that is nothing but a heading and a
     * colon, the `Usage:` that opens one, and the first word of an indented
     * two-column row, which in this package's help is always a command name, an
     * option or the name of a configured thing.
     *
     * Deliberately a presentation pass over the package's own text and nothing
     * else. It never sees a task description, a worker's output or anything
     * else a user or a model wrote, so a line that happens to look like a
     * heading is at worst a line printed in bold.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public function helpText(array $lines): array
    {
        if (!$this->enabled) {
            return $lines;
        }

        return array_map(
            function (string $line): string {
                // A heading and nothing else: at most three words, which is
                // every heading this package's help has ("Options:", "Global
                // options:", "Exit codes:"). Bounded, so that a sentence of
                // prose that happens to introduce a block with a colon is not
                // mistaken for one.
                if (preg_match('/^[A-Z][A-Za-z0-9]*(?: [A-Za-z0-9]+){0,2}:$/', $line) === 1) {
                    return $this->heading($line);
                }

                if (preg_match('/^(Usage:)( .*)$/', $line, $matches) === 1) {
                    return $this->heading($matches[1]) . $matches[2];
                }

                // An indented row whose first column is a name and whose second
                // is prose about it: the command list, the option lists, the
                // exit statuses, and the check names in "ralph help preflight".
                // Two spaces between the columns is what distinguishes such a
                // row from an indented sentence that happens to start with a
                // word.
                $row = '/^( {2,6})('
                    . '(?:-{1,2}[\w-]+(?:, --[\w-]+)?|[a-z][\w-]*|\d{1,3})'
                    // An option or a command may be followed by the placeholder
                    // for its argument: "--agent NAME", "logs TASK-ID".
                    . '(?: [A-Z][A-Z-]*)?'
                    . ')( {2,}\S.*)$/';

                if (preg_match($row, $line, $matches) === 1) {
                    return $matches[1] . $this->identifier($matches[2]) . $matches[3];
                }

                return $line;
            },
            $lines,
        );
    }

    /**
     * Wrap text in a sequence, when styling is on and there is text to wrap.
     *
     * An empty string is left alone: a sequence around nothing is four bytes of
     * output that cannot be seen and can still end up in a file somebody is
     * diffing.
     */
    private function wrap(string $codes, string $text): string
    {
        if (!$this->enabled || $text === '') {
            return $text;
        }

        return "\033[" . $codes . 'm' . $text . self::RESET;
    }
}
