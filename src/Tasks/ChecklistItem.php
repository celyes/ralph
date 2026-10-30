<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * One top-level checklist item, as the file states it.
 *
 * Deliberately a record of what was read rather than of what the runner knows:
 * `checked` is the marker in the file, not a verdict that the work was verified
 * — an item the user ticked by hand is indistinguishable here. Telling the two
 * apart needs what the state store knows and is {@see Completion}'s job, which
 * {@see TaskList} applies when it decides what is pending.
 *
 * The position is kept because two later pieces of work need it and neither can
 * recover it from the description: the checkbox update has to change one marker
 * and leave every other byte of the file alone (section C, item 6), and an
 * error about a task has to say which line it is on. {@see self::source()} is
 * the line as it was read, so a writer can confirm the line it is about to
 * rewrite is still the one that was parsed before it touches the file.
 *
 * An item may also carry details: the indented bullets and paragraphs written
 * under it, which the agent receives as implementation notes or acceptance
 * criteria. They are kept both as read ({@see self::detailLines()}) and as a
 * block of Markdown with their shared indentation removed
 * ({@see self::details()}), because the first is what a writer and a content
 * hash need and the second is what goes into a prompt.
 */
final class ChecklistItem
{
    /**
     * @param string $description the text after the checkbox, as written
     * @param bool $checked whether the file marks the item `[x]` or `[X]`
     * @param int $line 1-based line number of the item in its source
     * @param string $source the item's line as read, without its line ending
     * @param list<string> $details the lines below the item that belong to it,
     *                              as read and without their endings: every
     *                              line from the one after {@see self::line()}
     *                              up to the item's last detail line, with
     *                              blank ones normalised to the empty string
     */
    public function __construct(
        private readonly string $description,
        private readonly bool $checked,
        private readonly int $line,
        private readonly string $source,
        private readonly array $details = [],
    ) {
    }

    /**
     * The task text, as the user wrote it.
     *
     * Only surrounding whitespace is removed; Markdown inside it is left alone,
     * because it is passed to an agent as prose and normalising it is the
     * business of task identity (PROJECT.md section C, item 4).
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Whether the file marks this item as done.
     */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /**
     * 1-based line number of the item in the checklist.
     */
    public function line(): int
    {
        return $this->line;
    }

    /**
     * 1-based line number of the item's last line, its details included.
     *
     * Equal to {@see self::line()} for an item written on its own, because the
     * detail lines are the contiguous run of lines directly below it.
     */
    public function endLine(): int
    {
        return $this->line + count($this->details);
    }

    /**
     * The item's line exactly as read, without its line ending.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Whether anything is written under the item.
     */
    public function hasDetails(): bool
    {
        return $this->details !== [];
    }

    /**
     * The item's detail lines exactly as read, without their line endings.
     *
     * @return list<string>
     */
    public function detailLines(): array
    {
        return $this->details;
    }

    /**
     * The details as a block of Markdown, ready to put in front of an agent.
     *
     * The indentation the lines share is removed, so a bullet list written two
     * spaces in reads as a list rather than as a code block, while the relative
     * indentation of nested bullets and of fenced examples survives. The text
     * is otherwise untouched: it is the user's prose, and rewriting it is not
     * this parser's business.
     *
     * Empty when the item has no details.
     */
    public function details(): string
    {
        if ($this->details === []) {
            return '';
        }

        $indent = strlen($this->sharedIndent());

        return implode("\n", array_map(
            static fn (string $line): string => substr($line, $indent),
            $this->details,
        ));
    }

    /**
     * The whitespace every non-blank detail line starts with.
     *
     * Compared as literal text rather than as a width, so a block that mixes
     * tabs and spaces loses only the prefix its lines genuinely share instead of
     * having characters removed on an assumed tab size.
     */
    private function sharedIndent(): string
    {
        $shared = null;

        foreach ($this->details as $line) {
            if ($line === '') {
                continue;
            }

            $indent = substr($line, 0, strspn($line, " \t"));
            $shared = $shared === null ? $indent : self::commonPrefix($shared, $indent);

            if ($shared === '') {
                break;
            }
        }

        return $shared ?? '';
    }

    /**
     * The longest prefix two strings have in common.
     */
    private static function commonPrefix(string $left, string $right): string
    {
        $length = min(strlen($left), strlen($right));

        for ($index = 0; $index < $length; ++$index) {
            if ($left[$index] !== $right[$index]) {
                return substr($left, 0, $index);
            }
        }

        return substr($left, 0, $length);
    }
}
