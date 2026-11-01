<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Memory\ContextFile;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\ProjectContext;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the project's context file.
 *
 * A missing file, an empty file, a directory in its place and an unreadable
 * file are the whole point of this class, so these tests use a real temporary
 * project.
 */
#[CoversClass(ContextFile::class)]
#[CoversClass(ProjectContext::class)]
final class ContextFileTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_context_file_is_read_as_written(): void
    {
        $path = $this->project->write('.ralph/CONTEXT.md', "# Project context\n\nRun the tests with composer test.\n");

        $context = ContextFile::read($path);

        self::assertTrue($context->isPresent());
        self::assertFalse($context->isEmpty());
        self::assertSame("# Project context\n\nRun the tests with composer test.\n", $context->text());
        self::assertSame($path, $context->path());
        self::assertFalse($context->wasTrimmed());
        self::assertNull($context->notice());
    }

    #[Test]
    public function a_project_without_a_context_file_is_not_a_failure(): void
    {
        $context = ContextFile::read($this->project->path('.ralph/CONTEXT.md'));

        self::assertFalse($context->isPresent());
        self::assertTrue($context->isEmpty());
        self::assertSame('', $context->text());
        self::assertSame(0, $context->length());
        self::assertSame($this->project->path('.ralph/CONTEXT.md'), $context->path());
    }

    #[Test]
    public function a_context_file_that_says_nothing_is_present_and_empty(): void
    {
        $path = $this->project->write('.ralph/CONTEXT.md', "\n   \n");

        $context = ContextFile::read($path);

        self::assertTrue($context->isPresent());
        self::assertTrue($context->isEmpty());
    }

    #[Test]
    public function a_long_context_file_is_held_to_the_budget_and_says_so(): void
    {
        $path = $this->project->write('.ralph/CONTEXT.md', str_repeat("a line of conventions\n", 100));

        $context = ContextFile::read($path, new MemoryBudget(contextCharacters: 200));

        self::assertTrue($context->wasTrimmed());
        self::assertLessThanOrEqual(200, $context->length());
        self::assertSame('Trimmed: 197 of 2200 characters shown.', $context->notice());
    }

    #[Test]
    public function a_directory_where_the_context_file_goes_is_a_configuration_problem(): void
    {
        $this->project->write('.ralph/CONTEXT.md/placeholder', 'x');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('is a directory');

        ContextFile::read($this->project->path('.ralph/CONTEXT.md'));
    }

    #[Test]
    public function an_unreadable_context_file_stops_the_run_rather_than_being_ignored(): void
    {
        $path = $this->project->write('.ralph/CONTEXT.md', 'conventions');

        if (!chmod($path, 0o000) || is_readable($path)) {
            chmod($path, 0o600);
            self::markTestSkipped('This user can read a file with no permissions.');
        }

        try {
            ContextFile::read($path);
            self::fail('Expected a FilesystemException.');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString('Unable to read the project context', $exception->getMessage());
        } finally {
            chmod($path, 0o600);
        }
    }
}
