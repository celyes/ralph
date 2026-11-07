<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Run\RunBudget;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\RunStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a run row remembers between invocations of the loop.
 *
 * PROJECT.md section H, item 3 is two sentences that pull in opposite
 * directions, and both of them are here. Counters must survive a resume, so
 * that an interruption is not a way to buy another five tasks out of a limit of
 * five. A deliberately new run must be identifiable, so that the figure a
 * limit is measured against cannot be mistaken for the work one run did — and
 * so that starting afresh is a thing a user can do without the package
 * pretending the earlier work never happened.
 *
 * The counters are therefore asserted twice over: as totals, which is what
 * {@see RunBudget} reads, and as this run's own, which is what `status` will
 * show. A test that only checked the totals would pass against a store that
 * lost the distinction, and the distinction is half the item.
 *
 * The clock is frozen because the identifiers are built from it and the rows
 * are evidence; nothing here depends on real time passing.
 */
#[CoversClass(RunStore::class)]
#[CoversClass(RunRecord::class)]
final class RunStoreTest extends TestCase
{
    private Database $database;

    private FrozenClock $clock;

    private RunStore $runs;

    private string $root;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-07T09:00:00+00:00');
        $this->runs = new RunStore($this->database, $this->clock);
        $this->root = TemporaryDirectory::create('ralph-runs');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    #[Test]
    public function a_new_run_starts_with_nothing_spent_and_nothing_to_continue(): void
    {
        $run = $this->runs->open($this->agent(), $this->configuration());

        self::assertTrue($run->isRunning());
        self::assertTrue($run->isFreshStart());
        self::assertFalse($run->isResume());
        self::assertNull($run->resumedFrom());
        self::assertNull($run->resumedFromRunId());
        self::assertSame(0, $run->iterations());
        self::assertSame(0, $run->activeSeconds());
        self::assertSame(0, $run->carriedIterations());
        self::assertSame(0, $run->carriedSeconds());
    }

    /**
     * The item, in one case: what the interrupted run spent is what the
     * continuation starts from.
     */
    #[Test]
    public function a_resumed_run_begins_at_the_counters_the_interrupted_one_reached(): void
    {
        $first = $this->spent($this->runs->open($this->agent(), $this->configuration()), 3, 1200);
        $first = $this->runs->interrupt($first, 'Ctrl+C');

        $second = $this->runs->resume($first, $this->agent(), $this->configuration());

        self::assertSame(3, $second->iterations());
        self::assertSame(1200, $second->activeSeconds());
        self::assertSame($first->rowId(), $second->resumedFrom());
        self::assertSame($first->runId(), $second->resumedFromRunId());
        self::assertTrue($second->isResume());
        self::assertFalse($second->isFreshStart());
    }

    /**
     * The point of preserving them: the limits bound the work, not the process
     * that happened to be running when it was interrupted.
     */
    #[Test]
    public function a_resume_inherits_the_limits_as_its_predecessor_left_them(): void
    {
        $first = $this->spent($this->runs->open($this->agent(), $this->configuration()), 3, 1200);
        $limits = new Limits(5, 3, 1800, 3600);

        $budget = RunBudget::of($limits, $first->iterations(), $first->activeSeconds());
        self::assertSame(2, $budget->iterationsRemaining());

        $second = $this->runs->resume(
            $this->runs->interrupt($first, 'Ctrl+C'),
            $this->agent(),
            $this->configuration(),
        );

        $resumed = RunBudget::of($limits, $second->iterations(), $second->activeSeconds());

        self::assertSame(2, $resumed->iterationsRemaining(), 'A resume does not buy the limit back.');
        self::assertSame(2400, $resumed->secondsRemaining());
    }

    /**
     * Interrupting a resume and resuming that: the figure carried is a total,
     * so a chain of them cannot leak a limit one interruption at a time.
     */
    #[Test]
    public function counters_survive_being_resumed_more_than_once(): void
    {
        $run = $this->spent($this->runs->open($this->agent(), $this->configuration()), 2, 600);

        for ($resumes = 0; $resumes < 3; $resumes++) {
            $run = $this->runs->resume(
                $this->runs->interrupt($run, 'Ctrl+C'),
                $this->agent(),
                $this->configuration(),
            );
            $run = $this->spent($run, 1, 300);
        }

        self::assertSame(5, $run->iterations());
        self::assertSame(1500, $run->activeSeconds());

        // And the last of them did one task of those five, which is what a
        // reader of `status` is being told about.
        self::assertSame(1, $run->ownIterations());
        self::assertSame(300, $run->ownActiveSeconds());
    }

