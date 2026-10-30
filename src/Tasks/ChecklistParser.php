<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;

/**
 * Reads top-level checklist items out of Markdown.
 *
 * A task is a line that starts in column one with a bullet, a checkbox and a
 * description: `- [ ] Add a --dry-run option`, or `- [x]` / `- [X]` once it is
 * done. The three CommonMark bullet characters are accepted — `-`, `*` and `+`
 * are the same construct and a user who writes one of the other two means a
 * task — while an ordered list is not, since it is a different construct that
 * neither this project's documentation nor its generated checklist suggests.
 *
 * Everything else in the file is prose. Headings, paragraphs, tables and plain
 * bullets are not items, and a line inside a fenced code block is never an item
 * however it is written, which is what lets the checklist `ralph init` writes
 * carry a worked example of the syntax without that example becoming a task.
 *
 * Deliberately line-based rather than a Markdown parser. The file is a document
 * a human edits and the runner later rewrites one marker of, so what matters is
 * that the same line the user sees as a task is the line that is read and
 * written back, not that every CommonMark subtlety is honoured.
 *
 * What is indented under an item belongs to it. The bullets and paragraphs
 * written below a task, indented by any amount, are collected as that task's
 * details — the implementation notes or acceptance criteria the agent is given
 * along with the description — and they end at the first line that starts in
 * column one. A blank line does not end them, so an item can carry several
 * paragraphs, and a fenced block opened inside them runs to its own closing
 * fence, so an example written in a task's details can itself contain
 * checklist syntax.
 *
 * Two kinds of line are refused rather than ignored, because both are work a
 * user believes they have written down: a checkbox with nothing after it, and
 * an indented checkbox, which this release does not run as a subtask. Silently
 * skipping either would leave the checklist looking fuller than the runner's
 * view of it, so each stops the read with the line it was found on and what to
 * write instead. Prose that merely resembles a task is still prose — the line
 * has to be a bullet and a well-formed checkbox before it is anyone's mistake.
 */
final class ChecklistParser
{
    /**
     * A top-level checklist item.
     *
     * Anchored at column one: any leading whitespace makes a line indented, and
     * an indented line belongs to the item above it rather than being one.
     * Whitespace is required after both the bullet and the checkbox, so neither
     * `-[ ] x` nor `- [ ]x` is a task, and the description has to start with a
     * non-space character, so a bare `- [ ]` is not one either — it is the
     * empty description {@see self::EMPTY_ITEM} refuses.
     */
    private const string ITEM = '/^([-*+])[ \t]+\[([ xX])\][ \t]+(\S.*)$/u';

    /**
     * A top-level checkbox with no description after it.
     *
     * Disjoint from {@see self::ITEM}, which requires a non-space character
     * after the checkbox, so a line is at most one of the two.
     */
    private const string EMPTY_ITEM = '/^([-*+])[ \t]+\[([ xX])\][ \t]*$/';

    /**
     * An indented checkbox: a nested checkable subtask.
     *
     * Only the marker is matched, since every indented checkbox is refused
     * whether or not it has a description. The lookahead keeps the same
     * requirement as {@see self::ITEM} that something separate the checkbox
     * from what follows, so an indented `- [ ]x` is ordinary detail text.
     */
    private const string NESTED_ITEM = '/^[ \t]+([-*+])[ \t]+\[([ xX])\](?=[ \t]|$)/';

