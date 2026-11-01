<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which notes belong to a task and which to the project.
 */
#[CoversClass(MemoryScope::class)]
final class MemoryScopeTest extends TestCase
{
    #[Test]
    public function a_task_files_its_notes_under_its_own_identifier(): void
    {
        $task = TaskId::fromDescription('Add a --dry-run option');
        $scope = MemoryScope::forTask($task);

        self::assertSame($task->value(), $scope->directory());
        self::assertSame($task->value(), $scope->label());
        self::assertFalse($scope->isProject());

        $recalled = $scope->taskId();
        self::assertNotNull($recalled);
        self::assertTrue($task->equals($recalled));
    }

    #[Test]
    public function project_notes_live_under_a_directory_no_task_can_claim(): void
    {
        $scope = MemoryScope::project();

        self::assertSame('project', $scope->directory());
        self::assertTrue($scope->isProject());
        self::assertNull($scope->taskId());

        // Task directories are always "TASK-" and eight hex digits, so the
        // project directory can never be mistaken for one.
        self::assertStringStartsWith('TASK-', TaskId::fromDescription('anything')->value());
    }

    #[Test]
    public function a_scope_resolves_to_a_directory_under_the_memory_directory(): void
    {
        self::assertSame(
            '/tmp/p/.ralph/runtime/memory/project',
            MemoryScope::project()->directoryIn('/tmp/p/.ralph/runtime/memory'),
        );

        self::assertSame(
            '/tmp/p/.ralph/runtime/memory/project',
            MemoryScope::project()->directoryIn('/tmp/p/.ralph/runtime/memory/'),
        );
    }

    #[Test]
    public function two_scopes_for_the_same_task_are_the_same_scope(): void
    {
        $first = MemoryScope::forTask(TaskId::fromDescription('Add a flag'));
        $second = MemoryScope::forTask(TaskId::fromDescription('add a  flag'));

        self::assertTrue($first->equals($second));
        self::assertFalse($first->equals(MemoryScope::project()));
    }
}
