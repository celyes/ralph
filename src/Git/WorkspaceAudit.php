<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * What a worker changed about a repository's commits and branches, and whether
 * the pass can go on (PROJECT.md section F, item 9).
 *
 * The reading that sits between the two {@see WorkspaceState} snapshots and the
 * one thing allowed to act on them, {@see WorkspaceAuditor}. It holds both
 * states, because a user asked to resolve a blocked pass needs to see what the
 * workspace was handed as well as what it became, and the findings in the order
 * they were established: the unfinished-operation one first, since every other
 * reading beside it is a reading of a repository mid-operation.
 *
 * Three verdicts, and no fourth:
 *
 *  - **Clean.** The worker left the repository's commits and references exactly
 *    as it found them and did its work in the files, which is the ordinary
 *    case and the one every other step of section F assumes.
 *  - **Reconcilable.** Every finding is one this package can put back without
 *    losing anything — see {@see DriftKind} for why that is a short list.
 *  - **Blocking.** At least one finding is not, and the pass stops with the
 *    evidence preserved.
 *
 * A single blocking finding makes the whole reading blocking, even beside
 * reconcilable ones. Repairing half of a divergence would leave a state neither
 * the user nor the next run could reason about, and the reconcilable half is
 * reported anyway so that the full picture is in the message.
 *
 * **What this does not detect is worth stating, because the item's phrasing
 * invites the assumption that it is complete.** Tags, notes, remote-tracking
 * references, stash entries, reflog rewrites and `git gc` are all invisible
 * here; so is anything a worker did and then undid; so is a change to a
 * submodule's own repository. What is covered is the state the rest of section
 * F reads — HEAD, the local branches, and whether an operation is unfinished —
 * which is what decides whether staging and committing a task is safe. The
 * review a user gives the resulting commit is still the thing that catches
 * everything else.
 */
final class WorkspaceAudit
{
    /**
     * Findings named individually in a message.
     *
     * A worker that has done ten surprising things to a repository has done
     * something a person must look at; listing all of them would bury the
     * remedy under the evidence. The count is always exact, and the findings
     * are all on the value for a caller that reports them in full.
     */
    public const int FINDINGS_IN_MESSAGE = 5;

