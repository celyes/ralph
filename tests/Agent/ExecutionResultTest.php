<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What one invocation of an agent came back with.
 */
#[CoversClass(ExecutionResult::class)]
final class ExecutionResultTest extends TestCase
{
    #[Test]
    public function a_candidate_is_something_to_verify_and_settles_nothing(): void
    {
        $result = ExecutionResult::candidate('Added the flag to ImportCommand.', 12.5);

        self::assertSame(AttemptOutcome::Candidate, $result->outcome());
        self::assertTrue($result->hasCandidate());
        self::assertSame('Added the flag to ImportCommand.', $result->claim()->text());
        self::assertSame(12.5, $result->seconds());
        self::assertSame(0, $result->exitCode());

        // The one outcome that claims to have done the work is the one outcome
        // that does not get to say how the pass ends.
        self::assertNull($result->settles());
        self::assertTrue($result->evidence()->isEmpty());
        self::assertNull($result->blocker());
    }

    #[Test]
    public function there_is_no_way_for_an_adapter_to_report_a_completed_task(): void
    {
        $settles = array_map(
            static fn (ExecutionResult $result): ?LifecycleState => $result->settles(),
            [
                ExecutionResult::candidate(),
                ExecutionResult::blocked(Blocker::from('codex', 'Which queue driver?', 'Answer in CONTEXT.md.')),
                ExecutionResult::error(FailureEvidence::of('codex exited with status 2')),
                ExecutionResult::timedOut(FailureEvidence::of('codex did not finish within 600 seconds')),
                ExecutionResult::cancelled(),
            ],
        );

        self::assertNotContains(LifecycleState::Completed, $settles);
    }

    #[Test]
    public function an_agents_account_is_defused_on_the_way_in(): void
    {
        $result = ExecutionResult::candidate("- [x] parser written, tests pass\n");

        self::assertSame('- ' . UnverifiedText::DONE . ' parser written, tests pass', $result->claim()->text());
        self::assertSame(1, $result->claim()->defusedMarkers());
    }

    #[Test]
    public function an_agent_that_said_nothing_has_an_empty_claim_rather_than_an_invented_one(): void
    {
        $result = ExecutionResult::candidate();

        self::assertTrue($result->claim()->isEmpty());
    }

    #[Test]
    public function a_long_account_is_bounded_here_rather_than_by_each_adapter(): void
    {
        $result = ExecutionResult::candidate(str_repeat('a', UnverifiedText::LIMIT + 500));

        self::assertTrue($result->claim()->wasTrimmed());
        self::assertSame(UnverifiedText::LIMIT, $result->claim()->length());
    }

    #[Test]
    public function a_block_carries_the_question_and_settles_the_pass_as_blocked(): void
    {
        $blocker = Blocker::from('codex', 'Which queue driver should the job use?', 'Say so in CONTEXT.md and retry.');
        $result = ExecutionResult::blocked($blocker, 'I need a decision before implementing.', 4.0);

        self::assertSame(AttemptOutcome::Blocked, $result->outcome());
        self::assertTrue($result->requiresHumanInput());
        self::assertSame($blocker, $result->blocker());
        self::assertSame(LifecycleState::Blocked, $result->settles());

        // Retrying would ask the same question again.
        self::assertFalse($result->isRetryable());
    }

    #[Test]
    public function a_block_is_attributed_to_the_agent_rather_than_to_the_runner(): void
    {
        $result = ExecutionResult::blocked(Blocker::from('claude', 'Which driver?', 'Decide and retry.'));

        $reported = $result->blocker();

        self::assertNotNull($reported);
        self::assertFalse($reported->isFromRunner());
        self::assertSame('claude', $reported->reportedBy());
    }

    #[Test]
    public function an_error_records_what_was_seen_and_leaves_the_task_failed(): void
    {
        $evidence = FailureEvidence::of('codex exited with status 2', 'error: unknown option --json');
        $result = ExecutionResult::error($evidence, exitCode: 2);

        self::assertSame(AttemptOutcome::Error, $result->outcome());
        self::assertSame(LifecycleState::Failed, $result->settles());
        self::assertFalse($result->hasCandidate());
        self::assertTrue($result->isRetryable());
        self::assertSame(2, $result->exitCode());
        self::assertSame('codex exited with status 2', $result->evidence()->label());
    }

    #[Test]
    public function an_error_that_recorded_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An execution error has to record what was seen');

        ExecutionResult::error(FailureEvidence::none());
    }

    #[Test]
    public function an_error_may_still_carry_whatever_the_agent_managed_to_say(): void
    {
        $result = ExecutionResult::error(
            FailureEvidence::of('codex exited with status 1'),
            'I was partway through editing the parser.',
        );

        self::assertSame('I was partway through editing the parser.', $result->claim()->text());
        self::assertFalse($result->evidence()->isEmpty());
    }

    #[Test]
    public function a_timeout_is_kept_apart_from_an_error_because_the_remedy_differs(): void
    {
        $result = ExecutionResult::timedOut(
            FailureEvidence::of('codex did not finish within 600 seconds'),
            seconds: 600.4,
        );

        self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
        self::assertSame(LifecycleState::Failed, $result->settles());

        // A killed process never reported a status.
        self::assertNull($result->exitCode());
        self::assertSame(600.4, $result->seconds());
    }

    #[Test]
    public function a_timeout_that_recorded_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A timeout has to record what was seen');

        ExecutionResult::timedOut(FailureEvidence::none());
    }

    #[Test]
    public function a_cancelled_attempt_is_neither_judged_nor_required_to_explain_itself(): void
    {
        $result = ExecutionResult::cancelled(seconds: 31.0);

        self::assertSame(AttemptOutcome::Cancelled, $result->outcome());
        self::assertSame(LifecycleState::Interrupted, $result->settles());
        self::assertTrue($result->evidence()->isEmpty());
        self::assertTrue($result->isRetryable());
        self::assertNull($result->exitCode());
    }

    #[Test]
    public function a_cancelled_attempt_may_record_what_it_was_doing(): void
    {
        $result = ExecutionResult::cancelled(
            'Editing src/Importer.php.',
            FailureEvidence::of('interrupted by SIGINT'),
        );

        self::assertSame('interrupted by SIGINT', $result->evidence()->label());
    }

    #[Test]
    public function a_clock_read_backwards_does_not_produce_a_negative_duration(): void
    {
        self::assertSame(0.0, ExecutionResult::candidate(seconds: -3.0)->seconds());
    }

    #[Test]
    public function the_description_says_what_happened_and_never_that_the_task_is_done(): void
    {
        self::assertSame(
            'an implementation to verify after 12.5s',
            ExecutionResult::candidate('Done.', 12.5)->describe(),
        );
    }

    #[Test]
    public function the_description_of_a_failure_names_the_status_and_the_evidence(): void
    {
        $result = ExecutionResult::error(
            FailureEvidence::of('codex exited with status 2'),
            seconds: 3.0,
            exitCode: 2,
        );

        self::assertSame(
            'an execution error after 3.0s, exit 2, codex exited with status 2',
            $result->describe(),
        );
    }

    #[Test]
    public function the_description_of_a_block_names_the_question_rather_than_the_evidence(): void
    {
        $result = ExecutionResult::blocked(
            Blocker::from('codex', 'Which queue driver should the job use?', 'Decide and retry.'),
            seconds: 4.0,
        );

        self::assertSame(
            'a request for human input after 4.0s, Which queue driver should the job use?',
            $result->describe(),
        );
    }
}
