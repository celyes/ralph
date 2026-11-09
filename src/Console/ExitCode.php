<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\State\StopKind;

/**
 * The exit statuses `ralph` is allowed to end with, and what each one means.
 *
 * PROJECT.md section I, item 5 asks for consistent codes for success,
 * configuration failure, execution failure, limit exhaustion, blocking and
 * interruption. This is that set, settled: every command and every recognised
 * failure picks from here and nowhere else, and the statuses that were
 * provisional while the runner was being built — one shared "something needs a
 * person" code, a limit and a Ctrl+C that both exited 0 — are gone.
 *
 * **The code says what the command found, not what happened at some point in
 * the past.** For `run`, `resume` and `retry` that is how *that run* ended. For
 * `status` it is what the project is holding *now*. So a run that spent its
 * iteration budget exits {@see self::LIMIT}, while `ralph status` afterwards
 * exits {@see self::SUCCESS}: the budget was yesterday's event, and the
 * remaining pending tasks are indistinguishable from the pending tasks of a
 * project nobody has run yet.
 *
 * **Three of the nine mean "a person has to do something before this can go
 * further", and they are told apart on purpose.** {@see self::BLOCKED} is work
 * that exists and is waiting on a decision; {@see self::INTERRUPTED} is work
 * that exists and is waiting on `ralph resume`; {@see self::CONFIGURATION} is
 * work that never started. A caller that cannot tell those apart has to parse
 * the summary line to decide whether to resume, to page somebody, or to fix a
 * setting and try again — which is the whole reason this set exists.
 *
 * **The values follow convention rather than counting from one.** The 64–78
 * range is BSD sysexits, which is what shell callers and CI already read; 124
 * is what `timeout` exits with when a command outlives its budget; 130 is
 * 128 + SIGINT, which is what a shell reports for Ctrl+C whether or not this
 * package is involved. 1 is left for ordinary execution failure, where every
 * other tool puts it. Nothing is in the 2–63 range, and nothing above 130, so
 * a status in either is this package failing in a way it does not recognise.
 *
 * `bin/ralph` repeats {@see self::CONFIGURATION} as a literal for the
 * environment failures it detects before this class can be loaded;
 * {@see \Celyes\RalphPhp\Tests\DeclaredRequirementsTest} holds the two to each
 * other.
 */
final class ExitCode
{
    /**
     * The command did what it was asked.
     *
     * For a run, that means the loop worked through the pending tasks it took
     * on and ended on its own terms. Not a promise that every task passed — a
     * task whose checks failed without spending its attempts leaves the loop
     * with work to do and is reported as {@see self::EXECUTION} — but a promise
     * that nothing is waiting for a person.
     */
    public const int SUCCESS = 0;

    /**
     * Work was attempted and did not succeed.
     *
     * A task the loop gave up on after spending its attempt limit, or a run
     * that could not carry on at all. The difference from {@see self::BLOCKED}
     * is who the next move belongs to: this is work that failed on its own
     * terms and can be attempted again (`ralph retry TASK-ID`), not work
     * waiting on a judgement nobody but the user can make.
     *
     * 1, where every other command-line tool puts "the thing you asked for
     * failed", rather than a sysexits value — none of them describes a run
     * whose tasks did not pass, and borrowing the nearest would make the
     * commonest failure the obscurest code.
     */
    public const int EXECUTION = 1;

    /**
     * The command line was wrong: unknown command, unknown option, missing
     * option value. Nothing was executed.
     */
    public const int USAGE = 64;

    /**
     * A documented command is not implemented in this build.
     */
    public const int UNAVAILABLE = 69;

    /**
     * An unexpected internal error. Reported with a stack trace in verbose mode
     * and treated as a bug in Ralph PHP.
     */
    public const int SOFTWARE = 70;

    /**
     * The command stopped with something waiting for a person to decide, and
     * the work it had already done is preserved.
     *
     * The blocking status of section I, item 5. It covers a task whose agent
     * asked a question, and every refusal the runner makes *after* work has
     * happened: a worker that moved commits or branches the package will not
     * put back, a checklist that was reworded mid-task, a checkbox write
     * overtaken by somebody's edit, a verified change that could not be staged,
     * committed or published.
     *
     * What those have in common — and what separates them from
     * {@see self::CONFIGURATION} — is that an agent has run and the result is
     * sitting in a worktree, an index or a branch. Nothing is undone, nothing
     * is retried on its own, and the next action is on the run's record and in
     * `ralph status`.
     *
     * Not a failure of the work: whatever was verified is committed and
     * recorded. It is a status of its own because a caller that cannot tell
     * "the checklist is done" from "the run stopped short" would have to read
     * prose to find out.
     *
     * The value is sysexits' "temporary failure; the user is invited to retry",
     * which is exactly what a block is: the command will get further once the
     * person has answered.
     */
    public const int BLOCKED = 75;

