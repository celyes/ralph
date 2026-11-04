<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Git\ManagedPaths;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which paths in the user's checkout belong to the package.
 *
 * The distinction decides whether a run starts, so the tests are written from
 * both sides: a change the package is responsible for must not be reported as
 * the user's work, and a change to the project must not be absorbed as the
 * package's. The paths are compared as `git status` spells them — relative,
 * forward slashes, a trailing slash on a wholly untracked directory.
 */
#[CoversClass(ManagedPaths::class)]
final class ManagedPathsTest extends TestCase
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
    public function the_packages_own_directory_and_everything_in_it_belongs_to_it(): void
    {
        $managed = $this->managedPaths();

        foreach ([
            '.ralph',
            '.ralph/',
            '.ralph/config.json',
            '.ralph/tasks.md',
            '.ralph/CONTEXT.md',
            '.ralph/runtime/',
            '.ralph/runtime/state.sqlite',
            '.ralph/runtime/logs/TASK-1/worker.log',
            '.ralph/runtime/worktrees/TASK-1/src/Foo.php',
            '.ralph/adapters.php',
        ] as $path) {
            self::assertTrue($managed->covers($path), $path . ' belongs to the package');
        }
    }

    #[Test]
    public function the_projects_own_files_do_not(): void
    {
        $managed = $this->managedPaths();

        foreach ([
            'src/Foo.php',
            'composer.json',
            'README.md',
            '.gitignore',
            'tasks.md',
            'docs/.ralph/notes.md',
            '.ralphrc',
            '.ralph-notes.md',
        ] as $path) {
            self::assertFalse($managed->covers($path), $path . ' is the project\'s');
        }
    }

    #[Test]
    public function a_checklist_configured_elsewhere_is_recognised_where_it_actually_is(): void
    {
        $managed = $this->managedPaths([
            'tasks' => 'docs/backlog.md',
            'context' => 'docs/conventions/CONTEXT.md',
        ]);

        self::assertTrue($managed->covers('docs/backlog.md'));
        self::assertTrue($managed->covers('docs/conventions/CONTEXT.md'));

        // Still ours: the configuration and the runtime directory do not move.
        self::assertTrue($managed->covers('.ralph/config.json'));

        // And the directories they were put in are not ours wholesale.
        self::assertFalse($managed->covers('docs/README.md'));
        self::assertFalse($managed->covers('docs/conventions/style.md'));
        self::assertFalse($managed->covers('docs/'));
    }

    #[Test]
    public function the_backup_init_force_leaves_beside_a_file_belongs_to_the_package(): void
    {
        $managed = $this->managedPaths(['tasks' => 'docs/backlog.md']);

        self::assertTrue($managed->covers('docs/backlog.md.bak'));
        self::assertTrue($managed->covers('docs/backlog.md.bak.2'));
        self::assertFalse($managed->covers('docs/backlog.md.orig'));
        self::assertFalse($managed->covers('docs/other.md.bak'));
    }

    #[Test]
    public function the_repository_root_itself_is_not_the_packages(): void
    {
        $managed = $this->managedPaths();

        // Git never reports the root as a change, but a path that normalises to
        // nothing must not be answered with "ours" — that would make an entire
        // dirty checkout the package's responsibility.
        foreach (['', ' ', '/', '.', './'] as $path) {
            self::assertFalse($managed->covers($path), sprintf('"%s" is not ours', $path));
        }
    }

    #[Test]
    public function the_paths_are_listed_relative_to_the_project(): void
    {
        self::assertSame(
            ['.ralph', '.ralph/tasks.md', '.ralph/CONTEXT.md'],
            $this->managedPaths()->paths(),
        );

        self::assertSame(
            ['.ralph', 'docs/backlog.md', '.ralph/CONTEXT.md'],
            $this->managedPaths(['tasks' => 'docs/backlog.md'])->paths(),
        );
    }

    #[Test]
    public function a_backslash_is_a_character_in_a_name_and_not_a_separator(): void
    {
        // Git reports paths with forward slashes on every platform, so a
        // backslash is part of the filename. Translating it would claim a file
        // in the project root as the package's.
        $managed = $this->managedPaths();

        self::assertFalse($managed->covers('.ralph\\notes.md'));
        self::assertFalse($managed->covers('.ralph\\runtime\\state.sqlite'));
    }

    #[Test]
    public function a_leading_space_is_part_of_the_name_too(): void
    {
        self::assertFalse($this->managedPaths()->covers(' .ralph/tasks.md'));
        self::assertTrue($this->managedPaths()->covers('.ralph/ odd name.md'));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function managedPaths(array $settings = []): ManagedPaths
    {
        return ManagedPaths::forProject($this->configuration($settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }
}
