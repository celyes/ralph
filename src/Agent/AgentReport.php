<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\Support\Excerpt;
use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;

/**
 * What an agent said it did, once it has been read successfully.
 *
 * The three cases of PROJECT.md section E, item 5 as one value: a candidate
 * implementation, a request for human input, or an execution error. A report
 * exists only when a block was found and understood — {@see ReportReader}
 * refuses anything else — so nothing downstream has to handle a half-read one,
 * and "the agent reported nothing" is a different state rather than a report
 * with empty fields.
 *
 * **It is a claim, not a result.** This is the agent's account of its own
 * attempt, which is why the summary is {@see UnverifiedText} — defused on the
 * way in, so a model that writes `- [x] done` has that marker neutralised once,
 * here — and why there is no `completed` case. Turning a report into the
 * attempt's {@see ExecutionResult} is the adapter's step, and it is where the
 * claim meets what the package observed: a declared candidate from a process
 * that exited non-zero is an error, because the exit status is the observation
 * and the declaration is not.
 *
 * **A blocker has to be answerable.** {@see self::blocked()} requires a next
 * action, because an attempt that stopped to ask a question and recorded no
 * question leaves a user with a halted run and nothing to act on — the same
 * requirement {@see ExecutionResult::blocked()} makes, held one step earlier so
 * that a reader can refuse the block rather than fail while building a result.
 */
final class AgentReport
{
    private function __construct(
        private readonly AttemptOutcome $outcome,
        private readonly UnverifiedText $summary,
        private readonly ?string $nextAction,
    ) {
    }

    /**
     * The agent says it changed the workspace and the change is ready to check.
     *
     * The summary may be empty: an agent that did the work and said nothing
     * about it has still said which of the three cases this is, and that is the
     * part the loop acts on.
     */
    public static function candidate(string $summary = ''): self
    {
        return new self(AttemptOutcome::Candidate, UnverifiedText::of($summary), null);
    }

    /**
     * The agent stopped to ask for a decision.
     *
     * @throws InvalidArgumentException when either half of the question says
     *                                 nothing
     */
    public static function blocked(string $reason, string $nextAction): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A blocked report has to say what the decision is.');
        }

        if (trim($nextAction) === '') {
            throw new InvalidArgumentException('A blocked report has to say what is needed to unblock it.');
        }

        return new self(AttemptOutcome::Blocked, UnverifiedText::of($reason), trim($nextAction));
    }

    /**
     * The agent says it could not do the work.
     *
     * @throws InvalidArgumentException when it does not say what it ran into
     */
    public static function error(string $summary): self
    {
        if (trim($summary) === '') {
            throw new InvalidArgumentException('An error report has to say what the agent ran into.');
        }

        return new self(AttemptOutcome::Error, UnverifiedText::of($summary), null);
    }

    /**
     * Which of the three cases the agent declared.
     */
    public function outcome(): AttemptOutcome
    {
        return $this->outcome;
    }

    /**
     * The agent's own account, defused and bounded.
     */
    public function summary(): UnverifiedText
    {
        return $this->summary;
    }

    /**
     * What the agent needs a person to do, where it asked for one.
     */
    public function nextAction(): ?string
    {
        return $this->nextAction;
    }

    public function isCandidate(): bool
    {
        return $this->outcome === AttemptOutcome::Candidate;
    }

    public function requiresHumanInput(): bool
    {
        return $this->outcome === AttemptOutcome::Blocked;
    }

    public function isError(): bool
    {
        return $this->outcome === AttemptOutcome::Error;
    }

    /**
     * The question as a recordable blocker, attributed to the agent that asked
     * it.
     *
     * Attributed rather than raised, because {@see Blocker::RUNNER} means the
     * package itself stopped the task and a user reading `ralph status` has a
     * different thing to do about an agent's question than about the runner's.
     *
     * @param string $agent the configured agent name, as the run recorded it
     */
    public function blocker(string $agent): ?Blocker
    {
        if (!$this->requiresHumanInput() || $this->nextAction === null) {
            return null;
        }

        return Blocker::from($agent, $this->summary->text(), $this->nextAction);
    }

    /**
     * One line for a log note.
     *
     * Says what was declared and quotes the start of the account, so a reader
     * can see why an attempt was classified the way it was without opening the
     * database.
     */
    public function describe(): string
    {
        $declared = match ($this->outcome) {
            AttemptOutcome::Candidate => 'reported a candidate implementation',
            AttemptOutcome::Blocked => 'asked for human input',
            default => 'reported that it could not do the work',
        };

        if ($this->summary->isEmpty()) {
            return $declared;
        }

        return sprintf('%s: %s', $declared, Excerpt::of($this->summary->text()));
    }
}
