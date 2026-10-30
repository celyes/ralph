<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskBody;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Task identity: what makes two items the same task, and what does not.
 *
 * Identifiers end up in a SQLite database, in Git branch names and in log
 * paths that outlive the process, so these tests are as much about what must
 * never change as about what the code does today: the derivation is pinned to
 * literal values, and each thing identity is supposed to ignore — the checkbox,
 * the line, the file, the details, spacing, letter case — is asserted on its
 * own.
 */
#[CoversClass(TaskId::class)]
#[CoversClass(TaskBody::class)]
#[CoversClass(Task::class)]
final class TaskIdTest extends TestCase
{
    /**
     * The derivation, pinned.
     *
     * These values are `TASK-` plus the first eight hexadecimal digits of the
     * SHA-256 of the normalised description. If a change to normalisation or to
     * the hash makes this test fail, every task already stored by a user has
     * just lost its history — which is the point of asserting them literally.
     */
    #[Test]
    #[DataProvider('pinnedIdentifiers')]
    public function the_identifier_of_a_description_is_pinned(string $description, string $expected): void
    {
        self::assertSame($expected, TaskId::fromDescription($description)->value());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pinnedIdentifiers(): iterable
    {
        yield 'a flag' => ['Add a --dry-run option', 'TASK-19E0214A'];
        yield 'a refactor' => ['Extract the importer', 'TASK-0E1BEF5A'];
        yield 'documentation' => ['Document the exit codes', 'TASK-7EBB50C9'];
    }

    #[Test]
    #[DataProvider('descriptions')]
    public function an_identifier_is_the_prefix_and_eight_hexadecimal_digits(string $description): void
    {
        self::assertMatchesRegularExpression(
            '/^TASK-[0-9A-F]{8}$/',
            TaskId::fromDescription($description)->value(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function descriptions(): iterable
    {
        yield 'plain' => ['Add a --dry-run option'];
        yield 'markdown' => ['Rename `Importer` to **CsvImporter**'];
        yield 'punctuation only' => ['???'];
        yield 'accented' => ['Gérer les accents dans les descriptions'];
        yield 'non-latin' => ['إضافة خيار للتجربة الجافة'];
        yield 'emoji' => ['Ship it 🚀'];
        yield 'a paragraph' => [str_repeat('Do the thing thoroughly. ', 20)];
    }

    #[Test]
    public function the_checkbox_does_not_change_the_identifier(): void
    {
        $pending = new ChecklistItem('Add a --dry-run option', false, 3, '- [ ] Add a --dry-run option');
        $checked = new ChecklistItem('Add a --dry-run option', true, 3, '- [x] Add a --dry-run option');

        self::assertSame(
            TaskId::fromBody(TaskBody::ofItem($pending))->value(),
            TaskId::fromBody(TaskBody::ofItem($checked))->value(),
        );
    }

    #[Test]
    public function the_position_in_the_file_does_not_change_the_identifier(): void
    {
        $first = new ChecklistItem('Add a --dry-run option', false, 1, '- [ ] Add a --dry-run option');
        $later = new ChecklistItem('Add a --dry-run option', false, 94, '* [ ] Add a --dry-run option');

        self::assertTrue(Task::fromItem($first)->id()->equals(Task::fromItem($later)->id()));
    }

    #[Test]
    public function the_details_written_under_a_task_do_not_change_its_identifier(): void
    {
        $bare = new ChecklistItem('Add a --dry-run option', false, 1, '- [ ] Add a --dry-run option');
        $documented = new ChecklistItem(
            'Add a --dry-run option',
            false,
            1,
            '- [ ] Add a --dry-run option',
            ['  - It must not write anything.', '  - Cover it with a test.'],
        );

        self::assertTrue(Task::fromItem($bare)->id()->equals(Task::fromItem($documented)->id()));
    }

    #[Test]
    #[DataProvider('sameTask')]
    public function descriptions_that_differ_only_in_spacing_or_case_are_one_task(string $variant): void
    {
        self::assertSame(
            TaskId::fromDescription('Add a --dry-run option')->value(),
            TaskId::fromDescription($variant)->value(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sameTask(): iterable
    {
        yield 'identical' => ['Add a --dry-run option'];
        yield 'lowercased' => ['add a --dry-run option'];
        yield 'shouted' => ['ADD A --DRY-RUN OPTION'];
        yield 'title cased' => ['Add A --Dry-Run Option'];
        yield 'surrounding spaces' => ['   Add a --dry-run option   '];
        yield 'doubled spaces' => ['Add  a  --dry-run  option'];
        yield 'tabs' => ["Add\ta\t--dry-run\toption"];
        yield 'a trailing hard break' => ['Add a --dry-run option  '];
        yield 'non-breaking spaces' => ["Add\u{00A0}a --dry-run option"];
        yield 'an ideographic space' => ["Add\u{3000}a --dry-run option"];
        yield 'a zero-width space' => ["Add a --dry-run\u{200B} option"];
        yield 'a stray byte-order mark' => ["Add a --dry-run option\u{FEFF}"];
    }

    #[Test]
    #[DataProvider('differentTask')]
    public function descriptions_that_say_something_different_are_different_tasks(string $other): void
    {
        self::assertNotSame(
            TaskId::fromDescription('Add a --dry-run option')->value(),
            TaskId::fromDescription($other)->value(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function differentTask(): iterable
    {
        yield 'another task' => ['Add a --verbose option'];
        yield 'a trailing full stop' => ['Add a --dry-run option.'];
        yield 'emphasis' => ['Add a **--dry-run** option'];
        yield 'backticks' => ['Add a `--dry-run` option'];
        yield 'a missing word' => ['Add a --dry-run'];
        yield 'reordered words' => ['Add an option, --dry-run'];
        yield 'a different flag' => ['Add a --dry_run option'];
    }

    #[Test]
    public function case_is_folded_in_non_latin_scripts_too(): void
    {
        self::assertSame(
            TaskId::fromDescription('Gérer les accents')->value(),
            TaskId::fromDescription('GÉRER LES ACCENTS')->value(),
        );
    }

    /**
     * Accents are part of the text, not noise.
     *
     * This package does not require ext-intl, so it cannot apply Unicode
     * normalisation everywhere it runs; rather than fold accents where the
     * extension happens to exist and produce different identifiers on another
     * machine, descriptions are compared as written.
     */
    #[Test]
    public function an_accent_is_part_of_the_description(): void
    {
        self::assertNotSame(
            TaskId::fromDescription('Gérer les accents')->value(),
            TaskId::fromDescription('Gerer les accents')->value(),
        );
    }

    #[Test]
    public function a_body_keeps_the_description_as_written_alongside_the_normalised_text(): void
    {
        $body = TaskBody::of('  Add  a --DRY-RUN  option  ');

        self::assertSame('  Add  a --DRY-RUN  option  ', $body->original());
        self::assertSame('add a --dry-run option', $body->normalized());
        self::assertFalse($body->isEmpty());
    }

    #[Test]
    #[DataProvider('emptyDescriptions')]
    public function a_description_that_says_nothing_has_no_identity(string $description): void
    {
        $body = TaskBody::of($description);

        self::assertTrue($body->isEmpty());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A task with no description has no identifier.');

        TaskId::fromBody($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyDescriptions(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a tab' => ["\t"];
        yield 'a non-breaking space' => ["\u{00A0}"];
        yield 'a zero-width space' => ["\u{200B}"];
    }

    #[Test]
    public function a_description_that_is_not_utf8_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A task description must be valid UTF-8.');

        TaskBody::of("Add a \xC3 option");
    }

    #[Test]
    public function two_bodies_state_the_same_task_or_do_not(): void
    {
        self::assertTrue(TaskBody::of('Add a flag')->equals(TaskBody::of('add  a  flag')));
        self::assertFalse(TaskBody::of('Add a flag')->equals(TaskBody::of('Add a flag.')));
    }

    #[Test]
    public function an_identifier_can_be_read_back_from_its_own_text(): void
    {
        $id = TaskId::fromDescription('Add a --dry-run option');

        self::assertTrue(TaskId::fromString($id->value())->equals($id));
        self::assertSame($id->value(), (string) TaskId::fromString($id->value()));
    }

    #[Test]
    #[DataProvider('typedIdentifiers')]
    public function reading_an_identifier_forgives_case_and_surrounding_space(string $typed): void
    {
        self::assertTrue(TaskId::isValid($typed));
        self::assertSame('TASK-19E0214A', TaskId::fromString($typed)->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typedIdentifiers(): iterable
    {
        yield 'canonical' => ['TASK-19E0214A'];
        yield 'lowercased' => ['task-19e0214a'];
        yield 'mixed case' => ['Task-19e0214A'];
        yield 'padded' => ["  TASK-19E0214A\n"];
    }

    #[Test]
    #[DataProvider('notIdentifiers')]
    public function text_that_is_not_an_identifier_is_refused(string $value): void
    {
        self::assertFalse(TaskId::isValid($value));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a task identifier', $value));

        TaskId::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'a description' => ['Add a --dry-run option'];
        yield 'no prefix' => ['19E0214A'];
        yield 'the wrong prefix' => ['TASKS-19E0214A'];
        yield 'no separator' => ['TASK19E0214A'];
        yield 'too short' => ['TASK-19E0214'];
        yield 'too long' => ['TASK-19E0214A0'];
        yield 'not hexadecimal' => ['TASK-19E0214Z'];
        yield 'an inner space' => ['TASK- 9E0214A'];
        yield 'a full commit sha' => ['6cc6fc8a1b2c3d4e5f60718293a4b5c6d7e8f901'];
    }

    #[Test]
    public function a_task_reports_the_item_it_was_built_from(): void
    {
        $item = new ChecklistItem(
            'Add a --dry-run option',
            true,
            7,
            '- [x] Add a --dry-run option',
            ['  - It must not write anything.'],
        );

        $task = Task::fromItem($item);

        self::assertSame($item, $task->item());
        self::assertSame('Add a --dry-run option', $task->description());
        self::assertSame('- It must not write anything.', $task->details());
        self::assertTrue($task->isChecked());
        self::assertSame(7, $task->line());
        self::assertSame('add a --dry-run option', $task->body()->normalized());
        self::assertSame('TASK-19E0214A', $task->id()->value());
    }
}
