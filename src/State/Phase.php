<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Ralph;

/**
 * What a pass is in the middle of.
 *
 * The phases of PROJECT.md section D, item 3, recorded next to
 * {@see LifecycleState} on a generation and on each attempt. The state says
 * whether a pass is still being worked on; the phase says which part of the
 * work it is in, and the whole reason it is written down is resume. An
 * interruption during `executing` and one during `finalizing` are the same
 * state and two completely different jobs: the first means invoking the agent
 * again, the second means writing one line in a Markdown file for work the
 * checks have already passed. Without the phase the only safe move after an
 * interruption would be to start the pass over, which is the duplicated
 * implementation section H and release criterion 6 exist to rule out.
 *
 * Three things are encoded here beyond the list of words.
 *
 * The **order** is the sequence of PROJECT.md section G, item 1, and a pass
 * only ever moves forward through it. What it may not do is go backwards.
 * Re-preparing after a failed check is not a phase transition, it is the next
 * attempt — a new row in `attempts` that starts again at
 * {@see self::Preparing}, because context and bounded memory are loaded per
 * attempt (section D, item 5). Allowing a backward move would let a recorded
 * phase say `executing` about a pass whose checks have already passed, and
 * resume would then invoke the agent over work that is already done.
 *
 * The **resume partition** is {@see self::resumesInPlace()}. A pass that
 * stopped before the agent came back has no candidate implementation to keep,
 * so it is picked up as a fresh attempt; one that stopped after has work worth
 * preserving, and resume continues that same attempt from the phase it
 * records. This is what makes "resume a failed checklist write without
 * repeating the implementation" expressible at all.
 *
 * The **already-true questions** — {@see self::hasCandidate()} and
 * {@see self::verificationPassed()} — are what a resume asks before it does
 * anything. They are deliberately conservative about the phase a pass stopped
 * *in*: a pass interrupted during `verifying` is not reported as having passed
 * its checks, because some of them may have run and that is not the same as the
 * set of them having passed.
 *
 * Which states carry a phase at all is {@see LifecycleState::recordsPhase()}.
 */
enum Phase: string
{
    /**
     * Getting the prompt ready.
     *
     * Opening the attempt's log and loading project context and bounded
     * relevant memory. Nothing has been changed and the agent has not been
     * started, so a pass that stops here is begun again from the top.
     */
    case Preparing = 'preparing';

    /**
     * The agent is running.
     *
     * The one phase whose work is done by something other than the runner, and
     * the one that costs money and time. A pass that stops here is started
     * again as a new attempt: a half-finished agent invocation is not a
     * candidate implementation, and treating whatever it left in the working
     * tree as one would tick a box over changes nothing checked.
     */
    case Executing = 'executing';

    /**
     * The configured checks are running against what the agent produced.
     *
     * Run by the runner, from the configuration snapshotted at run start, and
     * independently of the worker — a zero exit code from the agent is not what
     * ticks a box. Re-runnable from the first check, which is why a pass
     * interrupted here is continued rather than re-executed.
     */
    case Verifying = 'verifying';

    /**
     * Everything succeeded and the checklist box is being ticked.
     *
     * The last thing a pass does, and deliberately after the checks: a
     * checkbox write that fails leaves the pass awaiting finalization with the
     * agent's work and the passing checks already recorded, so resume has only
     * the file to write (section G, item 8).
     */
    case Finalizing = 'finalizing';

    /**
     * The phase every attempt starts in.
     */
    public static function initial(): self
    {
        return self::Preparing;
    }

