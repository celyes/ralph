<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\Blocker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Why a pass stopped needing a person, and what that person should do.
 *
 * Three things are kept and each of them earns its place. The reason, because a
 * run that stops without one is unexplainable. The next action, because a
 * reason on its own reliably becomes a user staring at "blocked: ambiguous
 * branch ownership" with no idea what is expected of them. And the reporter,
 * because an agent asking for human input and the runner refusing to touch a
 * branch it does not own are both blockers, and attributing one to the other is
 * the kind of confusion that makes a tool untrustworthy.
 */
#[CoversClass(Blocker::class)]
final class BlockerTest extends TestCase
{
    #[Test]
    public function a_blocker_the_runner_raised_is_attributed_to_the_runner(): void
    {
        $blocker = Blocker::raised(
            'The branch ralph/TASK-19E0214A is owned by unrelated work.',
            'Delete the branch yourself, or let the pass that owns it finish.',
        );

        self::assertSame('The branch ralph/TASK-19E0214A is owned by unrelated work.', $blocker->reason());
        self::assertSame(
            'Delete the branch yourself, or let the pass that owns it finish.',
            $blocker->nextAction(),
        );
        self::assertSame(Blocker::RUNNER, $blocker->reportedBy());
        self::assertTrue($blocker->isFromRunner());
    }

    /**
     * The distinction the reporter exists for: the agent's words are not the
     * package's judgement.
     */
    #[Test]
    public function a_blocker_the_agent_reported_is_not_the_runners_judgement(): void
    {
        $blocker = Blocker::from(
            'codex',
            'The task does not say which database the migration targets.',
            'Add the target to the task details and retry.',
        );

        self::assertSame('codex', $blocker->reportedBy());
        self::assertFalse($blocker->isFromRunner());
        self::assertStringContainsString('reported by codex', $blocker->describe());
    }

