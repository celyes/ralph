<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\WorktreeLayout;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a task's workspace goes.
 *
 * Textual and without I/O, like the layout itself: it is asked where to create
 * a directory that does not exist yet. The two properties worth pinning are
 * that the path is derived from the generation slug and nothing else — which is
 * what lets an interrupted pass be found again by asking about one path — and
 * that reading a path back is strict, since the only use for that answer is
 * deciding what may be removed.
 */
#[CoversClass(WorktreeLayout::class)]
final class WorktreeLayoutTest extends TestCase
{
    private const string ROOT = '/home/u/project';

    #[Test]
    public function workspaces_live_in_the_runtime_directory_the_package_owns(): void
    {
        // Git-ignored, cleaned up with the rest of the runtime data, and in the
        // place a user looking for their task's files would look.
        self::assertSame(
            self::ROOT . '/.ralph/runtime/worktrees',
            WorktreeLayout::under(self::ROOT)->root(),
        );
    }

    #[Test]
    public function a_pass_is_named_by_its_slug_and_nothing_else(): void
    {
        $layout = WorktreeLayout::under(self::ROOT);
        $task = TaskId::fromDescription('Add a flag to the CLI');

        self::assertSame(
            self::ROOT . '/.ralph/runtime/worktrees/' . $task->value(),
            $layout->pathFor(TaskGeneration::first($task)),
        );

        // Reopening a finished task must not land in the old pass's directory.
        self::assertSame(
            self::ROOT . '/.ralph/runtime/worktrees/' . $task->value() . '-g2',
            $layout->pathFor(TaskGeneration::of($task, 2)),
        );
        self::assertNotSame(
            $layout->pathFor(TaskGeneration::first($task)),
            $layout->pathFor(TaskGeneration::of($task, 2)),
        );
    }

    #[Test]
    public function the_same_pass_is_given_the_same_path_on_a_later_run(): void
    {
        $generation = TaskGeneration::first(TaskId::fromDescription('Add a flag to the CLI'));

        self::assertSame(
            WorktreeLayout::under(self::ROOT)->pathFor($generation),
            WorktreeLayout::under(self::ROOT . '/')->pathFor($generation),
        );
    }

    #[Test]
    public function a_path_below_the_root_is_the_packages_to_reason_about(): void
    {
        $layout = WorktreeLayout::under(self::ROOT);

        self::assertTrue($layout->contains(self::ROOT . '/.ralph/runtime/worktrees/TASK-19E0214A'));
        self::assertTrue($layout->contains(self::ROOT . '/.ralph/runtime/worktrees//TASK-19E0214A/'));
        self::assertTrue($layout->contains(self::ROOT . '/.ralph/runtime/worktrees/TASK-19E0214A/src/Foo.php'));

        // The root itself is not a workspace, and nothing outside it is.
        self::assertFalse($layout->contains($layout->root()));
        self::assertFalse($layout->contains(self::ROOT . '/.ralph/runtime/logs/TASK-19E0214A'));
        self::assertFalse($layout->contains(self::ROOT . '/src'));
        self::assertFalse($layout->contains('/tmp/TASK-19E0214A'));

        // `..` cannot smuggle a path back out of the root.
        self::assertFalse($layout->contains(self::ROOT . '/.ralph/runtime/worktrees/../logs'));
    }

    #[Test]
    public function a_workspace_path_is_read_back_as_the_pass_that_owns_it(): void
    {
        $layout = WorktreeLayout::under(self::ROOT);
        $task = TaskId::fromDescription('Add a flag to the CLI');

        $first = $layout->generationAt($layout->pathFor(TaskGeneration::first($task)));
        self::assertNotNull($first);
        self::assertTrue($first->equals(TaskGeneration::first($task)));

        $second = $layout->generationAt($layout->pathFor(TaskGeneration::of($task, 7)));
        self::assertNotNull($second);
        self::assertTrue($second->equals(TaskGeneration::of($task, 7)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatBelongToNoPass(): iterable
    {
        yield 'the root itself' => [self::ROOT . '/.ralph/runtime/worktrees'];
        yield 'outside the root' => [self::ROOT . '/.ralph/runtime/logs/TASK-19E0214A'];
        yield 'a name that is not a slug' => [self::ROOT . '/.ralph/runtime/worktrees/scratch'];
        yield 'a slug with the wrong id length' => [self::ROOT . '/.ralph/runtime/worktrees/TASK-19E021'];
        yield 'a file inside a workspace' => [self::ROOT . '/.ralph/runtime/worktrees/TASK-19E0214A/src'];
        yield 'a generation zero' => [self::ROOT . '/.ralph/runtime/worktrees/TASK-19E0214A-g0'];
    }

    #[Test]
    #[DataProvider('pathsThatBelongToNoPass')]
    public function a_path_the_layout_did_not_hand_out_is_attributed_to_nobody(string $path): void
    {
        // Strict on purpose: the only use for the answer is deciding what may
        // be removed, and a guess there deletes someone's work.
        self::assertNull(WorktreeLayout::under(self::ROOT)->generationAt($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableRoots(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'relative' => ['project'];
        yield 'relative with a dot' => ['./project'];
    }

    #[Test]
    #[DataProvider('unusableRoots')]
    public function a_relative_project_root_is_refused(string $root): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute project root');

        WorktreeLayout::under($root);
    }
}
