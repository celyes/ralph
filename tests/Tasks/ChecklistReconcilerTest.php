<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Exception\ReconciliationException;
use Celyes\RalphPhp\Tasks\Checklist;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\ChecklistReconciler;
use Celyes\RalphPhp\Tasks\ChecklistSnapshot;
use Celyes\RalphPhp\Tasks\TaskChange;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskList;
use Celyes\RalphPhp\Tasks\TaskReconciliation;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Noticing that the checklist changed under a running task.
 *
 * Each test is the same shape as the run it protects: read a checklist, take a
 * snapshot of it as a task is picked up, edit the file the way a user would
 * while the agent works, read it again, and ask what may be recorded. The edits
 * are the ones people actually make — fixing a typo in a task, tightening the
 * acceptance criteria under it, deleting a task they changed their mind about,
 * ticking something off by hand, reordering the list — and the assertion is
 * which of them stop the run and which do not.
 */
#[CoversClass(ChecklistReconciler::class)]
#[CoversClass(ChecklistSnapshot::class)]
#[CoversClass(TaskReconciliation::class)]
#[CoversClass(TaskChange::class)]
#[CoversClass(ReconciliationException::class)]
final class ChecklistReconcilerTest extends TestCase
{
    /**
     * The checklist a run starts from in most of these tests.
     */
    private const string BEFORE = <<<'MARKDOWN'
        # Tasks

        - [x] Extract the importer
        - [ ] Add a --dry-run option
          - It must not write to the database.
        - [ ] Document the exit codes
        MARKDOWN;

    private ChecklistParser $parser;

    private ChecklistReconciler $reconciler;

    protected function setUp(): void
    {
        $this->parser = new ChecklistParser();
        $this->reconciler = new ChecklistReconciler();
    }

    #[Test]
    public function an_untouched_checklist_lets_the_progress_be_recorded(): void
    {
        $result = $this->reconcile(self::BEFORE, self::BEFORE, 'Add a --dry-run option');

        self::assertSame(TaskChange::None, $result->change());
        self::assertFalse($result->requiresPause());
        self::assertTrue($result->isUnchanged());
        self::assertSame([], $result->hints());
        self::assertSame('Add a --dry-run option', $result->current()?->description());
    }

