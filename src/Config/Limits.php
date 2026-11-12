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
        private readonly bool $bailOnFailure = Defaults::BAIL_ON_FAILURE,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys([
            'iterations',
            'attempts_per_task',
            'task_timeout',
            'run_timeout',
            'bail_on_failure',
        ]);

        return new self(
            $raw->int('iterations', Defaults::ITERATIONS),
            $raw->int('attempts_per_task', Defaults::ATTEMPTS_PER_TASK),
            $raw->int('task_timeout', Defaults::TASK_TIMEOUT_SECONDS),
            $raw->int('run_timeout', Defaults::RUN_TIMEOUT_SECONDS),
            $raw->bool('bail_on_failure', Defaults::BAIL_ON_FAILURE),
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
     * Whether a task the run gave up on ends the run instead of being passed
     * over.
     *
     * Off by default, because the tasks after a failed one are independent work
     * (PROJECT.md section 2). On, the loop stops at the first task it gives up
     * on and leaves the rest untouched and pending — the failure is reported
     * while its workspace and logs are the newest thing in the project, and a
     * checklist whose later tasks build on an earlier one does not run six more
     * agents against a foundation that is not there.
     *
     * A run may turn it on for itself with `run --bail`, or off with
     * `--no-bail`; neither changes the file ({@see \Celyes\RalphPhp\Run\RunIntent::bails()}).
     *
     * This is not about a pass that is *waiting* on somebody — a blocked or
     * interrupted pass stops the run whatever this says
     * ({@see \Celyes\RalphPhp\Run\TaskResult::stopsTheRun()}).
     */
    public function bailsOnFailure(): bool
    {
        return $this->bailOnFailure;
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
            'bail_on_failure' => $this->bailOnFailure,
        ];
    }
}
