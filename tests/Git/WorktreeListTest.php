<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\RegisteredWorktree;
use Celyes\RalphPhp\Git\WorktreeList;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading `git worktree list --porcelain`.
 *
 * Held against hand-built output rather than a live repository, for the reason
 * {@see \Celyes\RalphPhp\Tests\Git\WorkingTreeStatusTest} is: the cases worth
 * pinning are the ones a test cannot easily make Git produce — a bare
 * repository, a lock without a reason, a label from a newer Git than the one
 * installed. The same format read back out of a real repository is covered by
 * {@see TaskWorktreesTest}, which asks the actual `git`.
 */
#[CoversClass(WorktreeList::class)]
#[CoversClass(RegisteredWorktree::class)]
final class WorktreeListTest extends TestCase
{
    #[Test]
    public function the_main_working_tree_and_a_linked_one_are_read_in_order(): void
    {
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/main

            worktree /home/u/project/.ralph/runtime/worktrees/TASK-19E0214A
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            detached

            PORCELAIN);

        self::assertSame(2, $list->count());
        self::assertFalse($list->isEmpty());

        $main = $list->main();
        self::assertNotNull($main);
        self::assertSame('/home/u/project', $main->path());
        self::assertSame('9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481', $main->head());
        self::assertSame('main', $main->branch());
        self::assertTrue($main->isMain());
        self::assertFalse($main->isDetached());
        self::assertFalse($main->isBare());
        self::assertFalse($main->isLocked());
        self::assertFalse($main->isPrunable());