    #[Test]
    public function a_task_that_moved_is_the_same_task_at_a_new_line(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [ ] Document the exit codes
            - [ ] Add a --dry-run option
              - It must not write to the database.
            - [x] Extract the importer
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::None, $result->change());
        self::assertSame(4, $result->snapshot()->line());
        self::assertSame(4, $result->current()?->line());
    }

    #[Test]
    public function editing_another_task_is_not_this_tasks_business(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks and notes

            Everything below is in priority order.

            - [x] Extract the importer
            - [ ] Add a --dry-run option
              - It must not write to the database.
            - [ ] Document the exit codes in the README
            - [ ] Cache the schema lookup
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::None, $result->change());
        self::assertFalse($result->requiresPause());
    }

    #[Test]
    public function respacing_and_recasing_the_task_is_not_a_change(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            * [ ]   Add a --DRY-RUN   option
                -  It must not write to the database.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::None, $result->change());
    }

    #[Test]
    public function edited_details_stop_the_run(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Add a --dry-run option
              - It must not write to the database.
              - It must print the statements it would have run.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::DetailsEdited, $result->change());
        self::assertTrue($result->requiresPause());
        self::assertTrue($result->change()->isStillStated());
        self::assertStringContainsString('edited details under TASK-', $result->reason());
        self::assertStringContainsString('Add a --dry-run option', $result->reason());
        self::assertStringContainsString(
            'still on its task branch',
            implode("\n", $result->hints()),
        );
    }

    #[Test]
    public function deleted_details_stop_the_run(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Add a --dry-run option
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::DetailsEdited, $result->change());
        self::assertSame('', $result->current()?->details());
        self::assertSame('- It must not write to the database.', $result->snapshot()->details());
    }

    #[Test]
    public function reindenting_the_details_is_not_an_edit_to_them(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Add a --dry-run option
                  -   It must not write
                      to the database.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::None, $result->change());
    }

    #[Test]
    public function a_reworded_task_names_the_line_that_replaced_it(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Add a --dry-run option to the importer
              - It must not write to the database.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Reworded, $result->change());
        self::assertTrue($result->requiresPause());
        self::assertFalse($result->change()->isStillStated());
        self::assertNull($result->current());
        self::assertSame(
            'Add a --dry-run option to the importer',
            $result->replacement()?->description(),
        );
        self::assertStringContainsString('no longer states TASK-', $result->reason());
        self::assertStringContainsString(
            'the closest item now reads "Add a --dry-run option to the importer"',
            $result->reason(),
        );
    }

    #[Test]
    public function a_deleted_task_is_reported_as_gone(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Removed, $result->change());
        self::assertTrue($result->requiresPause());
        self::assertNull($result->current());
        self::assertNull($result->replacement());
        self::assertStringContainsString('no longer states TASK-', $result->reason());
        self::assertStringContainsString('"Add a --dry-run option"', $result->reason());
    }

    #[Test]
    public function a_task_replaced_by_unrelated_work_is_a_removal_not_a_rewording(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Upgrade the test runner to PHPUnit 12
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Removed, $result->change());
        self::assertNull($result->replacement());
    }

    #[Test]
    public function a_task_that_was_already_in_the_file_is_never_offered_as_the_rewording(): void
    {
        // "Document the exit codes" reads nothing like the task that went, but
        // the point is that it would not be offered even if it did: it is its
        // own task, and someone is waiting for it.
        $before = <<<'MARKDOWN'
            - [ ] Add a --dry-run option
            - [ ] Add a --dry-run option to the importer
            MARKDOWN;

        $after = <<<'MARKDOWN'
            - [ ] Add a --dry-run option to the importer
            MARKDOWN;

        $result = $this->reconcile($before, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Removed, $result->change());
        self::assertNull($result->replacement());
    }

    #[Test]
    public function the_closest_of_several_new_items_is_the_one_offered(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Rewrite the changelog
            - [ ] Add a --dry-run option to the import command
            - [ ] Document the exit codes
            - [ ] Benchmark the parser
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Reworded, $result->change());
        self::assertSame(
            'Add a --dry-run option to the import command',
            $result->replacement()?->description(),
        );
    }

    #[Test]
    public function a_box_ticked_by_hand_during_the_run_stops_it(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [x] Add a --dry-run option
              - It must not write to the database.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Ticked, $result->change());
        self::assertTrue($result->requiresPause());
        self::assertTrue($result->current()?->isChecked());
        self::assertFalse($result->snapshot()->isChecked());
        self::assertStringContainsString('marked done in', $result->reason());
        self::assertStringContainsString('something other than this run', $result->reason());
        self::assertStringContainsString('Untick the item', implode("\n", $result->hints()));
    }

    #[Test]
    public function a_box_ticked_by_hand_is_reported_even_when_the_details_changed_too(): void
    {
        $after = <<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [x] Add a --dry-run option
              - Done by hand, the flag is in the importer already.
            - [ ] Document the exit codes
            MARKDOWN;

        $result = $this->reconcile(self::BEFORE, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Ticked, $result->change());
    }

    #[Test]
    public function an_item_unticked_during_the_run_stops_it(): void
    {
        // The shape of a retry: the task was ticked when it was picked up, and
        // someone reopened it while the run was working on it.
        $before = <<<'MARKDOWN'
            - [x] Add a --dry-run option
            MARKDOWN;

        $after = <<<'MARKDOWN'
            - [ ] Add a --dry-run option
            MARKDOWN;

        $result = $this->reconcile($before, $after, 'Add a --dry-run option');

        self::assertSame(TaskChange::Unticked, $result->change());
        self::assertTrue($result->requiresPause());
        self::assertStringContainsString('marked as still to do', $result->reason());
        self::assertSame('unticked by hand', $result->change()->label());
    }

    #[Test]
    public function the_file_is_named_in_what_the_pause_says(): void
    {
        $checklist = $this->parser->parse(self::BEFORE, '/project/.ralph/tasks.md');
        $snapshot = ChecklistSnapshot::fromChecklist($checklist);

        $result = $this->reconciler->reconcile(
            $snapshot,
            TaskId::fromDescription('Add a --dry-run option'),
            $this->parser->parse("- [ ] Document the exit codes\n", '/project/.ralph/tasks.md'),
        );

        self::assertSame('/project/.ralph/tasks.md', $snapshot->source());
        self::assertSame('The task checklist /project/.ralph/tasks.md', $snapshot->subject());
        self::assertStringContainsString(
            'The task checklist /project/.ralph/tasks.md no longer states',
            $result->reason(),
        );
    }

    #[Test]
    public function a_pause_becomes_a_failure_a_command_can_stop_on(): void
    {
        $result = $this->reconcile(self::BEFORE, "- [ ] Document the exit codes\n", 'Add a --dry-run option');

        $exception = $result->toException();

        self::assertSame(TaskChange::Removed, $exception->change());
        self::assertSame($result->reason(), $exception->getMessage());
        self::assertSame($result->hints(), $exception->hints());
        self::assertSame(78, $exception->exitCode());
    }

    #[Test]
    public function an_unchanged_task_cannot_be_turned_into_a_failure(): void
    {
        $result = $this->reconcile(self::BEFORE, self::BEFORE, 'Add a --dry-run option');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An unchanged task is not a reconciliation failure.');

        $result->toException();
    }

    #[Test]
    public function reconciling_a_task_the_snapshot_never_had_is_refused(): void
    {
        $snapshot = ChecklistSnapshot::fromChecklist($this->parser->parse(self::BEFORE));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a task in the recorded checklist snapshot');

        $this->reconciler->reconcile(
            $snapshot,
            TaskId::fromDescription('Benchmark the parser'),
            $this->parser->parse(self::BEFORE),
        );
    }

    #[Test]
    public function a_checklist_edited_into_two_identical_tasks_is_refused_in_its_own_words(): void
    {
        $snapshot = ChecklistSnapshot::fromChecklist($this->parser->parse(self::BEFORE));

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('states the same task on lines');

        $this->reconciler->reconcile(
            $snapshot,
            TaskId::fromDescription('Add a --dry-run option'),
            $this->parser->parse(
                "- [ ] Add a --dry-run option\n- [ ] Add a --dry-run option\n",
            ),
        );
    }

    #[Test]
    public function an_already_identified_reading_is_not_identified_twice(): void
    {
        $snapshot = ChecklistSnapshot::fromChecklist(
            $this->parser->parse(self::BEFORE, '/project/.ralph/tasks.md'),
        );

        $result = $this->reconciler->reconcileAgainst(
            $snapshot,
            TaskId::fromDescription('Add a --dry-run option'),
            TaskList::fromChecklist($this->parser->parse("- [ ] Document the exit codes\n")),
        );

        self::assertSame(TaskChange::Removed, $result->change());
        self::assertStringContainsString('/project/.ralph/tasks.md', $result->reason());
    }

    #[Test]
    public function the_snapshot_holds_every_task_the_checklist_stated(): void
    {
        $snapshot = ChecklistSnapshot::fromChecklist($this->parser->parse(self::BEFORE));

        self::assertSame(3, $snapshot->count());
        self::assertTrue($snapshot->knows(TaskId::fromDescription('Extract the importer')));
        self::assertFalse($snapshot->knows(TaskId::fromDescription('Benchmark the parser')));
        self::assertNull($snapshot->find(TaskId::fromDescription('Benchmark the parser')));
        self::assertSame('The task checklist', $snapshot->subject());
        self::assertSame(
            ['Extract the importer', 'Add a --dry-run option', 'Document the exit codes'],
            array_map(
                static fn ($task): string => $task->description(),
                $snapshot->tasks(),
            ),
        );
        self::assertTrue($snapshot->find(TaskId::fromDescription('Extract the importer'))?->isChecked());
    }

    private function reconcile(string $before, string $after, string $running): TaskReconciliation
    {
        return $this->reconciler->reconcile(
            ChecklistSnapshot::fromChecklist($this->parse($before)),
            TaskId::fromDescription($running),
            $this->parse($after),
        );
    }

    private function parse(string $markdown): Checklist
    {
        return $this->parser->parse($markdown);
    }
}
