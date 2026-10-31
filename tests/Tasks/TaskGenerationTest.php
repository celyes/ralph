<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\RecordedGeneration;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a pass at a task is named, and what a recorded pass remembers.
 *
 * The slug goes into Git branch names and into a SQLite database that outlive
 * the process, so it is pinned to literal values here: a change to its spelling
 * would leave the branch of every reopened task owned by nobody.
 */
#[CoversClass(TaskGeneration::class)]
#[CoversClass(RecordedGeneration::class)]
final class TaskGenerationTest extends TestCase
{
    /**
     * The spelling, pinned: the bare identifier for the first pass, `-gN` after
     * that.
     */
    #[Test]
    #[DataProvider('pinnedSlugs')]
    public function the_slug_is_pinned(int $number, string $expected): void
    {
        self::assertSame(
            $expected,
            TaskGeneration::of(self::id(), $number)->slug(),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function pinnedSlugs(): iterable
    {
        yield 'first' => [1, 'TASK-19E0214A'];
        yield 'second' => [2, 'TASK-19E0214A-g2'];
        yield 'tenth' => [10, 'TASK-19E0214A-g10'];
    }

    #[Test]
    #[DataProvider('pinnedSlugs')]
    public function a_slug_reads_back_as_the_generation_that_wrote_it(int $number, string $slug): void
    {
        $generation = TaskGeneration::fromSlug($slug);

        self::assertTrue(TaskGeneration::isSlug($slug));
        self::assertSame($number, $generation->number());
        self::assertSame(self::id()->value(), $generation->task()->value());
        self::assertSame($slug, $generation->slug());
    }

    /**
     * A first generation's slug is exactly the identifier, so a project where
     * nothing has been reopened reads as though generations did not exist.
     */
    #[Test]
    public function the_first_generation_is_named_by_the_identifier_alone(): void
    {
        $first = TaskGeneration::first(self::id());

        self::assertSame('TASK-19E0214A', $first->slug());
        self::assertSame('TASK-19E0214A', $first->label());
        self::assertTrue($first->isFirst());
        self::assertFalse($first->isReopening());
    }

    #[Test]
    public function a_later_generation_says_which_pass_it_is(): void
    {
        $second = TaskGeneration::first(self::id())->next();

        self::assertSame(2, $second->number());
        self::assertSame('TASK-19E0214A (generation 2)', $second->label());
        self::assertFalse($second->isFirst());
        self::assertTrue($second->isReopening());
    }

    /**
     * Two passes at one task must never name one branch: that is the whole
     * point of the suffix.
     */
    #[Test]
    public function generations_of_one_task_are_named_apart(): void
    {
        $first = TaskGeneration::first(self::id());
        $second = $first->next();

        self::assertNotSame($first->slug(), $second->slug());
        self::assertFalse($first->equals($second));
        self::assertTrue($second->belongsTo(self::id()));
        self::assertTrue($first->equals(TaskGeneration::of(self::id(), 1)));
    }

    /**
     * The number alone is not the identity: generation 2 of one task and
     * generation 2 of another are different passes with different branches.
     */
    #[Test]
    public function generations_of_different_tasks_are_never_the_same_pass(): void
    {
        $here = TaskGeneration::of(self::id(), 2);
        $elsewhere = TaskGeneration::of(TaskId::fromString('TASK-ABCDEF12'), 2);

        self::assertFalse($here->equals($elsewhere));
        self::assertFalse($elsewhere->belongsTo(self::id()));
        self::assertNotSame($here->slug(), $elsewhere->slug());
    }

    /**
     * Advancing is not mutation: the record of the pass that just ended cannot
     * change under a caller still reporting on it.
     */
    #[Test]
    public function advancing_leaves_the_earlier_generation_alone(): void
    {
        $first = TaskGeneration::first(self::id());
        $first->next();

        self::assertSame(1, $first->number());
    }

    #[Test]
    #[DataProvider('notSlugs')]
    public function text_that_is_not_a_slug_is_refused(string $text): void
    {
        self::assertFalse(TaskGeneration::isSlug($text));

        $this->expectException(InvalidArgumentException::class);

        TaskGeneration::fromSlug($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notSlugs(): iterable
    {
        yield 'a branch name' => ['ralph/TASK-19E0214A-add-a-flag'];
        yield 'generation zero' => ['TASK-19E0214A-g0'];
        yield 'a leading zero' => ['TASK-19E0214A-g02'];
        yield 'no generation number' => ['TASK-19E0214A-g'];
        yield 'the wrong separator' => ['TASK-19E0214A-2'];
        yield 'not an identifier' => ['TASK-ZZZZZZZZ-g2'];
        yield 'empty' => [''];
    }

    /**
     * Typed and stored values are forgiven their case and spacing, as
     * identifiers are, because this is what a user pastes out of a status table.
     */
    #[Test]
    public function a_slug_is_read_forgivingly(): void
    {
        self::assertSame(
            'TASK-19E0214A-g3',
            TaskGeneration::fromSlug('  task-19e0214a-G3 ')->slug(),
        );
    }

    #[Test]
    public function a_generation_below_the_first_is_not_a_pass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a generation');

        TaskGeneration::of(self::id(), 0);
    }

    #[Test]
    public function a_completed_pass_remembers_what_it_left_behind(): void
    {
        $pass = RecordedGeneration::completed(
            TaskGeneration::first(self::id()),
            'ralph/TASK-19E0214A-add-a-flag',
            'a1b2c3d',
            TaskSnapshot::hash('Add a --dry-run option'),
        );

        self::assertTrue($pass->isComplete());
        self::assertFalse($pass->isOpen());
        self::assertSame(1, $pass->number());
        self::assertSame('a1b2c3d', $pass->commit());
        self::assertTrue($pass->ownsBranch('ralph/TASK-19E0214A-add-a-flag'));
        self::assertFalse($pass->ownsBranch('ralph/task-19e0214a-add-a-flag'));
        self::assertSame(self::id()->value(), $pass->task()->value());
    }

    /**
     * A task that was already satisfied completes without a commit
     * (PROJECT.md section G, item 6), so a null commit cannot be read as "did
     * not finish".
     */
    #[Test]
    public function a_pass_can_complete_without_a_commit(): void
    {
        $pass = RecordedGeneration::completed(TaskGeneration::first(self::id()));

        self::assertTrue($pass->isComplete());
        self::assertNull($pass->commit());
    }

    #[Test]
    public function an_open_pass_has_no_commit_and_may_still_own_a_branch(): void
    {
        $pass = RecordedGeneration::open(
            TaskGeneration::first(self::id()),
            'ralph/TASK-19E0214A-add-a-flag',
        );

        self::assertTrue($pass->isOpen());
        self::assertFalse($pass->isComplete());
        self::assertNull($pass->commit());
        self::assertSame('ralph/TASK-19E0214A-add-a-flag', $pass->branch());
    }

    #[Test]
    #[DataProvider('blankReferences')]
    public function a_reference_that_names_nothing_is_refused(string $blank): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecordedGeneration::completed(TaskGeneration::first(self::id()), $blank);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    /**
     * The details under a task can be rewritten between passes, and a user
     * reopening work they finished a while ago is worth telling.
     */
    #[Test]
    public function a_pass_says_whether_the_work_is_still_worded_the_same(): void
    {
        $before = TaskSnapshot::of(self::task(
            'Add a --dry-run option',
            ['  - It must not write to the database.'],
        ));
        $after = TaskSnapshot::of(self::task(
            'Add a --dry-run option',
            ['  - It must not write to the database or the logs.'],
        ));

        $pass = RecordedGeneration::completed(
            TaskGeneration::first($before->id()),
            null,
            null,
            $before->contentHash(),
        );

        self::assertTrue($pass->statesSameWork($before));
        self::assertFalse($pass->statesSameWork($after));
    }

    /**
     * Nothing recorded is reported as "not the same", so the message
     * under-claims rather than over-claims.
     */
    #[Test]
    public function a_pass_with_no_recorded_hash_does_not_claim_the_work_is_unchanged(): void
    {
        $snapshot = TaskSnapshot::of(self::task('Add a --dry-run option'));
        $pass = RecordedGeneration::completed(TaskGeneration::first($snapshot->id()));

        self::assertNull($pass->contentHash());
        self::assertFalse($pass->statesSameWork($snapshot));
    }

    private static function id(): TaskId
    {
        return TaskId::fromString('TASK-19E0214A');
    }

    /**
     * @param list<string> $details
     */
    private static function task(string $description, array $details = []): Task
    {
        return Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            $details,
        ));
    }
}
