<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use Celyes\RalphPhp\State\AttemptOutcome;

/**
 * The things the fixture worker can do when it is run.
 *
 * PROJECT.md section E, item 12 names five: success, failure, blocking, slow
 * execution and interruption. They are an enum rather than five boolean flags
 * because a step is in exactly one of them, and because the list is the item's
 * list — {@see \Celyes\RalphPhp\Tests\Fixture\FixtureWorkerTest} asserts that
 * these cases are those five, so a mode quietly dropped is a failing test
 * rather than a sentence in a progress note that is no longer true.
 *
 * What separates them is not how elaborate they are but what the *package* has
 * to do about them, which is why `slow` and `interruption` are two modes and
 * not one with a flag:
 *
 * - {@see self::Success}, {@see self::Failure} and {@see self::Blocking} run to
 *   completion and declare one of {@see ResultProtocol}'s three outcomes, so
 *   they exercise reading a report (section E, item 5).
 * - {@see self::Slow} waits without touching signals: an ordinary program that
 *   is merely too slow, which is what the attempt timeout is enforced against.
 *   Its default disposition for a termination signal is to die, so nothing of
 *   it notices being stopped.
 * - {@see self::Interruption} waits *and* installs signal handlers, so it
 *   records which signal reached it before it goes. That record is what
 *   section H's interruption handling needs to be testable at all: "stop the
 *   managed worker and release ownership only after termination is confirmed"
 *   cannot be checked against a worker that leaves no trace of having been
 *   asked.
 */
enum FixtureMode: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Blocking = 'blocking';
    case Slow = 'slow';
    case Interruption = 'interruption';

    /**
     * The outcome a step in this mode reports when it reaches the end.
     *
     * {@see self::Slow} reports a candidate, which makes a short wait a slow
     * success rather than a separate mode. {@see self::Interruption} reports an
     * error, because reaching the end means the interruption the test was
     * waiting for never arrived — a test that expected one should fail loudly
     * rather than pass on a worker that quietly finished.
     */
    public function declares(): AttemptOutcome
    {
        return match ($this) {
            self::Success, self::Slow => AttemptOutcome::Candidate,
            self::Blocking => AttemptOutcome::Blocked,
            self::Failure, self::Interruption => AttemptOutcome::Error,
        };
    }

    /**
     * Whether a step in this mode sits there rather than finishing.
     */
    public function waits(): bool
    {
        return $this === self::Slow || $this === self::Interruption;
    }

    /**
     * Whether a step in this mode notices being asked to stop.
     *
     * Only {@see self::Interruption}: see the class description for why being
     * slow and being interruptible are deliberately different programs.
     */
    public function handlesSignals(): bool
    {
        return $this === self::Interruption;
    }

    /**
     * The exit status a step in this mode uses unless the test says otherwise.
     *
     * Zero for the two outcomes a healthy agent reports — a blocked agent has
     * not failed, it has asked something — and one for the two that mean the
     * work did not happen.
     */
    public function defaultStatus(): int
    {
        return match ($this) {
            self::Success, self::Slow, self::Blocking => 0,
            self::Failure, self::Interruption => 1,
        };
    }

    /**
     * What a step in this mode says about itself unless the test says
     * otherwise.
     *
     * Fixed strings: a summary travels into evidence, memory notes and the next
     * attempt's briefing, so a test asserting on one needs it to be the same
     * text every run (section J, item 8's "no live credentials" is not the only
     * sense in which these tests have to be repeatable).
     */
    public function defaultSummary(): string
    {
        return match ($this) {
            self::Success => 'Made the change the task asked for.',
            self::Slow => 'Made the change the task asked for, eventually.',
            self::Failure => 'Could not do the work: the fixture worker was told to fail.',
            self::Blocking => 'The fixture worker was told to ask for a decision.',
            self::Interruption => 'Waited to be interrupted and never was.',
        };
    }

    /**
     * What a blocking step asks for unless the test says otherwise.
     *
     * Non-empty for every mode, because only {@see self::Blocking} reports it
     * and {@see \Celyes\RalphPhp\Agent\ReportReader} refuses a blocked report
     * that carries no next action — a default of "" would make the obvious
     * `FixtureStep::blocking()` an unreadable report instead of a question.
     */
    public function defaultNextAction(): string
    {
        return 'Decide what the fixture worker should have done, and say so in the task.';
    }
}
