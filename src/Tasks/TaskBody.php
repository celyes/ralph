<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * A task's description reduced to the text its identity is made of.
 *
 * Identity has to survive the edits a user makes without meaning to change the
 * work: retyping a line and leaving two spaces behind, pasting a non-breaking
 * space out of a document, correcting the capitalisation of a sentence. None of
 * those is a different task, so none of them may produce a different
 * {@see TaskId} — otherwise the runner forgets a task's history, branch and
 * attempt count because its first word was lowercased.
 *
 * What is normalised away, and nothing else:
 *
 * - invisible characters, which cannot be what distinguishes two tasks;
 * - every run of whitespace, including the Unicode spaces an editor inserts,
 *   collapsed to one ordinary space, and removed from both ends;
 * - letter case.
 *
 * What is deliberately left alone is as important. Markdown inside the
 * description — `**bold**`, backticks, a link — is kept, because stripping it
 * means guessing which characters are markup and which are the task (a task
 * about `*` in a glob pattern is a real task). Punctuation is kept, so
 * "Add a flag" and "Add a flag." are two tasks: a trailing full stop is not
 * noise a user adds twice by accident, and the alternative is a rule nobody can
 * predict the edge of. Accents are kept as written, since this package does not
 * require ext-intl and so cannot apply Unicode normalisation on every machine —
 * doing it only where the extension happens to be installed would make task IDs
 * differ between a developer's machine and CI, which is worse than not doing it
 * at all.
 *
 * Folding case is the one rule that can bite: two genuinely different tasks that
 * differ only in capitalisation — "Rename foo to Foo" and "Rename Foo to foo" —
 * read as one task here and are refused as duplicates by
 * {@see TaskIdentifier}. That refusal names both lines and is resolved by a
 * single clarifying word, while the opposite choice would silently orphan a
 * running task's history the moment someone sentence-cased the checklist.
 *
 * The item's details are not part of its body. They are notes about how to do
 * the work, which get rewritten while the task stays the same task; the content
 * hash of PROJECT.md section D covers them, so an edit to a task's details is
 * reported as that task changing rather than as one task disappearing and
 * another appearing.
 */
final class TaskBody
{
    /**
     * Characters with no width, removed before anything else.
     *
     * A zero-width space or a stray byte-order mark in the middle of a line is
     * invisible in every editor, so it cannot be the thing that makes two tasks
     * different — but it would change a hash.
     */
    private const string INVISIBLE = '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

    /**
     * Whitespace, as a user can produce it.
     *
     * The `u` modifier switches on Unicode properties as well as UTF-8, so this
     * covers the spaces that arrive by copy-and-paste — non-breaking, en, em,
     * ideographic — and not only the ASCII ones a bare `\s` would match. Those
     * are exactly the characters that would otherwise split one task into two
     * while looking identical in an editor.
     */
    private const string WHITESPACE = '/\s+/u';

    private function __construct(
        private readonly string $original,
        private readonly string $normalized,
    ) {
    }

    /**
     * Normalise a task description.
     *
     * @throws InvalidArgumentException when the text is not valid UTF-8, which
     *                                  the checklist reader has already refused
     *                                  with a readable message by the time a
     *                                  description gets here
     */
    public static function of(string $description): self
    {
        if (!mb_check_encoding($description, 'UTF-8')) {
            throw new InvalidArgumentException('A task description must be valid UTF-8.');
        }

        $text = (string) preg_replace(self::INVISIBLE, '', $description);
        $text = (string) preg_replace(self::WHITESPACE, ' ', $text);

        return new self($description, mb_strtolower(trim($text), 'UTF-8'));
    }

    /**
     * Build the body of a checklist item.
     *
     * The checkbox and the line number are not read at all: an item is the same
     * task whether it is ticked or not and wherever it sits in the file, which
     * is what lets a task keep its identity when the checklist is reordered or
     * a finished task is reopened.
     */
    public static function ofItem(ChecklistItem $item): self
    {
        return self::of($item->description());
    }

    /**
     * The description as the user wrote it.
     */
    public function original(): string
    {
        return $this->original;
    }

    /**
     * The text identity is computed from.
     */
    public function normalized(): string
    {
        return $this->normalized;
    }

    /**
     * Whether two descriptions state the same task.
     */
    public function equals(self $other): bool
    {
        return $this->normalized === $other->normalized;
    }

    /**
     * Whether the description says nothing at all.
     *
     * Not reachable through the checklist, which refuses an empty description
     * where the user can see the line, but a body built from somewhere else —
     * a state record, a test — can be empty and must not be given an ID.
     */
    public function isEmpty(): bool
    {
        return $this->normalized === '';
    }
}
