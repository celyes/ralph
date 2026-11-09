<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Run\DurableOutcome;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\Run\RunStop;
use Celyes\RalphPhp\Run\TaskResult;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\StopKind;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The gate on a finishing event: it announces the store's word, not the loop's
 * (PROJECT.md section I, item 4, first half).
 *
 * A program acting on `task.finished` merges a branch or reports a task done,
 * and the one thing it cannot recover from is being told a task finished when
 * the next process to open the database will not agree. So every case here is
 * built the same way: the row is written to say one thing, the object the loop
 * is holding says another, and what the event carries is asserted to be the
 * row's.
 *
 * A real database throughout, in memory. The behaviour under test *is* the read
 * back out of the store, so a double standing in for the store would confirm
 * only that this class can be told what to say — which is the thing it exists
 * not to do.
 */
#[CoversClass(DurableOutcome::class)]
final class DurableOutcomeTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    private Database $database;

    private Journal $journal;

    private int $runRowId = 0;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->journal = Journal::over($this->database);
    }

    /**
     * The outcome is the state the row holds, and the loop's claim is reported
     * beside it rather than instead of it.
     *
     * The disagreement is the case that matters: a result object made before
     * the row was closed still says `running`, and an event carrying that word
     * would tell a consumer a finished pass is still going. The claim is kept,
     * because a stream that silently substituted one word for another would
     * leave nothing to notice the substitution by.
     */
    #[Test]
    public function the_outcome_is_read_back_out_of_the_row(): void
    {
        $pass = $this->givenAPass();
        $this->journal->generations()->complete($this->givenACommit($pass));

        // What the loop is holding: the row as it stood before it was closed.
        $durable = DurableOutcome::ofTask($this->journal, $this->resultFor($pass));

        self::assertSame('completed', $durable->outcome());
        self::assertTrue($durable->isConfirmed());

        $published = $durable->toArray();
        self::assertTrue($published['confirmed']);
        self::assertSame('completed', $published['state']);
        self::assertSame('running', $published['claimed'], 'the loop\'s own word is kept, not dropped');
    }

    /**
     * Nothing to disagree with, nothing reported: `claimed` is there to show a
     * substitution, so a line that carries it where the two words match would
     * read as part of the envelope instead.
     */
    #[Test]
    public function a_row_that_agrees_reports_no_claim(): void
    {
        $pass = $this->givenAPass();
        $completed = $this->journal->generations()->complete($this->givenACommit($pass));

        $durable = DurableOutcome::ofTask($this->journal, $this->resultFor($completed, 'completed'));

        self::assertSame('completed', $durable->outcome());
        self::assertArrayNotHasKey('claimed', $durable->toArray());
    }

    /**
     * What the finalization rests on, published rather than left for the
     * consumer to go and look up.
     */
    #[Test]
    public function it_publishes_what_the_finalization_rests_on(): void
    {
        $pass = $this->givenAPass();
        $committed = $this->givenACommit($pass);
        $this->journal->generations()->complete($committed);

        $published = DurableOutcome::ofTask($this->journal, $this->resultFor($committed))->toArray();

        self::assertSame(str_repeat('b', 40), $published['commit']);
        self::assertFalse($published['checklist_write_owed']);
        self::assertNotNull($published['finished_at']);
    }

    /**
     * A tick the pass still owes is on the line, because it is the difference
     * between a task a consumer can act on and one this package has not
     * finished with.
     */
    #[Test]
    public function an_unwritten_tick_is_reported_as_still_owed(): void
    {
        $pass = $this->givenAPass();
        $this->journal->checklists()->plan(
            $pass,
            $this->runRowId,
            '.ralph/tasks.md',
            str_repeat('1', 64),
            str_repeat('2', 64),
        );

        $published = DurableOutcome::ofTask($this->journal, $this->resultFor($pass))->toArray();

        self::assertTrue($published['checklist_write_owed']);
    }

    /**
     * A row that cannot be read back is said to be unconfirmed, and the pass's
     * claim is carried as a claim.
     *
     * There is no ordinary way for this to happen — the pass wrote the row
     * moments earlier — which is the reason it is worth pinning: inventing an
     * outcome for a row nobody can find, or writing the claim as though the
     * store had agreed to it, are the two things that would make every other
     * line untrustworthy.
     */
    #[Test]
    public function a_row_that_cannot_be_read_back_is_not_dressed_up(): void
    {
        $task = $this->task();
        $unrecorded = GenerationRecord::fromRow($this->row(TaskGeneration::first($task->id())->slug(), 'completed'));

        $durable = DurableOutcome::ofTask($this->journal, TaskResult::of($task, $unrecorded));

        self::assertFalse($durable->isConfirmed());
        self::assertSame('completed', $durable->outcome(), 'the claim, reported as one');

        $published = $durable->toArray();
        self::assertFalse($published['confirmed']);
        self::assertSame('completed', $published['claimed']);
        self::assertArrayHasKey('reason', $published);
        self::assertArrayNotHasKey('state', $published, 'there is no durable state to report');
    }

    /**
     * A run's ending is its row's stop kind, once the row is closed.
     */
    #[Test]
    public function a_closed_run_announces_the_kind_its_row_carries(): void
    {
        $run = $this->givenARun();
        $this->journal->runs()->complete($run, 'every task completed', 'review the branches', StopKind::Finished);

        $durable = DurableOutcome::ofRun($this->journal, $run, RunStop::everythingCompleted(1, '.ralph/tasks.md'));

        self::assertSame('finished', $durable->outcome());
        self::assertTrue($durable->isConfirmed());
        self::assertSame('completed', $durable->toArray()['state']);
    }

    /**
     * An ending read off a row still open is reported as undurable — and
     * reported, rather than withheld.
     *
     * Both halves matter. A consumer that saw `run.started` and nothing after
     * it cannot tell a crashed run from one still going, so the line is still
     * written; and the run it describes has not been closed, so the line says
     * so instead of claiming a stop a later `ralph status` would contradict.
     */
    #[Test]
    public function an_ending_whose_row_is_still_open_says_so(): void
    {
        $run = $this->givenARun();

        $durable = DurableOutcome::ofRun($this->journal, $run, RunStop::failure('the base reference is gone'));

        self::assertSame('failed', $durable->outcome(), 'the stop the caller decided on');
        self::assertFalse($durable->isConfirmed());

        $published = $durable->toArray();
        self::assertFalse($published['confirmed']);
        self::assertArrayHasKey('reason', $published);
        self::assertSame('running', $published['state']);
    }

    /**
     * A run closed as one kind does not announce another, whatever the caller
     * decided.
     */
    #[Test]
    public function a_run_announces_its_recorded_kind_over_the_decided_one(): void
    {
        $run = $this->givenARun();
        $this->journal->runs()->interrupt($run, 'interrupted', 'run ralph resume', StopKind::Interrupted);

        $durable = DurableOutcome::ofRun($this->journal, $run, RunStop::failure('the base reference is gone'));

        self::assertSame('interrupted', $durable->outcome());
        self::assertSame('failed', $durable->toArray()['claimed']);
    }

    /**
     * A pass whose row is open, as the loop holds it before it finishes.
     */
    private function givenAPass(): GenerationRecord
    {
        $snapshot = TaskSnapshot::of($this->task());
        $this->journal->tasks()->record($snapshot);

        $started = $this->journal->generations()->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            str_repeat('a', 40),
            null,
            $this->givenARun()->rowId(),
        );

        return $this->journal->generations()->beginAttempt($started);
    }

    /**
     * The result the loop hands over: the row as it stood when the pass
     * returned, which is to say before the ending the event reports.
     */
    private function resultFor(GenerationRecord $record, string $claimed = 'running'): TaskResult
    {
        return TaskResult::of($this->task(), GenerationRecord::fromRow(
            $this->row($record->slug(), $claimed),
        ));
    }

    /**
     * The commit a pass has to have before the store will let it complete,
     * which is the gate of section G, item 7 and not this item's to restate.
     */
    private function givenACommit(GenerationRecord $pass): GenerationRecord
    {
        return $this->journal->generations()->recordCommit($pass, str_repeat('b', 40));
    }

    /**
     * A generation row as a value, for a claim that is deliberately out of step
     * with the store.
     *
     * @return array<string, mixed>
     */
    private function row(string $slug, string $state): array
    {
        return [
            'id' => 1,
            'slug' => $slug,
            'run_id' => 1,
            'state' => $state,
            'phase' => null,
            'attempts' => 1,
            'content_hash' => str_repeat('c', 64),
            'agent' => 'codex',
            'adapter' => 'codex',
            'agent_settings' => '{"executable":"codex"}',
            'base_reference' => 'HEAD',
            'base_sha' => str_repeat('a', 40),
            'branch' => null,
            'commit_sha' => null,
            'no_change' => 0,
            'push_state' => 'not_required',
            'push_remote' => null,
            'push_error' => null,
            'worktree_path' => null,
            'owns_worktree' => 0,
            'blocker' => null,
            'started_at' => '2026-11-09T10:00:00+00:00',
            'updated_at' => '2026-11-09T10:01:00+00:00',
            'finished_at' => null,
        ];
    }

    private function task(): Task
    {
        return Task::fromItem(new ChecklistItem(
            self::DESCRIPTION,
            false,
            1,
            '- [ ] ' . self::DESCRIPTION,
            [],
        ));
    }

    /**
     * An open run row, inserted once and returned however often it is asked
     * for: a pass and the run it belongs to are the same run here.
     */
    private function givenARun(): RunRecord
    {
        if ($this->runRowId === 0) {
            $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

            $this->database->execute(
                'INSERT INTO runs
                     (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                      checks, tasks_path, base_reference, started_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'RUN-0001',
                    'running',
                    $agent['agent'],
                    $agent['adapter'],
                    'project_default',
                    $agent['settings'],
                    '{}',
                    '[]',
                    '.ralph/tasks.md',
                    'HEAD',
                    '2026-11-09T10:00:00+00:00',
                    '2026-11-09T10:00:00+00:00',
                ],
            );

            $this->runRowId = $this->database->lastInsertId();
        }

        return $this->journal->runs()->get($this->runRowId);
    }
}