    /**
     * The opening line of a fenced code block in column one.
     *
     * Up to three leading spaces are allowed, as CommonMark does, because a
     * fence indented that far still opens a code block.
     */
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})(.*)$/';

    /**
     * The opening line of a fenced code block inside an item's details.
     *
     * Any indentation at all, because details are indented to begin with and a
     * fenced example inside them is indented at least as far as the prose
     * around it.
     */
    private const string DETAIL_FENCE = '/^[ \t]+(`{3,}|~{3,})(.*)$/';

    /**
     * Parse checklist items out of Markdown.
     *
     * @param string $markdown the document's contents
     * @param string|null $source path to report in errors and keep with the result
     *
     * @throws ChecklistException when the contents are not valid UTF-8, a
     *                            checkbox has no description, or a checkbox is
     *                            indented below another line
     */
    public function parse(string $markdown, ?string $source = null): Checklist
    {
        $this->requireUtf8($markdown, $source);

        $items = [];
        $openFence = null;

        /**
         * The item being read, held back until its details are known.
         *
         * @var array{description: string, checked: bool, line: int, source: string}|null $open
         */
        $open = null;

        /** @var list<string> $details */
        $details = [];

        foreach ($this->lines($markdown) as $index => $line) {
            if ($openFence !== null) {
                // A fence is only ever open while an item is still being read
                // when that fence was opened inside the item's details, since a
                // fence in column one ends the item above it before it opens.
                // So everything up to the closing fence belongs to the item,
                // whatever its indentation: the contents of a fenced example
                // are neither checklist syntax nor a mistake, even when written
                // as a task in column one.
                if ($open !== null) {
                    $details[] = self::blankToEmpty($line);
                }

                if ($this->closesFence($openFence, $line, $open !== null)) {
                    $openFence = null;
                }

                continue;
            }

            if (preg_match(self::ITEM, $line, $matches) === 1) {
                if ($open !== null) {
                    $items[] = self::itemFrom($open, $details);
                }

                $open = [
                    'description' => rtrim($matches[3]),
                    'checked' => $matches[2] !== ' ',
                    'line' => $index + 1,
                    'source' => $line,
                ];
                $details = [];

                continue;
            }

            if (preg_match(self::EMPTY_ITEM, $line) === 1) {
                throw self::emptyDescription($line, $index + 1, $source);
            }

            if (preg_match(self::NESTED_ITEM, $line) === 1) {
                throw self::nestedSubtask($line, $index + 1, $source);
            }

            if ($open !== null && self::belongsToItem($line)) {
                $details[] = self::blankToEmpty($line);
                $openFence = $this->opensFence($line, true);

                continue;
            }

            // A line in column one ends the item above it, whether it is a
            // heading, a paragraph or the start of a code block.
            if ($open !== null) {
                $items[] = self::itemFrom($open, $details);
                $open = null;
                $details = [];
            }

            $openFence = $this->opensFence($line);
        }

        if ($open !== null) {
            $items[] = self::itemFrom($open, $details);
        }

        return new Checklist($items, $source);
    }

    /**
     * Whether a line continues the item above it.
     *
     * Indented lines do, by any amount: the nesting an editor shows is what a
     * user means by "under this task", and demanding CommonMark's exact
     * continuation width would reject a one-space indent that plainly reads as
     * a detail. Blank lines do too, so an item can carry more than one
     * paragraph; the blank lines that turn out to be followed by something else
     * are dropped again when the item is built.
     *
     * A line in column one never does. That makes a new task, a heading or a
     * paragraph end the details above it, and it means CommonMark's lazy
     * continuation — unindented prose directly below a list item — is not
     * supported here: in a file the runner rewrites line by line, "indented
     * belongs to the task above" is a rule a user can see is being followed.
     */
    private static function belongsToItem(string $line): bool
    {
        return trim($line) === '' || $line[0] === ' ' || $line[0] === "\t";
    }

    /**
     * A whitespace-only line, reduced to nothing.
     *
     * Kept out of the indentation a detail block shares, so a stray space on an
     * otherwise empty line cannot stop the block from being dedented. Inside a
     * fenced example this also drops trailing whitespace from an otherwise
     * empty line of code, which no checklist depends on.
     */
    private static function blankToEmpty(string $line): string
    {
        return trim($line) === '' ? '' : $line;
    }

    /**
     * Build an item from the line it was read on and the lines below it.
     *
     * The blank lines between an item's last detail and whatever follows are
     * dropped, so an item's details end at its last line of content and
     * {@see ChecklistItem::endLine()} points at that line rather than at the
     * empty space in front of the next task.
     *
     * @param array{description: string, checked: bool, line: int, source: string} $open
     * @param list<string> $details
     */
    private static function itemFrom(array $open, array $details): ChecklistItem
    {
        while ($details !== [] && $details[count($details) - 1] === '') {
            array_pop($details);
        }

        return new ChecklistItem(
            $open['description'],
            $open['checked'],
            $open['line'],
            $open['source'],
            $details,
        );
    }

    /**
     * Split a document into lines, without their endings.
     *
     * All three line endings are recognised, so a checklist written on Windows
     * is read the same as one written here and a carriage return cannot end up
     * inside a task description.
     *
     * @return list<string>
     */
    private function lines(string $markdown): array
    {
        // A byte-order mark would otherwise sit in front of the first line's
        // bullet and hide a task that looks perfectly ordinary in an editor.
        $markdown = (string) preg_replace('/^\xEF\xBB\xBF/', '', $markdown);

        $lines = preg_split('/\r\n|\n|\r/', $markdown);

        return $lines === false ? [] : $lines;
    }

    /**
     * The fence a line opens, or null when it opens none.
     *
     * A backtick fence's info string cannot contain a backtick, so a line of
     * prose that happens to hold several code spans does not silently swallow
     * the rest of the checklist.
     *
     * @param bool $withinItem whether the line is one of an item's detail
     *                         lines, which may be indented any distance
     */
    private function opensFence(string $line, bool $withinItem = false): ?string
    {
        $pattern = $withinItem ? self::DETAIL_FENCE : self::FENCE;

        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        if (str_starts_with($matches[1], '`') && str_contains($matches[2], '`')) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Whether a line closes the given fence.
     *
     * A closing fence uses the same character, is at least as long, and carries
     * nothing but whitespace after it. A fence that is never closed therefore
     * runs to the end of the document, which is CommonMark's behaviour and the
     * safer one here: an unterminated example is treated as the code it looks
     * like rather than as tasks.
     *
     * @param bool $withinItem whether the fence was opened inside an item's
     *                         details, where its closing line is indented as
     *                         far as the example it ends
     */
    private function closesFence(string $fence, string $line, bool $withinItem = false): bool
    {
        $indent = $withinItem ? '[ \t]*' : ' {0,3}';
        $pattern = sprintf('/^%s%s{%d,}[ \t]*$/', $indent, $fence[0], strlen($fence));

        return preg_match($pattern, $line) === 1;
    }

    /**
     * Refuse a checkbox with nothing written after it.
     *
     * An item with no description cannot be given to an agent, cannot be
     * identified by its content and cannot be reported in a status line, and
     * the user is three words away from a task that works — so this says which
     * line to finish rather than quietly leaving one task fewer than the file
     * shows.
     */
    private static function emptyDescription(string $line, int $number, ?string $source): ChecklistException
    {
        return new ChecklistException(
            sprintf(
                '%s has a checkbox with no task on line %d: "%s".',
                self::subject($source),
                $number,
                self::excerpt($line),
            ),
            [
                'Write what should be done after the checkbox, as in "- [ ] Add a --dry-run option".',
                'Delete the line if there is nothing to do there.',
            ],
        );
    }

    /**
     * Refuse an indented checkbox.
     *
     * This release runs top-level items only (PROJECT.md section 2), and an
     * indented checkbox is the one piece of detail text that looks exactly like
     * work the runner has accepted. Reading it as an ordinary detail line would
     * hand it to the agent inside another task's notes and never check it off,
     * which is indistinguishable from the runner having lost it; both ways out
     * — promote it, or write it as a plain bullet — are one edit away.
     */
    private static function nestedSubtask(string $line, int $number, ?string $source): ChecklistException
    {
        return new ChecklistException(
            sprintf(
                '%s has an indented checkbox on line %d: "%s".',
                self::subject($source),
                $number,
                self::excerpt($line),
            ),
            [
                'This release runs top-level tasks only. Move the item to the start of the line to have it run on its own.',
                'To keep it as a detail of the task above it, write it as a plain "- " bullet without a checkbox.',
                'To show the syntax without writing a task, put the line in a fenced code block.',
            ],
        );
    }

    /**
     * The offending line, short enough to read inside a message.
     */
    private static function excerpt(string $line): string
    {
        $line = trim($line);

        return mb_strlen($line) > 60 ? mb_substr($line, 0, 59) . '…' : $line;
    }

    /**
     * How to name the document in an error.
     */
    private static function subject(?string $source): string
    {
        return $source === null ? 'The task checklist' : 'The task checklist ' . $source;
    }

    /**
     * Refuse a document that is not valid UTF-8.
     *
     * Reported here rather than left to fail later, because task descriptions
     * become branch names, commit messages, log entries and structured events:
     * an invalid byte that is accepted now surfaces as a confusing failure
     * somewhere far away from the file that caused it.
     *
     * @throws ChecklistException
     */
    private function requireUtf8(string $markdown, ?string $source): void
    {
        if (mb_check_encoding($markdown, 'UTF-8')) {
            return;
        }

        throw new ChecklistException(
            sprintf('%s is not valid UTF-8.', self::subject($source)),
            ['Save the file as UTF-8 and run the command again.'],
        );
    }
}
