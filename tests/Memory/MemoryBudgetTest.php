<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Memory\MemoryBudget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bounds an attempt's context and memory are loaded under.
 */
#[CoversClass(MemoryBudget::class)]
final class MemoryBudgetTest extends TestCase
{
    #[Test]
    public function the_defaults_are_the_declared_ones(): void
    {
        $budget = new MemoryBudget();

        self::assertSame(MemoryBudget::CONTEXT_CHARACTERS, $budget->contextCharacters());
        self::assertSame(MemoryBudget::NOTES, $budget->notes());
        self::assertSame(MemoryBudget::NOTE_CHARACTERS, $budget->noteCharacters());
        self::assertSame(MemoryBudget::TOTAL_NOTE_CHARACTERS, $budget->totalNoteCharacters());
    }

    #[Test]
    public function the_per_note_bound_is_never_larger_than_the_total(): void
    {
        // Not enforced, only intended: a per-note ceiling above the shared total
        // would be a ceiling that never applies.
        self::assertLessThanOrEqual(
            MemoryBudget::TOTAL_NOTE_CHARACTERS,
            MemoryBudget::NOTE_CHARACTERS,
        );
    }

    #[Test]
    public function the_remaining_total_can_be_narrowed_without_touching_the_other_bounds(): void
    {
        $budget = (new MemoryBudget(5000, 4, 1000, 3000))->withTotalNoteCharacters(750);

        self::assertSame(750, $budget->totalNoteCharacters());
        self::assertSame(5000, $budget->contextCharacters());
        self::assertSame(4, $budget->notes());
        self::assertSame(1000, $budget->noteCharacters());
    }

    #[Test]
    public function narrowing_leaves_the_original_budget_alone(): void
    {
        $budget = new MemoryBudget(totalNoteCharacters: 3000);

        $budget->withTotalNoteCharacters(10);

        self::assertSame(3000, $budget->totalNoteCharacters());
    }

    /**
     * @return iterable<string, array{array<int, int>, string}>
     */
    public static function nonPositiveBounds(): iterable
    {
        yield 'no context' => [[0, 4, 1000, 3000], 'context characters'];
        yield 'no notes' => [[5000, 0, 1000, 3000], 'note count'];
        yield 'no note characters' => [[5000, 4, 0, 3000], 'note characters'];
        yield 'negative total' => [[5000, 4, 1000, -1], 'total note characters'];
    }

    /**
     * @param array<int, int> $bounds
     */
    #[Test]
    #[DataProvider('nonPositiveBounds')]
    public function a_bound_that_could_never_be_spent_is_refused(array $bounds, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($named);

        new MemoryBudget(...$bounds);
    }

    #[Test]
    public function narrowing_to_nothing_is_refused_rather_than_stored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MemoryBudget())->withTotalNoteCharacters(0);
    }

    #[Test]
    public function the_floor_is_the_minimum_unless_the_whole_budget_is_smaller(): void
    {
        self::assertSame(MemoryBudget::MINIMUM_NOTE_CHARACTERS, (new MemoryBudget())->noteFloor());
        self::assertSame(50, (new MemoryBudget(totalNoteCharacters: 50))->noteFloor());
    }

    #[Test]
    public function the_floor_follows_the_total_it_is_asked_about(): void
    {
        // Which is why a nearly spent allowance is measured against the floor of
        // the budget as configured, before it is narrowed: otherwise fifty
        // characters left out of twelve thousand would look like the whole of a
        // fifty-character budget and buy one more note.
        $budget = new MemoryBudget(totalNoteCharacters: 12000);

        self::assertSame(200, $budget->noteFloor());
        self::assertSame(50, $budget->withTotalNoteCharacters(50)->noteFloor());
    }

    #[Test]
    public function the_bounds_can_be_reported_as_data(): void
    {
        self::assertSame([
            'context_characters' => 5000,
            'notes' => 4,
            'note_characters' => 1000,
            'total_note_characters' => 3000,
        ], (new MemoryBudget(5000, 4, 1000, 3000))->toArray());
    }
}