    /**
     * @param list<WorkspaceDrift> $drifts in the order they were established
     */
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly string $workspace,
        private readonly string $branch,
        private readonly WorkspaceState $before,
        private readonly WorkspaceState $after,
        private readonly array $drifts,
    ) {
    }

    /**
     * One reading.
     *
     * @param string               $branch the name the pass's branch has, which
     *        is what makes a reference the pass's own rather than a stranger's
     * @param list<WorkspaceDrift> $drifts
     */
    public static function of(
        TaskGeneration $generation,
        string $workspace,
        string $branch,
        WorkspaceState $before,
        WorkspaceState $after,
        array $drifts,
    ): self {
        return new self($generation, $workspace, $branch, $before, $after, $drifts);
    }

    /**
     * A reading of a workspace the worker left exactly as it found it.
     */
    public static function unchanged(
        TaskGeneration $generation,
        string $workspace,
        string $branch,
        WorkspaceState $state,
    ): self {
        return new self($generation, $workspace, $branch, $state, $state, []);
    }

    /**
     * The pass the workspace belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * The workspace the reading is of.
     */
    public function workspace(): string
    {
        return $this->workspace;
    }

    /**
     * The name the pass's branch has.
     */
    public function branch(): string
    {
        return $this->branch;
    }

    /**
     * The state the worker was handed.
     */
    public function before(): WorkspaceState
    {
        return $this->before;
    }

    /**
     * The state the worker left.
     */
    public function after(): WorkspaceState
    {
        return $this->after;
    }

    /**
     * The commit the workspace was on before the worker ran, which is what a
     * repair restores it to.
     */
    public function expectedHead(): string
    {
        return $this->before->head();
    }

    /**
     * Every finding, in the order it was established.
     *
     * @return list<WorkspaceDrift>
     */
    public function drifts(): array
    {
        return $this->drifts;
    }

    /**
     * The findings that stop the pass.
     *
     * @return list<WorkspaceDrift>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->drifts, static fn (WorkspaceDrift $d): bool => $d->isBlocking()));
    }

    /**
     * The findings this package can put back.
     *
     * @return list<WorkspaceDrift>
     */
    public function reconcilable(): array
    {
        return array_values(array_filter($this->drifts, static fn (WorkspaceDrift $d): bool => $d->isReconcilable()));
    }

    /**
     * Whether the findings include one of this kind.
     */
    public function has(DriftKind $kind): bool
    {
        foreach ($this->drifts as $drift) {
            if ($drift->kind() === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first finding of a kind, for a repair that needs its subject.
     */
    public function first(DriftKind $kind): ?WorkspaceDrift
    {
        foreach ($this->drifts as $drift) {
            if ($drift->kind() === $kind) {
                return $drift;
            }
        }

        return null;
    }

    /**
     * Whether the worker left the repository's commits and references alone.
     */
    public function isClean(): bool
    {
        return $this->drifts === [];
    }

    /**
     * Whether something was found and all of it can be put back.
     */
    public function isReconcilable(): bool
    {
        return $this->drifts !== [] && $this->blocking() === [];
    }

    /**
     * Whether the pass must stop.
     */
    public function isBlocking(): bool
    {
        return $this->blocking() !== [];
    }

    public function count(): int
    {
        return count($this->drifts);
    }

    /**
     * One line saying what was found.
     */
    public function summary(): string
    {
        if ($this->isClean()) {
            return sprintf(
                'the worker left the repository\'s commits and branches as it found them in %s',
                $this->workspace,
            );
        }

        return sprintf(
            'the worker changed the repository\'s commits or branches in %d way%s in %s, %s',
            $this->count(),
            $this->count() === 1 ? '' : 's',
            $this->workspace,
            $this->isBlocking()
                ? 'and not all of it can be put back'
                : 'all of which can be put back',
        );
    }

    /**
     * The evidence, one item per line, blocking findings first.
     *
     * Ordered by what the reader has to act on rather than by what was found
     * first: a user reading this is deciding what to do about the pass, and the
     * findings that stopped it are the ones that need deciding about.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];
        $ordered = [...$this->blocking(), ...$this->reconcilable()];
        $shown = array_slice($ordered, 0, self::FINDINGS_IN_MESSAGE);

        foreach ($shown as $drift) {
            $details[] = sprintf(
                '%s %s.',
                $drift->isBlocking() ? 'Blocking:' : 'Can be put back:',
                $drift->summary(),
            );
        }

        if (count($ordered) > count($shown)) {
            $details[] = sprintf('… and %d more.', count($ordered) - count($shown));
        }

        $details[] = sprintf('The workspace was handed %s.', $this->before->describe());
        $details[] = sprintf('The worker left it %s.', $this->after->describe());

        return $details;
    }

    /**
     * What the user can do about it.
     *
     * Every remedy is theirs. The package says what it will not do first,
     * because a user who expects a tool to force the branch or reset the
     * workspace has to be told that this one does not before the rest of the
     * list makes sense.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if (!$this->isBlocking()) {
            return [];
        }

        $hints = [
            'Ralph PHP will not force a branch, reset a workspace or delete a reference to tidy this up: '
                . 'each of those can destroy a commit somebody has and this run has never seen.',
            'Nothing was staged and nothing was committed. The workspace, its branch and every commit in '
                . 'it are left exactly as the worker left them.',
        ];

        $commands = [];

        foreach ($this->blocking() as $drift) {
            $commands[$drift->inspectionCommand()] = true;
        }

        foreach (array_slice(array_keys($commands), 0, self::FINDINGS_IN_MESSAGE) as $command) {
            $hints[] = sprintf('Run "%s" in %s to see what it holds.', $command, $this->workspace);
        }

        $hints[] = sprintf(
            'Put the repository back the way you want it — or keep what the worker did and tick the task '
                . 'off yourself — then run the command again. The pass is %s.',
            $this->generation->label(),
        );

        return $hints;
    }

    /**
     * One phrase for a log header.
     */
    public function describe(): string
    {
        if ($this->isClean()) {
            return sprintf('%s: no change to commits or branches', $this->generation->label());
        }

        return sprintf(
            '%s: %d finding%s, %s',
            $this->generation->label(),
            $this->count(),
            $this->count() === 1 ? '' : 's',
            $this->isBlocking() ? 'blocking' : 'reconcilable',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation->slug(),
            'workspace' => $this->workspace,
            'branch' => $this->branch,
            'clean' => $this->isClean(),
            'blocking' => $this->isBlocking(),
            'before' => $this->before->toArray(),
            'after' => $this->after->toArray(),
            'drifts' => array_map(static fn (WorkspaceDrift $d): array => $d->toArray(), $this->drifts),
        ];
    }
}
