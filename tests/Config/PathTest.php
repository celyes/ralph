<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Config;

use Celyes\RalphPhp\Config\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Resolving configured paths.
 *
 * Configured locations are routinely paths that do not exist yet — `ralph init`
 * is what creates them — so this handling must be textual and cannot lean on
 * realpath().
 */
#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function roots(): iterable
    {
        yield 'posix absolute' => ['/srv/project/.ralph/tasks.md', true];
        yield 'relative' => ['.ralph/tasks.md', false];
        yield 'explicitly relative' => ['./tasks.md', false];
        yield 'parent relative' => ['../tasks.md', false];
        yield 'windows drive' => ['C:\\projects\\app\\tasks.md', true];
        yield 'windows drive with slash' => ['D:/projects/app/tasks.md', true];
        yield 'home shorthand is not expanded' => ['~/tasks.md', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('roots')]
    public function absolute_paths_are_recognised(string $path, bool $expected): void
    {
        self::assertSame($expected, Path::isAbsolute($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'duplicate separators' => ['/srv//project///tasks.md', '/srv/project/tasks.md'];
        yield 'current directory segments' => ['/srv/./project/./tasks.md', '/srv/project/tasks.md'];
        yield 'parent segments' => ['/srv/project/docs/../tasks.md', '/srv/project/tasks.md'];
        yield 'trailing separator' => ['/srv/project/', '/srv/project'];
        yield 'above the root stays at the root' => ['/../../tasks.md', '/tasks.md'];
        yield 'relative parent segments are kept' => ['../../tasks.md', '../../tasks.md'];
        yield 'relative parent segments are collapsed' => ['docs/../tasks.md', 'tasks.md'];
        yield 'a relative path that collapses to nothing' => ['docs/..', '.'];
        yield 'backslashes become separators' => ['C:\\projects\\app\\tasks.md', 'C:/projects/app/tasks.md'];
        yield 'root' => ['/', '/'];
    }

    #[Test]
    #[DataProvider('paths')]
    public function paths_are_normalized_textually(string $path, string $expected): void
    {
        self::assertSame($expected, Path::normalize($path));
    }

    #[Test]
    public function normalization_does_not_follow_symlinks_or_require_existence(): void
    {
        self::assertSame('/does/not/exist/tasks.md', Path::normalize('/does/not/./exist/nope/../tasks.md'));
    }
}
