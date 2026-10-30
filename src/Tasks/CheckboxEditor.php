<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;

/**
 * Changes one task's marker in a checklist and leaves every other byte alone.
 *
 * The checklist is the user's document. They wrote its headings, its prose and
 * its spacing, they may well track it in Git, and the only edit Ralph PHP is
 * entitled to make in it is turning `[ ]` into `[x]` on the task it has just
 * verified — and `[x]` back into `[ ]`, which is what retrying a task that was
 * ticked prematurely needs. So this is a single-character substitution inside
 * one line, not a re-rendering: the bullet character, the spacing around the
 * checkbox, the description, the trailing whitespace, the line's ending, the
 * endings of every other line, a byte-order mark and the presence or absence of
 * a final newline all come back exactly as they went in. A writer that parsed
 * the file and printed it again would be easier and would quietly reformat a
 * document nobody asked it to touch.
 *
 * Free of I/O, so the guarantee above can be asserted on strings rather than
 * inferred from a file. {@see ChecklistWriter} is what puts the result on disk.
 *
 * The task is named by its identifier, not by a line number. Identifiers are
 * derived from the description alone ({@see TaskId}), so a task that moved
 * while the agent worked is still the same task and is still found; a task the
 * file no longer states is refused rather than guessed at, since ticking the
 * wrong line is worse than reporting that the checklist changed. Pausing a run
 * for that reconciliation instead of simply failing is PROJECT.md section C,
 * item 7, and it builds on this refusal.
 *
 * Locating the line goes through {@see ChecklistParser} rather than a search of
 * the text, which is what keeps a checkbox written inside a fenced example —
 * the one `ralph init` puts in every new checklist — from ever being the line
 * that gets ticked.
 */
final class CheckboxEditor
{
    /**
     * The marker inside an item's line, with the text around it captured.
     *
     * Anchored at the start of the line and applied to a line the parser has
     * already accepted as an item, so it matches the checkbox of that item and
     * nothing else. Only the state character is replaced; both captures are
     * written back as they were, which is where the spacing and the bullet
     * character survive.
     */
    private const string MARKER = '/^([-*+][ \t]+\[)[ xX](\])/';

    /**
     * What a ticked marker is written as.
     *
     * Lower case, as the checklist `ralph init` generates uses and as
     * PROJECT.md writes. `[X]` is read as ticked wherever a user wrote it, and
     * unticking and re-ticking such an item normalises it to `[x]` — the one
     * formatting change this makes, and only ever to the marker it was asked to
     * rewrite.
     */
    private const string TICKED = 'x';

    /**
     * What an open marker is written as.
     */
    private const string OPEN = ' ';

    /**
     * A byte-order mark, which the parser drops before counting lines.
     */
    private const string BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly ChecklistParser $parser = new ChecklistParser())
    {
    }

    /**
     * Mark a task done.
     *
     * @param string $markdown the checklist as it stands
     * @param string|null $source path to name in errors, when the document came
     *                            from a file
     *
     * @throws ChecklistException when the document cannot be parsed or no
     *                            longer states the task
     */
    public function tick(string $markdown, TaskId $id, ?string $source = null): CheckboxEdit
    {
        return $this->set($markdown, $id, true, $source);
    }

    /**
     * Mark a task as still to do.
     *
     * What reopening a task needs: a box ticked by a run whose work turned out
     * not to hold has to be able to go back to being work.
     *
     * @throws ChecklistException
     */
    public function untick(string $markdown, TaskId $id, ?string $source = null): CheckboxEdit
    {
        return $this->set($markdown, $id, false, $source);
    }

    /**
     * Set a task's marker, leaving the rest of the document untouched.
     *
     * @throws ChecklistException
     */
    private function set(string $markdown, TaskId $id, bool $checked, ?string $source): CheckboxEdit
    {
        $checklist = $this->parser->parse($markdown, $source);
        $task = TaskList::fromChecklist($checklist)->find($id);

        if ($task === null) {
            throw self::unknownTask($id, $checklist);
        }

        $line = $task->line();

        if ($task->isChecked() === $checked) {
            return CheckboxEdit::unchanged($markdown, $id, $line, $checked);
        }

        return CheckboxEdit::applied(
            self::rewriteLine($markdown, $line, $checked, $task, $checklist),
            $id,
            $line,
            $checked,
        );
    }

    /**
     * Replace the marker on one line of the document.
     *
     * The document is split into alternating text and line endings, the one
     * line is substituted, and the pieces are joined again: every ending comes
     * back as it was, so a file written on Windows stays written on Windows, a
     * file that mixes endings keeps its mixture, and a file with no final
     * newline does not acquire one.
     *
     * @throws ChecklistException when the line does not hold the marker the
     *                            parser read there, which means the document
     *                            changed under us
     */
    private static function rewriteLine(
        string $markdown,
        int $line,
        bool $checked,
        Task $task,
        Checklist $checklist,
    ): string {
        $prefix = str_starts_with($markdown, self::BOM) ? self::BOM : '';
        $pieces = self::split(substr($markdown, strlen($prefix)));

        // Text and endings alternate, so the nth line's text is at 2(n-1).
        $index = ($line - 1) * 2;
        $original = $pieces[$index] ?? '';

        $rewritten = preg_replace(
            self::MARKER,
            '${1}' . ($checked ? self::TICKED : self::OPEN) . '${2}',
            $original,
            1,
        );

        if ($rewritten === null || $rewritten === $original) {
            throw self::unexpectedLine($task, $line, $checklist);
        }

        $pieces[$index] = $rewritten;

        return $prefix . implode('', $pieces);
    }

    /**
     * Split a document into its lines and the endings between them.
     *
     * Both are kept, in order, so joining the pieces reproduces the document
     * byte for byte. All three endings are recognised, as the parser does, so
     * the line numbers it reports index these pieces.
     *
     * @return list<string>
     */
    private static function split(string $markdown): array
    {
        $pieces = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        return $pieces === false ? [$markdown] : $pieces;
    }

    /**
     * Refuse to tick a task the checklist no longer states.
     *
     * The task was read out of this file, so meeting this means the file
     * changed since: the task was reworded, deleted, or its description edited
     * — any of which gives it a different identity. Ticking something anyway
     * would mark work done that nobody asked for, and the user's edit is the
     * more recent statement of intent, so the run stops and says what it was
     * looking for.
     */
    private static function unknownTask(TaskId $id, Checklist $checklist): ChecklistException
    {
        return new ChecklistException(
            sprintf('%s no longer states the task %s.', $checklist->subject(), $id->value()),
            [
                'A task is identified by its description, so editing or deleting the line changes which task it is.',
                'Restore the wording the run started with to have its result recorded against it.',
                'Otherwise leave the checklist as it is: the task was not ticked, and the work it produced is still on its branch.',
            ],
        );
    }

    /**
     * Refuse a line that is not the item it was read as.
     *
     * Not reachable from a single reading of an unchanged document — the line
     * was matched as an item moments earlier — so this exists for the case
     * where the document changed between the parse and the substitution, and
     * says which line stopped the write rather than rewriting a line whose
     * meaning is unknown.
     */
    private static function unexpectedLine(Task $task, int $line, Checklist $checklist): ChecklistException
    {
        return new ChecklistException(
            sprintf(
                '%s does not state the task %s as a checkbox on line %d any more.',
                $checklist->subject(),
                $task->id()->value(),
                $line,
            ),
            ['The checklist was edited while the task was running. Check the line and run the command again.'],
        );
    }
}
