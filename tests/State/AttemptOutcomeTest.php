<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an attempt's outcome is allowed to decide.
 *
 * The asymmetry is the whole of this type: four outcomes settle how the attempt
 * ends, and the one that claims to have implemented something settles nothing.
 * A test that only checked the spellings would pass with that inverted.
 */
#[CoversClass(AttemptOutcome::class)]
final class AttemptOutcomeTest extends TestCase
{
    #[Test]
    public function the_outcomes_are_the_vocabulary_the_column_takes(): void
    {
        self::assertSame(
            ['candidate', 'blocked', 'error', 'timed_out', 'cancelled'],
            AttemptOutcome::values(),
        );
        self::assertSame(
            AttemptOutcome::values(),
            Schema::attemptOutcomes(),
            'The enum and the schema vocabulary have drifted, so an outcome the runner can '
            . 'compute is one the database would refuse.',
        );
    }

    #[Test]
    public function a_candidate_settles_nothing(): void
    {
        self::assertNull(
            AttemptOutcome::Candidate->settles(),
            'A claimed implementation decided how the attempt ended, which is exactly what '
            . 'verification is for.',
        );
        self::assertTrue(AttemptOutcome::Candidate->hasCandidate());
        self::assertFalse(AttemptOutcome::Candidate->requiresHumanInput());
        self::assertFalse(AttemptOutcome::Candidate->requiresBlocker());
        self::assertTrue(AttemptOutcome::Candidate->isRetryable());
    }

    #[Test]
    public function every_other_outcome_decides_the_state_itself(): void
    {
        self::assertSame(LifecycleState::Blocked, AttemptOutcome::Blocked->settles());
        self::assertSame(LifecycleState::Failed, AttemptOutcome::Error->settles());
        self::assertSame(LifecycleState::Failed, AttemptOutcome::TimedOut->settles());
        self::assertSame(LifecycleState::Interrupted, AttemptOutcome::Cancelled->settles());
    }

    #[Test]
    public function a_candidate_may_end_any_way_the_checks_take_it(): void
    {
        foreach (
            [
                LifecycleState::Completed,
                LifecycleState::Failed,
                LifecycleState::Blocked,
                LifecycleState::Interrupted,
            ] as $state
        ) {
            self::assertTrue(
                AttemptOutcome::Candidate->allows($state),
                sprintf('A verified candidate could not be recorded as %s.', $state->value),
            );
        }
    }

    #[Test]
    public function a_candidate_may_not_be_recorded_as_never_having_run(): void
    {
        self::assertFalse(AttemptOutcome::Candidate->allows(LifecycleState::Pending));
        self::assertFalse(AttemptOutcome::Candidate->allows(LifecycleState::Skipped));
    }

    #[Test]
    public function a_settled_outcome_allows_only_the_state_it_settles(): void
    {
        self::assertTrue(AttemptOutcome::Blocked->allows(LifecycleState::Blocked));

        foreach (LifecycleState::all() as $state) {
            if ($state === LifecycleState::Blocked) {
                continue;
            }

            self::assertFalse(
                AttemptOutcome::Blocked->allows($state),
                sprintf(
                    'An attempt that asked for human input could be recorded as %s.',
                    $state->value,
                ),
            );
        }
    }

    #[Test]
    public function an_attempt_that_asked_a_question_must_record_the_question(): void
    {
        self::assertTrue(AttemptOutcome::Blocked->requiresBlocker());

        foreach (AttemptOutcome::cases() as $outcome) {
            if ($outcome === AttemptOutcome::Blocked) {
                continue;
            }

            self::assertFalse($outcome->requiresBlocker());
        }
    }

    #[Test]
    public function only_a_question_is_not_worth_asking_twice(): void
    {
        self::assertFalse(
            AttemptOutcome::Blocked->isRetryable(),
            'Retrying a blocked attempt asks the same question again.',
        );

        foreach ([AttemptOutcome::Error, AttemptOutcome::TimedOut, AttemptOutcome::Cancelled] as $outcome) {
            self::assertTrue(
                $outcome->isRetryable(),
                sprintf('An attempt that %s cannot be tried again.', $outcome->value),
            );
        }
    }

    #[Test]
    public function only_a_candidate_leaves_something_to_verify(): void
    {
        foreach (AttemptOutcome::cases() as $outcome) {
            self::assertSame($outcome === AttemptOutcome::Candidate, $outcome->hasCandidate());
        }
    }

    #[Test]
    public function a_stored_outcome_reads_back(): void
    {
        foreach (AttemptOutcome::cases() as $outcome) {
            self::assertSame($outcome, AttemptOutcome::fromStorage($outcome->value));
            self::assertSame($outcome, AttemptOutcome::recorded($outcome->value));
            self::assertSame($outcome, AttemptOutcome::recorded(' ' . $outcome->value . ' '));
        }
    }

    #[Test]
    public function an_attempt_still_running_has_recorded_no_outcome(): void
    {
        self::assertNull(AttemptOutcome::recorded(null));
        self::assertNull(AttemptOutcome::recorded('  '));
    }

    #[Test]
    public function an_outcome_the_enum_does_not_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"succeeded" is not an attempt outcome');

        AttemptOutcome::fromStorage('succeeded');
    }

    #[Test]
    public function each_outcome_reads_as_its_own_phrase(): void
    {
        $described = array_map(
            static fn (AttemptOutcome $outcome): string => $outcome->describe(),
            AttemptOutcome::cases(),
        );

        self::assertSame($described, array_unique($described));
        self::assertNotContains('', $described);
    }
}
