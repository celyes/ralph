<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

use Celyes\RalphPhp\Ralph;

/**
 * The configuration Ralph PHP assumes when a setting is not given.
 *
 * One source of truth for two callers: the loader, which fills gaps in a
 * user's file, and `ralph init`, which writes the starting file. If the two
 * disagreed, an initialised project would behave differently from a project
 * whose file omits the same setting.
 *
 * Defaults are deliberately conservative: nothing is published and no agent
 * credential is assumed to exist.
 */
final class Defaults
{
    /**
     * Project configuration, relative to the repository root.
     */
    public const string CONFIG_FILE = Ralph::PROJECT_DIRECTORY . '/config.json';

    /**
     * Markdown checklist the runner reads pending work from.
     */
    public const string TASKS_FILE = Ralph::PROJECT_DIRECTORY . '/tasks.md';

    /**
     * User-maintained project instructions passed to every attempt.
     */
    public const string CONTEXT_FILE = Ralph::PROJECT_DIRECTORY . '/CONTEXT.md';

    /**
     * Directory the runner owns: state, logs, memory and task worktrees.
     *
     * Its contents are generated, git-ignored and removed by cleanup, so a
     * configured task checklist or context file must not live here.
     */
    public const string RUNTIME_DIRECTORY = Ralph::PROJECT_DIRECTORY . '/runtime';

    /**
     * Summaries and observations supplied to later attempts.
     */
    public const string MEMORY_DIRECTORY = self::RUNTIME_DIRECTORY . '/memory';

    /**
     * Worker and verification output, one set per task.
     */
    public const string LOGS_DIRECTORY = self::RUNTIME_DIRECTORY . '/logs';

    /**
     * Isolated task workspaces, one linked Git worktree per task.
     */
    public const string WORKTREES_DIRECTORY = self::RUNTIME_DIRECTORY . '/worktrees';

    /**
     * SQLite database holding task, attempt, Git and recovery records.
     *
     * Deliberately not a configurable setting. It is the file that says which
     * commit belongs to which task, and a project that could point two
     * configurations at two databases would be a project where reopening a
     * finished task cannot see that it was ever finished.
     */
    public const string STATE_FILE = self::RUNTIME_DIRECTORY . '/state.sqlite';

    /**
     * Optional PHP file registering adapters for agents the package does not
     * ship, read by {@see \Celyes\RalphPhp\Agent\AdapterRegistrationFile}.
     *
     * Deliberately not a configurable setting, for the same reason the state
     * file is not: it is code this package executes in its own process, and a
     * file that says which code a run loads belongs at one reviewable path
     * rather than wherever a setting points. Most projects have no such file.
     */
    public const string ADAPTERS_FILE = Ralph::PROJECT_DIRECTORY . '/adapters.php';

    /**
     * Agent used when `run --agent NAME` is not given.
     */
    public const string AGENT = 'codex';

    /**
     * Commit task branches start from. `HEAD` works in any repository,
     * whatever its default branch is called.
     */
    public const string BASE_REFERENCE = 'HEAD';

    /**
     * Prefix for generated task branch names.
     */
    public const string BRANCH_PREFIX = 'ralph/';

    /**
     * Pushing is opt-in: a loop that publishes by default would put unreviewed
     * work on a shared remote.
     */
    public const bool PUSH_ENABLED = false;

    /**
     * Remote used when pushing is enabled.
     */
    public const string PUSH_REMOTE = 'origin';

    /**
     * Tasks a single run may process.
     */
    public const int ITERATIONS = 10;

    /**
     * Attempts allowed per task before it is left failed.
     */
    public const int ATTEMPTS_PER_TASK = 3;

    /**
     * Seconds one task may spend in the agent before it is cancelled.
     */
    public const int TASK_TIMEOUT_SECONDS = 1800;

    /**
     * Seconds of active run time before the run stops at a task boundary.
     */
    public const int RUN_TIMEOUT_SECONDS = 7200;

    /**
     * Seconds a single verification command may run.
     */
    public const int CHECK_TIMEOUT_SECONDS = 600;

    /**
     * Built-in agent definitions.
     *
     * Both ship with the adapter and executable named after the agent and no
     * required environment variables: Codex and Claude Code can both be
     * authenticated by an interactive login, so demanding an API-key variable
     * would fail preflight for a working setup. Users list the variables their
     * own authentication needs.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function agents(): array
    {
        return [
            'codex' => [
                'adapter' => 'codex',
                'executable' => 'codex',
                'model' => null,
                'arguments' => [],
                'environment' => [],
            ],
            'claude' => [
                'adapter' => 'claude',
                'executable' => 'claude',
                'model' => null,
                'arguments' => [],
                'environment' => [],
            ],
        ];
    }

    /**
     * Verification commands assumed for a PHP package.
     *
     * `composer test` is the convention this package follows itself. Preflight
     * reports it if the project has no such script, which is a clearer failure
     * than a run that verifies nothing.
     *
     * @return list<array<string, mixed>>
     */
    public static function checks(): array
    {
        return [
            [
                'name' => 'tests',
                'command' => ['composer', 'test'],
                'timeout' => self::CHECK_TIMEOUT_SECONDS,
            ],
        ];
    }

    /**
     * The complete default configuration, in the file's own layout.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'tasks' => self::TASKS_FILE,
            'context' => self::CONTEXT_FILE,
            'agent' => self::AGENT,
            'agents' => self::agents(),
            'checks' => self::checks(),
            'git' => [
                'base' => self::BASE_REFERENCE,
                'branch_prefix' => self::BRANCH_PREFIX,
                'push' => [
                    'enabled' => self::PUSH_ENABLED,
                    'remote' => self::PUSH_REMOTE,
                ],
            ],
            'limits' => [
                'iterations' => self::ITERATIONS,
                'attempts_per_task' => self::ATTEMPTS_PER_TASK,
                'task_timeout' => self::TASK_TIMEOUT_SECONDS,
                'run_timeout' => self::RUN_TIMEOUT_SECONDS,
            ],
        ];
    }

    private function __construct()
    {
    }
}