    /**
     * The totals are what the limits read; what this run did is still legible
     * beside them.
     */
    #[Test]
    public function a_resumed_run_says_both_what_it_inherited_and_what_it_spent(): void
    {
        $first = $this->spent($this->runs->open($this->agent(), $this->configuration()), 3, 1200);
        $second = $this->spent(
            $this->runs->resume($this->runs->interrupt($first, 'Ctrl+C'), $this->agent(), $this->configuration()),
            2,
            400,
        );

        self::assertSame(3, $second->carriedIterations());
        self::assertSame(1200, $second->carriedSeconds());
        self::assertSame(5, $second->iterations());
        self::assertSame(1600, $second->activeSeconds());
        self::assertSame(2, $second->ownIterations());
        self::assertSame(400, $second->ownActiveSeconds());
    }

    /**
     * A run that ended in the ordinary way has nothing left to continue, and
     * continuing it would be a new run wearing a finished run's spent budget.
     */
    #[Test]
    public function a_completed_run_cannot_be_resumed_and_the_refusal_says_what_to_do(): void
    {
        $run = $this->runs->complete(
            $this->runs->open($this->agent(), $this->configuration()),
            'every pending task was completed',
        );

        try {
            $this->runs->resume($run, $this->agent(), $this->configuration());
            self::fail('A completed run was resumed.');
        } catch (StateException $refusal) {
            self::assertStringContainsString('ended as completed', $refusal->getMessage());
            self::assertStringContainsString('every pending task was completed', implode(' ', $refusal->hints()));
            self::assertStringContainsString('ralph run', implode(' ', $refusal->hints()));
        }
    }

    /**
     * The commonest thing there is to resume: a runner that was killed and
     * never closed its row.
     */
    #[Test]
    public function a_run_whose_worker_died_without_closing_the_row_can_still_be_resumed(): void
    {
        $crashed = $this->spent($this->runs->open($this->agent(), $this->configuration()), 1, 90);

        $resumed = $this->runs->resume($crashed, $this->agent(), $this->configuration());

        self::assertSame(LifecycleState::Running, $crashed->state());
        self::assertSame(1, $resumed->iterations());
        self::assertSame(90, $resumed->activeSeconds());
    }

    #[Test]
    public function a_failed_run_can_be_resumed_once_what_failed_is_fixed(): void
    {
        $failed = $this->runs->fail(
            $this->spent($this->runs->open($this->agent(), $this->configuration()), 1, 60),
            'VerificationException: the checks could not be started',
        );

        $resumed = $this->runs->resume($failed, $this->agent(), $this->configuration());

        self::assertSame(1, $resumed->iterations());
        self::assertSame($failed->runId(), $resumed->resumedFromRunId());
    }

    /**
     * A resume continues from the commit the interrupted run branched from,
     * unless it is given another: the work it is continuing is on branches cut
     * from that one.
     */
    #[Test]
    public function a_resume_keeps_the_base_commit_the_interrupted_run_recorded(): void
    {
        $first = $this->runs->open($this->agent(), $this->configuration(), 'a1b2c3d4e5f6');

        $second = $this->runs->resume(
            $this->runs->interrupt($first, 'Ctrl+C'),
            $this->agent(),
            $this->configuration(),
        );

        self::assertSame('a1b2c3d4e5f6', $second->baseSha());
    }

    #[Test]
    public function a_new_run_after_a_completed_one_starts_the_counters_again(): void
    {
        $first = $this->runs->complete(
            $this->spent($this->runs->open($this->agent(), $this->configuration()), 5, 3000),
            'the iteration limit of 5 was reached',
        );

        $second = $this->runs->open($this->agent(), $this->configuration());

        self::assertSame(0, $second->iterations());
        self::assertSame(0, $second->activeSeconds());
        self::assertTrue($second->isFreshStart());

        // And the run it followed is still there to read, with what it did.
        self::assertSame(5, $this->runs->get($first->rowId())->iterations());
        self::assertCount(2, $this->runs->all());
    }

