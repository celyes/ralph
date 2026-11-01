<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\State\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a pass's commit stands with the remote.
 *
 * The four states exist so that a failed publication is recoverable without
 * repeating any work, and the distinctions between them are what make that
 * possible: resume has to tell "never meant to push" from "has a commit waiting
 * to go out" from "attempted and refused". Most of what is tested here is that
 * those three stay apart, and that the combinations which cannot be true — a
 * failure with no reason, a remote named by a pass that was never going to
 * push — are refused rather than stored and puzzled over later.
 */
#[CoversClass(PushState::class)]
#[CoversClass(PushResult::class)]
final class PushResultTest extends TestCase
{
    /**
     * The spellings go into `generations.push_state`, so they are pinned to
     * literals and to the order the column's CHECK constraint is built from.
     */
    #[Test]
    public function the_stored_spellings_and_their_order_are_fixed(): void
    {
        self::assertSame(
            ['not_required', 'pending', 'published', 'failed'],
            PushState::values(),
        );
        self::assertSame(PushState::values(), Schema::pushStates());
    }

    #[Test]
    public function every_case_is_offered_as_a_list(): void
    {
        self::assertSame(PushState::cases(), PushState::all());
        self::assertCount(4, PushState::all());
    }

    /**
     * Pushing is opt-in, so a pass that is not configured to publish starts
     * settled rather than owing anything.
     */
    #[Test]
    public function the_starting_state_follows_whether_publication_is_enabled(): void
    {
        self::assertSame(PushState::Pending, PushState::required(true));
        self::assertSame(PushState::NotRequired, PushState::required(false));
    }

    #[Test]
    #[DataProvider('states')]
    public function a_state_reads_back_from_its_stored_spelling(PushState $state): void
    {
        self::assertSame($state, PushState::fromStorage($state->value));
    }

    /**
     * @return iterable<string, array{PushState}>
     */
    public static function states(): iterable
    {
        foreach (PushState::cases() as $state) {
            yield $state->value => [$state];
        }
    }

    #[Test]
    public function a_stored_state_this_build_does_not_know_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"pushed" is not a push state');

