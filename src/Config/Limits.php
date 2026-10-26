<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `limits` section: the bounds that make the loop stop on its own.
 *
 * Every limit exists because an agent loop can otherwise run indefinitely —
 * retrying a task it cannot finish, or working through a checklist long after
 * the user stopped watching.
 */
final class Limits
{
    public function __construct(
        private readonly int $iterations = Defaults::ITERATIONS,
        private readonly int $attemptsPerTask = Defaults::ATTEMPTS_PER_TASK,
        private readonly int $taskTimeout = Defaults::TASK_TIMEOUT_SECONDS,
        private readonly int $runTimeout = Defaults::RUN_TIMEOUT_SECONDS,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['iterations', 'attempts_per_task', 'task_timeout', 'run_timeout']);

        return new self(
            $raw->int('iterations', Defaults::ITERATIONS),
            $raw->int('attempts_per_task', Defaults::ATTEMPTS_PER_TASK),
            $raw->int('task_timeout', Defaults::TASK_TIMEOUT_SECONDS),
            $raw->int('run_timeout', Defaults::RUN_TIMEOUT_SECONDS),
        );
    }

    /**
     * Tasks a single run may process.
     */
    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * Attempts a task may consume before it is left failed. Counted across
     * resumes, so an interrupted run does not reset the budget.
     */
    public function attemptsPerTask(): int
    {
        return $this->attemptsPerTask;
    }

    /**
     * Seconds a single task may spend in the agent before it is cancelled.
     */
    public function taskTimeout(): int
    {
        return $this->taskTimeout;
    }

    /**
     * Seconds of active run time after which the run stops at a task boundary.
     */
    public function runTimeout(): int
    {
        return $this->runTimeout;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'iterations' => $this->iterations,
            'attempts_per_task' => $this->attemptsPerTask,
            'task_timeout' => $this->taskTimeout,
            'run_timeout' => $this->runTimeout,
        ];
    }
}
