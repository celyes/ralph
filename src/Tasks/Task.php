<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * A checklist item together with the identity the runner works with.
 *
 * {@see ChecklistItem} is what the file says; a Task is that item once it has a
 * {@see TaskId} and a normalised {@see TaskBody}. The two are kept apart so the
 * parser stays a reader of Markdown with no opinion about identity, and so the
 * rule that identity ignores the checkbox and the line number is stated in one
 * place instead of being implied by whatever the parser happened to hash.
 *
 * The item is kept whole rather than copied field by field, because the things
 * around it need it as it was read: {@see CheckboxEditor} rewrites the marker on
 * the line it was parsed from ({@see ChecklistItem::source()}), and an error
 * about a task has to say which line it is on.
 */
final class Task
{
    public function __construct(
        private readonly TaskId $id,
        private readonly TaskBody $body,
        private readonly ChecklistItem $item,
    ) {
    }

    /**
     * Identify a checklist item.
     */
    public static function fromItem(ChecklistItem $item): self
    {
        $body = TaskBody::ofItem($item);

        return new self(TaskId::fromBody($body), $body, $item);
    }

    /**
     * The task's stable identifier.
     */
    public function id(): TaskId
    {
        return $this->id;
    }

    /**
     * The description reduced to what identity is computed from.
     */
    public function body(): TaskBody
    {
        return $this->body;
    }

    /**
     * The item as the checklist states it.
     */
    public function item(): ChecklistItem
    {
        return $this->item;
    }

    /**
     * The task text, as the user wrote it.
     */
    public function description(): string
    {
        return $this->item->description();
    }

    /**
     * The implementation notes written under the task, ready for a prompt.
     */
    public function details(): string
    {
        return $this->item->details();
    }

    /**
     * Whether the checklist marks this task as done.
     *
     * The file's marker, not a verdict: an item a user ticked by hand looks the
     * same here. {@see TaskList} is what turns this into a {@see Completion},
     * using the state store's record to say whether a ticked item is the user's
     * word or the runner's.
     */
    public function isChecked(): bool
    {
        return $this->item->isChecked();
    }

    /**
     * 1-based line number of the task in the checklist.
     */
    public function line(): int
    {
        return $this->item->line();
    }
}
