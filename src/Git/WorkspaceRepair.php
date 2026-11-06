<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\State\GenerationRecord;

/**
 * What reconciling a workspace actually did, and the row as it stands
 * afterwards.
 *
 * The *reconcile* half of PROJECT.md section F, item 9's "reconcile or block",
 * and the record that it happened. A repair is deliberately a visible event
 * rather than a silent correction: the one thing item 9 forbids is silently
 * accepting what a worker did to the repository, and a soft reset that nobody
 * is told about would be exactly that with an extra step. So every action is
 * kept as a line a person can read, the audit that justified it is kept beside
 * them, and {@see self::wasNeeded()} is what a caller reports.
 *
 * The row is carried because repairing can write to it. A worker that put HEAD
 * on the pass's own branch leaves a reference that nothing records, and
 * {@see TaskBranches} reads a reference nothing records as unrelated work — so
 * the repair records the claim, and the caller needs the updated row rather
 * than the copy it was holding.
 */
final class WorkspaceRepair
{
    /**
     * @param list<string> $actions what was done, in the order it was done
     */
    private function __construct(
        private readonly WorkspaceAudit $audit,
        private readonly GenerationRecord $record,
        private readonly WorkspaceState $state,
        private readonly array $actions,
    ) {
    }

    /**
     * Nothing was found, so nothing was done.
     */
    public static function nothingToDo(
        WorkspaceAudit $audit,
        GenerationRecord $record,
        WorkspaceState $state,
    ): self {
        return new self($audit, $record, $state, []);
    }

    /**
     * The repair that was carried out.
     *
     * @param list<string> $actions
     */
    public static function of(
        WorkspaceAudit $audit,
        GenerationRecord $record,
        WorkspaceState $state,
        array $actions,
    ): self {
        return new self($audit, $record, $state, $actions);
    }

    /**
     * What was found, including the findings that needed no action.
     */
    public function audit(): WorkspaceAudit
    {
        return $this->audit;
    }

    /**
     * The pass's row as it stands after the repair.
     */
    public function record(): GenerationRecord
    {
        return $this->record;
    }

    /**
     * The workspace's state as it stands after the repair, read back from Git
     * rather than assumed.
     */
    public function state(): WorkspaceState
    {
        return $this->state;
    }

    /**
     * What was done, in the order it was done.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Whether anything had to be put back.
     */
    public function wasNeeded(): bool
    {
        return $this->actions !== [];
    }

    /**
     * One line for a log or a status table.
     */
    public function describe(): string
    {
        if (!$this->wasNeeded()) {
            return $this->audit->describe();
        }

        return sprintf(
            '%s: put back by %s',
            $this->audit->generation()->label(),
            implode('; ', $this->actions),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->audit->generation()->slug(),
            'repaired' => $this->wasNeeded(),
            'actions' => $this->actions,
            'audit' => $this->audit->toArray(),
            'state' => $this->state->toArray(),
        ];
    }
}
