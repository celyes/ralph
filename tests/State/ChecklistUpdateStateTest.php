<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\ChecklistMarker;
use Celyes\RalphPhp\State\ChecklistUpdateState;
use Celyes\RalphPhp\State\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two words a planned checklist write is stored with.
 *
 * Small enums, and what is worth pinning about them is the spellings and the
 * partition. The spellings are written into `checklist_updates`, whose `CHECK`
 * constraints {@see Schema} builds from exactly these cases, so a renamed case
 * would otherwise be discovered as a refused insert in the middle of finishing
 * a verified task. The partition is what a recovery path reads: a row is either
 * still owed or settled, never both and never neither.
 */
#[CoversClass(ChecklistUpdateState::class)]
#[CoversClass(ChecklistMarker::class)]
final class ChecklistUpdateStateTest extends TestCase
{
    #[Test]
    public function the_stored_spellings_are_pinned(): void
    {
        self::assertSame(
            ['pending', 'applied', 'failed', 'superseded'],
            ChecklistUpdateState::values(),
        );
        self::assertSame(['checked', 'unchecked'], ChecklistMarker::values());
    }

    /**
     * The database and the enum cannot drift: the column's vocabulary is built
     * from these cases.
     */
    #[Test]
    public function the_schema_constrains_the_columns_with_these_cases(): void
    {
        self::assertSame(ChecklistUpdateState::values(), Schema::checklistUpdateStates());
        self::assertSame(ChecklistMarker::values(), Schema::checklistMarkers());
    }

    #[Test]
    public function every_state_is_either_outstanding_or_settled(): void
    {
        foreach (ChecklistUpdateState::all() as $state) {
            self::assertNotSame(
                $state->isOutstanding(),
                $state->isSettled(),
                $state->value . ' must be exactly one of the two.',
            );
            self::assertNotSame('', $state->label());
            self::assertNotSame('', $state->describe());
            self::assertNotSame('', $state->nextAction());
        }
    }

    /**
     * Only `pending` is owed. The distinction `failed` carries is that a write
     * which cannot be applied as planned must not be retried as though it
     * could.
     */
    #[Test]
    public function only_a_pending_write_is_still_owed(): void
    {
        self::assertTrue(ChecklistUpdateState::Pending->isOutstanding());
        self::assertFalse(ChecklistUpdateState::Applied->isOutstanding());
        self::assertFalse(ChecklistUpdateState::Failed->isOutstanding());
        self::assertFalse(ChecklistUpdateState::Superseded->isOutstanding());

        self::assertTrue(ChecklistUpdateState::Applied->isApplied());
        self::assertTrue(ChecklistUpdateState::Failed->hasFailed());
        self::assertTrue(ChecklistUpdateState::Superseded->isSuperseded());
    }

    /**
     * Only a pending write has a next action that does anything, and it is the
     * one PROJECT.md section G, item 8 promises: the box, and not the work.
     */
    #[Test]
    public function the_pending_states_next_action_is_the_write_and_nothing_else(): void
    {
        $action = ChecklistUpdateState::Pending->nextAction();

        self::assertStringContainsString('`ralph resume`', $action);
        self::assertStringContainsString('only the box', $action);
        self::assertStringContainsString('not repeated', $action);
    }

    #[Test]
    public function a_stored_state_is_read_back_and_an_unknown_one_is_refused(): void
    {
        self::assertSame(
            ChecklistUpdateState::Applied,
            ChecklistUpdateState::fromStorage('applied'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"written" is not a checklist update state');

        ChecklistUpdateState::fromStorage('written');
    }

    #[Test]
    public function a_stored_marker_is_read_back_and_an_unknown_one_is_refused(): void
    {
        self::assertSame(ChecklistMarker::Checked, ChecklistMarker::fromStorage('checked'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"ticked" is not a checklist marker');

        ChecklistMarker::fromStorage('ticked');
    }

    #[Test]
    public function a_marker_comes_from_the_boolean_the_editor_takes(): void
    {
        self::assertSame(ChecklistMarker::Checked, ChecklistMarker::of(true));
        self::assertSame(ChecklistMarker::Unchecked, ChecklistMarker::of(false));

        self::assertTrue(ChecklistMarker::Checked->isChecked());
        self::assertFalse(ChecklistMarker::Unchecked->isChecked());

        self::assertSame('- [x]', ChecklistMarker::Checked->literal());
        self::assertSame('- [ ]', ChecklistMarker::Unchecked->literal());
        self::assertSame('tick the box', ChecklistMarker::Checked->describe());
        self::assertSame('open the box again', ChecklistMarker::Unchecked->describe());
    }
}
