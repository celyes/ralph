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
     * Append-only stream of what each run did, one JSON object per line.
     *
     * Deliberately not a configurable setting, and deliberately not under
     * `logs/`: the logs directory holds a copy of what other processes printed
     * and is organised by task, while this is one file, written by this package
     * only, that a supervising process can follow for the whole project
     * ({@see \Celyes\RalphPhp\Event\EventStream}).
     */
    public const string EVENTS_FILE = self::RUNTIME_DIRECTORY . '/events.jsonl';

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
     * Whether a task the run gives up on ends the run.
     *
     * Off, because the first release assumes tasks are independent work
     * (PROJECT.md section 2): the task after a failed one usually has nothing
     * to do with it, and stopping by default would turn one bad task into a
     * checklist that never gets worked through. Projects whose tasks build on
     * each other, and anyone who would rather look at a failure while it is
     * fresh than find six of them in the morning, turn it on.
     */
    public const bool BAIL_ON_FAILURE = false;

    /**
     * Seconds a single verification command may run.
     */
    public const int CHECK_TIMEOUT_SECONDS = 600;

    /**
     * Seconds a single workspace preparation command may run.
     *
     * Longer than a verification command, and for a different kind of work: an
     * install in a new worktree has a cold Composer cache and goes to the
     * network, where a check is the project's own suite on dependencies that
     * are already there.
     */
    public const int PREPARE_TIMEOUT_SECONDS = 900;

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
     * Verification commands assumed for a project: none.
     *
     * Empty, and deliberately. The package used to assume `composer test`,
     * which made a project that does not define that script unusable until its
     * owner either wrote one or edited the configuration — a requirement
     * Ralph PHP invented rather than one its job needs. Only the project knows
     * how it is verified: it may be `composer test`, `vendor/bin/phpunit`,
     * `make check`, a script with a flag, or several commands in order.
     *
     * What the empty default costs is stated wherever it is reached rather than
     * only here, because it is a real cost: a run with no checks has nothing to
     * decide a task on but the agent's own result and the commit it produced,
     * so {@see \Celyes\RalphPhp\Preflight\Check\VerificationCheck} warns
     * about it, `ralph run` says so in its opening lines, and a task's
     * completion record names it. Adding one check under `checks` is what turns
     * that back into evidence.
     *
     * @return list<array<string, mixed>>
     */
    public static function checks(): array
    {
        return [];
    }

    /**
     * Commands assumed to prepare a new task worktree: none.
     *
     * Empty, for the reason {@see self::checks()} is. A linked worktree holds
     * the repository's tracked files and nothing else, so a PHP project whose
     * checks need `vendor/` does want `composer install` here — but that is a
     * fact about the project, not about Ralph PHP, and assuming it made the
     * package refuse to start anywhere there was no `composer.json` and run a
     * minutes-long install per task everywhere there was.
     *
     * A project says what its worktrees need by listing it; a project whose
     * checkout is usable as Git leaves it says nothing and nothing is run.
     *
     * @return list<array<string, mixed>>
     */
    public static function prepare(): array
    {
        return [];
    }

    /**
     * Files copied into a new task worktree from the controlling checkout.
     *
     * Empty: a copied file is one that exists on this machine and in no commit,
     * so only the project knows whether it has any, and guessing at `.env`
     * would make a run depend on a file the package invented a need for.
     *
     * @return list<string>
     */
    public static function copies(): array
    {
        return [];
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
            'workspace' => [
                'prepare' => self::prepare(),
                'copy' => self::copies(),
            ],
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
                'bail_on_failure' => self::BAIL_ON_FAILURE,
            ],
        ];
    }

    private function __construct()
    {
    }
}
