<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

/**
 * Which kind of thing ended a run.
 *
 * PROJECT.md section H, item 9 names three situations the loop must stop in —
 * an unresolved decision, exhausted attempts, ambiguous ownership — and the
 * loop has three more ways of ending that are not stops at all: it ran out of
 * work, it ran out of budget, or somebody pressed Ctrl+C. All six are recorded
 * the same way — {@see \Celyes\RalphPhp\Run\RunStop} is the record — because
 * all six leave a user with the same question, and the kind is what separates
 * "there is nothing left to do" from "something is waiting for you".
 *
 * **Ambiguous ownership has no case of its own, deliberately.** A branch the
 * runner will not touch, a commit no pass recorded, a worktree registered
 * somewhere the configuration does not place it: each of those reaches the loop
 * as a pass that moved to `blocked` with the runner named as the reporter, or
 * as a setup failure that never got as far as a task. The first is
 * {@see self::Decision} and the second is {@see self::Failed}, and in both the
 * blocker says which kind of ambiguity it was — inventing a seventh case here
 * would mean the loop classifying a refusal it did not make.
 *
 * Nothing branches on this yet beyond what is printed. Section I, item 5 is
 * where consistent exit codes are defined, and this is the vocabulary they will
 * be defined over: a run that stopped on a decision and a run that finished its
 * checklist must not exit alike.
 */
enum StopKind: string
{
    /**
     * The loop ran out of pending work.
     *
     * Every task the checklist stated was taken on, or there was nothing to
     * take on. Not necessarily a clean run: a task whose checks failed without
     * spending its attempts ends here too, which is why
     * {@see self::needsAttention()} is not the opposite of this case.
     */
    case Finished = 'finished';

    /**
     * A pass is waiting on a person, and the loop stopped rather than hand the
     * next task to an agent that has asked a question.
     *
     * Section H, item 9's "unresolved decisions", and its "ambiguous ownership"
     * whenever the ambiguity was found while a task was being worked on. The
     * reason and the next action come from the pass's own blocker, so what is
     * reported is what was recorded rather than a second description of it.
     */
    case Decision = 'decision';

    /**
     * At least one task ran out of attempts and was passed over unchecked.
     *
     * Section H, item 9's "exhausted attempts". The run itself carried on — the
     * tasks after a failed one are independent work (PROJECT.md section 2), and
     * {@see \Celyes\RalphPhp\Run\TaskResult::stopsTheRun()} is where that is decided — so what this
     * marks is a run that reached the end of the checklist with work the loop
     * had given up on. Reporting it as an ordinary finish would leave the user
     * to notice the unchecked boxes themselves.
     */
    case Attempts = 'attempts';

    /**
     * The run's own budget is spent: iterations, or total active time.
     *
     * Section H, item 2's two run-level limits. The untaken tasks were not
     * started and are still pending, which is the difference between this and
     * {@see self::Attempts}.
     */
    case Limit = 'limit';

    /**
     * The run was stopped from outside.
     *
     * Ctrl+C, or anything else that asked the process to stop (section H,
     * item 4). The pass that was under way gave way at a phase boundary and
     * kept its workspace; what follows is a resume, not a new run.
     */
    case Interrupted = 'interrupted';

    /**
     * The run could not carry on at all.
     *
     * A setup step that failed, or an outcome that could not be recorded. The
     * run's row is closed with this so that a resume can tell a run that died
     * from one that is still going, and the failure's own hints are the next
     * action.
     */
    case Failed = 'failed';

    /**
     * Every kind, as the strings the `stop_kind` column may hold.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether something is waiting for a person before the work can go further.
     *
     * True for the three section H, item 9 situations and false for the three
     * ordinary endings. A `Limit` stop is deliberately false: the run did
     * exactly what it was configured to do, and running it again is the whole
     * of the answer.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Decision, self::Attempts, self::Failed => true,
            self::Finished, self::Limit, self::Interrupted => false,
        };
    }

    /**
     * How the kind reads in a report.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Finished => 'the checklist was worked through',
            self::Decision => 'a task is waiting on a person',
            self::Attempts => 'a task ran out of attempts',
            self::Limit => 'the run reached a configured limit',
            self::Interrupted => 'the run was stopped',
            self::Failed => 'the run could not carry on',
        };
    }
}
