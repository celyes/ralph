<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\StopKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The documented set of exit statuses (PROJECT.md section I, item 5).
 *
 * What is under test is the *set*, not the arithmetic: that every ending a run
 * can have is given a status, that the statuses are told apart where the item
 * asks for them to be told apart, that the mapping is reversible where a run's
 * row has to agree with the process, and that the documentation cannot drift
 * from the constants. The commands' own suites hold each command to the code it
 * returns; this holds the vocabulary they all read from.
 */
#[CoversClass(ExitCode::class)]
final class ExitCodeTest extends TestCase
{
    /**
     * Every ending has a status, and the six categories the item names are
     * distinct numbers.
     *
     * The point of the item: a caller must be able to tell "the checklist is
     * done" from "a decision is waiting", "a task was given up on", "a limit
     * stopped it" and "somebody pressed Ctrl+C" without reading prose. Two of
     * those sharing a code — which is how this started, with one "needs a
     * person" status for three endings — is the failure this test exists to
     * prevent coming back.
     */
    #[Test]
    public function the_six_documented_categories_are_six_distinct_statuses(): void
    {
        $categories = [
            'success' => ExitCode::SUCCESS,
            'configuration failure' => ExitCode::CONFIGURATION,
            'execution failure' => ExitCode::EXECUTION,
            'limit exhaustion' => ExitCode::LIMIT,
            'blocking' => ExitCode::BLOCKED,
            'interruption' => ExitCode::INTERRUPTED,
        ];

        self::assertSame(
            count($categories),
            count(array_unique($categories)),
            'Two of the categories section I, item 5 names share a status: ' . json_encode($categories),
        );
    }

    /**
     * Each of the six endings a run can have maps to a status, and the mapping
     * is the one the help text and the README state.
     */
    #[Test]
    public function every_ending_maps_to_its_documented_status(): void
    {
        self::assertSame(ExitCode::SUCCESS, ExitCode::forStop(StopKind::Finished));
        self::assertSame(ExitCode::BLOCKED, ExitCode::forStop(StopKind::Decision));
        self::assertSame(ExitCode::EXECUTION, ExitCode::forStop(StopKind::Attempts));
        self::assertSame(ExitCode::EXECUTION, ExitCode::forStop(StopKind::Failed));
        self::assertSame(ExitCode::LIMIT, ExitCode::forStop(StopKind::Limit));
        self::assertSame(ExitCode::INTERRUPTED, ExitCode::forStop(StopKind::Interrupted));
    }

    /**
     * Exhausted attempts and a run that could not carry on share a status
     * deliberately, and nothing else does.
     *
     * Both are work that was attempted and did not succeed, and a caller's
     * response to either is to look at the logs and attempt it again; the
     * difference between them is a sentence, which is on the run's row. Every
     * other pair of endings is a different number, which is the half of this
     * that matters.
     */
    #[Test]
    public function only_the_two_kinds_of_execution_failure_share_a_status(): void
    {
        $byStatus = [];

        foreach (StopKind::cases() as $kind) {
            $byStatus[ExitCode::forStop($kind)][] = $kind->value;
        }

        $shared = array_filter($byStatus, static fn (array $kinds): bool => count($kinds) > 1);

        self::assertSame([ExitCode::EXECUTION => ['attempts', 'failed']], $shared);
    }

    /**
     * A failure's status decides what the run's row records, so the two cannot
     * disagree.
     *
     * The round trip is the contract: whatever {@see ExitCode::stopKindFor()}
     * answers for a status, mapping that ending forward again has to give the
     * status back. Without it a refusal could exit as a block while leaving a
     * row `ralph status` then reports as an execution failure.
     */
    #[Test]
    public function the_ending_recorded_for_a_status_maps_back_to_that_status(): void
    {
        foreach ([ExitCode::BLOCKED, ExitCode::INTERRUPTED] as $code) {
            self::assertSame($code, ExitCode::forStop(ExitCode::stopKindFor($code)));
        }
    }

    /**
     * Everything that is not one of the two endings-by-name is a run that could
     * not carry on, including a bug.
     *
     * An unrecognised status must not invent a seventh {@see StopKind}: the
     * vocabulary is what every report is written over, and a case no report can
     * describe would reach a user as a blank.
     */
    #[Test]
    public function any_other_status_is_recorded_as_a_run_that_could_not_carry_on(): void
    {
        foreach ([ExitCode::CONFIGURATION, ExitCode::EXECUTION, ExitCode::SOFTWARE, ExitCode::USAGE, 211] as $code) {
            self::assertSame(StopKind::Failed, ExitCode::stopKindFor($code));
        }
    }

    /**
     * Nothing documents a status the class does not define, and the class
     * defines no status nothing documents.
     *
     * `ralph help` prints {@see ExitCode::documented()} verbatim, so an
     * undocumented constant is a status a user meets with no explanation
     * anywhere, and a documented value that is not a constant is a line in the
     * help that nothing can ever produce.
     */
    #[Test]
    public function the_documentation_and_the_constants_describe_the_same_set(): void
    {
        $defined = array_values(array_filter(
            (new ReflectionClass(ExitCode::class))->getConstants(),
            static fn (mixed $value): bool => is_int($value),
        ));

        self::assertSame(
            array_values(array_unique($defined)),
            array_keys(ExitCode::documented()),
            'The documented statuses and the defined ones differ.',
        );
    }

    /**
     * The statuses are listed in ascending order, and every one of them has
     * something to say.
     */
    #[Test]
    public function the_documented_statuses_are_ordered_and_all_described(): void
    {
        $codes = array_keys(ExitCode::documented());
        $sorted = $codes;
        sort($sorted);

        self::assertSame($sorted, $codes);

        foreach (ExitCode::documented() as $code => $meaning) {
            self::assertNotSame('', trim($meaning), sprintf('Status %d is documented with nothing.', $code));
        }
    }

    /**
     * The values stay inside the ranges a shell caller can read, and out of the
     * ranges that mean something else.
     *
     * 1 and the sysexits range are what tools and CI already interpret; 124 is
     * `timeout`'s, and 130 is 128 + SIGINT. Nothing is allowed in 2–63, where
     * programs put their own ad-hoc codes, and nothing above 130, where a
     * status means the kernel killed the process rather than that this package
     * decided anything.
     */
    #[Test]
    public function the_values_follow_the_conventions_a_caller_already_reads(): void
    {
        foreach (array_keys(ExitCode::documented()) as $code) {
            self::assertTrue(
                $code === 0 || $code === 1 || ($code >= 64 && $code <= 78) || $code === 124 || $code === 130,
                sprintf('Status %d is in a range a caller cannot interpret.', $code),
            );
        }
    }

    /**
     * A status nothing documents is said to be undocumented rather than
     * described anyway.
     */
    #[Test]
    public function an_undocumented_status_is_reported_as_undocumented(): void
    {
        self::assertSame('the command did what it was asked', ExitCode::describe(ExitCode::SUCCESS));
        self::assertStringContainsString('does not document', ExitCode::describe(211));
    }
}