        PushState::fromStorage('pushed');
    }

    /**
     * The refusal names what the column may hold, since a user reading it has
     * the database in front of them and not this file.
     */
    #[Test]
    public function the_refusal_lists_the_states_a_column_may_hold(): void
    {
        try {
            PushState::fromStorage('');
            self::fail('An empty push state should not be readable.');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString(implode(', ', PushState::values()), $refusal->getMessage());
        }
    }

    /**
     * Outstanding and satisfied partition the four states: every state is
     * exactly one of the two, which is what lets finalisation ask one question.
     */
    #[Test]
    #[DataProvider('states')]
    public function publication_is_either_outstanding_or_satisfied(PushState $state): void
    {
        self::assertNotSame(
            $state->isOutstanding(),
            $state->isSatisfied(),
            sprintf('%s must be exactly one of outstanding and satisfied.', $state->value),
        );
    }

    /**
     * A refused push is still a push that has to happen, so it is outstanding
     * alongside a pending one — and it is what resume retries.
     */
    #[Test]
    public function a_refused_push_is_still_owed(): void
    {
        self::assertTrue(PushState::Failed->isOutstanding());
        self::assertTrue(PushState::Failed->needsRetry());
        self::assertTrue(PushState::Pending->needsRetry());
        self::assertFalse(PushState::Published->needsRetry());
        self::assertFalse(PushState::NotRequired->needsRetry());
    }

    /**
     * The distinction the class exists for: a task that completed without a
     * push and a task whose branch is on the remote are different facts, even
     * though neither holds the task back.
     */
    #[Test]
    public function not_required_is_not_the_same_fact_as_published(): void
    {
        self::assertNotSame(PushState::NotRequired, PushState::Published);
        self::assertTrue(PushState::NotRequired->isSatisfied());
        self::assertTrue(PushState::Published->isSatisfied());
        self::assertNotSame(PushState::NotRequired->label(), PushState::Published->label());
        self::assertNotSame(PushState::NotRequired->describe(), PushState::Published->describe());
    }

    #[Test]
    public function only_a_refused_push_reports_as_failed(): void
    {
        foreach (PushState::cases() as $state) {
            self::assertSame($state === PushState::Failed, $state->hasFailed());
        }
    }

    #[Test]
    #[DataProvider('states')]
    public function every_state_has_a_label_and_a_sentence(PushState $state): void
    {
        self::assertNotSame('', $state->label());
        self::assertNotSame('', $state->describe());
    }

    #[Test]
    public function a_pass_that_does_not_publish_records_no_remote_and_no_error(): void
    {
        $push = PushResult::notRequired();

        self::assertSame(PushState::NotRequired, $push->state());
        self::assertNull($push->remote());
        self::assertNull($push->error());
        self::assertTrue($push->isSatisfied());
        self::assertFalse($push->isOutstanding());
        self::assertFalse($push->hasFailed());
    }

    #[Test]
    public function a_pending_push_records_the_remote_it_is_aimed_at(): void
    {
        $push = PushResult::pending('origin');

        self::assertSame(PushState::Pending, $push->state());
        self::assertSame('origin', $push->remote());
        self::assertNull($push->error());
        self::assertTrue($push->isOutstanding());
    }

    #[Test]
    public function what_a_configuration_implies_before_anything_is_attempted(): void
    {
        self::assertSame(PushState::Pending, PushResult::expected(true, 'origin')->state());
        self::assertSame('origin', PushResult::expected(true, 'origin')->remote());

        $off = PushResult::expected(false, 'origin');

        self::assertSame(PushState::NotRequired, $off->state());
        self::assertNull($off->remote(), 'A pass that will not push names no remote.');
    }

    #[Test]
    public function a_published_push_is_settled(): void
    {
        $push = PushResult::published('origin');

        self::assertSame(PushState::Published, $push->state());
        self::assertSame('origin', $push->remote());
        self::assertTrue($push->isSatisfied());
    }

    #[Test]
    public function a_failed_push_keeps_the_reason_git_gave(): void
    {
        $push = PushResult::failed('origin', "! [rejected] main -> main (non-fast-forward)\nhint: …");

        self::assertTrue($push->hasFailed());
        self::assertTrue($push->isOutstanding());
        self::assertSame('origin', $push->remote());
        self::assertStringContainsString('non-fast-forward', (string) $push->error());
    }

    /**
     * "It failed" with nothing after it is the one failure report that helps
     * nobody.
     */
    #[Test]
    public function a_failed_push_must_say_why(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must record why it failed');

        PushResult::failed('origin', "  \n ");
    }

    #[Test]
    #[DataProvider('resultsThatNeedARemote')]
    public function a_push_that_is_going_somewhere_must_name_where(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records the remote it is aimed at');

        $build();
    }

    /**
     * @return iterable<string, array{callable(): PushResult}>
     */
    public static function resultsThatNeedARemote(): iterable
    {
        yield 'pending' => [static fn (): PushResult => PushResult::pending('   ')];
        yield 'published' => [static fn (): PushResult => PushResult::published('')];
        yield 'failed' => [static fn (): PushResult => PushResult::failed('', 'rejected')];
    }

    #[Test]
    public function a_remote_is_stored_without_its_surrounding_whitespace(): void
    {
        self::assertSame('origin', PushResult::pending("  origin \n")->remote());
    }

    /**
     * A long rejection is cut down, and the cut is marked so an excerpt is
     * never mistaken for the whole message.
     */
    #[Test]
    public function a_very_long_reason_is_truncated_and_says_so(): void
    {
        $push = PushResult::failed('origin', str_repeat('x', PushResult::REASON_LIMIT + 500));

        $error = (string) $push->error();

        self::assertStringContainsString('truncated', $error);
        self::assertStringContainsString('see the task log', $error);
        self::assertStringStartsWith(str_repeat('x', 100), $error);
    }

    #[Test]
    public function a_reason_at_the_limit_is_kept_whole(): void
    {
        $reason = str_repeat('x', PushResult::REASON_LIMIT);

        self::assertSame($reason, PushResult::failed('origin', $reason)->error());
    }

    /**
     * Moving to a new state returns a new value, so the record of the attempt
     * that failed is still readable by whatever is reporting on it.
     */
    #[Test]
    public function succeeding_leaves_the_earlier_result_readable(): void
    {
        $failed = PushResult::failed('origin', 'rejected');

        $published = $failed->succeeded();

        self::assertSame(PushState::Published, $published->state());
        self::assertNull($published->error(), 'A success carries no failure reason.');
        self::assertSame('origin', $published->remote());
        self::assertTrue($failed->hasFailed(), 'The failed result must not have been mutated.');
        self::assertSame('rejected', $failed->error());
    }

    #[Test]
    public function a_pending_push_can_be_refused_and_keeps_its_remote(): void
    {
        $refused = PushResult::pending('upstream')->refused('remote: permission denied');

        self::assertTrue($refused->hasFailed());
        self::assertSame('upstream', $refused->remote());
        self::assertStringContainsString('permission denied', (string) $refused->error());
    }

    /**
     * There is nothing a pass that was never going to push could have pushed,
     * so neither outcome can be recorded against it.
     */
    #[Test]
    public function a_push_that_was_not_required_cannot_have_succeeded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot have succeeded');

        PushResult::notRequired()->succeeded();
    }

    #[Test]
    public function a_push_that_was_not_required_cannot_have_failed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot have failed');

        PushResult::notRequired()->refused('rejected');
    }

    #[Test]
    public function refusing_without_a_reason_is_still_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must record why it failed');

        PushResult::pending('origin')->refused('');
    }

    #[Test]
    #[DataProvider('results')]
    public function a_result_survives_a_trip_through_its_three_columns(PushResult $push): void
    {
        $columns = $push->toColumns();

        $read = PushResult::fromStorage($columns['state'], $columns['remote'], $columns['error']);

        self::assertSame($push->state(), $read->state());
        self::assertSame($push->remote(), $read->remote());
        self::assertSame($push->error(), $read->error());
    }

    /**
     * @return iterable<string, array{PushResult}>
     */
    public static function results(): iterable
    {
        yield 'not required' => [PushResult::notRequired()];
        yield 'pending' => [PushResult::pending('origin')];
        yield 'published' => [PushResult::published('origin')];
        yield 'failed' => [PushResult::failed('origin', 'rejected: non-fast-forward')];
    }

    #[Test]
    public function the_columns_a_row_holds_are_the_state_remote_and_error(): void
    {
        self::assertSame(
            ['state' => 'failed', 'remote' => 'origin', 'error' => 'rejected'],
            PushResult::failed('origin', 'rejected')->toColumns(),
        );
        self::assertSame(
            ['state' => 'not_required', 'remote' => null, 'error' => null],
            PushResult::notRequired()->toColumns(),
        );
    }

    /**
     * A row that was never going to push cannot have kept a remote, whatever
     * else the columns say — so the stray values are dropped rather than read
     * back as a pass that owes a push.
     */
    #[Test]
    public function a_stored_not_required_row_ignores_a_stray_remote(): void
    {
        $read = PushResult::fromStorage('not_required', 'origin', 'rejected');

        self::assertSame(PushState::NotRequired, $read->state());
        self::assertNull($read->remote());
        self::assertNull($read->error());
    }

    #[Test]
    #[DataProvider('rowsMissingTheirRemote')]
    public function a_stored_push_that_names_no_remote_is_refused(string $state, ?string $remote): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names no remote');

        PushResult::fromStorage($state, $remote, null);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function rowsMissingTheirRemote(): iterable
    {
        yield 'pending, null' => ['pending', null];
        yield 'pending, blank' => ['pending', '  '];
        yield 'published, null' => ['published', null];
        yield 'failed, null' => ['failed', null];
    }

    /**
     * A failure whose reason was lost says so, rather than being read as a
     * failure with nothing wrong with it.
     */
    #[Test]
    public function a_stored_failure_with_no_reason_recorded_says_that(): void
    {
        $read = PushResult::fromStorage('failed', 'origin', null);

        self::assertTrue($read->hasFailed());
        self::assertSame('The reason was not recorded.', $read->error());
    }

    #[Test]
    public function a_stored_state_outside_the_vocabulary_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a push state');

        PushResult::fromStorage('shipped', 'origin', null);
    }

    #[Test]
    public function a_result_describes_itself_for_a_status_line(): void
    {
        self::assertSame(
            'publication is not enabled for this task',
            PushResult::notRequired()->describe(),
        );
        self::assertSame('pushed "origin"', PushResult::published('origin')->describe());
        self::assertSame('push pending "origin"', PushResult::pending('origin')->describe());
        self::assertSame('push failed', PushResult::failed('origin', 'rejected')->label());
    }

    /**
     * A status line is one line, so a multi-line rejection is reported by its
     * first line and the rest left to the log.
     */
    #[Test]
    public function a_multi_line_rejection_is_described_by_its_first_line(): void
    {
        $push = PushResult::failed('origin', "! [rejected] main -> main\nhint: fetch first\nhint: see push --help");

        self::assertSame(
            'push to "origin" failed: ! [rejected] main -> main',
            $push->describe(),
        );
    }
}
