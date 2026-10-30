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
 * Everything else in the file is prose. Headings, paragraphs, tables and
 * indented bullets are not items, and a line inside a fenced code block is
 * never an item however it is written, which is what lets the checklist
 * `ralph init` writes carry a worked example of the syntax without that example
 * becoming a task.
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
 * One deferred refinement, already specified: a line that is nearly an item —
 * an empty description, or an indented checkbox pretending to be a subtask —
 * is only skipped or read as a detail line here, where PROJECT.md section C,
 * item 3 makes it a readable error.
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
     * non-space character, so a bare `- [ ]` is not one either.
     */
    private const string ITEM = '/^([-*+])[ \t]+\[([ xX])\][ \t]+(\S.*)$/u';

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
     * @throws ChecklistException when the contents are not valid UTF-8
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
                // are not checklist syntax even when written as a task in
                // column one.
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
            sprintf('The task checklist %s is not valid UTF-8.', $source ?? 'contents'),
            ['Save the file as UTF-8 and run the command again.'],
        );
    }
}
