<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\ChecklistMarker;
use Celyes\RalphPhp\State\ChecklistStore;
use Celyes\RalphPhp\State\ChecklistUpdate;
use Celyes\RalphPhp\State\ChecklistUpdateState;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The planned writes to the user's checklist, and what becomes of them.
 *
 * PROJECT.md section G, item 8 turns on one thing being durable: the write to
 * the user's file is recorded *before* it is attempted, so a pass whose work is
 * verified, committed and published and whose checkbox write was refused is
 * afterwards recognisable as owing a line in a Markdown file and nothing else.
 * Every case here is about that row being able to say so without ambiguity —
 * which pass owes the write, what it was planned against, how often it has been
 * tried, and the difference between a write to retry and one to reconcile.
 *
 * The clock is frozen and advanced by hand: `applied_at` is evidence of when a
 * box came to be ticked, and a test that could not control it could only assert
 * that the column holds something.
 */
#[CoversClass(ChecklistStore::class)]
#[CoversClass(ChecklistUpdate::class)]
#[CoversClass(ChecklistMarker::class)]
#[CoversClass(ChecklistUpdateState::class)]
final class ChecklistStoreTest extends TestCase
{
    private const string PATH = '.ralph/tasks.md';

    private const string DESCRIPTION = 'Add a --dry-run option';

    private Database $database;

    private FrozenClock $clock;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private ChecklistStore $checklists;

