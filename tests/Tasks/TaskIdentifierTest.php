<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Tasks\Checklist;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Identifying a whole checklist, and refusing one whose tasks cannot be told
 * apart.
 *
 * Written against documents a user plausibly writes — a checklist that has been
 * reordered since the last run, one where a finished task was ticked, one where
 * the same task was pasted twice, one where two items differ only in their
 * notes — because that is where duplicate descriptions actually come from.
 */
#[CoversClass(TaskIdentifier::class)]
#[CoversClass(Task::class)]
#[CoversClass(Checklist::class)]
final class TaskIdentifierTest extends TestCase
{
    private ChecklistParser $parser;

    private TaskIdentifier $identifier;

    protected function setUp(): void
    {
        $this->parser = new ChecklistParser();
        $this->identifier = new TaskIdentifier();
    }

    #[Test]
    public function every_item_is_identified_in_file_order(): void
    {
        $tasks = $this->identify(<<<'MARKDOWN'
            # Tasks

            - [x] Extract the importer
            - [ ] Add a --dry-run option
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(
            [
                ['TASK-0E1BEF5A', 'Extract the importer', true, 3],
                ['TASK-19E0214A', 'Add a --dry-run option', false, 4],
                ['TASK-7EBB50C9', 'Document the exit codes', false, 5],
            ],
            array_map(
                static fn (Task $task): array => [
                    $task->id()->value(),
                    $task->description(),
                    $task->isChecked(),
                    $task->line(),
                ],
                $tasks,
            ),
        );
    }

    #[Test]
    public function a_checklist_with_no_items_identifies_nothing(): void
    {
        self::assertSame([], $this->identify("# Tasks\n\nNothing yet.\n"));
    }

    /**
     * The checklist `ralph init` writes explains identity and duplicates in its
     * own prose, and that prose must not itself identify as a task.
     */
    #[Test]
    public function the_generated_checklist_identifies_no_tasks(): void
    {
        self::assertSame([], $this->identify(Templates::tasks(), '/project/.ralph/tasks.md'));
    }

    /**
     * The reason identity exists: between two runs the user ticks a task,
     * reorders the rest, renames the file and indents a note under one of them.
     * Every identifier has to come back unchanged, or the state store loses the
     * attempts and the branch belonging to work that is still pending.
     */
    #[Test]
    public function identifiers_survive_reordering_ticking_and_editing_the_notes(): void
    {
        $before = $this->identify(<<<'MARKDOWN'
            - [ ] Extract the importer
            - [ ] Add a --dry-run option
            - [ ] Document the exit codes
            MARKDOWN, '/project/.ralph/tasks.md');

        $after = $this->identify(<<<'MARKDOWN'
            ## Still to do

            - [ ] Document the exit codes
              - Mention the blocked status.
            - [x] Add a --dry-run option

            ## Done earlier

            - [x] Extract the importer
            MARKDOWN, '/project/docs/work.md');

        self::assertSame(
            ['TASK-0E1BEF5A', 'TASK-19E0214A', 'TASK-7EBB50C9'],
            self::sortedIds($before),
        );
        self::assertSame(self::sortedIds($before), self::sortedIds($after));
    }

    #[Test]
    public function the_same_task_written_twice_is_refused_with_both_lines(): void
    {
        try {
            $this->identify(<<<'MARKDOWN'
                - [ ] Add a --dry-run option
                - [ ] Document the exit codes
                - [ ] Add a --dry-run option
                MARKDOWN, '/project/.ralph/tasks.md');

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertSame(
                'The task checklist /project/.ralph/tasks.md states the same task on lines 1 and 3: "Add a --dry-run option".',
                $exception->getMessage(),
            );
            self::assertSame(
                [
                    'A task is identified by its description, so two items that read the same are the same task.',
                    'Reword one of them to say what is different about it, or delete the repeated line.',
                ],
                $exception->hints(),
            );
        }
    }

    #[Test]
    #[DataProvider('duplicatesThatLookDifferent')]
    public function two_items_that_differ_only_in_spacing_or_case_are_refused(string $repeat): void
    {
        try {
            $this->identify("- [ ] Add a --dry-run option\n- [ ] " . $repeat . "\n");

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('states the same task on lines 1 and 2', $exception->getMessage());
            self::assertContains(
                sprintf(
                    'The two differ only in capitalisation or spacing, which task identity ignores: "%s" and "%s".',
                    'Add a --dry-run option',
                    trim($repeat),
                ),
                $exception->hints(),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicatesThatLookDifferent(): iterable
    {
        yield 'lowercased' => ['add a --dry-run option'];
        yield 'shouted' => ['ADD A --DRY-RUN OPTION'];
        yield 'doubled spaces' => ['Add  a  --dry-run  option'];
    }

    /**
     * The hint about capitalisation is only true when the two lines look
     * different; adding it to a plainly repeated line would be nonsense.
     */
    #[Test]
    public function an_exact_repeat_is_not_blamed_on_capitalisation(): void
    {
        try {
            $this->identify("- [ ] Add a --dry-run option\n- [ ] Add a --dry-run option\n");

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            foreach ($exception->hints() as $hint) {
                self::assertStringNotContainsString('capitalisation', $hint);
            }
        }
    }

    #[Test]
    public function a_task_repeated_with_different_notes_is_still_one_task(): void
    {
        try {
            $this->identify(<<<'MARKDOWN'
                - [ ] Add a --dry-run option
                  - Print what would be written.
                - [ ] Add a --dry-run option
                  - Cover it with a test.
                MARKDOWN);

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('states the same task on lines 1 and 3', $exception->getMessage());
            self::assertContains(
                'Indented details are not part of a task\'s identity, so two items that differ only in what is written under them still read as one task.',
                $exception->hints(),
            );
        }
    }

    /**
     * A task that is already ticked and then written again as pending is the
     * commonest way this happens: the user forgot it was done. Reopening work
     * is supported, but by unticking the item rather than by listing it twice
     * (PROJECT.md section C, item 8), so the refusal stands.
     */
    #[Test]
    public function a_checked_item_and_a_pending_copy_of_it_are_refused(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('states the same task on lines 1 and 2');

        $this->identify("- [x] Add a --dry-run option\n- [ ] Add a --dry-run option\n");
    }

    #[Test]
    public function a_long_repeated_description_is_quoted_short(): void
    {
        $description = 'Rewrite the importer so that it streams rows instead of loading the whole file into memory';

        try {
            $this->identify(sprintf("- [ ] %s\n- [ ] %s\n", $description, $description));

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString(
                '"Rewrite the importer so that it streams rows instead of loa…"',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function a_checklist_parsed_from_a_string_is_named_without_a_path(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('The task checklist states the same task on lines 1 and 2');

        $this->identify("- [ ] Add a --dry-run option\n- [ ] Add a --dry-run option\n");
    }

    /**
     * Eight hexadecimal digits means two unrelated tasks can collide, and this
     * pair really does: both descriptions hash to TASK-0ADF80F7. The checklist
     * is refused rather than quietly running two tasks under one identifier,
     * one branch and one row of state.
     */
    #[Test]
    public function two_different_tasks_with_one_generated_identifier_are_refused(): void
    {
        self::assertSame(
            TaskId::fromDescription('collision probe 14565')->value(),
            TaskId::fromDescription('collision probe 24048')->value(),
        );

        try {
            $this->identify(
                "- [ ] collision probe 14565\n- [ ] collision probe 24048\n",
                '/project/.ralph/tasks.md',
            );

            self::fail('Expected the colliding tasks to be refused.');
        } catch (ChecklistException $exception) {
            self::assertSame(
                'The task checklist /project/.ralph/tasks.md has two different tasks with the same generated identifier '
                . 'TASK-0ADF80F7, on lines 1 and 2: "collision probe 14565" and "collision probe 24048".',
                $exception->getMessage(),
            );
            self::assertSame(
                [
                    'Reword either description. The identifier is derived from the description, so different wording produces a different identifier.',
                    'This is a hash collision between two unrelated tasks, which is rare enough to be worth reporting at https://github.com/celyes/ralph-php/issues.',
                ],
                $exception->hints(),
            );
        }
    }

    #[Test]
    public function identifying_a_checklist_is_refused_at_the_first_duplicate(): void
    {
        try {
            $this->identify(<<<'MARKDOWN'
                - [ ] Extract the importer
                - [ ] Add a --dry-run option
                - [ ] Add a --dry-run option
                - [ ] Extract the importer
                MARKDOWN);

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('lines 2 and 3', $exception->getMessage());
        }
    }

    /**
     * A duplicate is a configuration problem, reported like every other
     * unreadable checklist: nothing has run, and the fix is in the file.
     */
    #[Test]
    public function a_duplicate_is_reported_as_a_configuration_failure(): void
    {
        try {
            $this->identify("- [ ] Add a flag\n- [ ] Add a flag\n");

            self::fail('Expected the duplicated task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertSame(78, $exception->exitCode());
        }
    }

    /**
     * @return list<Task>
     */
    private function identify(string $markdown, ?string $source = null): array
    {
        return $this->identifier->identify($this->parser->parse($markdown, $source));
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<string>
     */
    private static function sortedIds(array $tasks): array
    {
        $ids = array_map(static fn (Task $task): string => $task->id()->value(), $tasks);
        sort($ids);

        return $ids;
    }
}
