<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Init;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Init\InitializationOutcome;
use Celyes\RalphPhp\Init\InitializationReport;
use Celyes\RalphPhp\Init\InitializedPath;
use Celyes\RalphPhp\Init\ProjectInitializer;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Initialising a project.
 *
 * Uses real directories: what is under test is what ends up on disk — which
 * files exist, what they contain, and above all that a file the user already
 * wrote is not touched.
 */
#[CoversClass(ProjectInitializer::class)]
#[CoversClass(Templates::class)]
#[CoversClass(InitializationReport::class)]
#[CoversClass(InitializedPath::class)]
#[CoversClass(InitializationOutcome::class)]
final class ProjectInitializerTest extends TestCase
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
    public function it_creates_the_configuration_the_checklist_the_context_file_and_the_runtime_directories(): void
    {
        $report = (new ProjectInitializer())->initialize($this->project->root());

        self::assertSame($this->project->root(), $report->projectRoot());

        self::assertSame(
            [
                Defaults::CONFIG_FILE,
                Defaults::TASKS_FILE,
                Defaults::CONTEXT_FILE,
                Defaults::RUNTIME_DIRECTORY,
                Defaults::LOGS_DIRECTORY,
                Defaults::MEMORY_DIRECTORY,
                Defaults::WORKTREES_DIRECTORY,
            ],
            array_map(
                static fn (InitializedPath $path): string => $path->relativePath(),
                $report->paths(),
            ),
        );

        self::assertSame($report->paths(), $report->created());
        self::assertSame([], $report->kept());

        foreach ([Defaults::CONFIG_FILE, Defaults::TASKS_FILE, Defaults::CONTEXT_FILE] as $file) {
            self::assertFileExists($this->project->path($file));
        }

        foreach (ProjectInitializer::runtimeDirectories() as $directory) {
            self::assertDirectoryExists($this->project->path($directory));
        }
    }

    #[Test]
    public function the_written_configuration_loads_back_as_the_documented_defaults(): void
    {
        (new ProjectInitializer())->initialize($this->project->root());

        $loader = new ConfigurationLoader();

        self::assertSame(
            $loader->defaults($this->project->root())->toArray(),
            $loader->load($this->project->root())->toArray(),
        );
    }

    #[Test]
    public function the_written_checklist_has_no_pending_task(): void
    {
        (new ProjectInitializer())->initialize($this->project->root());

        $checklist = (string) file_get_contents($this->project->path(Defaults::TASKS_FILE));

        self::assertStringContainsString('- [ ] Add a --dry-run option', $checklist);
        self::assertSame(
            [],
            self::checklistItemsOutsideCodeBlocks($checklist),
            'The template must not leave a task the runner would pick up.',
        );
    }

    #[Test]
    public function running_it_again_keeps_the_user_content_of_every_file(): void
    {
        $initializer = new ProjectInitializer();
        $initializer->initialize($this->project->root());

        $edited = [
            Defaults::CONFIG_FILE => '{"agent": "claude"}',
            Defaults::TASKS_FILE => "# My tasks\n\n- [ ] Something of my own\n",
            Defaults::CONTEXT_FILE => "# My project\n\nIt is mine.\n",
        ];

        foreach ($edited as $file => $contents) {
            $this->project->write($file, $contents);
        }

        $report = $initializer->initialize($this->project->root());

        self::assertSame([], $report->created());
        self::assertCount(7, $report->kept());

        foreach ($edited as $file => $contents) {
            self::assertStringEqualsFile($this->project->path($file), $contents);
        }
    }

    #[Test]
    public function a_single_missing_file_is_recreated_while_the_others_are_kept(): void
    {
        $initializer = new ProjectInitializer();
        $initializer->initialize($this->project->root());

        unlink($this->project->path(Defaults::TASKS_FILE));

        $report = $initializer->initialize($this->project->root());

        self::assertSame(
            [Defaults::TASKS_FILE],
            array_map(static fn (InitializedPath $path): string => $path->relativePath(), $report->created()),
        );
        self::assertStringEqualsFile($this->project->path(Defaults::TASKS_FILE), Templates::tasks());
    }

    #[Test]
    public function an_existing_configuration_decides_where_the_checklist_and_context_file_are_created(): void
    {
        $this->project->writeConfigArray([
            'tasks' => 'docs/backlog.md',
            'context' => 'docs/AGENT.md',
        ]);

        $report = (new ProjectInitializer())->initialize($this->project->root());

        self::assertSame('docs/backlog.md', $report->tasks()->relativePath());
        self::assertSame('docs/AGENT.md', $report->context()->relativePath());
        self::assertFileExists($this->project->path('docs/backlog.md'));
        self::assertFileExists($this->project->path('docs/AGENT.md'));
        self::assertFileDoesNotExist($this->project->path(Defaults::TASKS_FILE));
        self::assertTrue($report->configuration()->wasKept());
    }

    #[Test]
    public function an_unusable_existing_configuration_stops_initialisation_before_anything_is_written(): void
    {
        $this->project->writeConfigArray(['limits' => ['iterations' => 0]]);

        try {
            (new ProjectInitializer())->initialize($this->project->root());
            self::fail('An invalid configuration must not be initialised around.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('"limits.iterations"', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->project->path(Defaults::TASKS_FILE));
        self::assertDirectoryDoesNotExist($this->project->path(Defaults::RUNTIME_DIRECTORY));
    }

    #[Test]
    public function the_runtime_directories_are_not_readable_by_other_users(): void
    {
        (new ProjectInitializer())->initialize($this->project->root());

        foreach (ProjectInitializer::runtimeDirectories() as $directory) {
            $mode = fileperms($this->project->path($directory));

            self::assertNotFalse($mode);
            self::assertSame(
                0,
                $mode & 0o077,
                $directory . ' holds raw worker output and must be owner-only.',
            );
        }
    }

    #[Test]
    public function a_directory_where_a_file_belongs_is_reported_rather_than_removed(): void
    {
        mkdir($this->project->path(Defaults::TASKS_FILE), 0o700, true);

        $exception = $this->failure();

        self::assertStringContainsString('A directory exists at', $exception->getMessage());
        self::assertStringContainsString('tasks.md', $exception->getMessage());
        self::assertDirectoryExists($this->project->path(Defaults::TASKS_FILE));
    }

    #[Test]
    public function a_file_where_a_runtime_directory_belongs_is_reported_rather_than_removed(): void
    {
        $this->project->write(Defaults::RUNTIME_DIRECTORY, 'not a directory');

        $exception = $this->failure();

        self::assertStringContainsString('A file exists at', $exception->getMessage());
        self::assertStringEqualsFile($this->project->path(Defaults::RUNTIME_DIRECTORY), 'not a directory');
    }

    #[Test]
    public function a_project_directory_that_does_not_exist_is_reported(): void
    {
        $missing = $this->project->path('nowhere');

        $exception = $this->failure($missing);

        self::assertStringContainsString($missing, $exception->getMessage());
        self::assertStringContainsString('does not exist', $exception->getMessage());
    }

    #[Test]
    public function an_empty_project_directory_is_reported(): void
    {
        $exception = $this->failure('');

        self::assertSame('No project directory was given.', $exception->getMessage());
    }

    #[Test]
    public function a_project_directory_that_cannot_be_written_to_is_reported(): void
    {
        $directory = TemporaryDirectory::create('ralph-readonly');
        chmod($directory, 0o500);

        if (is_writable($directory)) {
            // Running as a user that ignores the permission bits, root in a
            // container being the usual case.
            chmod($directory, 0o700);
            TemporaryDirectory::remove($directory);
            self::markTestSkipped('This user can write to a directory with mode 0500.');
        }

        try {
            $exception = $this->failure($directory);

            self::assertStringContainsString('is not writable', $exception->getMessage());
        } finally {
            chmod($directory, 0o700);
            TemporaryDirectory::remove($directory);
        }
    }

    /**
     * Initialise and return the failure, which every caller here expects.
     */
    private function failure(?string $root = null): FilesystemException
    {
        try {
            (new ProjectInitializer())->initialize($root ?? $this->project->root());
        } catch (FilesystemException $exception) {
            self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());

            return $exception;
        }

        self::fail('Initialisation was expected to fail.');
    }

    /**
     * Checklist items the reader would treat as tasks: `- [ ]` and `- [x]`
     * markers outside fenced code blocks.
     *
     * @return list<string>
     */
    private static function checklistItemsOutsideCodeBlocks(string $markdown): array
    {
        $items = [];
        $inFence = false;

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;

                continue;
            }

            if (!$inFence && preg_match('/^\s*[-*+]\s*\[[ xX]\]/', $line) === 1) {
                $items[] = $line;
            }
        }

        return $items;
    }
}
