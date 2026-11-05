<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * One change in a task workspace that staging deliberately left out.
 *
 * Kept rather than discarded, because an exclusion is a decision about what a
 * commit contains and a decision nobody can see is indistinguishable from a
 * bug. Two cases make the record worth its weight: an agent that spent its
 * attempt editing `.ralph/tasks.md` instead of the project, and a verified task
 * whose only change was to a copied `.env` — both of which produce a commit
 * that does not contain the thing the agent did, and neither of which is
 * explicable afterwards unless the exclusion was written down.
 *
 * The change is carried as the {@see WorkingTreeChange} Git reported, so the
 * record says what the path's state actually was — modified, added, deleted —
 * rather than only that it was skipped.
 */
final class ExcludedChange
{
    private function __construct(
        private readonly WorkingTreeChange $change,
        private readonly ExclusionReason $reason,
    ) {
    }

    public static function of(WorkingTreeChange $change, ExclusionReason $reason): self
    {
        return new self($change, $reason);
    }

    public function change(): WorkingTreeChange
    {
        return $this->change;
    }

    public function reason(): ExclusionReason
    {
        return $this->reason;
    }

    /**
     * The path Git named, relative to the workspace root.
     */
    public function path(): string
    {
        return $this->change->path();
    }

    /**
     * One line naming the change and why it was left out.
     */
    public function describe(): string
    {
        return sprintf('%s — %s', $this->change->describe(), $this->reason->label());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason->value] + $this->change->toArray();
    }
}
