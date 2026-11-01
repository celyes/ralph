<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskRecord;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the `tasks` table remembers about a checklist, and what it refuses to
 * claim.
 *
 * The interesting behaviour is all about honesty and about noticing edits. A
 * run must never report a tick it did not earn, so a checked box found in the
 * file is recorded as the user's and only {@see TaskStore::markVerified()} —
 * called after verification, Git and any required push have succeeded — writes
 * the stronger verdict. And the three timestamps have to stay distinguishable,
 * because `last_seen_at` is what identifies a task the user has deleted, which
 * is a different event from a task whose details were edited.
 *
 * Written against a real migrated database rather than a double: the rules
 * being tested are partly the schema's, and a store tested against a fake would
 * stop testing the thing that actually refuses bad rows.
 */
#[CoversClass(TaskStore::class)]
#[CoversClass(TaskRecord::class)]
final class TaskStoreTest extends TestCase
{
    private Database $database;

    private FrozenClock $clock;

    private TaskStore $tasks;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $this->tasks = new TaskStore($this->database, $this->clock);
    }

    #[Test]
    public function recording_a_task_stores_what_the_checklist_said(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option', ['- It must not write to the database.']);

        $record = $this->tasks->record($snapshot);

        self::assertTrue($record->id()->equals($snapshot->id()));
        self::assertSame('Add a --dry-run option', $record->description());
        self::assertSame('- It must not write to the database.', $record->details());
        self::assertSame($snapshot->contentHash(), $record->contentHash());
        self::assertGreaterThan(0, $record->rowId());
    }

    /**
     * An unticked item is pending, which is the state a run picks work up
     * from.
     */
    #[Test]
    public function an_unticked_item_is_recorded_as_pending(): void
    {
        $record = $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame(Completion::Pending, $record->completion());
        self::assertTrue($record->isPending());
        self::assertFalse($record->isVerified());
    }

    /**
     * A box that was already ticked when the package first saw it is the
     * user's work, not the runner's. PROJECT.md section C, item 5 requires the
     * two be labelled apart.
     */
    #[Test]
    public function a_ticked_item_the_runner_never_ran_is_recorded_as_the_users(): void
    {
        $record = $this->tasks->record($this->snapshot('Already done', checked: true));

        self::assertSame(Completion::UserCompleted, $record->completion());
        self::assertFalse($record->isVerified());
        self::assertFalse($record->isPending());
    }

    /**
     * The only thing that writes the stronger verdict, and only after the work
     * has actually been carried through.
     */
    #[Test]
    public function marking_a_task_verified_records_it_as_the_runners(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);

        $record = $this->tasks->markVerified($snapshot->id());

        self::assertSame(Completion::RunnerVerified, $record->completion());
        self::assertTrue($record->isVerified());
    }

    /**
     * Re-reading the checklist after the runner ticked a box must not
     * downgrade what the run earned.
     */
    #[Test]
    public function a_later_reading_does_not_demote_a_tick_the_runner_earned(): void
    {
        $pending = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($pending);
        $this->tasks->markVerified($pending->id());

        $record = $this->tasks->record($this->snapshot('Add a --dry-run option', checked: true));

        self::assertSame(Completion::RunnerVerified, $record->completion());
    }

    /**
     * Unticking is how a user reopens finished work, so the reading is believed
     * about whether the task is done. The pass that earned the tick is kept as
     * its own generation rather than contradicted here.
     */
    #[Test]
    public function unticking_a_verified_task_returns_it_to_pending(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);
        $this->tasks->markVerified($snapshot->id());

        $record = $this->tasks->record($this->snapshot('Add a --dry-run option', checked: false));

        self::assertSame(Completion::Pending, $record->completion());
        self::assertTrue($record->isPending());
    }

    /**
     * The distinction that survives a reopening: a task ticked by the user and
     * then unticked and reticked is still the user's, never promoted by having
     * been seen twice.
     */
    #[Test]
    public function a_users_tick_is_never_promoted_by_being_read_again(): void
    {
        $this->tasks->record($this->snapshot('Already done', checked: true));
        $this->tasks->record($this->snapshot('Already done', checked: false));

        $record = $this->tasks->record($this->snapshot('Already done', checked: true));

        self::assertSame(Completion::UserCompleted, $record->completion());
    }

    #[Test]
    public function recording_the_same_task_twice_updates_its_row_rather_than_adding_one(): void
    {
        $this->tasks->record($this->snapshot('Add a --dry-run option'));
        $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame(1, $this->tasks->count());
    }

    /**
     * Identity comes from the wording and from nothing else, so a checklist
     * that was reordered maps onto the same rows.
     */
    #[Test]
    public function a_task_that_moved_in_the_file_is_the_same_row(): void
    {
        $first = $this->tasks->record($this->snapshot('Add a --dry-run option', line: 3));

        $moved = $this->tasks->record($this->snapshot('Add a --dry-run option', line: 41));

        self::assertSame($first->rowId(), $moved->rowId());
        self::assertSame(1, $this->tasks->count());
    }

    #[Test]
    public function an_edit_to_the_details_is_recorded_as_a_new_hash(): void
    {
        $before = $this->tasks->record(
            $this->snapshot('Add a --dry-run option', ['- It must not write.']),
        );

        $after = $this->tasks->record(
            $this->snapshot('Add a --dry-run option', ['- It must not write.', '- And must say so.']),
        );

        self::assertSame($before->rowId(), $after->rowId(), 'The identity is unchanged.');
        self::assertNotSame($before->contentHash(), $after->contentHash());
        self::assertSame('- It must not write.' . "\n" . '- And must say so.', $after->details());
    }

    /**
     * Recording the edit is what makes it visible; judging what to do about it
     * needs a human and belongs to reconciliation.
     *
     * The marker is held still here on purpose, so that what is under test is
     * the edit rather than the unticking: a ticked task whose details were
     * rewritten keeps the verdict it had, and the new hash is what tells
     * reconciliation to look at it.
     */
    #[Test]
    public function an_edit_to_the_details_does_not_change_the_completion(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option', ['- It must not write.'], checked: true);
        $this->tasks->record($snapshot);
        $this->tasks->markVerified($snapshot->id());

        $record = $this->tasks->record(
            $this->snapshot('Add a --dry-run option', ['- Rewritten entirely.'], checked: true),
        );

        self::assertSame(Completion::RunnerVerified, $record->completion());
        self::assertNotSame($snapshot->contentHash(), $record->contentHash());
    }

    #[Test]
    public function a_recorded_task_can_be_asked_whether_a_reading_states_the_same_work(): void
    {
        $original = $this->snapshot('Add a --dry-run option', ['- It must not write.']);
        $record = $this->tasks->record($original);

        self::assertTrue($record->statesSameWork($original));
        self::assertFalse(
            $record->statesSameWork($this->snapshot('Add a --dry-run option', ['- Changed.'])),
        );
        self::assertFalse(
            $record->hasChangedFrom($this->task('Add a --dry-run option', ['- It must not write.'])),
        );
        self::assertTrue(
            $record->hasChangedFrom($this->task('Add a --dry-run option', ['- Changed.'])),
        );
    }

    /**
     * Re-indenting a bullet or rewrapping a line is not a change of work, so it
     * is not reported as one.
     */
    #[Test]
    public function reformatting_the_details_is_not_recorded_as_changed_work(): void
    {
        $record = $this->tasks->record(
            $this->snapshot('Add a --dry-run option', ['- It must not write.']),
        );

        self::assertTrue($record->statesSameWork(
            $this->snapshot('Add a --dry-run option', ['-   It must   not write.']),
        ));
    }

    #[Test]
    public function a_task_that_was_never_recorded_is_not_found(): void
    {
        self::assertNull($this->tasks->find(TaskId::fromDescription('Never mentioned')));
        self::assertFalse($this->tasks->has(TaskId::fromDescription('Never mentioned')));
        self::assertSame(0, $this->tasks->count());
    }

    #[Test]
    public function a_recorded_task_is_found_by_its_identifier(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);

        self::assertTrue($this->tasks->has($snapshot->id()));
        self::assertTrue($this->tasks->get($snapshot->id())->id()->equals($snapshot->id()));
    }

    /**
     * Reported as the mistake it is, rather than left to surface as a foreign
     * key failure three writes later.
     */
    #[Test]
    public function asking_for_a_task_that_was_never_recorded_is_refused_readably(): void
    {
        $this->expectException(StateException::class);
        $this->expectExceptionMessage('has no record of task');

        $this->tasks->get(TaskId::fromDescription('Never mentioned'));
    }

    #[Test]
    public function marking_a_task_that_was_never_recorded_is_refused(): void
    {
        $this->expectException(StateException::class);

        $this->tasks->markVerified(TaskId::fromDescription('Never mentioned'));
    }

    /**
     * Ordered by when they were first seen, not by checklist position, which
     * this table does not store: position changes without the work changing.
     */
    #[Test]
    public function tasks_are_listed_oldest_first(): void
    {
        $this->tasks->record($this->snapshot('First task'));
        $this->clock->advance(60);
        $this->tasks->record($this->snapshot('Second task'));
        $this->clock->advance(60);
        $this->tasks->record($this->snapshot('Third task'));

        self::assertSame(
            ['First task', 'Second task', 'Third task'],
            array_map(
                static fn (TaskRecord $task): string => $task->description(),
                $this->tasks->all(),
            ),
        );
    }

    /**
     * Two tasks first seen in the same reading still come back in a settled
     * order, because the row id breaks the tie.
     */
    #[Test]
    public function tasks_first_seen_together_are_listed_in_the_order_they_were_recorded(): void
    {
        $this->tasks->recordReading(
            $this->snapshot('First task'),
            $this->snapshot('Second task'),
            $this->snapshot('Third task'),
        );

        self::assertSame(
            ['First task', 'Second task', 'Third task'],
            array_map(
                static fn (TaskRecord $task): string => $task->description(),
                $this->tasks->all(),
            ),
        );
    }

    #[Test]
    public function the_verified_tasks_are_the_ones_the_runner_carried_through(): void
    {
        $verified = $this->snapshot('Runner did this');
        $this->tasks->record($verified);
        $this->tasks->record($this->snapshot('User did this', checked: true));
        $this->tasks->record($this->snapshot('Nobody did this'));
        $this->tasks->markVerified($verified->id());

        $result = $this->tasks->verified();

        self::assertSame(1, $result->count());
        self::assertTrue($result->contains($verified->id()));
        self::assertFalse($result->contains(TaskId::fromDescription('User did this')));
    }

    #[Test]
    public function nothing_is_verified_in_a_fresh_database(): void
    {
        self::assertTrue($this->tasks->verified()->isEmpty());
    }

    /**
     * Every task in a reading gets the same `last_seen_at`. With per-task
     * timestamps a reading that took two seconds would look like two readings,
     * and the absent-task query below would name tasks that are still there.
     */
    #[Test]
    public function a_whole_reading_is_recorded_at_one_moment(): void
    {
        $result = $this->tasks->recordReading(
            $this->snapshot('First task'),
            $this->snapshot('Second task'),
        );

        foreach ($result['tasks'] as $task) {
            self::assertTrue(
                $task->lastSeenAt()->equals($result['reading']),
                'Every task in a reading shares the reading\'s moment.',
            );
        }
        self::assertSame('2026-11-01T09:30:00+00:00', $result['reading']->value());
    }

    /**
     * And it stays one moment even when the reading takes time.
     *
     * The clock here moves a second every time it is read, which is what a slow
     * reading of a long checklist looks like. A store that stamped each task as
     * it wrote it would produce a spread of timestamps, and
     * {@see TaskStore::absentSince()} — which names the tasks whose last_seen is
     * older than the reading — would then report tasks that are still in the
     * file as deleted.
     */
    #[Test]
    public function a_reading_that_takes_time_is_still_recorded_at_one_moment(): void
    {
        $ticking = new class () implements Clock {
            private int $calls = 0;

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable(
                    sprintf('2026-11-01 09:30:%02d', $this->calls++),
                    new DateTimeZone('UTC'),
                );
            }
        };
        $store = new TaskStore($this->database, $ticking);

        $result = $store->recordReading(
            $this->snapshot('First task'),
            $this->snapshot('Second task'),
            $this->snapshot('Third task'),
        );

        foreach ($result['tasks'] as $task) {
            self::assertTrue(
                $task->lastSeenAt()->equals($result['reading']),
                'A reading that spans a clock tick is still one reading.',
            );
        }
        self::assertSame([], $store->absentSince($result['reading']));
    }

    #[Test]
    public function recording_an_empty_reading_records_nothing(): void
    {
        $result = $this->tasks->recordReading();

        self::assertSame([], $result['tasks']);
        self::assertSame(0, $this->tasks->count());
    }

    /**
     * The test for a task the user removed: PROJECT.md section C, item 7
     * requires it be noticed rather than have old progress applied to it.
     */
    #[Test]
    public function a_task_the_checklist_no_longer_states_is_left_behind_by_a_reading(): void
    {
        $this->tasks->recordReading(
            $this->snapshot('Still there'),
            $this->snapshot('Since deleted'),
        );
        $this->clock->advance(3600);

        $second = $this->tasks->recordReading($this->snapshot('Still there'));

        $absent = $this->tasks->absentSince($second['reading']);

        self::assertCount(1, $absent);
        self::assertSame('Since deleted', $absent[0]->description());
        self::assertTrue($absent[0]->wasAbsentFrom($second['reading']));
        self::assertFalse($second['tasks'][0]->wasAbsentFrom($second['reading']));
    }

    /**
     * Returned rather than deleted: their generations hold branches and commits
     * that still exist in the repository.
     */
    #[Test]
    public function an_absent_task_keeps_its_row(): void
    {
        $this->tasks->recordReading($this->snapshot('Since deleted'));
        $this->clock->advance(3600);

        $this->tasks->recordReading();

        self::assertSame(1, $this->tasks->count());
        self::assertTrue($this->tasks->has(TaskId::fromDescription('Since deleted')));
    }

    /**
     * Identity coming from the wording, a reworded task is a deleted one and a
     * new one — which is exactly what reconciliation has to be told about.
     */
    #[Test]
    public function a_reworded_task_is_absent_and_its_replacement_is_new(): void
    {
        $this->tasks->recordReading($this->snapshot('Add a --dry-run option'));
        $this->clock->advance(3600);

        $second = $this->tasks->recordReading($this->snapshot('Add a --dry-run flag'));

        $absent = $this->tasks->absentSince($second['reading']);

        self::assertCount(1, $absent);
        self::assertSame('Add a --dry-run option', $absent[0]->description());
        self::assertSame(2, $this->tasks->count());
    }

    #[Test]
    public function nothing_is_absent_when_the_whole_checklist_was_read(): void
    {
        $result = $this->tasks->recordReading(
            $this->snapshot('First task'),
            $this->snapshot('Second task'),
        );

        self::assertSame([], $this->tasks->absentSince($result['reading']));
    }

    #[Test]
    public function the_three_timestamps_start_out_together(): void
    {
        $record = $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame('2026-11-01T09:30:00+00:00', $record->firstSeenAt()->value());
        self::assertSame('2026-11-01T09:30:00+00:00', $record->lastSeenAt()->value());
        self::assertSame('2026-11-01T09:30:00+00:00', $record->updatedAt()->value());
    }

    /**
     * First seen never changes: it is what makes a status table orderable by
     * age rather than by file position.
     */
    #[Test]
    public function first_seen_never_moves(): void
    {
        $this->tasks->record($this->snapshot('Add a --dry-run option'));
        $this->clock->advance(86_400);

        $later = $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame('2026-11-01T09:30:00+00:00', $later->firstSeenAt()->value());
    }

    /**
     * The one that moves every time, including when nothing changed — which is
     * what makes it able to identify a task that has stopped appearing.
     */
    #[Test]
    public function last_seen_moves_on_every_reading_even_when_nothing_changed(): void
    {
        $this->tasks->record($this->snapshot('Add a --dry-run option'));
        $this->clock->advance(3600);

        $later = $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame('2026-11-01T10:30:00+00:00', $later->lastSeenAt()->value());
        self::assertSame(
            '2026-11-01T09:30:00+00:00',
            $later->updatedAt()->value(),
            'A reading that changed nothing is not something moving.',
        );
    }

    #[Test]
    public function updated_moves_when_the_content_changes(): void
    {
        $this->tasks->record($this->snapshot('Add a --dry-run option', ['- It must not write.']));
        $this->clock->advance(3600);

        $later = $this->tasks->record(
            $this->snapshot('Add a --dry-run option', ['- Rewritten entirely.']),
        );

        self::assertSame('2026-11-01T10:30:00+00:00', $later->updatedAt()->value());
    }

    #[Test]
    public function updated_moves_when_the_completion_changes(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);
        $this->clock->advance(3600);

        $later = $this->tasks->record($this->snapshot('Add a --dry-run option', checked: true));

        self::assertSame('2026-11-01T10:30:00+00:00', $later->updatedAt()->value());
    }

    /**
     * `updated_at` keeps meaning "when something about this task moved" rather
     * than "when a run last looked at it", which is what `last_seen_at` is for.
     */
    #[Test]
    public function marking_the_completion_it_already_has_moves_nothing(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);
        $this->tasks->markVerified($snapshot->id());
        $this->clock->advance(3600);

        $again = $this->tasks->markVerified($snapshot->id());

        self::assertSame('2026-11-01T09:30:00+00:00', $again->updatedAt()->value());
    }

    #[Test]
    public function marking_a_new_completion_moves_updated(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $this->tasks->record($snapshot);
        $this->clock->advance(3600);

        $marked = $this->tasks->markCompletion($snapshot->id(), Completion::UserCompleted);

        self::assertSame(Completion::UserCompleted, $marked->completion());
        self::assertSame('2026-11-01T10:30:00+00:00', $marked->updatedAt()->value());
        self::assertSame(
            '2026-11-01T09:30:00+00:00',
            $marked->lastSeenAt()->value(),
            'Marking a completion is not a reading of the checklist.',
        );
    }

    /**
     * A reading is one fact about the checklist, and half of it recorded is a
     * state nothing knows how to interpret — so it is written in one
     * transaction.
     */
    #[Test]
    public function a_reading_that_fails_partway_records_none_of_itself(): void
    {
        $this->database->execute(
            'CREATE TRIGGER refuse_second AFTER INSERT ON tasks
             WHEN NEW.description = \'Second task\'
             BEGIN SELECT RAISE(ABORT, \'refused\'); END',
        );

        try {
            $this->tasks->recordReading(
                $this->snapshot('First task'),
                $this->snapshot('Second task'),
            );
            self::fail('The refused insert should have failed the reading.');
        } catch (StateException) {
            self::assertSame(
                0,
                $this->tasks->count(),
                'The first task must not survive a reading that failed.',
            );
        }
    }

    #[Test]
    public function a_record_describes_itself_for_a_status_table(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $record = $this->tasks->record($snapshot);

        self::assertSame(
            sprintf('%s Add a --dry-run option [%s]', $snapshot->id()->value(), Completion::Pending->label()),
            $record->describe(),
        );
    }

    /**
     * A row holding a completion this build does not know would otherwise
     * surface as a bare ValueError naming an enum the user has never heard of.
     */
    #[Test]
    public function a_row_with_an_unknown_completion_is_refused_readably(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a task completion');

        TaskRecord::fromRow([
            'id' => 1,
            'task_id' => 'TASK-19E0214A',
            'description' => 'Add a --dry-run option',
            'details' => '',
            'content_hash' => str_repeat('a', 64),
            'completion' => 'half_done',
            'first_seen_at' => '2026-11-01T09:30:00+00:00',
            'last_seen_at' => '2026-11-01T09:30:00+00:00',
            'updated_at' => '2026-11-01T09:30:00+00:00',
        ]);
    }

    #[Test]
    public function a_row_missing_a_required_column_is_refused_readably(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no description');

        TaskRecord::fromRow([
            'id' => 1,
            'task_id' => 'TASK-19E0214A',
            'details' => '',
            'content_hash' => str_repeat('a', 64),
            'completion' => 'pending',
            'first_seen_at' => '2026-11-01T09:30:00+00:00',
            'last_seen_at' => '2026-11-01T09:30:00+00:00',
            'updated_at' => '2026-11-01T09:30:00+00:00',
        ]);
    }

    /**
     * A task with no details written under it has an empty string, not a
     * missing value: there is nothing to say rather than something unknown.
     */
    #[Test]
    public function a_task_with_no_details_reads_them_as_empty(): void
    {
        $record = $this->tasks->record($this->snapshot('Add a --dry-run option'));

        self::assertSame('', $record->details());
    }

    /**
     * The clock is an interface so this is testable at all, and the default is
     * the real one — a store built without one still writes a usable timestamp.
     */
    #[Test]
    public function a_store_built_without_a_clock_uses_the_real_one(): void
    {
        $store = new TaskStore($this->database);
        $before = Timestamp::fromStorage(gmdate('c', time() - 1));

        $record = $store->record($this->snapshot('Add a --dry-run option'));

        self::assertTrue($record->firstSeenAt()->hasReached($before));
    }

    /**
     * @param list<string> $details
     */
    private function snapshot(
        string $description,
        array $details = [],
        bool $checked = false,
        int $line = 1,
    ): TaskSnapshot {
        return TaskSnapshot::of($this->task($description, $details, $checked, $line));
    }

    /**
     * @param list<string> $details
     */
    private function task(
        string $description,
        array $details = [],
        bool $checked = false,
        int $line = 1,
    ): Task {
        return Task::fromItem(new ChecklistItem(
            $description,
            $checked,
            $line,
            sprintf('- [%s] %s', $checked ? 'x' : ' ', $description),
            $details,
        ));
    }
}
