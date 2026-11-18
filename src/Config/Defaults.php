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
 * Defaults are deliberately conservative: nothing outside the project's own
 * files is touched and no agent credential is assumed to exist.
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
     * Directory the runner owns: state, logs and memory.
     *
     * Its contents are generated and git-ignored, so a configured task
     * checklist or context file must not live here.
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
     * SQLite database holding task, attempt and recovery records.
     *
     * Deliberately not a configurable setting. It is the file that says what
     * has been done to which task, and a project that could point two
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
     * Whether a task's own log files are removed once the task has succeeded.
     *
     * Off, so logs are kept. A log is the only account of what an agent did
     * that survives the agent, and the moment it is most wanted is usually
     * after the run that wrote it looked fine: a commit that passed the checks
     * and still did the wrong thing is read backwards, from the ticked box to
     * the output that produced it. Deleting that by default would make the
     * package quietly lossy about its own work.
     *
     * What turning it on is for is the opposite case, and a real one: a long
     * checklist on a project whose suite is loud leaves a runtime directory
     * measured in hundreds of megabytes, nearly all of it the successful
     * tasks nobody will reread. A project that would rather keep only the
     * output of the passes that went wrong sets `logs.wipe_on_success` to
     * true, and keeps everything else — the commit, the state, the completion
     * evidence — because only the log files of a *successful* task are
     * eligible.
     *
     * @see \Celyes\RalphPhp\Config\LogSettings for the section this belongs to
     */
    public const bool WIPE_LOGS_ON_SUCCESS = false;

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
            'limits' => [
                'iterations' => self::ITERATIONS,
                'attempts_per_task' => self::ATTEMPTS_PER_TASK,
                'task_timeout' => self::TASK_TIMEOUT_SECONDS,
                'run_timeout' => self::RUN_TIMEOUT_SECONDS,
                'bail_on_failure' => self::BAIL_ON_FAILURE,
            ],
            'logs' => [
                'wipe_on_success' => self::WIPE_LOGS_ON_SUCCESS,
            ],
        ];
    }

    private function __construct()
    {
    }
}
