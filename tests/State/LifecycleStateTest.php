<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the lifecycle allows, and what it refuses.
 *
 * Two things here are not really about the enum at all. The spellings are
 * pinned to literals because they are written into a SQLite database and read
 * back by later runs, so renaming a case would make every stored row
 * unreadable. And the transitions are tested as a whole table rather than one
 * happy path at a time: the cases that matter are the ones that must *not* be
 * allowed — a pass completing without ever running, a finished pass being
 * changed instead of superseded — and those only show up if every pair is
 * checked.
 */
#[CoversClass(LifecycleState::class)]
final class LifecycleStateTest extends TestCase
{
    /**
     * The seven states of PROJECT.md section D, item 2, spelled as they are
     * stored.
     */
    #[Test]
    public function the_stored_spellings_are_pinned(): void
    {
        self::assertSame(
            ['pending', 'running', 'completed', 'skipped', 'blocked', 'failed', 'interrupted'],
            LifecycleState::values(),
        );
    }

    #[Test]
    public function a_pass_starts_pending(): void
    {
        self::assertSame(LifecycleState::Pending, LifecycleState::initial());
    }

    #[Test]
    public function the_schema_vocabulary_is_the_enum(): void
    {
        self::assertSame(LifecycleState::values(), Schema::states());
        self::assertSame(LifecycleState::openValues(), Schema::openStates());
        self::assertSame(LifecycleState::closedValues(), Schema::closedStates());
    }

    /**
     * The partition the one-open-pass-per-task index rests on: every state is
     * either open or closed, and none is both.
     */
    #[Test]
    public function open_and_closed_partition_the_lifecycle(): void
    {
        $classified = [...LifecycleState::openValues(), ...LifecycleState::closedValues()];
        $every = LifecycleState::values();
        sort($classified);
        sort($every);

        // One comparison covers the whole partition: a state in neither list
        // leaves the left side short, and a state in both leaves it long.
        self::assertSame(
            $every,
            $classified,
            'A lifecycle state is not classified exactly once, so the one-open-pass index cannot rely on it.',
        );

        self::assertSame(
            ['completed', 'skipped'],
            LifecycleState::closedValues(),
            'A state a pass can be continued from was recorded as finished with.',
        );
        self::assertSame(
            LifecycleState::closedValues(),
            array_map(static fn (LifecycleState $state): string => $state->value, LifecycleState::closed()),
        );
        self::assertSame(
            LifecycleState::openValues(),
            array_map(static fn (LifecycleState $state): string => $state->value, LifecycleState::open()),
        );
        self::assertSame(LifecycleState::cases(), LifecycleState::all());
    }

    #[Test]
    #[DataProvider('states')]
    public function open_is_the_complement_of_closed(LifecycleState $state): void
    {
        self::assertNotSame($state->isOpen(), $state->isClosed());
        self::assertSame($state->isOpen(), Schema::isOpen($state->value));
    }

    /**
     * {@see Schema::isOpen()} takes a stored string, so it is asked about
     * values that are not states at all. It answers by looking for an open
     * state rather than by ruling out a closed one: an unreadable state
     * reported as open is a pass the runner would try to continue without
     * knowing what it is.
     */
    #[Test]
    public function a_value_that_is_not_a_state_is_not_open_either(): void
    {
        self::assertFalse(Schema::isOpen('paused'));
        self::assertFalse(Schema::isOpen('Running'));
        self::assertFalse(Schema::isOpen(''));
    }

    /**
     * A closed pass leads nowhere: reopening is a new generation, which is what
     * keeps the finished pass's branch, commit and evidence readable.
     */
    #[Test]
    #[DataProvider('states')]
    public function only_an_open_pass_can_move_at_all(LifecycleState $state): void
    {
        self::assertSame($state->isOpen(), $state->successors() !== []);
    }

    #[Test]
    #[DataProvider('states')]
    public function a_state_cannot_move_to_itself(LifecycleState $state): void
    {
        self::assertFalse($state->canTransitionTo($state));
    }

    /**
     * Nothing is complete that never ran.
     */
    #[Test]
    #[DataProvider('states')]
    public function running_is_the_only_route_to_completed(LifecycleState $state): void
    {
        self::assertSame(
            $state === LifecycleState::Running,
            $state->canTransitionTo(LifecycleState::Completed),
        );
    }

    /**
     * Skipping is a decision taken before the work starts.
     */
    #[Test]
    #[DataProvider('states')]
    public function only_an_unstarted_pass_can_be_skipped(LifecycleState $state): void
    {
        self::assertSame(
            $state === LifecycleState::Pending,
            $state->canTransitionTo(LifecycleState::Skipped),
        );
    }

    #[Test]
    #[DataProvider('allowedTransitions')]
    public function an_allowed_transition_returns_the_new_state(
        LifecycleState $from,
        LifecycleState $to,
    ): void {
        self::assertTrue($from->canTransitionTo($to));
        self::assertSame($to, $from->transitionTo($to));
    }

