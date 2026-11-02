<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;

/**
 * What one invocation of an agent came back with.
 *
 * The result half of the common contract. Its whole purpose is that the runner
 * can act on an attempt without knowing which agent ran: both built-in adapters
 * and any registered one normalise onto the same few cases (PROJECT.md section
 * E, item 10), so verification, committing and pushing are written once.
 *
 * **It cannot report success.** There is no `completed` here and no boolean
 * saying the task is done. The most an adapter may claim is
 * {@see self::candidate()} — there is something in the workspace worth
 * verifying — because section 4 is explicit that a zero exit code is not
 * sufficient: the checks have to pass, a commit or a justified no-change result
 * has to be recorded, and any required push has to succeed. The verdict comes
 * from those, which is why {@see AttemptOutcome::settles()} returns null for a
 * candidate and why that enum is reused here rather than a parallel one being
 * invented.
 *
 * **The claim and the evidence stay apart.** {@see self::claim()} is the
 * agent's own account, passed through {@see UnverifiedText} on the way in so
 * that a construction cannot bypass the gate: a model that reports
 * `- [x] all tests pass` has that marker defused here, once, rather than in
 * each adapter. {@see self::evidence()} is what the package observed about the
 * invocation itself — the exit status, the error output, the timeout. They are
 * separate parameters, stored in separate columns and rendered under separate
 * headings, and nothing merges them.
 *
 * Interpreting an agent's raw output *into* one of these — including refusing
 * output that is absent or malformed — is section E, item 5, and belongs to
 * each adapter.
 */
final class ExecutionResult
{
    private function __construct(
        private readonly AttemptOutcome $outcome,
        private readonly UnverifiedText $claim,
        private readonly FailureEvidence $evidence,
        private readonly ?Blocker $blocker,
        private readonly float $seconds,
        private readonly ?int $exitCode,
    ) {
    }

    /**
     * The agent says it has implemented something.
     *
     * A claim about the workspace and nothing more: what makes it true is the
     * verification run that follows.
     *
     * @param string $claim the agent's own account, in its own words
     */
    public static function candidate(string $claim = '', float $seconds = 0.0, ?int $exitCode = 0): self
    {
        return new self(
            AttemptOutcome::Candidate,
            UnverifiedText::of($claim),
            FailureEvidence::none(),
            null,
            self::duration($seconds),
            $exitCode,
        );
    }

    /**
     * The agent asked for a human decision instead of implementing.
     *
     * The blocker is required and not optional, which is the whole difference
     * between this and an error: an attempt that stopped to ask a question and
     * recorded no question leaves a user with a halted run and nothing to act
     * on. It should be attributed to the agent — `Blocker::from($agentName,
     * …)` — because the question is the agent's, not the package's.
     */
    public static function blocked(
        Blocker $blocker,
        string $claim = '',
        float $seconds = 0.0,
        ?int $exitCode = 0,
    ): self {
        return new self(
            AttemptOutcome::Blocked,
            UnverifiedText::of($claim),
            FailureEvidence::none(),
            $blocker,
            self::duration($seconds),
            $exitCode,
        );
    }

    /**
     * The invocation failed, or came back with nothing usable.
     *
     * One case for a non-zero exit, a missing result and output the adapter
     * could not read, because the loop does the same thing with all three:
     * there is no candidate to verify and the attempt may be retried. Which of
     * them it was belongs in the evidence, where a user can read it.
     *
     * @throws InvalidArgumentException when no evidence is given
     */
    public static function error(FailureEvidence $evidence, string $claim = '', float $seconds = 0.0, ?int $exitCode = null): self
    {
        return new self(
            AttemptOutcome::Error,
            UnverifiedText::of($claim),
            self::required($evidence, 'An execution error has to record what was seen'),
            null,
            self::duration($seconds),
            $exitCode,
        );
    }

    /**
     * The attempt ran past the seconds its budget allowed and was stopped.
     *
     * Apart from an error because the remedy is different: a limit can be
     * raised, and a task that times out every time is a task that is too large
     * rather than an agent that is broken. The exit code is null — a killed
     * process never reported one.
     *
     * @throws InvalidArgumentException when no evidence is given
     */
    public static function timedOut(FailureEvidence $evidence, string $claim = '', float $seconds = 0.0): self
    {
        return new self(
            AttemptOutcome::TimedOut,
            UnverifiedText::of($claim),
            self::required($evidence, 'A timeout has to record what was seen'),
            null,
            self::duration($seconds),
            null,
        );
    }

    /**
     * The attempt was stopped from outside: Ctrl+C, or a run out of time.
     *
     * Evidence is optional here, and that is deliberate — nothing was wrong
     * with the attempt and nothing about it passed, so there may be genuinely
     * nothing to record beyond the fact that it was cut short.
     */
    public static function cancelled(
        string $claim = '',
        ?FailureEvidence $evidence = null,
        float $seconds = 0.0,
    ): self {
        return new self(
            AttemptOutcome::Cancelled,
            UnverifiedText::of($claim),
            $evidence ?? FailureEvidence::none(),
            null,
            self::duration($seconds),
            null,
        );
    }

    /**
     * Which of the normalised cases this is.
     */
    public function outcome(): AttemptOutcome
    {
        return $this->outcome;
    }

    /**
     * The agent's own account, defused and bounded.
     */
    public function claim(): UnverifiedText
    {
        return $this->claim;
    }

    /**
     * What the package observed about the invocation.
     */
    public function evidence(): FailureEvidence
    {
        return $this->evidence;
    }

    /**
     * The question the agent stopped to ask, where it asked one.
     */
    public function blocker(): ?Blocker
    {
        return $this->blocker;
    }

    /**
     * Wall-clock seconds the invocation took.
     */
    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * The agent process's exit status, or null when it never reported one.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Whether there is something in the workspace to verify.
     */
    public function hasCandidate(): bool
    {
        return $this->outcome->hasCandidate();
    }

    /**
     * Whether the agent is waiting on a person.
     */
    public function requiresHumanInput(): bool
    {
        return $this->outcome->requiresHumanInput();
    }

    /**
     * Whether trying again with the same inputs is worth anything.
     */
    public function isRetryable(): bool
    {
        return $this->outcome->isRetryable();
    }

    /**
     * The state this result decides on its own, or null when it decides
     * nothing.
     *
     * Null for a candidate: see the class description.
     */
    public function settles(): ?LifecycleState
    {
        return $this->outcome->settles();
    }

    /**
     * One line for the terminal and the log.
     *
     * Says what the outcome was and how long it took, and nothing about whether
     * the task is done, because this is not where that is decided.
     */
    public function describe(): string
    {
        $parts = [sprintf('%s after %.1fs', $this->outcome->describe(), $this->seconds)];

        if ($this->exitCode !== null && $this->exitCode !== 0) {
            $parts[] = sprintf('exit %d', $this->exitCode);
        }

        if ($this->blocker !== null) {
            $parts[] = $this->blocker->label();
        } elseif (!$this->evidence->isEmpty()) {
            $parts[] = $this->evidence->label();
        }

        return implode(', ', $parts);
    }

    /**
     * A duration that cannot be negative, which a clock read backwards would
     * otherwise make it.
     */
    private static function duration(float $seconds): float
    {
        return max(0.0, $seconds);
    }

    /**
     * @throws InvalidArgumentException when the evidence is empty
     */
    private static function required(FailureEvidence $evidence, string $message): FailureEvidence
    {
        if ($evidence->isEmpty()) {
            throw new InvalidArgumentException($message . '; FailureEvidence::none() says nothing a user can act on.');
        }

        return $evidence;
    }
}
