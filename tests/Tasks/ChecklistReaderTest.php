<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the project's checklist from disk.
 *
 * A missing file, a path that is a directory and an unreadable file are the
 * whole point of this class, so these tests use real temporary projects rather
 * than a stubbed filesystem.
 */
#[CoversClass(ChecklistReader::class)]
final class ChecklistReaderTest extends TestCase
{
    private TemporaryProject $project;

    private ChecklistReader $reader;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->reader = new ChecklistReader();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_configured_checklist_is_read(): void
    {
        $this->project->write('.ralph/tasks.md', "# Tasks\n\n- [x] Done\n- [ ] Pending\n");

        $checklist = $this->reader->read((new ConfigurationLoader())->defaults($this->project->root()));

        self::assertSame($this->project->path('.ralph/tasks.md'), $checklist->source());
        self::assertSame(2, $checklist->count());
        self::assertTrue($checklist->items()[0]->isChecked());
        self::assertFalse($checklist->items()[1]->isChecked());
    }

    #[Test]
    public function a_checklist_configured_elsewhere_is_read_from_there(): void
    {
        $this->project->write('docs/backlog.md', "- [ ] Configured elsewhere\n");
        $configuration = (new ConfigurationLoader())
            ->fromArray(['tasks' => 'docs/backlog.md'], $this->project->root());

        $checklist = $this->reader->read($configuration);

        self::assertSame($this->project->path('docs/backlog.md'), $checklist->source());
        self::assertSame('Configured elsewhere', $checklist->items()[0]->description());
    }

    #[Test]
    public function an_empty_checklist_is_not_an_error(): void
    {
        $this->project->write('.ralph/tasks.md', '');

        $checklist = $this->reader->read((new ConfigurationLoader())->defaults($this->project->root()));

        self::assertTrue($checklist->isEmpty());
    }

    #[Test]
    public function a_missing_checklist_is_reported_with_its_path(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('No task checklist at ' . $this->project->path('.ralph/tasks.md'));

        $this->reader->read((new ConfigurationLoader())->defaults($this->project->root()));
    }

    #[Test]
    public function a_missing_checklist_says_how_to_create_one(): void
    {
        try {
            $this->reader->readFile($this->project->path('.ralph/tasks.md'));
            self::fail('Expected a ChecklistException.');
        } catch (ChecklistException $exception) {
            self::assertContains('Run "ralph init" in the project to create one.', $exception->hints());
        }
    }

    #[Test]
    public function a_directory_in_place_of_the_checklist_is_reported_as_one(): void
    {
        $this->project->write('.ralph/tasks.md/keep', '');

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('is a directory');

        $this->reader->readFile($this->project->path('.ralph/tasks.md'));
    }

    #[Test]
    public function an_unreadable_checklist_is_reported_rather_than_read_as_empty(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] Work\n");

        if (!chmod($file, 0o000) || is_readable($file)) {
            chmod($file, 0o600);
            self::markTestSkipped('This user can read a file with no permissions.');
        }

        try {
            $this->reader->readFile($file);
            self::fail('Expected a ChecklistException.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('Unable to read the task checklist', $exception->getMessage());
        } finally {
            chmod($file, 0o600);
        }
    }

    #[Test]
    public function a_checklist_that_is_not_utf8_is_refused_with_its_path(): void
    {
        $file = $this->project->write('.ralph/tasks.md', "- [ ] Caf\xE9\n");

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage($file . ' is not valid UTF-8');

        $this->reader->readFile($file);
    }
}
