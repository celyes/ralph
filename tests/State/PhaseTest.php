<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the recorded phase lets resume avoid doing twice.
 *
 * The spellings and the order are pinned to literals because they are written
 * into SQLite and read back by later runs: renaming a case or reordering the
 * sequence would make stored rows mean something else. The rest of the file is
 * about the two partitions the phase exists for — which phases already have an
 * implementation and a commit behind them, and which of those resume continues
 * rather than starts over — plus the transitions that must be refused, since a
 * phase allowed to move backwards would have resume redo committed work.
 */
#[CoversClass(Phase::class)]
final class PhaseTest extends TestCase
{
    /**
     * The six phases of PROJECT.md section D, item 3, spelled and ordered as
     * they are stored.
     */
    #[Test]
    public function the_stored_spellings_and_their_order_are_pinned(): void
    {
        self::assertSame(
            ['preparing', 'executing', 'verifying', 'committing', 'publishing', 'finalizing'],
            Phase::values(),
        );
        self::assertSame(Phase::cases(), Phase::all());
    }

    #[Test]
    public function an_attempt_starts_by_preparing(): void
    {
        self::assertSame(Phase::Preparing, Phase::initial());
        self::assertTrue(Phase::Preparing->isFirst());
        self::assertFalse(Phase::Preparing->isLast());
    }

    #[Test]
    public function ticking_the_box_is_the_last_thing_a_pass_does(): void
    {
        self::assertSame(Phase::Finalizing, Phase::cases()[count(Phase::cases()) - 1]);
        self::assertTrue(Phase::Finalizing->isLast());
        self::assertFalse(Phase::Finalizing->isFirst());
        self::assertNull(Phase::Finalizing->next());
    }

    #[Test]
    public function the_schema_vocabulary_is_the_enum(): void
    {
        self::assertSame(Phase::values(), Schema::phases());
    }

    /**
     * The positions are what {@see Phase::isBefore()} and the already-true
     * questions are built on, so they have to be the sequence itself and not
     * merely increasing.
     */
    #[Test]
    #[DataProvider('phases')]
    public function the_position_is_the_place_in_the_sequence(Phase $phase): void
    {
        $expected = array_search($phase->value, Phase::values(), true);

        self::assertIsInt($expected);
        self::assertSame($expected + 1, $phase->position());
    }

    #[Test]
    #[DataProvider('phases')]
    public function next_is_the_phase_that_follows_in_order(Phase $phase): void
    {
        $following = Phase::cases()[$phase->position()] ?? null;

        self::assertSame($following, $phase->next());
    }

    #[Test]
    #[DataProvider('phasePairs')]
    public function before_and_after_are_each_other_reversed(Phase $one, Phase $other): void
    {
        self::assertSame($one->isBefore($other), $other->isAfter($one));

        if ($one === $other) {
            self::assertFalse($one->isBefore($other));
            self::assertFalse($one->isAfter($other));
        }

        self::assertSame($one->hasReached($other), !$one->isBefore($other));
    }

    /**
     * Only `committing` and `publishing` are conditional: nothing is committed
     * for a task that was already satisfied, and nothing is pushed unless
     * pushing is enabled. Every pass prepares, executes, verifies and
     * finalizes.
     */
    #[Test]
    #[DataProvider('phases')]
    public function the_two_conditional_phases_are_the_git_ones(Phase $phase): void
    {
        self::assertSame(
            in_array($phase, [Phase::Committing, Phase::Publishing], true),
            $phase->isOptional(),
        );
    }

    #[Test]
    #[DataProvider('phases')]
    public function only_executing_runs_the_agent(Phase $phase): void
    {
        self::assertSame($phase === Phase::Executing, $phase->invokesAgent());
    }

    /**
     * The sequence a pass takes with pushing on, and the two that a
     * configuration or an already-satisfied task shortens it to. All three have
     * to be walkable one allowed transition at a time.
     *
     * @param list<string> $sequence
     */
    #[Test]
    #[DataProvider('sequences')]
    public function a_pass_walks_its_sequence_one_allowed_move_at_a_time(array $sequence): void
    {
        $phase = Phase::initial();
        self::assertSame($sequence[0], $phase->value);

        foreach (array_slice($sequence, 1) as $step) {
            $next = Phase::from($step);

            self::assertTrue(
                $phase->canTransitionTo($next),
                sprintf('A pass cannot get from %s to %s.', $phase->value, $next->value),
            );

            $phase = $phase->transitionTo($next);
        }

        self::assertTrue($phase->isLast(), 'A sequence did not end by finalizing.');
    }

    #[Test]
    #[DataProvider('phases')]
    public function the_successors_are_every_later_phase(Phase $phase): void
    {
        $later = array_values(array_filter(
            Phase::cases(),
            static fn (Phase $case): bool => $case->position() > $phase->position(),
        ));

        self::assertSame($later, $phase->successors());
        self::assertSame($phase->successors() !== [], !$phase->isLast());
    }

    #[Test]
    #[DataProvider('phases')]
    public function remaining_starts_with_the_phase_itself(Phase $phase): void
    {
        self::assertSame([$phase, ...$phase->successors()], $phase->remaining());
        self::assertSame(count(Phase::cases()) - $phase->position() + 1, count($phase->remaining()));
    }

