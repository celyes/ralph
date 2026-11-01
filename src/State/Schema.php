<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Tasks\Completion;
use InvalidArgumentException;

/**
 * The words the state store is allowed to write down.
 *
 * Every column that holds one value out of a fixed set gets a `CHECK`
 * constraint built from one of these lists, and {@see Migrations} renders them
 * into the DDL rather than repeating the strings. The point is that there is
 * one place to read to find out what a column can contain, and that a typo in
 * application code is refused by the database instead of being stored and
 * puzzled over three runs later.
 *
 * Some of these lists mirror a PHP enum that already exists
 * ({@see LifecycleState}, {@see Phase}, {@see Completion},
 * {@see PushState}, {@see AgentSource}); those are built from the enum's cases
 * so the two cannot drift. The rest — run states, attempt outcomes, reference
 * and checklist vocabularies — are named here first because the schema needs
 * them before the types that will own them are written, and those types are
 * expected to adopt exactly these spellings.
 *
 * The list of {@see self::TABLES} is part of the contract too: it is what
 * bootstrap and the tests check a database against to decide it is a Ralph PHP
 * state store and not some other SQLite file that happens to sit at that path.
 */
final class Schema
{
    /**
     * How a run ended.
     *
     * @var list<string>
     */
    public const array RUN_STATES = ['running', 'completed', 'interrupted', 'failed'];

    /**
     * What the agent came back with, normalised across adapters.
     *
     * The three outcomes PROJECT.md section E, item 5 requires be told apart —
     * a candidate implementation, a request for human input, an execution
     * error — plus the two ways the runner itself ends an attempt.
     *
     * @var list<string>
     */
    public const array ATTEMPT_OUTCOMES = ['candidate', 'blocked', 'error', 'timed_out', 'cancelled'];

    /**
     * How one verification command finished.
     *
     * `not_run` exists because the checks are a sequence: when one fails the
     * rest are skipped, and a skipped check must not be stored as a passing
     * one.
     *
     * @var list<string>
     */
    public const array CHECK_OUTCOMES = ['passed', 'failed', 'timed_out', 'not_run'];

    /**
     * The kinds of Git fact worth recording.
     *
     * @var list<string>
     */
    public const array GIT_REFERENCE_KINDS = ['base', 'branch', 'commit', 'worktree', 'push'];

    /**
     * What happened to a recorded Git reference.
     *
     * @var list<string>
     */
    public const array GIT_REFERENCE_OUTCOMES = [
        'recorded',
        'created',
        'reused',
        'published',
        'rejected',
        'failed',
        'removed',
    ];

    /**
     * The marker a checklist write is aiming for.
     *
     * @var list<string>
     */
    public const array CHECKLIST_MARKERS = ['checked', 'unchecked'];

    /**
     * Where a planned checklist write stands.
     *
     * `pending` is the state that makes a failed checkbox write recoverable:
     * the implementation and the commit are already safe, so resume has only
     * the file to write. `superseded` covers a write that a later generation
     * replaced before it was applied.
     *
     * @var list<string>
     */
    public const array CHECKLIST_UPDATE_STATES = ['pending', 'applied', 'failed', 'superseded'];

    /**
     * The tables a complete state store has, in dependency order.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'schema_migrations',
        'runs',
        'tasks',
        'generations',
        'attempts',
        'verification_results',
        'git_references',
        'checklist_snapshots',
        'checklist_updates',
    ];

    /**
     * The ledger table, which is not itself migrated.
     *
     * It has to exist before the first migration can be recorded, so
     * {@see Migrator} creates it directly. Its shape is therefore fixed for
     * good: a later version can add tables, but it can only ever read this one
     * the way it was first written.
     */
    public const string LEDGER = 'schema_migrations';