    #[Test]
    public function a_run_and_everything_it_continues_can_be_read_as_one_history(): void
    {
        $first = $this->runs->open($this->agent(), $this->configuration());
        $second = $this->runs->resume(
            $this->runs->interrupt($first, 'Ctrl+C'),
            $this->agent(),
            $this->configuration(),
        );
        $third = $this->runs->resume(
            $this->runs->interrupt($second, 'Ctrl+C'),
            $this->agent(),
            $this->configuration(),
        );

        self::assertSame(
            [$third->runId(), $second->runId(), $first->runId()],
            array_map(
                static fn (RunRecord $run): string => $run->runId(),
                $this->runs->lineage($third),
            ),
        );

        self::assertSame($second->runId(), $this->runs->predecessor($third)?->runId());
        self::assertNull($this->runs->predecessor($first));
        self::assertCount(1, $this->runs->lineage($first), 'A fresh start is its own whole history.');
    }

    /**
     * Counting an iteration on a resumed run adds to the total it inherited,
     * rather than starting a second count beside it.
     */
    #[Test]
    public function the_counters_go_on_from_where_the_resume_began(): void
    {
        $first = $this->spent($this->runs->open($this->agent(), $this->configuration()), 2, 500);
        $second = $this->runs->resume(
            $this->runs->interrupt($first, 'Ctrl+C'),
            $this->agent(),
            $this->configuration(),
        );

        $second = $this->runs->countIteration($second);
        $second = $this->runs->addActiveSeconds($second, 120);

        self::assertSame(3, $second->iterations());
        self::assertSame(620, $second->activeSeconds());
        self::assertSame(1, $second->ownIterations());
        self::assertSame(120, $second->ownActiveSeconds());

        // Read back rather than taken from the returned object: the row is
        // what a later process will believe.
        self::assertSame(3, $this->runs->get($second->rowId())->iterations());
        self::assertSame(1, $this->runs->find($second->runId())?->ownIterations());
        self::assertSame(1, $this->runs->running()?->ownIterations());
        self::assertSame(1, $this->runs->latest()?->ownIterations());
    }

    /**
     * What `status` prints, and what `status --json` carries: a continuation
     * must not read as a run that did five tasks by itself.
     */
    #[Test]
    public function a_continuation_describes_itself_as_one(): void
    {
        $first = $this->spent($this->runs->open($this->agent(), $this->configuration()), 3, 900);
        $second = $this->spent(
            $this->runs->resume($this->runs->interrupt($first, 'Ctrl+C'), $this->agent(), $this->configuration()),
            2,
            400,
        );

        $described = $second->describe();

        self::assertStringContainsString('2 tasks continuing ' . $first->runId(), $described);
        self::assertStringContainsString('(5 in all)', $described);
        self::assertStringContainsString('3 tasks from HEAD', $first->describe());
        self::assertStringNotContainsString('continuing', $first->describe());

        self::assertSame($first->runId(), $second->toArray()['resumed_from']);
        self::assertSame(5, $second->toArray()['iterations']);
        self::assertSame(2, $second->toArray()['iterations_here']);
        self::assertSame(1300, $second->toArray()['active_seconds']);
        self::assertSame(400, $second->toArray()['active_seconds_here']);
        self::assertNull($first->toArray()['resumed_from']);
    }

    #[Test]
    public function one_task_is_said_in_the_singular(): void
    {
        $run = $this->spent($this->runs->open($this->agent(), $this->configuration()), 1, 60);

        self::assertStringContainsString('1 task from HEAD', $run->describe());
    }

    /**
     * A run that has spent `$iterations` tasks and `$seconds` working.
     */
    private function spent(RunRecord $run, int $iterations, int $seconds): RunRecord
    {
        for ($counted = 0; $counted < $iterations; $counted++) {
            $run = $this->runs->countIteration($run);
        }

        return $seconds === 0 ? $run : $this->runs->addActiveSeconds($run, $seconds);
    }

    private function agent(): AgentSelection
    {
        return new AgentSelection(AgentDefinition::builtIn('codex'), AgentSource::ProjectDefault);
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->defaults($this->root);
    }
}
