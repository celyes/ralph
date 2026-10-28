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
 * Two deferred refinements, both already specified: indented bullets and
 * paragraphs under an item are ignored here and become its details in
 * PROJECT.md section C, item 2, and a line that is nearly an item — an empty
 * description, or an indented checkbox pretending to be a subtask — is only
 * skipped here, where section C, item 3 makes it a readable error.
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
     * The opening or closing line of a fenced code block.
     *
     * Up to three leading spaces are allowed, as CommonMark does, because a
     * fence indented that far still opens a code block.
     */
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})(.*)$/';

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

        foreach ($this->lines($markdown) as $index => $line) {
            if ($openFence !== null) {
                if ($this->closesFence($openFence, $line)) {
                    $openFence = null;
                }

                continue;
            }

            $openFence = $this->opensFence($line);
            if ($openFence !== null) {
                continue;
            }

            if (preg_match(self::ITEM, $line, $matches) !== 1) {
                continue;
            }

            $items[] = new ChecklistItem(
                rtrim($matches[3]),
                $matches[2] !== ' ',
                $index + 1,
                $line,
            );
        }

        return new Checklist($items, $source);
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
     */
    private function opensFence(string $line): ?string
    {
        if (preg_match(self::FENCE, $line, $matches) !== 1) {
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
     */
    private function closesFence(string $fence, string $line): bool
    {
        $pattern = sprintf('/^ {0,3}%s{%d,}[ \t]*$/', $fence[0], strlen($fence));

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