    /**
     * Every phase, in the order a pass moves through them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Every phase as the database spells it.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Read a phase back out of the state store.
     *
     * The column has a `CHECK` constraint built from these cases, so a value
     * this cannot read did not come from a database this build wrote. Stopping
     * is the only safe answer: a phase misread as an earlier one is work done
     * twice, and misread as a later one is work skipped.
     *
     * @throws StateException when the value is not one of these phases
     */
    public static function fromStorage(string $value): self
    {
        $phase = self::tryFrom($value);

        if ($phase instanceof self) {
            return $phase;
        }

        // The two phases an earlier build recorded and this one does not have:
        // a pass stopped while committing or pushing had done its work and
        // passed its checks, which is what `finalizing` now means. Read that
        // way rather than refused, so a project whose database carries a
        // half-finished run from the Git era can still be resumed — the only
        // thing left to do for such a pass is the checkbox, and the commit it
        // made stays in the repository for its owner to keep or discard.
        $retired = match ($value) {
            'committing', 'publishing' => self::Finalizing,
            default => null,
        };

        if ($retired instanceof self) {
            return $retired;
        }

        throw new StateException(
            sprintf('The state store holds the phase "%s", which this version does not know.', $value),
            [
                'The phases it knows are: ' . implode(', ', self::values()) . '.',
                sprintf(
                    'A state store written by a newer %s can hold phases this build cannot read; check which version wrote it.',
                    Ralph::NAME,
                ),
            ],
        );
    }

    /**
     * The phase column as read from a row, which is nullable.
     *
     * Null is not an unknown phase, it is the absence of one: a pass that has
     * not begun, or one that is finished with and no longer in the middle of
     * anything. An empty string is not that — it is a value no build of the
     * package writes — so it is refused like any other.
     *
     * @throws StateException when the value is a string that is not a phase
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::fromStorage($value);
    }

    /**
     * Where this phase comes in the sequence, counting from one.
     *
     * For comparing phases and for reading in a status line ("3 of 4"); the
     * number itself is never stored, since renumbering on a later release would
     * make stored rows mean something else.
     */
    public function position(): int
    {
        return match ($this) {
            self::Preparing => 1,
            self::Executing => 2,
            self::Verifying => 3,
            self::Finalizing => 4,
        };
    }

    /**
     * Whether this is where an attempt begins.
     */
    public function isFirst(): bool
    {
        return $this === self::Preparing;
    }

    /**
     * Whether this is the last thing a pass does before it is complete.
     */
    public function isLast(): bool
    {
        return $this === self::Finalizing;
    }

    /**
     * The phase that follows this one, or null at the end of the sequence.
     *
     * Every pass that gets as far as a phase records the next one, so this is
     * also the sequence a pass actually moves through.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Preparing => self::Executing,
            self::Executing => self::Verifying,
            self::Verifying => self::Finalizing,
            self::Finalizing => null,
        };
    }

    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }

    public function isAfter(self $other): bool
    {
        return $this->position() > $other->position();
    }

    /**
     * Whether a pass that reached this phase had already reached the given one.
     *
     * True of the phase itself, which is what makes it read as "got at least
     * this far".
     */
    public function hasReached(self $other): bool
    {
        return $this->position() >= $other->position();
    }

    /**
     * Whether a pass can finish without ever recording this phase.
     *
     * None of them, since the Git phases went: every pass prepares its
     * context, invokes the agent, has the result verified, and is finalized
     * before its box is ticked. Kept because the question is a reader's as
     * much as a caller's — a phase list with nothing conditional in it is
     * worth being able to ask about rather than infer.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * Whether the agent is what is running in this phase.
     *
     * The phase boundary that matters for cancellation: Ctrl+C during
     * `executing` has a worker process tree to stop before the lock can be
     * released, and in every other phase it does not.
     */
    public function invokesAgent(): bool
    {
        return $this === self::Executing;
    }