    /**
     * Skipping forward is allowed because two phases are conditional; standing
     * still and going back are not.
     */
    #[Test]
    #[DataProvider('phasePairs')]
    public function a_pass_only_ever_moves_forward(Phase $from, Phase $to): void
    {
        self::assertSame($to->position() > $from->position(), $from->canTransitionTo($to));
    }

    #[Test]
    #[DataProvider('phases')]
    public function a_phase_cannot_move_to_itself(Phase $phase): void
    {
        self::assertFalse($phase->canTransitionTo($phase));
    }

    #[Test]
    #[DataProvider('allowedMoves')]
    public function an_allowed_move_returns_the_new_phase(Phase $from, Phase $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
        self::assertSame($to, $from->transitionTo($to));
    }

    /**
     * A refused move is a sequencing mistake in the runner, so the message has
     * to name both phases and say what was possible instead — including that
     * going over the same ground is the next attempt rather than a phase.
     */
    #[Test]
    #[DataProvider('refusedMoves')]
    public function a_refused_move_says_what_is_possible_instead(Phase $from, Phase $to): void
    {
        self::assertFalse($from->canTransitionTo($to));

        try {
            $from->transitionTo($to);
        } catch (StateException $refused) {
            self::assertStringContainsString($from->value, $refused->getMessage());
            self::assertStringContainsString($to->value, $refused->getMessage());

            $hint = implode(' ', $refused->hints());

            if ($from->isLast()) {
                self::assertStringContainsString(
                    'end of the pass',
                    $hint,
                    'A pass with nowhere left to go was refused without saying so.',
                );

                return;
            }

            self::assertStringContainsString(
                Phase::initial()->value,
                $hint,
                'A refused backward move did not say that going over the same ground is the next attempt.',
            );

            // The exact list, not merely each member of it: a hint that named
            // every phase would contain all the successors and still tell the
            // user a pass can go somewhere it cannot.
            self::assertStringContainsString(
                'move on to: ' . implode(', ', array_map(
                    static fn (Phase $case): string => $case->value,
                    $from->successors(),
                )) . '.',
                $hint,
                'A refused move did not name exactly the phases the pass could move on to.',
            );

            return;
        }

        self::fail(sprintf('A pass was allowed to go from %s to %s.', $from->value, $to->value));
    }

    /**
     * The agent has produced something by the time the checks run, and not
     * before: a run cut short partway through `executing` is not a candidate.
     */
    #[Test]
    #[DataProvider('phases')]
    public function a_candidate_exists_from_verifying_onwards(Phase $phase): void
    {
        self::assertSame($phase->hasReached(Phase::Verifying), $phase->hasCandidate());
    }

    /**
     * Reaching `committing` is what passing the checks means. A pass stopped
     * during `verifying` has no verdict — some checks having run is not the run
     * having passed.
     */
    #[Test]
    #[DataProvider('phases')]
    public function verification_has_passed_from_committing_onwards(Phase $phase): void
    {
        self::assertSame($phase->hasReached(Phase::Committing), $phase->verificationPassed());
        self::assertFalse(Phase::Verifying->verificationPassed());
    }

    /**
     * `committing` is excluded on purpose: a crash inside it can leave Git and
     * the database disagreeing, and that question is answered by looking at the
     * repository. Where this is true, resume must not create a second commit.
     */
    #[Test]
    #[DataProvider('phases')]
    public function a_commit_is_on_record_from_publishing_onwards(Phase $phase): void
    {
        self::assertSame($phase->hasReached(Phase::Publishing), $phase->commitRecorded());
        self::assertFalse(Phase::Committing->commitRecorded());
    }

    /**
     * The three already-true questions are nested, not independent: nothing is
     * committed that was not verified, and nothing is verified without a
     * candidate to verify.
     */
    #[Test]
    public function the_already_true_questions_are_nested(): void
    {
        foreach (Phase::cases() as $phase) {
            if (!$phase->commitRecorded()) {
                continue;
            }

            self::assertTrue($phase->verificationPassed(), $phase->value . ' has a commit it never verified.');
            self::assertTrue($phase->hasCandidate(), $phase->value . ' has a commit of nothing.');
        }

        foreach (Phase::cases() as $phase) {
            if (!$phase->verificationPassed()) {
                continue;
            }

            self::assertTrue($phase->hasCandidate(), $phase->value . ' verified something that does not exist.');
        }
    }

    /**
     * The partition the recorded phase exists for: a pass with an
     * implementation worth keeping is continued, one without is begun again.
     */
    #[Test]
    #[DataProvider('phases')]
    public function resume_continues_exactly_the_passes_that_have_something_to_keep(Phase $phase): void
    {
        self::assertSame($phase->hasCandidate(), $phase->resumesInPlace());
        self::assertNotSame($phase->resumesInPlace(), $phase->requiresNewAttempt());
    }