    private int $run;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $this->tasks = new TaskStore($this->database, $this->clock);
        $this->generations = new GenerationStore($this->database, $this->clock);
        $this->checklists = new ChecklistStore($this->database, $this->clock);

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
                'main',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        $this->run = $this->database->lastInsertId();
    }

    #[Test]
    public function a_planned_write_is_recorded_as_pending_before_the_file_is_touched(): void
    {
        $pass = $this->committed();

        $planned = $this->plan($pass);

        self::assertSame(ChecklistUpdateState::Pending, $planned->state());
        self::assertSame(ChecklistMarker::Checked, $planned->marker());
        self::assertTrue($planned->isOutstanding());
        self::assertFalse($planned->isApplied());
        self::assertFalse($planned->isSettled());
        self::assertSame(self::PATH, $planned->path());
        self::assertSame($pass->rowId(), $planned->generationId());
        self::assertTrue($planned->generation()->equals($pass->generation()));
        self::assertSame($this->run, $planned->runId());
        self::assertSame(0, $planned->attempts());
        self::assertFalse($planned->wasRefused(), 'Nothing has been tried yet.');
        self::assertNull($planned->error());
        self::assertNull($planned->resultingHash());
        self::assertNull($planned->appliedAt());
        self::assertSame('2026-11-01T09:30:00+00:00', $planned->createdAt()->value());
    }

    /**
     * What the plan was made against, kept so that a retry can tell "the same
     * write, not yet made" from "a different task on that line now".
     */
    #[Test]
    public function a_planned_write_records_the_file_and_the_task_it_was_planned_against(): void
    {
        $planned = $this->plan($this->committed(), 'the-file-as-it-read', 'the-task-as-it-read');

        self::assertSame('the-file-as-it-read', $planned->expectedHash());
        self::assertSame('the-task-as-it-read', $planned->taskHash());
    }

    /**
     * A retry is the same write being attempted again. A second row would make
     * "is this pass still owed a write" a question with two answers.
     */
    #[Test]
    public function planning_the_same_write_again_reuses_the_outstanding_row(): void
    {
        $pass = $this->committed();
        $planned = $this->plan($pass, 'first-reading', 'task');

        $this->clock->advance(60);
        $again = $this->plan($pass, 'second-reading', 'task');

        self::assertSame($planned->rowId(), $again->rowId());
        self::assertCount(1, $this->checklists->forGeneration($pass));
        self::assertSame(
            'second-reading',
            $again->expectedHash(),
            'The row names the reading the write is about to be applied to.',
        );
        self::assertSame('2026-11-01T09:31:00+00:00', $again->updatedAt()->value());
        self::assertSame('2026-11-01T09:30:00+00:00', $again->createdAt()->value());
    }

    /**
     * A settled plan is history. Planning again after one has landed is a new
     * write — what reopening a task does — and must not reanimate the old row.
     */
    #[Test]
    public function planning_a_write_after_one_has_landed_records_a_second_plan(): void
    {
        $pass = $this->committed();
        $applied = $this->checklists->applied($this->plan($pass), 'after');

        $again = $this->plan($pass);

        self::assertNotSame($applied->rowId(), $again->rowId());
        self::assertCount(2, $this->checklists->forGeneration($pass));
        self::assertSame($again->rowId(), $this->checklists->latestFor($pass)?->rowId());
    }

    /**
     * A different marker is a different write: unticking a box a run ticked is
     * not a retry of the tick.
     */
    #[Test]
    public function planning_the_opposite_marker_records_a_second_plan(): void
    {
        $pass = $this->committed();
        $tick = $this->plan($pass);

        $untick = $this->checklists->plan(
            $pass,
            $this->run,
            self::PATH,
            'file',
            'task',
            ChecklistMarker::Unchecked,
        );

        self::assertNotSame($tick->rowId(), $untick->rowId());
        self::assertSame(ChecklistMarker::Unchecked, $untick->marker());
        self::assertFalse($untick->marker()->isChecked());
    }

    #[Test]
    public function an_applied_write_records_the_file_it_produced_and_when(): void
    {
        $planned = $this->plan($this->committed());
        $this->clock->advance(30);

        $applied = $this->checklists->applied($planned, 'the-file-with-the-box-ticked');

        self::assertSame(ChecklistUpdateState::Applied, $applied->state());
        self::assertTrue($applied->isApplied());
        self::assertTrue($applied->isSettled());
        self::assertFalse($applied->isOutstanding());
        self::assertSame('the-file-with-the-box-ticked', $applied->resultingHash());
        self::assertSame('2026-11-01T09:30:30+00:00', $applied->appliedAt()?->value());
        self::assertSame(1, $applied->attempts());
        self::assertNull($applied->error());
    }

    /**
     * The state that *is* pending finalization: the work is done, the file said
     * no, and the write is still the only thing owed.
     */
    #[Test]
    public function a_refused_write_stays_pending_with_the_reason_and_the_count(): void
    {
        $pass = $this->committed();
        $planned = $this->plan($pass);

        $deferred = $this->checklists->deferred($planned, 'Unable to write the task checklist');

        self::assertSame(ChecklistUpdateState::Pending, $deferred->state());
        self::assertTrue($deferred->isOutstanding());
        self::assertTrue($deferred->wasRefused());
        self::assertSame(1, $deferred->attempts());
        self::assertSame('Unable to write the task checklist', $deferred->error());
        self::assertNull($deferred->appliedAt());

        // And it is still what a recovery path finds.
        self::assertSame($deferred->rowId(), $this->checklists->outstandingFor($pass)?->rowId());
        self::assertCount(1, $this->checklists->outstanding());
    }

    #[Test]
    public function a_write_refused_twice_counts_both_attempts_and_keeps_the_latest_reason(): void
    {
        $planned = $this->plan($this->committed());

        $once = $this->checklists->deferred($planned, 'the directory is read-only');
        $twice = $this->checklists->deferred($once, 'the disk is full');

        self::assertSame(2, $twice->attempts());
        self::assertSame('the disk is full', $twice->error());
        self::assertSame($planned->rowId(), $twice->rowId());
    }

    /**
     * A refused write that is then made is one plan with two attempts behind
     * it, not two plans.
     */
    #[Test]
    public function a_refused_write_that_later_lands_is_the_same_plan_applied(): void
    {
        $pass = $this->committed();
        $deferred = $this->checklists->deferred($this->plan($pass), 'the directory is read-only');

        $applied = $this->checklists->applied($deferred, 'after');

        self::assertSame($deferred->rowId(), $applied->rowId());
        self::assertSame(ChecklistUpdateState::Applied, $applied->state());
        self::assertSame(2, $applied->attempts());
        self::assertNull($applied->error(), 'The refusal that was overcome is not left standing.');
        self::assertNull($this->checklists->outstandingFor($pass));
        self::assertSame([], $this->checklists->outstanding());
    }

    /**
     * The distinction the two states exist for: a write to retry unchanged, and
     * a write that must not be retried because what it was planned against has
     * changed.
     */
    #[Test]
    public function an_abandoned_write_is_closed_rather_than_left_owed(): void
    {
        $pass = $this->committed();
        $planned = $this->plan($pass);

        $abandoned = $this->checklists->abandoned($planned, 'no longer states the task');

        self::assertSame(ChecklistUpdateState::Failed, $abandoned->state());
        self::assertTrue($abandoned->state()->hasFailed());
        self::assertFalse($abandoned->isOutstanding());
        self::assertTrue($abandoned->isSettled());
        self::assertSame('no longer states the task', $abandoned->error());
        self::assertStringContainsString('Reconcile', $abandoned->nextAction());

        self::assertNull($this->checklists->outstandingFor($pass));
        self::assertSame([], $this->checklists->outstanding());
    }

    /**
     * Reopening a task hands the box to the new pass. The old plan is kept as
     * history rather than deleted, and is not something a recovery path acts
     * on: the generation that owns the line now is not the one that planned it.
     */
    #[Test]
    public function a_later_pass_at_the_same_task_supersedes_an_outstanding_write(): void
    {
        $first = $this->committed();
        $planned = $this->plan($first);
        $second = $this->committed(TaskGeneration::of($first->task(), 2));

        $superseded = $this->checklists->supersede($first->task(), $second->rowId());

        self::assertSame(1, $superseded);
        self::assertSame([], $this->checklists->outstanding());

        $stored = $this->checklists->get($planned->rowId());
        self::assertSame(ChecklistUpdateState::Superseded, $stored->state());
        self::assertTrue($stored->state()->isSuperseded());
        self::assertStringContainsString('later pass', $stored->nextAction());
    }

    #[Test]
    public function superseding_leaves_the_new_passs_own_write_alone(): void
    {
        $first = $this->committed();
        $this->plan($first);
        $second = $this->committed(TaskGeneration::of($first->task(), 2));
        $kept = $this->plan($second);

        $this->checklists->supersede($first->task(), $second->rowId());

        self::assertSame(
            ChecklistUpdateState::Pending,
            $this->checklists->get($kept->rowId())->state(),
        );
        self::assertCount(1, $this->checklists->outstanding());
    }

    #[Test]
    public function superseding_leaves_another_tasks_write_alone(): void
    {
        $other = $this->committed(null, 'Document the import command');
        $this->plan($other);
        $pass = $this->committed();

        self::assertSame(0, $this->checklists->supersede($pass->task(), $pass->rowId()));
        self::assertCount(1, $this->checklists->outstanding());
    }

    /**
     * Writing `applied` to a superseded plan would claim a tick that belongs to
     * other work.
     */
    #[Test]
    public function a_superseded_write_cannot_be_settled(): void
    {
        $first = $this->committed();
        $planned = $this->plan($first);
        $second = $this->committed(TaskGeneration::of($first->task(), 2));
        $this->checklists->supersede($first->task(), $second->rowId());

        try {
            $this->checklists->applied($planned, 'after');
            self::fail('A superseded plan cannot be applied.');
        } catch (StateException $refused) {
            self::assertStringContainsString('superseded', $refused->getMessage());
            self::assertStringContainsString('later pass', implode(' ', $refused->hints()));
        }

        self::assertSame(
            ChecklistUpdateState::Superseded,
            $this->checklists->get($planned->rowId())->state(),
        );
    }

    #[Test]
    public function a_pass_with_no_planned_write_owes_nothing(): void
    {
        $pass = $this->committed();

        self::assertNull($this->checklists->outstandingFor($pass));
        self::assertNull($this->checklists->latestFor($pass));
        self::assertSame([], $this->checklists->forGeneration($pass));
        self::assertSame([], $this->checklists->outstanding());
    }

    #[Test]
    public function outstanding_writes_come_back_oldest_first(): void
    {
        $first = $this->committed(null, 'Add a --dry-run option');
        $second = $this->committed(null, 'Document the import command');

        $this->plan($first);
        $this->plan($second);

        $outstanding = $this->checklists->outstanding();

        self::assertCount(2, $outstanding);
        self::assertTrue($outstanding[0]->generation()->equals($first->generation()));
        self::assertTrue($outstanding[1]->generation()->equals($second->generation()));
    }

    #[Test]
    public function a_write_for_a_pass_that_is_not_recorded_is_refused_readably(): void
    {
        $pass = $this->committed();
        $this->database->execute('DELETE FROM generations WHERE id = ?', [$pass->rowId()]);

        try {
            $this->plan($pass);
            self::fail('A write cannot reference a pass that is not there.');
        } catch (StateException $refused) {
            self::assertStringContainsString($pass->slug(), $refused->getMessage());
            self::assertStringContainsString(
                'same state database',
                implode(' ', $refused->hints()),
            );
        }
    }

    #[Test]
    public function a_write_that_is_gone_cannot_be_read_back(): void
    {
        $planned = $this->plan($this->committed());
        $this->database->execute('DELETE FROM checklist_updates WHERE id = ?', [$planned->rowId()]);

        try {
            $this->checklists->get($planned->rowId());
            self::fail('A row that is gone cannot be returned.');
        } catch (StateException $refused) {
            self::assertStringContainsString('no longer in the state database', $refused->getMessage());
        }
    }

    /**
     * One line a user can read, and one sentence saying what to do about it.
     */
    #[Test]
    public function a_planned_write_describes_itself_and_what_is_left_to_do(): void
    {
        $planned = $this->plan($this->committed());

        self::assertStringContainsString('tick the box', $planned->describe());
        self::assertStringContainsString(self::PATH, $planned->describe());
        self::assertStringContainsString('has not been written', $planned->describe());
        self::assertStringContainsString('`ralph resume`', $planned->nextAction());
        self::assertSame('checklist write pending', $planned->state()->label());

        $refused = $this->checklists->deferred($planned, 'the directory is read-only');
        self::assertStringContainsString('the directory is read-only', $refused->describe());

        $applied = $this->checklists->applied($refused, 'after');
        self::assertStringContainsString('was written', $applied->describe());
        self::assertStringNotContainsString(
            'read-only',
            $applied->describe(),
            'A write that landed does not report the attempt it took to get there.',
        );
    }

    #[Test]
    public function a_planned_write_reads_back_as_structure(): void
    {
        $pass = $this->committed();
        $planned = $this->plan($pass, 'file', 'task');

        $structured = $this->checklists->applied($planned, 'after')->toArray();

        self::assertSame($pass->slug(), $structured['generation']);
        self::assertSame($pass->task()->value(), $structured['task']);
        self::assertSame($this->run, $structured['run']);
        self::assertSame(self::PATH, $structured['path']);
        self::assertSame('checked', $structured['marker']);
        self::assertSame('applied', $structured['state']);
        self::assertSame('file', $structured['expected_hash']);
        self::assertSame('task', $structured['task_hash']);
        self::assertSame('after', $structured['resulting_hash']);
        self::assertSame(1, $structured['attempts']);
        self::assertNull($structured['error']);
        self::assertSame('2026-11-01T09:30:00+00:00', $structured['applied_at']);
    }

    /**
     * A pass carried as far as a commit: the state a planned write belongs to.
     */
    /**
     * The reading of the file a plan was made against, as the write reads it
     * back.
     *
     * PROJECT.md section H, item 8 makes the checkbox write conditional on
     * this, so the row has to be able to answer it plainly: the document the
     * write is derived from is the one this reading describes, and anything
     * else on disk is an edit that arrived since.
     */
    #[Test]
    public function a_plan_knows_the_reading_of_the_file_it_was_made_against(): void
    {
        $owed = $this->plan($this->committed(), checklistHash: 'the-file-as-it-read');

        self::assertTrue($owed->describes('the-file-as-it-read'));
        self::assertFalse($owed->describes('edited-since'));
    }

    /**
     * And the task it was earned by, which is the check that refuses rather
     * than retries.
     */
    #[Test]
    public function a_plan_knows_whether_its_task_still_reads_as_it_did(): void
    {
        $owed = $this->plan($this->committed(), taskHash: 'the-task-as-it-read');

        self::assertTrue($owed->stillDescribes('the-task-as-it-read'));
        self::assertFalse($owed->stillDescribes('reworded-since'));
    }

    /**
     * A refused write keeps its subject, and a refreshed plan takes the new
     * one.
     *
     * The two readings move for different reasons. A write that was refused is
     * still owed and still about the same task, so nothing about what it
     * describes may drift while it waits. When the next run comes to make it,
     * it re-reads the file and plans again — and because that reuses the
     * outstanding row rather than adding to it, the row then describes the file
     * as it now reads, which is what the write will be conditional on.
     */
    #[Test]
    public function a_refused_plan_keeps_its_subject_until_the_next_run_refreshes_it(): void
    {
        $pass = $this->committed();
        $owed = $this->checklists->deferred(
            $this->plan($pass, checklistHash: 'the-file-as-it-read', taskHash: 'the-task-as-it-read'),
            'Unable to write the task checklist',
        );

        self::assertTrue($owed->describes('the-file-as-it-read'));
        self::assertTrue($owed->stillDescribes('the-task-as-it-read'));

        $refreshed = $this->plan($pass, checklistHash: 'the-file-with-a-task-added', taskHash: 'the-task-as-it-read');

        self::assertSame($owed->rowId(), $refreshed->rowId(), 'The same claim on the same line.');
        self::assertTrue($refreshed->describes('the-file-with-a-task-added'));
        self::assertFalse($refreshed->describes('the-file-as-it-read'));
        self::assertTrue($refreshed->stillDescribes('the-task-as-it-read'));
    }

    private function committed(
        ?TaskGeneration $generation = null,
        string $description = self::DESCRIPTION,
    ): GenerationRecord {
        $snapshot = TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));

        if (!$this->tasks->has($snapshot->id())) {
            $this->tasks->record($snapshot);
        }

        $previous = $this->generations->openFor($snapshot->id());

        if ($previous !== null && !$previous->generation()->equals($generation ?? $previous->generation())) {
            // A task has at most one pass that can be continued, so an earlier
            // one is finished before a later one opens — which is what reopening
            // a task actually does.
            $this->generations->complete($previous);
        }

        $pass = $this->generations->start(
            $generation ?? TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'main',
            'a1b2c3d4e5f6',
            PushResult::notRequired(),
            $this->run,
        );

        return $this->generations->recordCommit(
            $this->generations->beginAttempt($pass),
            str_repeat('f', 40),
        );
    }

    private function plan(
        GenerationRecord $pass,
        string $checklistHash = 'the-file-as-it-read',
        string $taskHash = 'the-task-as-it-read',
    ): ChecklistUpdate {
        return $this->checklists->plan($pass, $this->run, self::PATH, $checklistHash, $taskHash);
    }
}