        $linked = $list->linked();
        self::assertCount(1, $linked);
        self::assertSame('/home/u/project/.ralph/runtime/worktrees/TASK-19E0214A', $linked[0]->path());
        self::assertNull($linked[0]->branch());
        self::assertTrue($linked[0]->isDetached());
        self::assertFalse($linked[0]->isMain());
    }

    #[Test]
    public function a_branch_is_reported_by_the_name_the_rest_of_the_package_uses(): void
    {
        // Git names it in full here and nowhere else the package touches.
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/ralph/task-19e0214a

            PORCELAIN);

        $main = $list->main();
        self::assertNotNull($main);
        self::assertSame('ralph/task-19e0214a', $main->branch());
        self::assertSame($main, $list->forBranch('ralph/task-19e0214a'));
        self::assertNull($list->forBranch('refs/heads/ralph/task-19e0214a'));
        self::assertNull($list->forBranch('main'));
    }

    #[Test]
    public function a_bare_repository_has_no_working_tree_and_no_branch(): void
    {
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project.git
            bare

            PORCELAIN);

        $main = $list->main();
        self::assertNotNull($main);
        self::assertTrue($main->isBare());
        self::assertNull($main->head());
        self::assertNull($main->branch());
        // Bare is not detached: there is no HEAD checked out either way.
        self::assertFalse($main->isDetached());
        self::assertSame('/home/u/project.git (bare, main working tree)', $main->describe());
    }

    #[Test]
    public function a_lock_is_read_with_its_reason_and_without_one(): void
    {
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/main

            worktree /mnt/removable/wt
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            detached
            locked on a drive that is not always mounted

            worktree /home/u/other
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            detached
            locked

            PORCELAIN);

        $withReason = $list->at('/mnt/removable/wt');
        self::assertNotNull($withReason);
        self::assertTrue($withReason->isLocked());
        self::assertSame('on a drive that is not always mounted', $withReason->lockReason());
        self::assertStringContainsString('locked: on a drive that is not always mounted', $withReason->describe());

        // Locked for no stated reason is an answer, not an absence: the two
        // lead to different messages, so the empty string survives.
        $withoutReason = $list->at('/home/u/other');
        self::assertNotNull($withoutReason);
        self::assertTrue($withoutReason->isLocked());
        self::assertSame('', $withoutReason->lockReason());
        self::assertStringContainsString('locked', $withoutReason->describe());
        self::assertStringNotContainsString('locked:', $withoutReason->describe());
    }

    #[Test]
    public function a_registration_whose_directory_has_gone_reports_gits_own_reason(): void
    {
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/main

            worktree /home/u/project/.ralph/runtime/worktrees/TASK-19E0214A
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            detached
            prunable gitdir file points to non-existent location

            PORCELAIN);

        $stale = $list->at('/home/u/project/.ralph/runtime/worktrees/TASK-19E0214A');
        self::assertNotNull($stale);
        self::assertTrue($stale->isPrunable());
        self::assertSame('gitdir file points to non-existent location', $stale->prunableReason());
        self::assertStringContainsString('prunable: gitdir file', $stale->describe());
    }

    #[Test]
    public function a_label_this_version_does_not_know_is_ignored_rather_than_refused(): void
    {
        // Git has added lines to this format more than once, and will again.
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/main
            somethingnew with a value
            flagwithoutvalue

            PORCELAIN);

        self::assertSame(1, $list->count());
        $main = $list->main();
        self::assertNotNull($main);
        self::assertSame('main', $main->branch());
    }

    #[Test]
    public function a_record_cannot_be_moved_onto_a_path_from_another_paragraph(): void
    {
        // What a worktree whose path contains a newline looks like once Git has
        // printed it unquoted: the second line is read as a label, and if a
        // later `worktree` line could overwrite the first, this record would
        // claim a directory it is not in — which is how a path the runner is
        // about to create gets reported as already taken.
        $list = WorktreeList::parse("worktree /home/u/weird\nworktree /home/u/project\nbare\n");

        self::assertSame(1, $list->count());
        self::assertNotNull($list->at('/home/u/weird'));
        self::assertNull($list->at('/home/u/project'));
    }

    #[Test]
    public function output_git_could_not_have_written_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without a path');

        // Every record begins with the one line that attributes it to a
        // directory. A paragraph without it belongs to nothing.
        WorktreeList::parse("HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481\nbranch refs/heads/main\n");
    }

    #[Test]
    public function a_path_is_matched_exactly_as_git_spells_it(): void
    {
        $list = WorktreeList::parse("worktree /home/u/project\nbare\n");

        self::assertNotNull($list->at('/home/u/project'));
        // A near-match is a different directory, and adopting on one would mean
        // adopting a worktree the runner did not name.
        self::assertNull($list->at('/home/u/project/'));
        self::assertNull($list->at('/home/u/project/.'));
        self::assertNull($list->at('/home/u/Project'));
    }

    #[Test]
    public function a_path_with_an_unusual_name_survives_unquoted(): void
    {
        // Porcelain does not quote, which is what makes a space or a quote in a
        // path arrive as the path it is.
        $list = WorktreeList::parse("worktree /home/u/my project (old)\nbare\n");

        self::assertNotNull($list->at('/home/u/my project (old)'));
    }

    #[Test]
    public function a_repository_with_no_worktrees_reported_is_an_empty_list(): void
    {
        foreach (['', "\n", "\n\n  \n"] as $output) {
            $list = WorktreeList::parse($output);

            self::assertTrue($list->isEmpty());
            self::assertSame(0, $list->count());
            self::assertNull($list->main());
            self::assertSame([], $list->all());
            self::assertSame([], $list->linked());
            self::assertNull($list->at('/home/u/project'));
        }

        self::assertTrue(WorktreeList::none()->isEmpty());
    }

    #[Test]
    public function windows_line_endings_are_read_as_line_endings(): void
    {
        $list = WorktreeList::parse(
            "worktree /home/u/project\r\nHEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481\r\nbranch refs/heads/main\r\n\r\n",
        );

        $main = $list->main();
        self::assertNotNull($main);
        self::assertSame('/home/u/project', $main->path());
        self::assertSame('main', $main->branch());
    }

    #[Test]
    public function a_detached_worktree_is_described_by_the_commit_it_sits_on(): void
    {
        $list = WorktreeList::parse(<<<'PORCELAIN'
            worktree /home/u/project
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            branch refs/heads/main

            worktree /home/u/project/.ralph/runtime/worktrees/TASK-19E0214A
            HEAD 9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481
            detached

            PORCELAIN);

        $main = $list->main();
        self::assertNotNull($main);
        self::assertSame('/home/u/project (on main, main working tree)', $main->describe());

        $linked = $list->linked()[0];
        self::assertSame(
            '/home/u/project/.ralph/runtime/worktrees/TASK-19E0214A (detached at 9ee342dd51fa)',
            $linked->describe(),
        );
    }
}
