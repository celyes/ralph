<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\ReportReader;
use Celyes\RalphPhp\State\AttemptOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The format an agent says which of the three things it did in.
 *
 * Mostly one assertion in several shapes: the half of the protocol an agent is
 * told about and the half the package parses have to be the same protocol.
 */
#[CoversClass(ResultProtocol::class)]
final class ResultProtocolTest extends TestCase
{
    /**
     * The invariant the rest of section E, item 5 rests on. An example that the
     * reader would refuse is an instruction to fail, and nothing else in the
     * package would notice: every attempt would come back an execution error
     * for reporting in the form it was asked to report in.
     */
    #[Test]
    public function the_example_in_the_instructions_is_read_back_as_a_report(): void
    {
        $reading = ReportReader::readText(ResultProtocol::instructions());

        self::assertTrue($reading->hasReport(), $reading->describe());
        self::assertSame(AttemptOutcome::Candidate, $reading->report()?->outcome());
    }

    #[Test]
    public function every_declarable_outcome_has_an_example_that_is_read_back(): void
    {
        foreach (ResultProtocol::outcomes() as $name) {
            $outcome = ResultProtocol::outcomeNamed($name);
            self::assertNotNull($outcome);

            $block = sprintf("```%s\n%s\n```", ResultProtocol::FENCE, ResultProtocol::example($outcome));
            $reading = ReportReader::readText($block);

            self::assertTrue($reading->hasReport(), $name . ': ' . $reading->describe());
            self::assertSame($outcome, $reading->report()?->outcome());
        }
    }

    #[Test]
    public function the_three_declarable_outcomes_are_the_three_an_agent_may_claim(): void
    {
        self::assertSame(['candidate', 'blocked', 'error'], ResultProtocol::outcomes());
    }

    /**
     * A timeout and a cancellation are things the package watched happen to a
     * process. An agent that could declare them could report its own killing
     * as an orderly stop, and the run's own time limit would be answerable by
     * the thing it is there to bound.
     */
    #[Test]
    public function an_agent_cannot_declare_an_outcome_only_the_runner_observes(): void
    {
        self::assertNull(ResultProtocol::outcomeNamed(AttemptOutcome::TimedOut->value));
        self::assertNull(ResultProtocol::outcomeNamed(AttemptOutcome::Cancelled->value));
        self::assertFalse(ResultProtocol::isDeclarable(AttemptOutcome::TimedOut));
        self::assertFalse(ResultProtocol::isDeclarable(AttemptOutcome::Cancelled));
        self::assertTrue(ResultProtocol::isDeclarable(AttemptOutcome::Candidate));
    }

    #[Test]
    public function an_unknown_outcome_name_stands_for_nothing(): void
    {
        self::assertNull(ResultProtocol::outcomeNamed('completed'));
        self::assertNull(ResultProtocol::outcomeNamed('done'));
        self::assertNull(ResultProtocol::outcomeNamed(''));
    }

    /**
     * The instructions are read by an agent and by the user reading the same
     * briefing in a log, so they have to say what each outcome commits the
     * runner to — and that an exit status alone finishes nothing.
     */
    #[Test]
    public function the_instructions_say_what_each_outcome_does_and_what_silence_costs(): void
    {
        $instructions = ResultProtocol::instructions();

        self::assertStringContainsString('# Reporting back', $instructions);

        foreach (ResultProtocol::outcomes() as $name) {
            self::assertStringContainsString('`' . $name . '`', $instructions);
        }

        self::assertStringContainsString(ResultProtocol::NEXT_ACTION, $instructions);
        self::assertStringContainsString('verification commands', $instructions);
        self::assertStringContainsString('execution error whatever its exit status', $instructions);
        self::assertStringContainsString('the last one is read', $instructions);
    }

    /**
     * An example built by hand drifts from the keys the reader looks for. These
     * come from the same constants, which is what makes the round trip above
     * worth anything.
     */
    #[Test]
    public function an_example_is_built_from_the_keys_that_are_read(): void
    {
        $candidate = json_decode(ResultProtocol::example(AttemptOutcome::Candidate), true);
        $blocked = json_decode(ResultProtocol::example(AttemptOutcome::Blocked), true);

        self::assertIsArray($candidate);
        self::assertIsArray($blocked);
        self::assertSame(['outcome', 'summary'], array_keys($candidate));
        self::assertSame(['outcome', 'summary', 'next_action'], array_keys($blocked));
        self::assertSame('candidate', $candidate[ResultProtocol::OUTCOME]);
    }
}
