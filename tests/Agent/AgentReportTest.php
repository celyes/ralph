<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentReport;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\Support\UnverifiedText;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an agent said it did, once it has been read.
 */
#[CoversClass(AgentReport::class)]
final class AgentReportTest extends TestCase
{
    #[Test]
    public function a_candidate_says_there_is_something_to_check(): void
    {
        $report = AgentReport::candidate('Added the --dry-run option and a test for it.');

        self::assertSame(AttemptOutcome::Candidate, $report->outcome());
        self::assertTrue($report->isCandidate());
        self::assertFalse($report->requiresHumanInput());
        self::assertFalse($report->isError());
        self::assertSame('Added the --dry-run option and a test for it.', $report->summary()->text());
        self::assertNull($report->nextAction());
        self::assertNull($report->blocker('codex'));
    }

    /**
     * Which of the three cases this is, is the part the loop acts on. An agent
     * that did the work and described none of it has still said that.
     */
    #[Test]
    public function a_candidate_may_describe_itself_in_no_words_at_all(): void
    {
        $report = AgentReport::candidate();

        self::assertTrue($report->isCandidate());
        self::assertTrue($report->summary()->isEmpty());
        self::assertSame('reported a candidate implementation', $report->describe());
    }

    #[Test]
    public function a_blocker_is_attributed_to_the_agent_that_asked(): void
    {
        $report = AgentReport::blocked(
            'The orders table has no column for this.',
            'Decide whether a migration belongs in this task.',
        );

        self::assertSame(AttemptOutcome::Blocked, $report->outcome());
        self::assertTrue($report->requiresHumanInput());
        self::assertSame('Decide whether a migration belongs in this task.', $report->nextAction());

        $blocker = $report->blocker('codex');

        self::assertInstanceOf(Blocker::class, $blocker);
        self::assertSame('codex', $blocker->reportedBy());
        self::assertFalse($blocker->isFromRunner());
        self::assertSame('The orders table has no column for this.', $blocker->reason());
        self::assertSame('Decide whether a migration belongs in this task.', $blocker->nextAction());
    }

    /**
     * An attempt that stopped to ask a question and recorded no question leaves
     * a user with a halted run and nothing to act on. Refused one step before
     * {@see \Celyes\RalphPhp\Agent\ExecutionResult::blocked()} would refuse it,
     * so that a reader can turn it into evidence instead of an exception.
     */
    #[Test]
    public function a_question_without_a_next_action_is_not_a_report(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('what is needed');

        AgentReport::blocked('The orders table has no column for this.', "  \n ");
    }

    #[Test]
    public function a_question_without_a_question_is_not_a_report(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('what the decision is');

        AgentReport::blocked('', 'Decide something.');
    }

    #[Test]
    public function an_error_has_to_say_what_the_agent_ran_into(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('what the agent ran into');

        AgentReport::error('   ');
    }

    #[Test]
    public function an_error_report_is_the_agents_own_account_of_failing(): void
    {
        $report = AgentReport::error('The repository has no composer.json, so there is nothing to change.');

        self::assertSame(AttemptOutcome::Error, $report->outcome());
        self::assertTrue($report->isError());
        self::assertNull($report->blocker('claude'));
        self::assertStringContainsString('could not do the work', $report->describe());
    }

    /**
     * The summary is a model's prose arriving in the package, so the checkbox
     * gate applies to it here rather than being remembered in each adapter.
     */
    #[Test]
    public function a_summary_that_checks_itself_off_is_defused_on_the_way_in(): void
    {
        $report = AgentReport::candidate("- [x] implemented and verified\n");

        self::assertSame('- ' . UnverifiedText::DONE . ' implemented and verified', $report->summary()->text());
        self::assertSame(1, $report->summary()->defusedMarkers());
    }

    #[Test]
    public function a_long_summary_is_bounded_like_any_other_claim(): void
    {
        $report = AgentReport::candidate(str_repeat('x', UnverifiedText::LIMIT + 200));

        self::assertSame(UnverifiedText::LIMIT, $report->summary()->length());
        self::assertTrue($report->summary()->wasTrimmed());
    }

    #[Test]
    public function the_description_quotes_the_start_of_the_account(): void
    {
        $report = AgentReport::blocked('Which queue driver should the worker use?', 'Say so in CONTEXT.md.');

        self::assertSame(
            'asked for human input: Which queue driver should the worker use?',
            $report->describe(),
        );
    }
}