    #[Test]
    #[DataProvider('incompleteBlockers')]
    public function every_part_of_a_blocker_must_say_something(
        string $reporter,
        string $reason,
        string $action,
        string $expected,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        Blocker::from($reporter, $reason, $action);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function incompleteBlockers(): iterable
    {
        yield 'no reason' => ['runner', '   ', 'Retry it.', 'must state its reason'];
        yield 'no next action' => ['runner', 'It broke.', '', 'must state its next action'];
        yield 'no reporter' => [' ', 'It broke.', 'Retry it.', 'must state its reporter'];
    }

    #[Test]
    public function a_blocker_is_stored_without_surrounding_whitespace(): void
    {
        $blocker = Blocker::raised("  It broke.\n", "\tRetry it.  ");

        self::assertSame('It broke.', $blocker->reason());
        self::assertSame('Retry it.', $blocker->nextAction());
    }

    #[Test]
    public function a_blocker_survives_a_trip_through_its_column(): void
    {
        $original = Blocker::from('claude', 'Needs a decision about the API shape.', 'Decide and retry.');

        $read = Blocker::fromStorage($original->toStorage());

        self::assertTrue($read->equals($original));
        self::assertSame('claude', $read->reportedBy());
    }

    #[Test]
    public function the_column_holds_json_with_the_three_parts(): void
    {
        $stored = Blocker::from('codex', 'It broke.', 'Retry it.')->toStorage();

        self::assertSame(
            ['reason' => 'It broke.', 'next_action' => 'Retry it.', 'reported_by' => 'codex'],
            json_decode($stored, true, 8, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function two_blockers_differing_in_any_part_are_not_equal(): void
    {
        $blocker = Blocker::from('codex', 'It broke.', 'Retry it.');

        self::assertTrue($blocker->equals(Blocker::from('codex', 'It broke.', 'Retry it.')));
        self::assertFalse($blocker->equals(Blocker::from('claude', 'It broke.', 'Retry it.')));
        self::assertFalse($blocker->equals(Blocker::from('codex', 'It failed.', 'Retry it.')));
        self::assertFalse($blocker->equals(Blocker::from('codex', 'It broke.', 'Fix it.')));
    }

    /**
     * No blocker is the ordinary case — most passes are not blocked — so a null
     * column reads as one rather than as a missing value.
     */
    #[Test]
    #[DataProvider('emptyColumns')]
    public function a_column_with_nothing_in_it_is_no_blocker(?string $value): void
    {
        self::assertNull(Blocker::recorded($value));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptyColumns(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n "];
    }

    #[Test]
    public function a_recorded_blocker_is_read_from_a_present_column(): void
    {
        $stored = Blocker::raised('It broke.', 'Retry it.')->toStorage();

        self::assertSame('It broke.', Blocker::recorded($stored)?->reason());
    }

    /**
     * A blocker written before this type existed, or by a user editing a row,
     * is still worth showing rather than refusing.
     */
    #[Test]
    public function a_bare_string_in_the_column_is_read_as_a_reason(): void
    {
        $read = Blocker::fromStorage('the worktree has unrelated uncommitted changes');

        self::assertSame('the worktree has unrelated uncommitted changes', $read->reason());
        self::assertTrue($read->isFromRunner());
    }

    /**
     * And it under-claims about the rest: inventing an action for a blocker
     * nobody described is how a user ends up doing the wrong thing
     * confidently.
     */
    #[Test]
    public function a_bare_string_says_that_no_next_action_was_recorded(): void
    {
        $read = Blocker::fromStorage('it broke');

        self::assertStringContainsString('No next action was recorded', $read->nextAction());
        self::assertStringContainsString('read the task log', $read->nextAction());
    }

    /**
     * JSON that is valid but is not an object — a bare number, a list — is
     * treated the same way as prose, not read for keys it does not have.
     */
    #[Test]
    #[DataProvider('jsonThatIsNotAnObject')]
    public function valid_json_that_is_not_an_object_is_read_as_a_reason(string $stored): void
    {
        $read = Blocker::fromStorage($stored);

        self::assertSame(trim($stored), $read->reason());
        self::assertStringContainsString('No next action was recorded', $read->nextAction());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonThatIsNotAnObject(): iterable
    {
        yield 'a number' => ['42'];
        yield 'a list' => ['["it broke"]'];
        yield 'a quoted string' => ['"it broke"'];
        yield 'a boolean' => ['true'];
    }

    #[Test]
    public function a_stored_object_missing_its_next_action_still_reads(): void
    {
        $read = Blocker::fromStorage('{"reason":"it broke","reported_by":"codex"}');

        self::assertSame('it broke', $read->reason());
        self::assertSame('codex', $read->reportedBy());
        self::assertStringContainsString('No next action was recorded', $read->nextAction());
    }

    /**
     * An object with no reporter is read as the runner's, which is the only
     * attribution the package can make without guessing at an agent's name.
     */
    #[Test]
    public function a_stored_object_missing_its_reporter_is_read_as_the_runners(): void
    {
        $read = Blocker::fromStorage('{"reason":"it broke","next_action":"retry"}');

        self::assertTrue($read->isFromRunner());
    }

    #[Test]
    public function a_stored_object_with_no_reason_in_it_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must state its reason');

        Blocker::fromStorage('{"next_action":"retry","reported_by":"runner"}');
    }

    /**
     * A part of the stored object that is not text is ignored rather than cast,
     * since a reason of `{"a":1}` is not a sentence.
     */
    #[Test]
    public function stored_parts_of_the_wrong_type_are_not_read_as_text(): void
    {
        $read = Blocker::fromStorage('{"reason":"it broke","next_action":17,"reported_by":["codex"]}');

        self::assertSame('it broke', $read->reason());
        self::assertStringContainsString('No next action was recorded', $read->nextAction());
        self::assertTrue($read->isFromRunner());
    }

    /**
     * A blocker is read in a status table and in a terminal, so an agent that
     * writes an essay has it cut down — the full text belongs in the log.
     */
    #[Test]
    public function a_very_long_reason_is_truncated(): void
    {
        $blocker = Blocker::raised(str_repeat('x', Blocker::LIMIT + 500), 'Retry it.');

        self::assertSame(Blocker::LIMIT + 1, mb_strlen($blocker->reason()));
        self::assertStringEndsWith('…', $blocker->reason());
    }

    #[Test]
    public function a_reason_at_the_limit_is_kept_whole(): void
    {
        $reason = str_repeat('x', Blocker::LIMIT);

        self::assertSame($reason, Blocker::raised($reason, 'Retry it.')->reason());
    }

    #[Test]
    public function a_very_long_next_action_is_truncated_too(): void
    {
        $blocker = Blocker::raised('It broke.', str_repeat('y', Blocker::LIMIT + 1));

        self::assertSame(Blocker::LIMIT + 1, mb_strlen($blocker->nextAction()));
        self::assertStringEndsWith('…', $blocker->nextAction());
    }

    /**
     * A status table has one line per pass, so the label is one line however
     * many the reason runs to.
     */
    #[Test]
    public function the_status_label_is_a_single_short_line(): void
    {
        $label = Blocker::raised(
            "The agent asked for a decision.\nIt wanted to know which database to target.",
            'Decide and retry.',
        )->label();

        self::assertStringNotContainsString("\n", $label);
        self::assertStringContainsString('The agent asked for a decision.', $label);
    }

    /**
     * The end of a run says all three parts, because that is the moment the
     * user has to act on them.
     */
    #[Test]
    public function the_description_carries_the_reason_the_reporter_and_the_action(): void
    {
        $blocker = Blocker::from('codex', 'It needs a decision.', 'Decide and retry.');

        self::assertSame(
            'It needs a decision. (reported by codex) Next: Decide and retry.',
            $blocker->describe(),
        );
    }
}