    /**
     * The command could not start, and nothing was executed on the user's
     * behalf.
     *
     * An unsupported runtime, a missing autoloader, invalid project
     * configuration, a checklist that cannot be read, a state store that cannot
     * be trusted, a repository another runner holds, a checkout with
     * uncommitted changes that are not the run's to touch, a branch unrelated
     * work owns, a workspace that could not be created or made usable, a check
     * command that is not installed on this machine.
     *
     * Broader than its sysexits name suggests, and deliberately so: what these
     * share is not that a setting is wrong but that the project or its
     * environment is not in a state the command can run in, and that the remedy
     * is to put that right and run the command again. No agent has run, so
     * there is no work to preserve and nothing to resume — which is the line
     * between this and {@see self::BLOCKED}.
     */
    public const int CONFIGURATION = 78;

    /**
     * A configured limit stopped the run before the work ran out.
     *
     * The iteration limit or the total run-time budget of section H, item 2.
     * The run did exactly what it was configured to do and the untaken tasks
     * were never started, so nothing is waiting for a person and running
     * `ralph run` again carries on from where it stopped — but it is not
     * {@see self::SUCCESS}, because a caller that chains something onto a run
     * must not treat "stopped half way through the checklist" as "the checklist
     * is done".
     *
     * 124 is what `timeout` exits with for a command that outlived its budget,
     * which is near enough the same event to be worth agreeing with.
     */
    public const int LIMIT = 124;

    /**
     * The run was stopped from outside: Ctrl+C, or anything else that asked the
     * process to stop.
     *
     * Section H, item 4. The pass gave way at a phase boundary, its workspace
     * and branch are preserved, and the next action is `ralph resume` — which
     * is why it is not {@see self::EXECUTION}: nothing failed, and nothing has
     * to be decided before the work can continue.
     *
     * 128 + SIGINT, the status a shell reports for an interrupted process. A
     * code of this package's own choosing would disagree with what the shell
     * says about the same Ctrl+C whenever the signal arrived before `ralph`
     * could handle it.
     */
    public const int INTERRUPTED = 130;

    /**
     * The exit status for a run that ended and reported itself.
     *
     * The one place a run's ending becomes a number. {@see StopKind} is the
     * vocabulary — six endings, recorded on the run's row — and reading it here
     * rather than counting task results is what keeps the status, the summary
     * line and the row from disagreeing: a run can finish every task it took on
     * and still be a run that stopped short.
     */
    public static function forStop(StopKind $kind): int
    {
        return match ($kind) {
            StopKind::Finished => self::SUCCESS,
            StopKind::Decision => self::BLOCKED,
            StopKind::Attempts, StopKind::Failed => self::EXECUTION,
            StopKind::Limit => self::LIMIT,
            StopKind::Interrupted => self::INTERRUPTED,
        };
    }

    /**
     * Which ending to record for a failure that is on its way out of a run.
     *
     * The other direction of {@see self::forStop()}, and it exists so that the
     * run's row and the process status cannot disagree. A refusal raised after
     * an agent has run — drift, a reworded checklist, a commit that could not
     * be made — exits {@see self::BLOCKED}, so the row it leaves behind has to
     * say `decision` rather than `failed`, or `ralph status` would afterwards
     * report as an execution failure the very thing the run called a block.
     *
     * Only the two statuses that name an ending map back. Everything else is a
     * run that could not carry on, including {@see self::SOFTWARE}: a bug is
     * not a kind of ending, and inventing a seventh {@see StopKind} for one
     * would put a case in the vocabulary that no report knows how to describe.
     */
    public static function stopKindFor(int $code): StopKind
    {
        return match ($code) {
            self::BLOCKED => StopKind::Decision,
            self::INTERRUPTED => StopKind::Interrupted,
            default => StopKind::Failed,
        };
    }

    /**
     * Every documented status, as code => what it means, in ascending order.
     *
     * For `ralph help`, and for the test that holds the documentation to the
     * constants. A status missing from here is a status nothing documents.
     *
     * @return array<int, string>
     */
    public static function documented(): array
    {
        return [
            self::SUCCESS => 'the command did what it was asked',
            self::EXECUTION => 'work was attempted and did not succeed',
            self::USAGE => 'the command line was wrong; nothing ran',
            self::UNAVAILABLE => 'the command is not implemented in this build',
            self::SOFTWARE => 'an unexpected internal error, which is a bug in Ralph PHP',
            self::BLOCKED => 'stopped with something waiting for a person; the work is preserved',
            self::CONFIGURATION => 'the project or its environment is not in a state to run in',
            self::LIMIT => 'a configured limit stopped the run before the work ran out',
            self::INTERRUPTED => 'the run was stopped from outside; `ralph resume` continues it',
        ];
    }

    /**
     * What a status means, in one line.
     *
     * Undocumented codes are said to be undocumented rather than guessed at;
     * the only way to see one is a bug in this package or a process killed by
     * something that never reached it.
     */
    public static function describe(int $code): string
    {
        return self::documented()[$code] ?? sprintf('status %d, which Ralph PHP does not document', $code);
    }

    private function __construct()
    {
    }
}
