<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\State\AttemptOutcome;

/**
 * How an agent says which of the three things it did.
 *
 * PROJECT.md section E, item 5 asks that a candidate implementation, a request
 * for human input and an execution error be told apart, and that output which
 * is absent or malformed be refused rather than read as one of them. Telling
 * them apart needs the agent to say which it is, so this is the format it is
 * asked to say it in: one fenced block holding one JSON object.
 *
 * It is declared in one place because both halves of the protocol have to agree
 * and they are written in different files — {@see self::instructions()} goes
 * into the briefing every adapter sends, and {@see ReportReader} parses what
 * comes back. A format stated twice is a format that drifts, and the symptom
 * would be every attempt failing for not reporting in a form nothing asked for.
 *
 * **Why a format at all, rather than reading the agent's prose.** Section 4 is
 * explicit that a zero exit code does not finish a task, and a loop that
 * guessed from wording would be guessing about the one thing it must not get
 * wrong: whether to run verification and commit, stop and ask a person, or try
 * again. So the agent is asked for a token, not a sentence, and anything else
 * it printed stays its own account — the summary travels as
 * {@see \Celyes\RalphPhp\Support\UnverifiedText}, and the outcome it declares
 * is still only a claim that there is something worth verifying.
 *
 * **Why JSON.** Malformed has to be decidable. `json_decode` either reads an
 * object or it does not, which is what lets {@see ReportReader} refuse output
 * instead of salvaging it; a line-oriented format would have a dozen shapes
 * that are nearly right, and every one of them an invitation to guess.
 *
 * **Why a fence.** An agent's output is prose, progress and diffs, and the
 * report has to be findable in the middle of it. A fenced block with an info
 * string is something every coding agent already emits correctly, and the info
 * string is specific enough that nothing else in a transcript collides with it.
 *
 * The flags that make a particular CLI print it, and whatever that CLI's own
 * structured output says alongside, belong to each adapter (section E, items 6
 * and 7). This is the common part, which is what keeps section E, item 10's
 * normalisation from being three separate interpretations of the same three
 * words.
 */
final class ResultProtocol
{
    /**
     * The info string that marks the block.
     *
     * Named after the package so that a block of the agent's own JSON, or an
     * example out of some README, is not mistaken for a report.
     */
    public const string FENCE = 'ralph-result';

    /**
     * Which of the three cases this is: the one required key.
     */
    public const string OUTCOME = 'outcome';

    /**
     * The agent's own account of what it did, in its own words.
     */
    public const string SUMMARY = 'summary';

    /**
     * What a person has to decide, required when the outcome is blocked.
     */
    public const string NEXT_ACTION = 'next_action';

    /**
     * The outcomes an agent may declare, as they appear in the block.
     *
     * The three of item 5, and deliberately not all of
     * {@see AttemptOutcome}'s cases: a timeout and a cancellation are things
     * the package observed happening to the process, and an agent that could
     * declare them could report its own killing as an orderly stop.
     *
     * @return list<string>
     */
    public static function outcomes(): array
    {
        return array_map(
            static fn (AttemptOutcome $outcome): string => $outcome->value,
            self::declarable(),
        );
    }

    /**
     * The outcome a name stands for, or null when it names none of them.
     */
    public static function outcomeNamed(string $name): ?AttemptOutcome
    {
        foreach (self::declarable() as $outcome) {
            if ($outcome->value === $name) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * Whether an outcome is one an agent is allowed to declare.
     */
    public static function isDeclarable(AttemptOutcome $outcome): bool
    {
        return in_array($outcome, self::declarable(), true);
    }

    /**
     * The instructions, as a briefing section.
     *
     * Written to be read by an agent and intelligible to the user reading the
     * same briefing in a log. It says what each outcome commits the runner to
     * doing, because an agent that knows `blocked` stops the run will not
     * reach for it to mean "this was hard", and it says what happens without a
     * report, because that is the case an agent cannot discover by itself.
     */
    public static function instructions(): string
    {
        return implode("\n", [
            '# Reporting back',
            '',
            'Finish by printing one fenced block, as the last thing you print:',
            '',
            '```' . self::FENCE,
            self::example(AttemptOutcome::Candidate),
            '```',
            '',
            sprintf(
                '- `%s` — you changed something in the workspace and it is ready to be checked.'
                    . ' Ralph PHP then runs the project\'s verification commands itself and commits only'
                    . ' if they pass, so this claims there is work to check rather than that the task is done.',
                AttemptOutcome::Candidate->value,
            ),
            sprintf(
                '- `%s` — you need a decision only a person can make. Say what the decision is in'
                    . ' `%s` and what you need in `%s`:',
                AttemptOutcome::Blocked->value,
                self::SUMMARY,
                self::NEXT_ACTION,
            ),
            '  `' . self::example(AttemptOutcome::Blocked) . '`',
            '  The task is left unchecked and the run stops on it, so use it for a question'
                . ' and not for work that was merely difficult.',
            sprintf(
                '- `%s` — you could not do the work, and another attempt at it as it stands would'
                    . ' fail the same way. Put what you ran into in `%s`.',
                AttemptOutcome::Error->value,
                self::SUMMARY,
            ),
            '',
            'The block holds one JSON object and nothing else. An attempt whose output has no'
                . ' readable block is recorded as an execution error whatever its exit status, because'
                . ' a run cannot tell silence apart from a success it should commit. If more than one'
                . ' block is printed, the last one is read.',
        ]);
    }

    /**
     * A filled-in block for the instructions, in the order the keys are meant
     * to be written.
     *
     * Generated from the same constants the reader uses, so an example cannot
     * show a key that is no longer read.
     */
    public static function example(AttemptOutcome $outcome): string
    {
        $fields = [
            self::OUTCOME => $outcome->value,
            self::SUMMARY => match ($outcome) {
                AttemptOutcome::Blocked => 'the orders table has no column for this and adding one is a schema change',
                AttemptOutcome::Error => 'what you ran into, in a sentence or two',
                default => 'what you changed, in a sentence or two',
            },
        ];

        if ($outcome === AttemptOutcome::Blocked) {
            $fields[self::NEXT_ACTION] = 'decide whether a migration belongs in this task and say so in the task';
        }

        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<AttemptOutcome>
     */
    private static function declarable(): array
    {
        return [AttemptOutcome::Candidate, AttemptOutcome::Blocked, AttemptOutcome::Error];
    }

    private function __construct()
    {
    }
}
