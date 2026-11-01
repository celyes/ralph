<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\WorktreeOwnership;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which workspace a pass is using, and whether it is the pass's to remove.
 *
 * One question is behind all of this and cleanup has to get it right: a linked
 * worktree at `.ralph/runtime/worktrees/TASK-19E0214A` may have been created by
 * this generation, in which case removing it is tidying up, or by an earlier run
 * or by the user by hand, in which case removing it destroys work nobody asked
 * to lose. The path alone cannot say which, so ownership is recorded when the
 * worktree is prepared and never re-derived — a run that looked at the
 * directory later and guessed would guess wrong exactly when it mattered, after
 * an interruption, which is when the directory has something in it.
 */
#[CoversClass(WorktreeOwnership::class)]
final class WorktreeOwnershipTest extends TestCase
{
    /**
     * The starting value. Running in no workspace is a different fact from
     * running in the user's checkout.
     */
    #[Test]
    public function a_pass_that_has_not_been_prepared_has_no_workspace(): void
    {
        $worktree = WorktreeOwnership::none();

        self::assertNull($worktree->path());
        self::assertFalse($worktree->exists());
        self::assertFalse($worktree->isOwned());
    }

    #[Test]
    public function a_worktree_this_pass_created_is_its_to_remove(): void
    {
        $worktree = WorktreeOwnership::created('/repo/.ralph/runtime/worktrees/TASK-19E0214A');

        self::assertSame('/repo/.ralph/runtime/worktrees/TASK-19E0214A', $worktree->path());
        self::assertTrue($worktree->exists());
        self::assertTrue($worktree->isOwned());
        self::assertFalse($worktree->mustBePreserved());
    }

    /**
     * Covers both a worktree an earlier run left behind and a directory the
     * user prepared: either way it is not this pass's to delete.
     */
    #[Test]
    public function a_workspace_that_was_already_there_is_not_this_passs_to_delete(): void
    {
        $worktree = WorktreeOwnership::adopted('/repo/.ralph/runtime/worktrees/TASK-19E0214A');

        self::assertTrue($worktree->exists());
        self::assertFalse($worktree->isOwned());
        self::assertTrue($worktree->mustBePreserved());
    }

    /**
     * Asked in the safe direction, so the answer for "no workspace at all" is
     * the same as for someone else's: leave it alone.
     */
    #[Test]
    public function having_no_workspace_also_means_removing_nothing(): void
    {
        self::assertTrue(WorktreeOwnership::none()->mustBePreserved());
    }

    /**
     * Ownership is the only thing that distinguishes two records of the same
     * directory, which is precisely why it is stored rather than worked out.
     */
    #[Test]
    public function the_same_path_can_be_owned_or_not_and_the_record_is_what_says_which(): void
    {
        $path = '/repo/.ralph/runtime/worktrees/TASK-19E0214A';

        self::assertTrue(WorktreeOwnership::created($path)->isOwned());
        self::assertFalse(WorktreeOwnership::adopted($path)->isOwned());
    }

    #[Test]
    #[DataProvider('blankPaths')]
    public function a_created_worktree_must_name_a_directory(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a directory');

        WorktreeOwnership::created($path);
    }

    #[Test]
    #[DataProvider('blankPaths')]
    public function an_adopted_worktree_must_name_a_directory(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a directory');

        WorktreeOwnership::adopted($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a newline' => ["\n"];
    }

    #[Test]
    public function a_path_is_stored_without_surrounding_whitespace(): void
    {
        self::assertSame('/repo/work', WorktreeOwnership::created("  /repo/work\n")->path());
        self::assertSame('/repo/work', WorktreeOwnership::adopted(' /repo/work ')->path());
    }

    #[Test]
    #[DataProvider('ownerships')]
    public function a_record_survives_a_trip_through_its_two_columns(
        WorktreeOwnership $worktree,
    ): void {
        $columns = $worktree->toColumns();

        $read = WorktreeOwnership::fromStorage($columns['path'], $columns['owned'] === 1);

        self::assertSame($worktree->path(), $read->path());
        self::assertSame($worktree->isOwned(), $read->isOwned());
        self::assertSame($worktree->exists(), $read->exists());
    }

    /**
     * @return iterable<string, array{WorktreeOwnership}>
     */
    public static function ownerships(): iterable
    {
        yield 'none' => [WorktreeOwnership::none()];
        yield 'created' => [WorktreeOwnership::created('/repo/work/TASK-19E0214A')];
        yield 'adopted' => [WorktreeOwnership::adopted('/repo/work/TASK-19E0214A')];
    }

    /**
     * Stored as an integer, because SQLite has no boolean and the column is
     * constrained to 0 or 1.
     */
    #[Test]
    public function the_columns_a_row_holds_are_the_path_and_an_integer_flag(): void
    {
        self::assertSame(
            ['path' => '/repo/work', 'owned' => 1],
            WorktreeOwnership::created('/repo/work')->toColumns(),
        );
        self::assertSame(
            ['path' => '/repo/work', 'owned' => 0],
            WorktreeOwnership::adopted('/repo/work')->toColumns(),
        );
        self::assertSame(
            ['path' => null, 'owned' => 0],
            WorktreeOwnership::none()->toColumns(),
        );
    }

    #[Test]
    #[DataProvider('emptyStoredPaths')]
    public function a_stored_row_with_no_path_and_no_claim_is_no_workspace(?string $path): void
    {
        $read = WorktreeOwnership::fromStorage($path, false);

        self::assertFalse($read->exists());
        self::assertNull($read->path());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptyStoredPaths(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    /**
     * Half of a pair that is only meaningful whole. Resolving it quietly in the
     * direction of "do not remove" would hide the bug while leaking a worktree,
     * so it is refused.
     */
    #[Test]
    #[DataProvider('emptyStoredPaths')]
    public function a_stored_row_claiming_ownership_without_a_path_is_refused(?string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claims ownership without naming a path');

        WorktreeOwnership::fromStorage($path, true);
    }

    /**
     * Compared exactly: resolving paths needs a filesystem and this is a
     * record, so a near-match is a different directory.
     */
    #[Test]
    public function a_workspace_is_the_named_one_only_on_an_exact_match(): void
    {
        $worktree = WorktreeOwnership::created('/repo/.ralph/runtime/worktrees/TASK-19E0214A');

        self::assertTrue($worktree->isAt('/repo/.ralph/runtime/worktrees/TASK-19E0214A'));
        self::assertFalse($worktree->isAt('/repo/.ralph/runtime/worktrees/TASK-19E0214A/'));
        self::assertFalse($worktree->isAt('/repo/.ralph/runtime/worktrees/task-19e0214a'));
        self::assertFalse($worktree->isAt('/repo/.ralph/runtime/worktrees'));
    }

    #[Test]
    public function no_workspace_is_at_no_path(): void
    {
        self::assertFalse(WorktreeOwnership::none()->isAt('/repo/work'));
        self::assertFalse(WorktreeOwnership::none()->isAt(''));
    }

    /**
     * The description has to make the ownership visible, since that is the part
     * a user cannot see by looking at the directory.
     */
    #[Test]
    public function a_record_describes_both_the_path_and_who_made_it(): void
    {
        self::assertSame(
            '/repo/work (created by this pass)',
            WorktreeOwnership::created('/repo/work')->describe(),
        );
        self::assertSame(
            '/repo/work (pre-existing, left in place)',
            WorktreeOwnership::adopted('/repo/work')->describe(),
        );
        self::assertSame('no workspace prepared', WorktreeOwnership::none()->describe());
    }
}
