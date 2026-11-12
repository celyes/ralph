<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\StopKind;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;

/**
 * Why a run ended, and what the person reading that is expected to do.
 *
 * PROJECT.md section H, item 9 asks the loop to stop on unresolved decisions,
 * exhausted attempts and ambiguous ownership "with a readable reason and
 * documented next action". {@see Blocker} is the same promise kept for one
 * pass; this is it kept for the run, and the two are separate because they
 * answer different questions. A blocker says what happened to a task. This says
 * what happened to the run, which a user who has just watched twelve tasks go
 * by cannot reconstruct from the last line of the twelfth.
 *
 * **A reason without an action is where this kind of tool loses people.** "the
 * iteration limit of 5 was reached" is true and leaves a user guessing whether
 * to raise a setting, run the command again, or go and look for a problem. So
 * both halves are required, and a stop cannot be built without them.
 *
 * **Every ending gets one, not only the unhappy ones.** A run that worked
 * through the checklist has a next action too — the checklist is empty, add to
 * it — and having one shape for all six endings is what lets the summary line,
 * the run's row and (section I) the JSON all be written once rather than per
 * case.
 *
 * **It reports; it does not decide.** Nothing is read back off this to drive
 * behaviour. {@see StopKind} is the vocabulary a status or an exit code will be
 * chosen from, and both halves of the text are persisted on the run's row, so
 * what `ralph status` says about a run that ended last night is what the user
 * was told at the time rather than a fresh guess at it.
 */
final class RunStop
{
    /**
     * How much of either half is kept.
     *
     * {@see Blocker::LIMIT}, and for the same reason: this is read in a
     * terminal and in a status table, and the run's row is not where a long
     * story belongs.
     */
    public const int LIMIT = Blocker::LIMIT;

    private function __construct(
        private readonly StopKind $kind,
        private readonly string $reason,
        private readonly string $nextAction,
        private readonly ?TaskId $task,
    ) {
    }

    /**
     * A stop with both halves given.
     *
     * @param TaskId|null $task the task the stop is about, when it is about one
     *
     * @throws InvalidArgumentException when either half says nothing
     */
    public static function of(StopKind $kind, string $reason, string $nextAction, ?TaskId $task = null): self
    {
        return new self(
            $kind,
            self::text($reason, 'reason'),
            self::text($nextAction, 'next action'),
            $task,
        );
    }

    /**
     * The checklist states no tasks at all.
     */
    public static function noTasks(string $tasksPath): self
    {
        return self::of(
            StopKind::Finished,
            sprintf('%s states no tasks', $tasksPath),
            sprintf('Write the work as `- [ ]` items in %s, then run `ralph run`.', $tasksPath),
        );
    }

    /**
     * Every item the checklist states is already checked.
     */
    public static function nothingPending(string $tasksPath): self
    {
        return self::of(
            StopKind::Finished,
            'there was nothing pending: every task in the checklist is already checked',
            sprintf('Add the next task to %s, then run `ralph run`.', $tasksPath),
        );
    }

    /**
     * Every pending task was taken on and every one of them passed.
     */
    public static function everythingCompleted(int $taken, string $tasksPath): self
    {
        return self::of(
            StopKind::Finished,
            sprintf('every pending task was completed (%d task%s)', $taken, $taken === 1 ? '' : 's'),
            sprintf(
                'Review the task branches, then add the next work to %s and run `ralph run`.',
                $tasksPath,
            ),
        );
    }

