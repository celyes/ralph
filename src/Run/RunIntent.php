<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Tasks\TaskId;

/**
 * What a runner has been asked to do: start, continue, or try one task again.
 *
 * The three commands of PROJECT.md section 3 that work — `ralph run`,
 * `ralph resume` and `ralph retry TASK-ID` — are one loop with three different
 * openings, and this is the difference between them. It exists so that
 * {@see Runner} has one path through a run rather than three that could drift
 * apart: the lock, the checkout inspection, the frozen checks, the per-task
 * sequence and every one of the six endings are the same work whichever
 * command asked for it, and the parts that are *not* the same are exactly the
 * four questions this value answers.
 *
 *  - **Which agent.** A fresh run asks the configuration
 *    ({@see \Celyes\RalphPhp\Agent\AgentSelector}); a resume asks the run it is
 *    continuing ({@see AgentContinuity}) and lets `--agent` do nothing but
 *    confirm it. Section H, item 6.
 *  - **Whether a run row is opened or continued.** A continuation inherits the
 *    predecessor's iteration and time counters, which is what makes a limit
 *    survive an interruption instead of being handed back whole by the resume
 *    that was meant to finish the work (section H, item 3).
 *  - **Which tasks are taken on.** `run` and `resume` work through the
 *    checklist in file order; `retry` works on the one task named and stops.
 *  - **What the lock records.** A conflicting runner is told which command it
 *    is conflicting with, not merely that something holds the repository.
 *
 * Deliberately not a flag on the runner: a boolean `$resuming` would have left
 * `retry` to invent a third convention, and the lock purpose and the agent
 * decision would have been read from two different places.
 */
final class RunIntent
{
    /**
     * What the lock records a fresh run as.
     */
    public const string RUN = 'run';

    /**
     * What the lock records a continued run as.
     */
    public const string RESUME = 'resume';

    /**
     * What the lock records a single-task retry as.
     */
    public const string RETRY = 'retry';

    private function __construct(
        private readonly string $purpose,
        private readonly ?string $agent,
        private readonly ?TaskId $task,
        private readonly bool $continuesARun,
    ) {
    }

    /**
     * `ralph run`: the checklist's pending tasks, within the configured limits.
     *
     * @param string|null $agent the name `--agent` gave, which overrides the
     *        project default for this run only
     */
    public static function fresh(?string $agent = null): self
    {
        return new self(self::RUN, $agent, null, false);
    }

    /**
     * `ralph resume`: continue the run that was interrupted.
     *
     * @param string|null $agent the name `--agent` gave, which a resume may
     *        only use to confirm the agent the interrupted run recorded
     */
    public static function resumed(?string $agent = null): self
    {
        return new self(self::RESUME, $agent, null, true);
    }

    /**
     * `ralph retry TASK-ID`: one task, with its history kept.
     *
     * A fresh run row rather than a continuation: the point of retrying is to
     * grant the task another go at the configured limits, and inheriting the
     * budget of the run that used them up would refuse the task before it
     * started.
     */
    public static function retrying(TaskId $task, ?string $agent = null): self
    {
        return new self(self::RETRY, $agent, $task, false);
    }

    /**
     * What the repository lock records this as.
     */
    public function purpose(): string
    {
        return $this->purpose;
    }

    /**
     * The agent named on the command line, when one was.
     */
    public function agent(): ?string
    {
        return $this->agent;
    }

    /**
     * The single task this is about, for a retry.
     */
    public function task(): ?TaskId
    {
        return $this->task;
    }

    /**
     * Whether this continues an earlier run's row, counters and agent.
     */
    public function continuesARun(): bool
    {
        return $this->continuesARun;
    }

    /**
     * Whether only the named task is taken on.
     */
    public function isRetry(): bool
    {
        return $this->task !== null;
    }

    /**
     * One line for a log or a terminal.
     */
    public function describe(): string
    {
        return match (true) {
            $this->task !== null => sprintf('retrying %s', $this->task->value()),
            $this->continuesARun => 'resuming the interrupted run',
            default => 'processing pending tasks',
        };
    }
}
