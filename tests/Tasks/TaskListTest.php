<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Tasks\Checklist;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskIdentifier;
use Celyes\RalphPhp\Tasks\TaskList;
use Celyes\RalphPhp\Tasks\VerifiedTasks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Selecting the next task, skipping the ticked ones, and saying who finished
 * them.
 *
 * Written against checklists a project really has: one being started for the
 * first time with history already ticked in it, one part way through a run, one
 * finished, one where the user reopened an item, and one where the ticked items
 * are not where a naive reader would expect them.
 */
#[CoversClass(TaskList::class)]
#[CoversClass(Completion::class)]
#[CoversClass(VerifiedTasks::class)]
final class TaskListTest extends TestCase
{
    private ChecklistParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ChecklistParser();
    }

    #[Test]
    public function pending_tasks_are_selected_in_file_order(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            # Tasks

            - [ ] Add a --dry-run option
            - [ ] Document the exit codes
            - [ ] Cache the schema lookup
            MARKDOWN);

        self::assertSame(
            ['Add a --dry-run option', 'Document the exit codes', 'Cache the schema lookup'],
            self::descriptions($list->pending()),
        );
        self::assertSame('Add a --dry-run option', $list->next()?->description());
        self::assertTrue($list->hasPending());
    }

    #[Test]
    public function checked_tasks_are_skipped_wherever_they_sit(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [x] Extract the importer
            - [X] Split the config loader
            - [ ] Add a --dry-run option
            - [x] Document the exit codes
            - [ ] Cache the schema lookup
            MARKDOWN);

        self::assertSame(
            ['Add a --dry-run option', 'Cache the schema lookup'],
            self::descriptions($list->pending()),
        );
        self::assertSame('Add a --dry-run option', $list->next()?->description());
    }

    #[Test]
    public function a_fully_checked_checklist_has_nothing_to_do(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [x] Extract the importer
            - [x] Add a --dry-run option
            MARKDOWN);

        self::assertNull($list->next());
        self::assertFalse($list->hasPending());
        self::assertFalse($list->isEmpty());
        self::assertSame(2, $list->count());
        self::assertSame(0, $list->pendingCount());
        self::assertSame(2, $list->doneCount());
    }

    #[Test]
    public function an_empty_checklist_is_empty_rather_than_finished(): void
    {
        $list = $this->read(Templates::tasks());

        self::assertTrue($list->isEmpty());
        self::assertFalse($list->hasPending());
        self::assertNull($list->next());
        self::assertSame([], $list->all());
        self::assertSame(0, $list->count());
    }

    #[Test]
    public function every_task_is_kept_in_file_order_whether_checked_or_not(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
            - [x] Extract the importer
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(
            ['Add a --dry-run option', 'Extract the importer', 'Document the exit codes'],
            self::descriptions($list->all()),
        );
        self::assertSame(['Extract the importer'], self::descriptions($list->done()));
        self::assertSame(2, $list->pendingCount());
        self::assertSame(1, $list->doneCount());
    }

    #[Test]
    public function an_imported_checked_task_is_the_users_completion_not_the_runners(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [x] Extract the importer
            - [ ] Add a --dry-run option
            MARKDOWN);

        $imported = TaskId::fromDescription('Extract the importer');

        self::assertSame(Completion::UserCompleted, $list->completionOf($imported));
        self::assertSame(['Extract the importer'], self::descriptions($list->userCompleted()));
        self::assertSame([], $list->runnerVerified());
        self::assertSame('done (by you)', $list->completionOf($imported)->label());
        self::assertSame(
            'marked done in the checklist, not verified by Ralph PHP',
            $list->completionOf($imported)->describe(),
        );
    }

    #[Test]
    public function a_task_the_runner_verified_is_reported_as_verified(): void
    {
        $verified = TaskId::fromDescription('Extract the importer');

        $list = $this->read(
            <<<'MARKDOWN'
            - [x] Extract the importer
            - [x] Split the config loader
            - [ ] Add a --dry-run option
            MARKDOWN,
            VerifiedTasks::of($verified),
        );

        self::assertSame(Completion::RunnerVerified, $list->completionOf($verified));
        self::assertSame(['Extract the importer'], self::descriptions($list->runnerVerified()));
        self::assertSame(['Split the config loader'], self::descriptions($list->userCompleted()));
        self::assertSame(
            ['Extract the importer', 'Split the config loader'],
            self::descriptions($list->done()),
        );
        self::assertSame('done (verified)', Completion::RunnerVerified->label());
    }

    #[Test]
    public function an_unchecked_task_is_pending_even_when_it_was_verified_before(): void
    {
        $reopened = TaskId::fromDescription('Extract the importer');

        $list = $this->read(
            <<<'MARKDOWN'
            - [ ] Extract the importer
            - [ ] Add a --dry-run option
            MARKDOWN,
            VerifiedTasks::of($reopened),
        );

        self::assertSame(Completion::Pending, $list->completionOf($reopened));
        self::assertSame('Extract the importer', $list->next()?->description());
        self::assertSame([], $list->runnerVerified());
        self::assertSame([], $list->done());
    }

    #[Test]
    public function a_verified_identifier_the_checklist_no_longer_states_is_ignored(): void
    {
        $list = $this->read(
            '- [ ] Add a --dry-run option',
            VerifiedTasks::of(TaskId::fromDescription('A task somebody deleted')),
        );

        self::assertSame(1, $list->count());
        self::assertSame('Add a --dry-run option', $list->next()?->description());
    }

    #[Test]
    public function completion_is_pending_for_every_unchecked_task(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
              - It must print the planned changes and write nothing.
            - [x] Extract the importer
            MARKDOWN);

        $pending = $list->next();
        self::assertNotNull($pending);
        self::assertSame(Completion::Pending, $list->completionOf($pending->id()));
        self::assertTrue($list->completionOf($pending->id())->isPending());
        self::assertFalse($list->completionOf($pending->id())->isDone());
        self::assertSame('pending', $list->completionOf($pending->id())->label());
        self::assertSame(
            '- It must print the planned changes and write nothing.',
            $pending->details(),
        );
    }

    #[Test]
    public function a_task_is_found_by_its_identifier(): void
    {
        $list = $this->read(<<<'MARKDOWN'
            - [x] Extract the importer
            - [ ] Add a --dry-run option
            MARKDOWN);

        $id = TaskId::fromDescription('Add a --dry-run option');

        $found = $list->find($id);

        self::assertTrue($list->has($id));
        self::assertNotNull($found);
        self::assertSame('Add a --dry-run option', $found->description());
        self::assertSame(2, $found->line());
    }

    #[Test]
    public function an_unknown_identifier_is_not_found(): void
    {
        $list = $this->read('- [ ] Add a --dry-run option');

        $unknown = TaskId::fromDescription('Something else entirely');

        self::assertFalse($list->has($unknown));
        self::assertNull($list->find($unknown));
    }

    #[Test]
    public function asking_where_an_unknown_task_stands_is_refused_rather_than_answered(): void
    {
        $list = $this->read('- [ ] Add a --dry-run option');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a task in this checklist.');

        $list->completionOf(TaskId::fromDescription('Something else entirely'));
    }

    #[Test]
    public function reordering_and_ticking_do_not_change_which_task_is_which(): void
    {
        $before = $this->read(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
            - [ ] Document the exit codes
            MARKDOWN);

        $id = $before->next()?->id();
        self::assertNotNull($id);

        $after = $this->read(<<<'MARKDOWN'
            # Tasks

            - [ ] Document the exit codes
            - [x] Add a --dry-run option
            MARKDOWN);

        self::assertSame('Document the exit codes', $after->next()?->description());
        self::assertTrue($after->has($id));
        self::assertSame(Completion::UserCompleted, $after->completionOf($id));
    }

    #[Test]
    public function a_duplicate_description_is_refused_while_building_the_list(): void
    {
        $this->expectException(ChecklistException::class);

        $this->read(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
            - [ ] add a --dry-run  option
            MARKDOWN);
    }

    #[Test]
    public function tasks_given_directly_are_taken_in_the_order_given(): void
    {
        $tasks = (new TaskIdentifier())->identify($this->parse(<<<'MARKDOWN'
            - [x] Extract the importer
            - [ ] Add a --dry-run option
            MARKDOWN));

        $list = TaskList::of($tasks);

        self::assertSame(
            ['Extract the importer', 'Add a --dry-run option'],
            self::descriptions($list->all()),
        );
        self::assertSame('Add a --dry-run option', $list->next()?->description());
    }

    #[Test]
    public function two_tasks_with_one_identifier_are_a_programming_error_here(): void
    {
        $tasks = (new TaskIdentifier())->identify(
            $this->parse('- [ ] Add a --dry-run option'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('share the identifier');

        TaskList::of([...$tasks, ...$tasks]);
    }

    #[Test]
    public function the_verified_set_collapses_repeats_and_reads_stored_identifiers(): void
    {
        $id = TaskId::fromDescription('Extract the importer');

        self::assertTrue(VerifiedTasks::none()->isEmpty());
        self::assertSame(0, VerifiedTasks::none()->count());
        self::assertFalse(VerifiedTasks::none()->contains($id));

        $set = VerifiedTasks::of($id, $id);
        self::assertSame(1, $set->count());
        self::assertSame([$id->value()], $set->values());
        self::assertTrue($set->contains($id));

        $same = $set->with($id);
        self::assertSame($set, $same);

        $other = TaskId::fromDescription('Add a --dry-run option');
        $grown = $set->with($other);
        self::assertSame(2, $grown->count());
        self::assertSame(1, $set->count(), 'the original set must not change');

        $stored = VerifiedTasks::fromStrings([strtolower($id->value()), ' ' . $other->value() . ' ']);
        self::assertTrue($stored->contains($id));
        self::assertTrue($stored->contains($other));
    }

    #[Test]
    public function a_stored_identifier_that_is_not_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a task identifier');

        VerifiedTasks::fromStrings(['Extract the importer']);
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<string>
     */
    private static function descriptions(array $tasks): array
    {
        return array_map(static fn (Task $task): string => $task->description(), $tasks);
    }

    private function read(string $markdown, ?VerifiedTasks $verified = null): TaskList
    {
        return TaskList::fromChecklist($this->parse($markdown), $verified);
    }

    private function parse(string $markdown): Checklist
    {
        return $this->parser->parse($markdown);
    }
}