    /**
     * Every pair the table above does not list.
     */
    #[Test]
    #[DataProvider('refusedTransitions')]
    public function a_refused_transition_says_what_is_possible_instead(
        LifecycleState $from,
        LifecycleState $to,
    ): void {
        self::assertFalse($from->canTransitionTo($to));

        try {
            $from->transitionTo($to);
        } catch (StateException $refused) {
            self::assertStringContainsString($from->value, $refused->getMessage());
            self::assertStringContainsString($to->value, $refused->getMessage());

            $hint = implode(' ', $refused->hints());

            if ($from->isClosed()) {
                self::assertStringContainsString(
                    'new generation',
                    $hint,
                    'A finished pass was refused without saying that starting again is a new generation.',
                );

                return;
            }

            foreach ($from->successors() as $possible) {
                self::assertStringContainsString(
                    $possible->value,
                    $hint,
                    'A refused transition left out a state the pass could actually move to.',
                );
            }

            return;
        }

        self::fail(sprintf('A pass was allowed to go from %s to %s.', $from->value, $to->value));
    }

    #[Test]
    public function only_an_interruption_is_resumed(): void
    {
        foreach (LifecycleState::cases() as $state) {
            self::assertSame(
                $state === LifecycleState::Interrupted,
                $state->isResumable(),
                sprintf('%s was reported as something `ralph resume` picks up.', $state->value),
            );
        }
    }

    /**
     * Blocked is not a failure: nothing went wrong with the work, the runner
     * needs an answer. `status` tells the two apart so a user knows which tasks
     * need a person and which need another attempt.
     */
    #[Test]
    public function waiting_on_a_person_is_not_the_same_as_having_failed(): void
    {
        self::assertTrue(LifecycleState::Blocked->requiresHumanInput());
        self::assertFalse(LifecycleState::Blocked->isFailure());

        self::assertTrue(LifecycleState::Failed->isFailure());
        self::assertFalse(LifecycleState::Failed->requiresHumanInput());
    }

    #[Test]
    #[DataProvider('states')]
    public function a_pass_being_worked_on_is_not_waiting_for_another_attempt(LifecycleState $state): void
    {
        self::assertSame($state->isOpen() && !$state->isRunning(), $state->isRetryable());
    }

    #[Test]
    public function completed_is_the_one_state_that_earns_a_checkbox(): void
    {
        foreach (LifecycleState::cases() as $state) {
            self::assertSame($state === LifecycleState::Completed, $state->isCompleted());
        }
    }

    #[Test]
    #[DataProvider('states')]
    public function every_state_reads_as_a_sentence(LifecycleState $state): void
    {
        self::assertNotSame('', $state->describe());
        self::assertNotSame('', $state->nextAction());
    }

    #[Test]
    #[DataProvider('states')]
    public function a_stored_state_reads_back_as_the_case_that_wrote_it(LifecycleState $state): void
    {
        self::assertSame($state, LifecycleState::fromStorage($state->value));
    }

    /**
     * A state this build cannot read means the file was written by a newer
     * Ralph PHP or edited by hand. Both are reasons to stop: reading it as
     * pending would run work that is already committed.
     */
    #[Test]
    public function a_state_this_build_does_not_know_stops_the_run(): void
    {
        try {
            LifecycleState::fromStorage('paused');
        } catch (StateException $refused) {
            self::assertStringContainsString('paused', $refused->getMessage());
            self::assertStringContainsString('pending', implode(' ', $refused->hints()));

            return;
        }

        self::fail('An unknown stored state was read as a state the package knows.');
    }

    #[Test]
    public function the_spelling_is_case_sensitive(): void
    {
        $this->expectException(StateException::class);

        LifecycleState::fromStorage('Running');
    }

    /**
     * @return iterable<string, array{LifecycleState}>
     */
    public static function states(): iterable
    {
        foreach (LifecycleState::cases() as $state) {
            yield $state->value => [$state];
        }
    }

    /**
     * The transition table, written out independently of the enum.
     *
     * Duplication on purpose: a provider that asked `successors()` what it
     * allows would agree with any table at all, including one that allowed
     * everything.
     *
     * @return iterable<string, array{LifecycleState, LifecycleState}>
     */
    public static function allowedTransitions(): iterable
    {
        foreach (self::table() as $from => $targets) {
            foreach ($targets as $to) {
                yield $from . ' to ' . $to => [LifecycleState::from($from), LifecycleState::from($to)];
            }
        }
    }

    /**
     * @return iterable<string, array{LifecycleState, LifecycleState}>
     */
    public static function refusedTransitions(): iterable
    {
        $table = self::table();

        foreach (LifecycleState::cases() as $from) {
            foreach (LifecycleState::cases() as $to) {
                if (in_array($to->value, $table[$from->value], true)) {
                    continue;
                }

                yield $from->value . ' to ' . $to->value => [$from, $to];
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function table(): array
    {
        return [
            'pending' => ['running', 'skipped', 'blocked', 'failed', 'interrupted'],
            'running' => ['completed', 'blocked', 'failed', 'interrupted'],
            'completed' => [],
            'skipped' => [],
            'blocked' => ['running', 'failed'],
            'failed' => ['running', 'blocked'],
            'interrupted' => ['running', 'blocked', 'failed'],
        ];
    }
}
