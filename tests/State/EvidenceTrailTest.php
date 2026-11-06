<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\EvidenceTrail;
use Celyes\RalphPhp\State\FailureEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the next attempt is told, and what it is not told.
 *
 * PROJECT.md section G, item 4 asks for *bounded* evidence to be fed forward,
 * so the cases here are mostly about the bound: that a briefing never grows
 * with the number of failures behind it, and that what the bound leaves out is
 * announced rather than dropped. The one case that is not about the bound is
 * the one the bound exists to make possible — reading past an attempt that
 * observed nothing, so a failing check stays in front of the agent until
 * something fixes it.
 *
 * Attempts are built as rows rather than through the store: the fold reads
 * {@see AttemptRecord}s, and a database would only make the test slower.
 */
#[CoversClass(EvidenceTrail::class)]
final class EvidenceTrailTest extends TestCase
{
    #[Test]
    public function a_first_attempt_is_told_nothing(): void
    {
        $trail = EvidenceTrail::of([]);

        self::assertTrue($trail->isEmpty());
        self::assertSame('', $trail->toMarkdown());
    }

    /**
     * With one attempt to quote, its observations are passed on as they were
     * recorded: the briefing's own heading already says whose they are.
     */
    #[Test]
    public function the_newest_attempts_observations_are_passed_on_unattributed(): void
    {
        $trail = EvidenceTrail::of([
            $this->attempt(2, FailureEvidence::of('verification failed: tests', 'FAILURES! 1 test')),
        ]);

        self::assertSame(
            [['label' => 'verification failed: tests', 'detail' => 'FAILURES! 1 test']],
            $trail->entries(),
        );
        self::assertSame(0, $trail->omitted());
    }

    /**
     * The case the trail exists for: an attempt that errored or was cancelled
     * recorded nothing the runner saw, and the failing check behind it is still
     * why the task is not done.
     */
    #[Test]
    public function an_attempt_that_observed_nothing_does_not_hide_the_one_before_it(): void
    {
        $trail = EvidenceTrail::of([
            $this->attempt(3, null),
            $this->attempt(2, FailureEvidence::none()),
            $this->attempt(1, FailureEvidence::of('verification failed: tests', 'FAILURES! 1 test')),
        ]);

        self::assertSame(1, $trail->count());
        self::assertStringContainsString('verification failed: tests', $trail->entries()[0]['label']);
    }

    /**
     * Quoted attempts are named once there is more than one of them, so an
     * agent can tell the failure it has just caused from the one it inherited.
     */
    #[Test]
    public function observations_from_several_attempts_are_attributed_newest_first(): void
    {
        $trail = EvidenceTrail::of([
            $this->attempt(3, FailureEvidence::of('verification failed: stan', 'line 44: mixed')),
            $this->attempt(2, FailureEvidence::of('verification failed: tests', 'FAILURES! 1 test')),
        ]);

        self::assertSame(
            ['attempt 3: verification failed: stan', 'attempt 2: verification failed: tests'],
            array_column($trail->entries(), 'label'),
        );
    }

    /**
     * Only the attempts the bound on observations can hold are read, however
     * many failures are behind them.
     */
    #[Test]
    public function older_attempts_than_the_bound_allows_are_not_read_at_all(): void
    {
        $trail = EvidenceTrail::of([
            $this->attempt(4, FailureEvidence::of('verification failed: stan')),
            $this->attempt(3, FailureEvidence::of('verification failed: tests')),
            $this->attempt(2, FailureEvidence::of('verification failed: cs')),
            $this->attempt(1, FailureEvidence::of('the pass refused')),
        ]);

        self::assertSame(EvidenceTrail::ATTEMPTS, $trail->count());
        self::assertStringNotContainsString('cs', $trail->toMarkdown());
        self::assertSame(
            0,
            $trail->omitted(),
            'An attempt the trail never read is not an observation it dropped.',
        );
    }

    /**
     * The total is what {@see FailureEvidence} allows, whatever the attempts
     * bring, and the rest is counted.
     */
    #[Test]
    public function the_observations_are_bounded_and_what_is_left_out_is_announced(): void
    {
        $many = FailureEvidence::of('verification failed: tests')
            ->with('checks not reached')
            ->with('the workspace was adopted');

        $trail = EvidenceTrail::of([$this->attempt(3, $many), $this->attempt(2, $many)]);

        self::assertSame(FailureEvidence::ENTRIES, $trail->count());
        self::assertSame(3, $trail->omitted());
        self::assertStringContainsString('3 further observations are not shown', $trail->toMarkdown());
    }

    /**
     * A bound the stored evidence was already under is answered for here too.
     */
    #[Test]
    public function observations_an_earlier_bound_dropped_are_still_counted(): void
    {
        $bounded = FailureEvidence::of('verification failed: tests')
            ->with('checks not reached')
            ->with('the workspace was adopted')
            ->with('and one more the column could not hold');

        self::assertSame(1, $bounded->omitted());

        $trail = EvidenceTrail::of([$this->attempt(2, $bounded)]);

        self::assertSame(1, $trail->omitted());
        self::assertStringContainsString('1 further observation is not shown', $trail->toMarkdown());
    }

    #[Test]
    public function a_trail_of_no_attempts_at_all_is_asked_for_nothing(): void
    {
        $trail = EvidenceTrail::of([$this->attempt(2, FailureEvidence::of('verification failed: tests'))], 0);

        self::assertTrue($trail->isEmpty());
    }

    /**
     * One stored attempt, with whatever its evidence column holds.
     */
    private function attempt(int $number, ?FailureEvidence $evidence): AttemptRecord
    {
        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        return AttemptRecord::fromRow([
            'id' => $number,
            'generation_id' => 3,
            'run_id' => 1,
            'number' => $number,
            'state' => 'failed',
            'phase' => 'verifying',
            'agent' => $agent['agent'],
            'adapter' => $agent['adapter'],
            'agent_settings' => $agent['settings'],
            'outcome' => 'candidate',
            'exit_code' => 0,
            'duration_ms' => 1_200,
            'summary' => null,
            'failure_evidence' => $evidence === null || $evidence->isEmpty() ? null : $evidence->toStorage(),
            'blocker' => null,
            'log_path' => null,
            'started_at' => '2026-11-06T09:30:00+00:00',
            'updated_at' => '2026-11-06T09:31:00+00:00',
            'finished_at' => '2026-11-06T09:31:00+00:00',
        ]);
    }
}