    /**
     * Where a task stands in a run.
     *
     * The seven states of PROJECT.md section D, item 2, in the order a pass
     * moves through them. Built from {@see LifecycleState} so the column cannot
     * hold a value the enum does not have, and the enum cannot name a state the
     * column would refuse.
     *
     * Stored on an execution generation rather than on the task, because a
     * reopened task has a finished pass and a running one at the same time and
     * a single column could not say both. A task's own state is its latest
     * generation's, or `pending` when it has never been run.
     *
     * @return list<string>
     */
    public static function states(): array
    {
        return LifecycleState::values();
    }

    /**
     * The states a pass can still be picked up from, so it is continued rather
     * than superseded.
     *
     * The complement of {@see self::closedStates()}, and the definition behind
     * the one-open-pass-per-task index in {@see Migrations}: it is what
     * `GenerationHistory` means by an open generation.
     *
     * @return list<string>
     */
    public static function openStates(): array
    {
        return LifecycleState::openValues();
    }

    /**
     * The states a pass does not come back from.
     *
     * `completed` in the strict sense the package puts on ticking a box, and
     * `skipped` for a pass the runner deliberately did not carry out.
     *
     * @return list<string>
     */
    public static function closedStates(): array
    {
        return LifecycleState::closedValues();
    }

    /**
     * What a running task is in the middle of.
     *
     * The six phases of PROJECT.md section D, item 3. Built from {@see Phase}
     * so the column cannot hold a phase the enum does not have, and the enum
     * cannot name one the column would refuse.
     *
     * Recorded so that an interrupted run can be resumed from where it stopped
     * instead of from the beginning: the difference between `executing` and
     * `publishing` is the difference between invoking the agent again and only
     * retrying a push. The column is nullable because a pass that has not begun
     * and one that is finished with are not in the middle of anything;
     * {@see LifecycleState::recordsPhase()} is which states carry one.
     *
     * @return list<string>
     */
    public static function phases(): array
    {
        return Phase::values();
    }

    /**
     * Whether a pass's commit has been published.
     *
     * `not_required` is the default because pushing is opt-in. `failed` is kept
     * distinct from `pending` so that resume can retry only the publication,
     * without repeating the implementation or the commit.
     *
     * Built from {@see PushState} so the column cannot hold a value the enum
     * does not have.
     *
     * @return list<string>
     */
    public static function pushStates(): array
    {
        return PushState::values();
    }

    /**
     * Where a task's completion came from: the user's checklist or a verified
     * run.
     *
     * Built from {@see Completion} so the column cannot hold a value the enum
     * does not have.
     *
     * @return list<string>
     */
    public static function completions(): array
    {
        return array_map(static fn (Completion $case): string => $case->value, Completion::cases());
    }

    /**
     * How the run's agent was chosen.
     *
     * @return list<string>
     */
    public static function agentSources(): array
    {
        return array_map(static fn (AgentSource $case): string => $case->value, AgentSource::cases());
    }

    /**
     * The schema version this build of the package writes.
     */
    public static function version(): int
    {
        return Migrations::latestVersion();
    }

    /**
     * Whether a state is one a pass can be continued from.
     *
     * Takes the stored spelling rather than a {@see LifecycleState}, for the
     * callers that are reading rows: a query returns a string, and this answers
     * the open/closed question without first having to decide what to do about
     * a value the enum cannot read.
     */
    public static function isOpen(string $state): bool
    {
        return in_array($state, self::openStates(), true);
    }

    /**
     * A list of values as a SQL `IN` list of quoted literals.
     *
     * The vocabularies are package-defined, never user input, so quoting is
     * about producing valid SQL rather than about safety; the guard below is
     * there so that a value added carelessly later fails loudly here instead of
     * silently widening a constraint.
     *
     * @param list<string> $values
     */
    public static function literals(array $values): string
    {
        return implode(', ', array_map(
            static function (string $value): string {
                if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'A schema vocabulary value must be a lowercase identifier; "%s" is not.',
                        $value,
                    ));
                }

                return "'" . $value . "'";
            },
            $values,
        ));
    }

    private function __construct()
    {
    }
}
