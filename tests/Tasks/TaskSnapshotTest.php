<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a task's content hash covers, and what it must keep ignoring.
 *
 * The hash is written into the state store and read back by a later run to
 * decide whether the work asked for has changed, so the two things these tests
 * protect are its recipe — pinned to literal values, because a change to it
 * makes every stored task look edited — and the line between an edit to the
 * work and an edit to how the work is written down.
 */
#[CoversClass(TaskSnapshot::class)]
final class TaskSnapshotTest extends TestCase
{
    /**
     * The derivation, pinned: SHA-256 of the normalised description, a newline,
     * and the normalised details.
     */
    #[Test]
    #[DataProvider('pinnedHashes')]
    public function the_content_hash_is_pinned(string $description, string $details, string $expected): void
    {
        self::assertSame($expected, TaskSnapshot::hash($description, $details));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pinnedHashes(): iterable
    {
        yield 'no details' => [
            'Add a --dry-run option',
            '',
            hash('sha256', "add a --dry-run option\n"),
        ];

        yield 'with details' => [
            'Add a --dry-run option',
            '- It must not write to the database.',
            hash('sha256', "add a --dry-run option\n- it must not write to the database."),
        ];
    }

    #[Test]
    public function the_details_are_part_of_the_hash_but_not_of_the_identity(): void
    {
        $plain = self::task('Add a --dry-run option');
        $annotated = self::task('Add a --dry-run option', ['  - It must not write to the database.']);

        self::assertSame($plain->id()->value(), $annotated->id()->value());
        self::assertNotSame(
            TaskSnapshot::of($plain)->contentHash(),
            TaskSnapshot::of($annotated)->contentHash(),
        );
    }

    #[Test]
    public function spacing_case_and_indentation_are_not_changes_to_the_work(): void
    {
        $snapshot = TaskSnapshot::of(
            self::task('Add a --dry-run option', ['  - It must not write to the database.']),
        );

        self::assertTrue($snapshot->statesSameWork(
            self::task("Add  a\t--DRY-RUN option", ['      -   It must not write to the database.']),
        ));
    }

    #[Test]
    public function an_added_requirement_is_a_change_to_the_work(): void
    {
        $snapshot = TaskSnapshot::of(
            self::task('Add a --dry-run option', ['- It must not write to the database.']),
        );

        self::assertFalse($snapshot->statesSameWork(self::task(
            'Add a --dry-run option',
            ['- It must not write to the database.', '- It must print the statements.'],
        )));
    }

    #[Test]
    public function the_marker_is_outside_the_work_but_inside_a_full_match(): void
    {
        $open = self::task('Add a --dry-run option');
        $snapshot = TaskSnapshot::of($open);
        $ticked = self::task('Add a --dry-run option', [], checked: true);

        self::assertTrue($snapshot->statesSameWork($ticked));
        self::assertFalse($snapshot->matches($ticked));
        self::assertTrue($snapshot->matches($open));
        self::assertFalse($snapshot->isChecked());
    }

    #[Test]
    public function the_line_is_recorded_but_not_compared(): void
    {
        $snapshot = TaskSnapshot::of(self::task('Add a --dry-run option', [], line: 4));

        self::assertSame(4, $snapshot->line());
        self::assertTrue($snapshot->matches(self::task('Add a --dry-run option', [], line: 19)));
    }

    #[Test]
    public function a_different_task_never_matches(): void
    {
        $snapshot = TaskSnapshot::of(self::task('Add a --dry-run option'));

        self::assertFalse($snapshot->statesSameWork(self::task('Add a --dry-run option to the importer')));
    }

    #[Test]
    public function the_snapshot_keeps_the_text_as_the_user_wrote_it(): void
    {
        $snapshot = TaskSnapshot::of(
            self::task('Add a --DRY-RUN option', ['  - It must not write to the database.']),
        );

        self::assertSame('Add a --DRY-RUN option', $snapshot->description());
        self::assertSame('- It must not write to the database.', $snapshot->details());
    }

    /**
     * @param list<string> $details
     */
    private static function task(
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
