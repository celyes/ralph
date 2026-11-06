<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\Tasks\TaskList;

/**
 * What a run did, in the order it did it.
 *
 * The value `ralph run` prints and `status --json` will render. It is the run's
 * row plus the per-task results, because the row cannot hold the sequence and
 * the sequence cannot hold the limits.
 *
 * **It reports, it does not decide.** Nothing here is read back to drive
 * behaviour; the durable record is the database, and a caller holding this
 * after the process ends is holding history.
 */
final class RunReport
{
    /**
     * @param list<TaskResult> $results in the order the tasks were taken on
     */
    private function __construct(
        private readonly RunRecord $run,
        private readonly AgentSelection $agent,
        private readonly BaseCommit $base,
        private readonly TaskList $tasks,
        private readonly array $results,
        private readonly string $stopReason,
    ) {
    }

    /**
     * @param array<TaskResult> $results in the order the tasks were taken on;
     *        any array, so a caller that filtered its results keeps the order
     *        without having to reindex first
     */
    public static function of(
        RunRecord $run,
        AgentSelection $agent,
        BaseCommit $base,
        TaskList $tasks,
        array $results,
        string $stopReason,
    ): self {
        return new self($run, $agent, $base, $tasks, array_values($results), $stopReason);
    }

    /**
     * The run's final row.
     */
    public function run(): RunRecord
    {
        return $this->run;
    }

    public function runId(): string
    {
        return $this->run->runId();
    }

    /**
     * The agent the whole run used.
     */
    public function agent(): AgentSelection
    {
        return $this->agent;
    }

    /**
     * The commit every task branch started from.
     */
    public function base(): BaseCommit
    {
        return $this->base;
    }

    /**
     * The checklist as the run read it at its start.
     */
    public function tasks(): TaskList
    {
        return $this->tasks;
    }

    /**
     * @return list<TaskResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function result(string $task): ?TaskResult
    {
        foreach ($this->results as $result) {
            if ($result->taskId()->value() === $task) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return list<TaskResult>
     */
    public function completed(): array
    {
        return array_values(array_filter($this->results, static fn (TaskResult $r): bool => $r->isCompleted()));
    }

    /**
     * @return list<TaskResult>
     */
    public function unfinished(): array
    {
        return array_values(array_filter($this->results, static fn (TaskResult $r): bool => !$r->isCompleted()));
    }

    /**
     * @return list<TaskResult>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->results, static fn (TaskResult $r): bool => $r->isBlocked()));
    }

    /**
     * @return list<TaskResult>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->results, static fn (TaskResult $r): bool => $r->isFailure()));
    }

    /**
     * How many tasks the run took on.
     */
    public function count(): int
    {
        return count($this->results);
    }

    public function completedCount(): int
    {
        return count($this->completed());
    }

    /**
     * How many of the checklist's tasks are still pending, as the run left it.
     *
     * Counted from the reading the run started with less what it completed,
     * rather than by re-reading the file: a user who added tasks while the run
     * was going has not changed what this run had to do.
     */
    public function remaining(): int
    {
        return max(0, $this->tasks->pendingCount() - $this->completedCount());
    }

    /**
     * Whether every task the run took on was completed.
     */
    public function isClean(): bool
    {
        return $this->unfinished() === [];
    }

    /**
     * Whether anything needs a person before the run can go further.
     */
    public function needsAttention(): bool
    {
        return $this->blocked() !== [] || $this->failed() !== [];
    }

    /**
     * Why the loop stopped.
     */
    public function stopReason(): string
    {
        return $this->stopReason;
    }

    /**
     * The summary line at the end of `ralph run`.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %d of %d tasks completed with %s, %d remaining — %s',
            $this->run->runId(),
            $this->completedCount(),
            $this->count(),
            $this->agent->name(),
            $this->remaining(),
            $this->stopReason,
        );
    }

    /**
     * Every task's line, for the terminal.
     *
     * @return list<string>
     */
    public function details(): array
    {
        return array_map(static fn (TaskResult $result): string => $result->describe(), $this->results);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run->toArray(),
            'agent' => $this->agent->toArray(),
            'base' => ['reference' => $this->base->reference(), 'sha' => $this->base->sha()],
            'stop_reason' => $this->stopReason,
            'completed' => $this->completedCount(),
            'remaining' => $this->remaining(),
            'tasks' => array_map(static fn (TaskResult $result): array => $result->toArray(), $this->results),
        ];
    }
}