    /**
     * The phases, in order, from this one to the end of the sequence.
     *
     * @return list<self>
     */
    public function remaining(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->hasReached($this),
        ));
    }

    /**
     * The phases a pass in this one is allowed to move to.
     *
     * Every later phase: forward only, never to itself, and skipping allowed
     * because `committing` and `publishing` are conditional. A pass that is
     * finalizing has nowhere left to go — what follows is the end of the pass,
     * recorded as a {@see LifecycleState}, not another phase.
     *
     * @return list<self>
     */
    public function successors(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->isAfter($this),
        ));
    }

    /**
     * Whether a pass can move from this phase to the given one.
     *
     * A phase cannot move to itself. Recording the phase a pass is already in
     * is not a transition, and a second try at the same work is a new attempt
     * starting again at {@see self::Preparing}.
     */
    public function canTransitionTo(self $next): bool
    {
        return $next->isAfter($this);
    }

    /**
     * The given phase, once it has been checked that a pass can get there from
     * here.
     *
     * Returns the new phase rather than mutating, since an enum case is a
     * value: `$phase = $phase->transitionTo(Phase::Verifying)`.
     *
     * @throws StateException when the move would go backwards or stand still
     */
    public function transitionTo(self $next): self
    {
        if (!$this->canTransitionTo($next)) {
            throw new StateException(
                sprintf(
                    'A pass that is %s cannot go back to %s.',
                    $this->value,
                    $next->value,
                ),
                [$this->explainSuccessors()],
            );
        }

        return $next;
    }

    /**
     * Whether a pass that stopped in this phase has a candidate implementation
     * worth keeping.
     *
     * The agent has come back with one by the time verification starts, and not
     * before: a run cut short partway through `executing` left whatever it left,
     * and that is not a result the runner is willing to carry forward.
     */
    public function hasCandidate(): bool
    {
        return match ($this) {
            self::Verifying, self::Finalizing => true,
            self::Preparing, self::Executing => false,
        };
    }

    /**
     * Whether the configured checks had already passed when a pass reached this
     * phase.
     *
     * Reaching `finalizing` is what passing them means. A pass stopped
     * *during* `verifying` has no verdict: some checks may have run, which is
     * not the same as the set of them having passed.
     */
    public function verificationPassed(): bool
    {
        return $this === self::Finalizing;
    }

    /**
     * Whether resume continues this same attempt from here.
     *
     * The partition the recorded phase exists for. A pass interrupted from
     * `verifying` onwards has an implementation in the user's checkout that it
     * would be wrong to ask for twice, so resume picks the attempt up where it
     * stopped. Before that there is nothing to preserve, and starting a new
     * attempt is both safe and cheaper to reason about.
     */
    public function resumesInPlace(): bool
    {
        return match ($this) {
            self::Verifying, self::Finalizing => true,
            self::Preparing, self::Executing => false,
        };
    }

    /**
     * Whether resume has to start a fresh attempt instead of continuing this
     * one.
     *
     * The complement of {@see self::resumesInPlace()}: between them they cover
     * every phase, so a recorded phase always decides which of the two resume
     * does.
     */
    public function requiresNewAttempt(): bool
    {
        return !$this->resumesInPlace();
    }

    /**
     * The phase phrased for a status line.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Preparing => 'preparing the context',
            self::Executing => 'waiting for the agent',
            self::Verifying => 'running the configured checks',
            self::Finalizing => 'checking the task off',
        };
    }

    /**
     * What `ralph resume` does about a pass that stopped here.
     *
     * Written as what will and will not be repeated, because that is the
     * question a user who has just interrupted a run actually has.
     */
    public function resumeAction(): string
    {
        return match ($this) {
            self::Preparing => 'start the task again: nothing had been produced yet',
            self::Executing => 'invoke the agent again: an unfinished run is not a result',
            self::Verifying => 'run the checks again against the changes the agent already made',
            self::Finalizing => 'write the checklist box only: the work is done and verified',
        };
    }

    /**
     * The hint that goes with a refused transition.
     */
    private function explainSuccessors(): string
    {
        $successors = $this->successors();

        if ($successors === []) {
            return sprintf(
                'A pass that is %s is doing the last thing it does; what comes next is the end of the pass, not another phase.',
                $this->value,
            );
        }

        return sprintf(
            'From %s a pass can only move on to: %s. Going over the same ground again is the next attempt, which starts at %s.',
            $this->value,
            implode(', ', array_map(static fn (self $case): string => $case->value, $successors)),
            self::initial()->value,
        );
    }
}
