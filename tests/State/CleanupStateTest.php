<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\CleanupState;
use Celyes\RalphPhp\State\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two words a workspace cleanup is stored with, and the null between them.
 *
 * A small enum, and what is worth pinning is the spellings, the partition, and
 * the reading of an unknown value. The spellings go into
 * `generations.cleanup_state`, whose `CHECK` constraint {@see Schema} builds
 * from exactly these cases. The partition is what a resumed cleanup reads: a
 * removal is either still owed or finished, never both. And an unknown value is
 * refused rather than guessed at, because both guesses are wrong in a way that
 * costs something — "finished" leaks a worktree nothing will ever remove, and
 * "started" licences a forced removal of a directory this build has never
 * touched.
 */
#[CoversClass(CleanupState::class)]
final class CleanupStateTest extends TestCase
{
    #[Test]
    public function the_stored_spellings_are_pinned(): void
    {
        self::assertSame(['removing', 'removed'], CleanupState::values());
        self::assertSame(CleanupState::values(), Schema::cleanupStates());
    }

    #[Test]
    public function a_cleanup_is_either_under_way_or_finished(): void
    {
        foreach (CleanupState::all() as $state) {
            self::assertNotSame(
                $state->isInProgress(),
                $state->isFinished(),
                $state->value . ' must be exactly one of the two.',
            );
            self::assertNotSame('', $state->label());
            self::assertNotSame('', $state->describe());
        }

        self::assertTrue(CleanupState::Removing->isInProgress());
        self::assertTrue(CleanupState::Removed->isFinished());
    }

    /**
     * No cleanup has been started, which is the state almost every row is in
     * and the reason the column is nullable rather than carrying a third case.
     */
    #[Test]
    public function an_empty_column_is_no_cleanup_at_all(): void
    {
        self::assertNull(CleanupState::recorded(null));
        self::assertNull(CleanupState::recorded(''));
        self::assertNull(CleanupState::recorded('   '));
    }

    #[Test]
    public function a_recorded_state_is_read_back(): void
    {
        self::assertSame(CleanupState::Removing, CleanupState::recorded('removing'));
        self::assertSame(CleanupState::Removed, CleanupState::recorded('removed'));
    }

    #[Test]
    public function a_state_this_build_does_not_know_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"halfway" is not a cleanup state');

        CleanupState::recorded('halfway');
    }
}