    /**
     * The loop reached the end of the checklist with work it did not finish.
     *
     * The run was not stopped by this — a failed task leaves the tasks after it
     * free to be taken on, which is {@see TaskResult::stopsTheRun()} — so what
     * is being reported is where the run *left* things. The kind is
     * {@see StopKind::Attempts} when at least one of those tasks had its
     * attempt budget spent on it, because that is section H, item 9's
     * "exhausted attempts" and it is the case where another `ralph run` on its
     * own changes nothing.
     *
     * @param list<TaskResult> $unfinished the tasks that did not pass, in the
     *        order they were taken on
     * @param int              $taken      how many tasks the run took on in total
     * @param int              $attemptLimit the configured attempts per task,
     *        which is what decides whether a task was given up on or merely
     *        failed
     *
     * @throws InvalidArgumentException when nothing was left unfinished, which
     *         is {@see self::everythingCompleted()} and not this
     */
    public static function unfinished(array $unfinished, int $taken, int $attemptLimit): self
    {
        $spent = array_values(array_filter(
            $unfinished,
            static fn (TaskResult $result): bool => $result->attempts() >= $attemptLimit,
        ));

        $pass = $spent[0] ?? $unfinished[0]
            ?? throw new InvalidArgumentException('A run with nothing unfinished did not stop short of anything.');
        $id = $pass->taskId();

        $reason = sprintf(
            'every pending task was taken on; %d of %d did not pass%s',
            count($unfinished),
            $taken,
            $spent === []
                ? ''
                : sprintf(
                    ' and %s ran out of attempts',
                    count($spent) === 1 ? $spent[0]->taskId()->value() : sprintf('%d of them', count($spent)),
                ),
        );

        // The pass's own next action, where it recorded one. A pass that gave
        // up on a task knows why it gave up — a failing check, a branch it
        // would not touch, a spent attempt limit — and each of those wants
        // something different done about it. Writing one sentence here for all
        // of them would mean telling a user to retry a task whose branch is the
        // problem.
        $action = $pass->blocker()?->nextAction() ?? sprintf(
            'Read `ralph logs %s` for what the pass did, then run `ralph retry %s`.',
            $id->value(),
            $id->value(),
        );

        return self::of(
            $spent === [] ? StopKind::Finished : StopKind::Attempts,
            $reason,
            count($unfinished) === 1 ? $action : sprintf('%s: %s', $id->value(), $action),
            $id,
        );
    }

    /**
     * A task was given up on and the run was asked not to carry on past that.
     *
     * The ending `limits.bail_on_failure` and `run --bail` exist for. Nothing
     * about the pass itself differs from a failure the loop would have walked
     * past — the task is unchecked, retriable and holds its own evidence — so
     * the pass's own next action is what a user is given, with the tasks the
     * run did not take on named afterwards, because those are untouched and
     * still pending rather than failed.
     *
     * {@see StopKind::Failed} rather than {@see StopKind::Attempts}: a bail can
     * happen on a task's first attempt, and reporting spent attempts that were
     * never spent would send a user to raise a limit that is not the problem.
     *
     * @param int $pending tasks the run never took on
     */
    public static function bailed(TaskResult $result, int $pending): self
    {
        $id = $result->taskId();
        $action = $result->blocker()?->nextAction() ?? sprintf(
            'Read `ralph logs %s` for what the pass did, then run `ralph retry %s`.',
            $id->value(),
            $id->value(),
        );

        return self::of(
            StopKind::Failed,
            sprintf(
                '%s did not pass and the run was asked to stop at the first failure%s',
                $id->value(),
                $pending < 1 ? '' : sprintf(', with %d task%s untouched', $pending, $pending === 1 ? '' : 's'),
            ),
            $pending < 1
                ? $action
                : sprintf(
                    '%s The untouched tasks are still pending: run `ralph run` to carry on with them, '
                    . 'or `--no-bail` to work through failures in one run.',
                    $action,
                ),
            $id,
        );
    }

    /**
     * A pass is waiting on a person, so the loop stopped.
     *
     * Section H, item 9's "unresolved decisions", and its "ambiguous ownership"
     * for anything found while a task was being worked on. Both halves are the
     * pass's own blocker read back rather than described again: the pass is
     * where the judgement was made, and a run-level paraphrase of it is one
     * more thing that can disagree with the row.
     */
    public static function decision(TaskResult $result): self
    {
        $blocker = $result->blocker();
        $id = $result->taskId();

        return self::of(
            StopKind::Decision,
            sprintf(
                '%s needs human input: %s',
                $id->value(),
                $blocker?->label() ?? 'the pass stopped and recorded no reason',
            ),
            $blocker?->nextAction() ?? sprintf(
                'Read `ralph logs %s`, resolve what it is waiting for, then run `ralph retry %s`.',
                $id->value(),
                $id->value(),
            ),
            $id,
        );
    }

    /**
     * A resume could not put an interrupted pass back in step, so it took
     * nothing on.
     *
     * The same kind of ending as {@see self::decision()} and for the same
     * reason — somebody has to decide something — but found before the first
     * task rather than during one. Kept as its own factory because the
     * reconciliation names the pass rather than the task, and because what a
     * user is owed here is the reading that stopped it: a worktree they still
     * have, a branch something else moved, a commit nothing claimed.
     */
    public static function unreconciled(Recovery $recovery): self
    {
        $blocker = $recovery->blocker();

        return self::of(
            StopKind::Decision,
            sprintf(
                '%s could not be reconciled: %s',
                $recovery->record()->slug(),
                $blocker?->label() ?? 'the reconciliation stopped and recorded no reason',
            ),
            $recovery->nextAction(),
            $recovery->record()->task(),
        );
    }