    #[Test]
    public function an_interrupted_push_is_retried_without_the_agent(): void
    {
        self::assertTrue(Phase::Publishing->resumesInPlace());
        self::assertTrue(Phase::Publishing->commitRecorded());
        self::assertFalse(Phase::Publishing->invokesAgent());

        self::assertTrue(Phase::Executing->requiresNewAttempt());
        self::assertFalse(Phase::Executing->commitRecorded());
    }

    #[Test]
    #[DataProvider('phases')]
    public function every_phase_reads_as_a_sentence(Phase $phase): void
    {
        self::assertNotSame('', $phase->describe());
        self::assertNotSame('', $phase->resumeAction());
        self::assertNotSame($phase->describe(), $phase->resumeAction());
    }

    #[Test]
    #[DataProvider('phases')]
    public function a_stored_phase_reads_back_as_the_case_that_wrote_it(Phase $phase): void
    {
        self::assertSame($phase, Phase::fromStorage($phase->value));
        self::assertSame($phase, Phase::recorded($phase->value));
    }

    /**
     * Null is the absence of a phase, which is what the column holds for a pass
     * that has not begun or is finished with. An empty string is not that.
     */
    #[Test]
    public function an_absent_phase_is_null_and_nothing_else(): void
    {
        self::assertNull(Phase::recorded(null));

        $this->expectException(StateException::class);

        Phase::recorded('');
    }

    /**
     * A phase this build cannot read means the file was written by a newer
     * Ralph PHP or edited by hand. Guessing is work done twice or work skipped.
     */
    #[Test]
    public function a_phase_this_build_does_not_know_stops_the_run(): void
    {
        try {
            Phase::fromStorage('thinking');
        } catch (StateException $refused) {
            self::assertStringContainsString('thinking', $refused->getMessage());
            self::assertStringContainsString('publishing', implode(' ', $refused->hints()));

            return;
        }

        self::fail('An unknown stored phase was read as a phase the package knows.');
    }

    #[Test]
    public function the_spelling_is_case_sensitive(): void
    {
        $this->expectException(StateException::class);

        Phase::fromStorage('Executing');
    }

    /**
     * Which states carry a phase at all. The nullable column is not an
     * invitation to leave it out: a pass that stopped somewhere records where,
     * because that is the evidence `ralph logs` and `ralph resume` read.
     */
    #[Test]
    public function a_pass_records_a_phase_exactly_while_it_is_somewhere(): void
    {
        self::assertTrue(LifecycleState::Running->recordsPhase());
        self::assertTrue(LifecycleState::Interrupted->recordsPhase());
        self::assertTrue(LifecycleState::Blocked->recordsPhase());
        self::assertTrue(LifecycleState::Failed->recordsPhase());

        self::assertFalse(LifecycleState::Pending->recordsPhase());
        self::assertFalse(LifecycleState::Completed->recordsPhase());
        self::assertFalse(LifecycleState::Skipped->recordsPhase());
    }

    /**
     * A pass that is finished with is not in the middle of anything, and a
     * pending one has not begun; everything else is, or was, somewhere.
     */
    #[Test]
    public function only_an_unstarted_or_finished_pass_has_no_phase(): void
    {
        foreach (LifecycleState::cases() as $state) {
            self::assertSame(
                !$state->isClosed() && $state !== LifecycleState::Pending,
                $state->recordsPhase(),
                sprintf('%s disagrees about whether it records a phase.', $state->value),
            );
        }
    }

    /**
     * @return iterable<string, array{Phase}>
     */
    public static function phases(): iterable
    {
        foreach (Phase::cases() as $phase) {
            yield $phase->value => [$phase];
        }
    }

    /**
     * @return iterable<string, array{Phase, Phase}>
     */
    public static function phasePairs(): iterable
    {
        foreach (Phase::cases() as $one) {
            foreach (Phase::cases() as $other) {
                yield $one->value . ' and ' . $other->value => [$one, $other];
            }
        }
    }

    /**
     * @return iterable<string, array{Phase, Phase}>
     */
    public static function allowedMoves(): iterable
    {
        foreach (Phase::cases() as $from) {
            foreach ($from->successors() as $to) {
                yield $from->value . ' to ' . $to->value => [$from, $to];
            }
        }
    }

    /**
     * Every pair that is not a move forward: standing still, and every way back.
     *
     * @return iterable<string, array{Phase, Phase}>
     */
    public static function refusedMoves(): iterable
    {
        foreach (Phase::cases() as $from) {
            foreach (Phase::cases() as $to) {
                if (in_array($to, $from->successors(), true)) {
                    continue;
                }

                yield $from->value . ' to ' . $to->value => [$from, $to];
            }
        }
    }

    /**
     * Every sequence a pass can actually take, written out independently of the
     * enum. There is no sequence that publishes without committing: a pass with
     * no commit has nothing to push.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function sequences(): iterable
    {
        yield 'pushing enabled' => [
            ['preparing', 'executing', 'verifying', 'committing', 'publishing', 'finalizing'],
        ];
        yield 'pushing off' => [
            ['preparing', 'executing', 'verifying', 'committing', 'finalizing'],
        ];
        yield 'already satisfied, no commit' => [
            ['preparing', 'executing', 'verifying', 'finalizing'],
        ];
    }
}
