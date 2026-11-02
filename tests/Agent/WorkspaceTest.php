<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The directory an attempt is allowed to change.
 */
#[CoversClass(Workspace::class)]
final class WorkspaceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('workspace');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function an_existing_absolute_directory_is_a_workspace(): void
    {
        $workspace = Workspace::at($this->directory);

        self::assertSame($this->directory, $workspace->path());
        self::assertSame($this->directory, (string) $workspace);
    }

    #[Test]
    public function a_relative_path_is_refused_because_it_would_resolve_somewhere_else(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        Workspace::at('.ralph/runtime/worktrees/TASK-0a1b2c3d');
    }

    #[Test]
    public function a_path_that_says_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a path');

        Workspace::at('   ');
    }

    #[Test]
    public function a_directory_that_is_not_there_is_refused_before_a_command_is_built(): void
    {
        $missing = $this->directory . '/worktrees/TASK-0a1b2c3d';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has to be prepared before an attempt is given it');

        Workspace::at($missing);
    }

    #[Test]
    public function a_file_is_not_a_workspace(): void
    {
        $file = $this->directory . '/README.md';
        file_put_contents($file, "# A project\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory that exists');

        Workspace::at($file);
    }

    #[Test]
    public function a_trailing_separator_is_a_formatting_difference_rather_than_another_directory(): void
    {
        self::assertSame($this->directory, Workspace::at($this->directory . '/')->path());
        self::assertSame($this->directory, Workspace::at($this->directory . '//')->path());
        self::assertTrue(Workspace::at($this->directory . '/')->equals(Workspace::at($this->directory)));
    }

    #[Test]
    public function the_root_directory_keeps_its_separator(): void
    {
        self::assertSame('/', Workspace::at('/')->path());
    }

    #[Test]
    public function surrounding_whitespace_is_not_part_of_a_path(): void
    {
        self::assertSame($this->directory, Workspace::at(' ' . $this->directory . ' ')->path());
    }

    #[Test]
    public function the_path_is_not_canonicalised_so_it_still_reads_as_the_runner_recorded_it(): void
    {
        $link = $this->directory . '/link';
        mkdir($this->directory . '/target');

        if (!@symlink($this->directory . '/target', $link)) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }

        self::assertSame($link, Workspace::at($link)->path());
        self::assertNotSame(realpath($link), Workspace::at($link)->path());
    }

    #[Test]
    public function two_different_directories_are_not_the_same_workspace(): void
    {
        mkdir($this->directory . '/first');
        mkdir($this->directory . '/second');

        self::assertFalse(
            Workspace::at($this->directory . '/first')->equals(Workspace::at($this->directory . '/second')),
        );
    }

    #[Test]
    public function a_relative_path_inside_the_workspace_is_made_absolute(): void
    {
        $workspace = Workspace::at($this->directory);

        self::assertSame(
            $this->directory . DIRECTORY_SEPARATOR . 'src/Importer.php',
            $workspace->resolve('src/Importer.php'),
        );
    }

    #[Test]
    public function resolving_an_absolute_path_would_escape_the_workspace_and_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be relative and not empty');

        Workspace::at($this->directory)->resolve('/etc/passwd');
    }

    #[Test]
    public function resolving_a_path_that_climbs_out_of_the_workspace_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('climbs out of the workspace');

        Workspace::at($this->directory)->resolve('src/../../other/file.php');
    }

    #[Test]
    public function resolving_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be relative and not empty');

        Workspace::at($this->directory)->resolve('');
    }

    #[Test]
    public function a_file_named_with_two_dots_is_not_a_path_climbing_out(): void
    {
        $workspace = Workspace::at($this->directory);

        self::assertSame(
            $this->directory . DIRECTORY_SEPARATOR . 'docs/..notes.md',
            $workspace->resolve('docs/..notes.md'),
        );
    }
}