    /**
     * The run's own budget is spent.
     *
     * @param int $pending tasks the run never took on, which are untouched and
     *        still pending
     */
    public static function limitReached(RunBudget $budget, int $pending): self
    {
        return self::of(
            StopKind::Limit,
            sprintf(
                '%s with %d task%s still pending',
                $budget->exhausted() ?? 'the run stopped',
                $pending,
                $pending === 1 ? '' : 's',
            ),
            $pending < 1
                ? 'Nothing was left undone: the limit and the checklist ran out together.'
                : sprintf(
                    'The untaken tasks were not started and are still pending: run `ralph run` again '
                    . 'to carry on, or raise `%s` to get further in one run.',
                    $budget->spentSetting() ?? 'limits.iterations',
                ),
        );
    }

    /**
     * The run was stopped from outside.
     *
     * @param Interruption|null $interruption the signal that asked for it, when
     *        the package saw one
     * @param int|null          $pending      tasks left for a resume to pick
     *        up, or null when the stop surfaced somewhere the loop was not
     *        counting them. Left unsaid rather than guessed at: "with 0 tasks
     *        still pending" would read as a finished checklist.
     */
    public static function interrupted(?Interruption $interruption, ?int $pending = null): self
    {
        return self::of(
            StopKind::Interrupted,
            sprintf(
                '%s%s',
                $interruption === null ? 'the run was stopped' : $interruption->reason(),
                $pending === null
                    ? ''
                    : sprintf(', with %d task%s still pending', $pending, $pending === 1 ? '' : 's'),
            ),
            'Run `ralph resume` to reconcile the stopped pass with the repository and carry on with '
            . 'the agent this run used; nothing is implemented or committed twice.',
        );
    }

    /**
     * The run could not carry on, or stopped on something a person must settle.
     *
     * @param list<string> $hints the failure's own next actions, which are
     *        where ambiguous ownership found during setup says what to do about
     *        itself — a branch owned by unrelated work, a checkout with changes
     *        that are not the run's to touch
     * @param StopKind     $kind  which of the two it was.
     *        {@see StopKind::Failed} by default, since most failures are; a
     *        refusal raised after an agent has run is
     *        {@see StopKind::Decision}, because the work exists and what is
     *        missing is a judgement. The caller decides, from the failure's own
     *        exit status ({@see \Celyes\RalphPhp\Console\ExitCode::stopKindFor()}),
     *        rather than this guessing from the reason text.
     */
    public static function failure(string $reason, array $hints = [], StopKind $kind = StopKind::Failed): self
    {
        $action = trim(implode(' ', array_filter(
            $hints,
            static fn (string $hint): bool => trim($hint) !== '',
        )));

        return self::of(
            $kind,
            $reason,
            $action === ''
                ? 'Read the failure above and the run\'s logs under `.ralph/runtime/logs/`; nothing '
                . 'the run had already recorded was undone.'
                : $action,
        );
    }

    public function kind(): StopKind
    {
        return $this->kind;
    }

    /**
     * Why the run ended.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * What the user is expected to do about it.
     */
    public function nextAction(): string
    {
        return $this->nextAction;
    }

    /**
     * The task the stop is about, when it is about one.
     */
    public function task(): ?TaskId
    {
        return $this->task;
    }

    /**
     * Whether something is waiting for a person.
     */
    public function needsAttention(): bool
    {
        return $this->kind->needsAttention();
    }

    /**
     * The reason alone, for the end of a summary line that already says the
     * rest.
     */
    public function describe(): string
    {
        return $this->reason;
    }

    /**
     * Both halves, for a log or a caller with one line to spend.
     */
    public function describeFully(): string
    {
        return sprintf('%s — next: %s', $this->reason, $this->nextAction);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'reason' => $this->reason,
            'next_action' => $this->nextAction,
            'task' => $this->task?->value(),
            'needs_attention' => $this->needsAttention(),
        ];
    }

    /**
     * @throws InvalidArgumentException when the text says nothing
     */
    private static function text(string $value, string $what): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('A run that stopped must record its %s.', $what));
        }

        return mb_strlen($trimmed) <= self::LIMIT ? $trimmed : mb_substr($trimmed, 0, self::LIMIT) . '…';
    }
}
