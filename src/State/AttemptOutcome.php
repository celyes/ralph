<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * What one invocation of the agent came back with.
 *
 * The three results PROJECT.md section E, item 5 requires be told apart — a
 * candidate implementation, a request for human input, an execution error —
 * plus the two ways the runner itself ends an attempt. Both adapters normalise
 * onto these cases, which is what keeps verification and Git behaviour
 * independent of which agent ran (section E, item 10).
 *
 * **A candidate is not a result.** {@see self::Candidate} is the only case that
 * does not settle what happens to the pass: every other outcome decides the
 * attempt's state by itself, while a candidate's verdict comes from the
 * verification commands and the commit — PROJECT.md section 4 is explicit that
 * a zero exit code is not sufficient. {@see self::settles()} is where that
 * asymmetry is written down, and {@see AttemptStore} refuses a state an outcome
 * does not allow, so an agent that exits zero and says it is finished cannot
 * produce a `completed` row on its own say-so.
 */
enum AttemptOutcome: string
{
    /**
     * The agent says it has implemented something.
     *
     * A claim about the workspace, nothing more. What makes it true is the
     * verification run that follows.
     */
    case Candidate = 'candidate';

    /**
     * The agent asked for a human decision instead of implementing.
     *
     * Distinct from an error: nothing went wrong, and retrying with the same
     * inputs would ask the same question again.
     */
    case Blocked = 'blocked';

    /**
     * The invocation failed, or came back with nothing usable.
     *
     * Covers a non-zero exit, a missing result, and output the adapter could
     * not read — from the loop's point of view those are the same situation:
     * there is no candidate to verify and the attempt can be retried.
     */
    case Error = 'error';

    /**
     * The attempt ran past its configured execution limit and was stopped.
     *
     * Kept apart from {@see self::Error} because the remedy differs — a limit
     * can be raised, and a task that times out repeatedly is a task that is too
     * large rather than one whose agent is broken.
     */
    case TimedOut = 'timed_out';

    /**
     * The attempt was stopped from outside: Ctrl+C, or a run that ran out of
     * its total time budget.
     *
     * The work is not judged either way. Nothing was wrong with the attempt,
     * and nothing about it passed.
     */
    case Cancelled = 'cancelled';

    /**
     * The stored spellings, in the order they are declared.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Read the column back.
     *
     * @throws InvalidArgumentException when the value is not one this build
     *                                 writes
     */
    public static function fromStorage(string $value): self
    {
        $outcome = self::tryFrom($value);

        if ($outcome === null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an attempt outcome; the recorded ones are %s.',
                $value,
                implode(', ', self::values()),
            ));
        }

        return $outcome;
    }

    /**
     * The same, for the nullable column.
     *
     * Null is ordinary and not an absence of data: an attempt that is still
     * running has not come back with anything yet.
     *
     * @throws InvalidArgumentException when a present value is unreadable
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::fromStorage(trim($value));
    }

    /**
     * Whether there is something in the workspace to verify.
     */
    public function hasCandidate(): bool
    {
        return $this === self::Candidate;
    }

    /**
     * Whether the agent is waiting on a person.
     */
    public function requiresHumanInput(): bool
    {
        return $this === self::Blocked;
    }

    /**
     * Whether this outcome is worth trying again with the same inputs.
     *
     * A blocked attempt is not: the question it asked would be asked again. A
     * cancelled one is, which is what makes an interrupted run resumable.
     */
    public function isRetryable(): bool
    {
        return $this !== self::Blocked;
    }

    /**
     * The state this outcome decides on its own, or null when it decides
     * nothing.
     *
     * Null for a candidate, and that is the whole point: the one outcome that
     * claims to have done the work is the one outcome that does not get to say
     * how the pass ends.
     */
    public function settles(): ?LifecycleState
    {
        return match ($this) {
            self::Candidate => null,
            self::Blocked => LifecycleState::Blocked,
            self::Error, self::TimedOut => LifecycleState::Failed,
            self::Cancelled => LifecycleState::Interrupted,
        };
    }

    /**
     * Whether an attempt with this outcome may be recorded in the given state.
     *
     * A candidate may end any way the verification and Git steps take it,
     * including still running while they are carried out. Anything else may
     * only end the way it settles.
     */
    public function allows(LifecycleState $state): bool
    {
        $settled = $this->settles();

        if ($settled === null) {
            return $state !== LifecycleState::Pending && $state !== LifecycleState::Skipped;
        }

        return $state === $settled;
    }

    /**
     * Whether a blocker has to be recorded beside this outcome.
     *
     * An attempt that stopped to ask a question and recorded no question is a
     * row nobody can act on.
     */
    public function requiresBlocker(): bool
    {
        return $this === self::Blocked;
    }

    /**
     * One phrase for a status line or a note heading.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Candidate => 'an implementation to verify',
            self::Blocked => 'a request for human input',
            self::Error => 'an execution error',
            self::TimedOut => 'a timeout',
            self::Cancelled => 'a cancelled attempt',
        };
    }
}
