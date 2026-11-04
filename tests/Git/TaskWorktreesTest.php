<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\WorktreeException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\RegisteredWorktree;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorktreeLayout;
use Celyes\RalphPhp\Git\WorktreeList;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Preparing a task's isolated workspace, against real repositories.
 *
 * A linked worktree is a thing Git maintains administrative records about, and
 * every case worth testing is one of those records disagreeing with the
 * filesystem — a registration whose directory was deleted, a directory Git
 * never registered, a worktree an interrupted run left with changes in it. None
 * of that can be faked usefully, so these tests create repositories and ask the
 * actual `git`, as PROJECT.md section J, item 5 requires.
 *
 * Two assertions recur and are the point of the class. A created workspace is
 * *linked* — its `.git` is a file pointing into the main repository, so it costs
 * a checkout rather than a clone — and the controlling checkout is untouched by
 * everything here, which is the other half of the promise
 * {@see \Celyes\RalphPhp\Git\ControllingCheckout} makes.
 */
#[CoversClass(TaskWorktrees::class)]
#[CoversClass(TaskWorktree::class)]
#[CoversClass(WorktreeException::class)]
#[CoversClass(WorktreeLayout::class)]
#[CoversClass(WorktreeList::class)]
#[CoversClass(RegisteredWorktree::class)]
final class TaskWorktreesTest extends TestCase
{
    private TemporaryProject $project;

    private string $head;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this is about real linked worktrees.');
        }

        $this->project = TemporaryProject::create();
        $this->head = $this->givenACommittedProject();
    }

    protected function tearDown(): void
    {
        // Remove the worktrees' administrative records first: deleting the
        // repository out from under them would leave nothing behind, but Git
        // complains loudly enough to obscure a real failure.
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
    }

    #[Test]
    public function a_task_is_given_a_linked_worktree_at_the_recorded_base_commit(): void
    {
        $generation = $this->generation();

        $worktree = $this->worktrees()->prepare($generation, $this->base());

        self::assertTrue($worktree->wasCreated());
        self::assertFalse($worktree->wasAdopted());
        self::assertSame($this->pathFor($generation), $worktree->path());
        self::assertTrue($worktree->generation()->equals($generation));
        self::assertDirectoryExists($worktree->path());

        // Linked, not cloned: a file pointing into the main repository.
        self::assertFileExists($worktree->path() . '/.git');
        self::assertDirectoryDoesNotExist($worktree->path() . '/.git/objects');

        // Checked out at the commit the run recorded, with no branch: naming
        // and owning a task branch is PROJECT.md section F, item 3.
        self::assertSame($this->head, trim(Git::run($worktree->path(), 'rev-parse', 'HEAD')->standardOutput));
        self::assertSame('HEAD', trim(Git::run($worktree->path(), 'rev-parse', '--abbrev-ref', 'HEAD')->standardOutput));
        self::assertSame(
            '* main',
            rtrim(Git::run($this->project->root(), 'branch', '--list')->standardOutput, "\n"),
        );
        self::assertStringEqualsFile($worktree->path() . '/src/Foo.php', "<?php\n// original\n");
    }

    #[Test]
    public function the_workspace_is_somewhere_the_package_owns_and_git_ignores(): void
    {
        $generation = $this->generation();

        $worktree = $this->worktrees()->prepare($generation, $this->base());

        self::assertSame(
            $this->project->root() . '/.ralph/runtime/worktrees/' . $generation->slug(),
            $worktree->path(),
        );

        // The controlling checkout does not report a tree full of new files.
        self::assertSame('', Git::run($this->project->root(), 'status', '--porcelain=v1')->standardOutput);
    }

    #[Test]
    public function the_workspace_is_the_handle_an_adapter_is_given(): void
    {
        $worktree = $this->worktrees()->prepare($this->generation(), $this->base());

        self::assertSame($worktree->path(), $worktree->workspace()->path());
        self::assertSame($worktree->path() . '/src/Foo.php', $worktree->workspace()->resolve('src/Foo.php'));
    }

    #[Test]
    public function ownership_is_recorded_so_cleanup_knows_what_it_may_remove(): void
    {
        $generation = $this->generation();
        $worktrees = $this->worktrees();

        $created = $worktrees->prepare($generation, $this->base());
        $ownership = $created->ownership();

        self::assertTrue($ownership->isOwned());
        self::assertFalse($ownership->mustBePreserved());
        self::assertTrue($ownership->isAt($created->path()));

        // Asking twice adopts rather than creating again, and an adopted
        // workspace is not this run's to delete.
        $adopted = $worktrees->prepare($generation, $this->base());

        self::assertTrue($adopted->wasAdopted());
        self::assertSame($created->path(), $adopted->path());
        self::assertFalse($adopted->ownership()->isOwned());
        self::assertTrue($adopted->ownership()->mustBePreserved());
    }

    #[Test]
    public function an_interrupted_passs_workspace_is_continued_in_rather_than_replaced(): void
    {
        $generation = $this->generation();
        $worktrees = $this->worktrees();

        $first = $worktrees->prepare($generation, $this->base());

        // What an interrupted run leaves: work in progress, not yet committed.
        file_put_contents($first->path() . '/src/Foo.php', "<?php\n// half implemented\n");
        file_put_contents($first->path() . '/src/New.php', "<?php\n// added\n");

        $resumed = $worktrees->prepare($generation, $this->base());

        self::assertTrue($resumed->wasAdopted());
        self::assertStringEqualsFile($resumed->path() . '/src/Foo.php', "<?php\n// half implemented\n");
        self::assertFileExists($resumed->path() . '/src/New.php');
    }

    #[Test]
    public function a_registration_whose_directory_was_deleted_is_recovered(): void
    {
        $generation = $this->generation();
        $worktrees = $this->worktrees();

        $path = $worktrees->prepare($generation, $this->base())->path();
        self::assertDirectoryExists($path);

        // `rm -rf` of a task workspace leaves Git's record behind, and Git then
        // refuses the path outright. The record is the package's own and the
        // directory is gone, so there is nothing to lose by replacing it.
        self::removeDirectory($path);
        $stale = $worktrees->existing($generation);
        self::assertNotNull($stale);

        $recovered = $worktrees->prepare($generation, $this->base());

        self::assertTrue($recovered->wasCreated());
        self::assertSame($path, $recovered->path());
        self::assertSame($this->head, trim(Git::run($path, 'rev-parse', 'HEAD')->standardOutput));
    }

    #[Test]
    public function a_directory_holding_someone_elses_files_stops_the_run(): void
    {
        $generation = $this->generation();
        $path = $this->pathFor($generation);

        mkdir($path, 0o700, true);
        file_put_contents($path . '/notes.md', "mine\n");
        file_put_contents($path . '/draft.php', "<?php\n");

        $exception = $this->refusal($generation);

        self::assertStringContainsString($path, $exception->getMessage());
        self::assertStringContainsString('already holds files', $exception->getMessage());
        self::assertStringContainsString('not a worktree Git knows about', $exception->getMessage());
        self::assertStringContainsString('draft.php, notes.md', implode("\n", $exception->hints()));
        self::assertStringContainsString('will not delete or overwrite them', implode("\n", $exception->hints()));
        self::assertSame(78, $exception->exitCode());

        // The refusal is the feature: the files are exactly as they were.
        self::assertStringEqualsFile($path . '/notes.md', "mine\n");
        self::assertStringEqualsFile($path . '/draft.php', "<?php\n");
        self::assertNull($this->worktrees()->existing($generation));
    }

    #[Test]
    public function a_long_listing_of_occupying_files_is_summarised(): void
    {
        $generation = $this->generation();
        $path = $this->pathFor($generation);
        mkdir($path, 0o700, true);

        foreach (range(1, 8) as $number) {
            file_put_contents(sprintf('%s/file-%d.txt', $path, $number), 'x');
        }

        $hints = implode("\n", $this->refusal($generation)->hints());

        self::assertStringContainsString('file-1.txt', $hints);
        self::assertStringContainsString('file-5.txt', $hints);
        self::assertStringContainsString('and 3 more', $hints);
        self::assertStringNotContainsString('file-8.txt', $hints);
    }

    #[Test]
    public function a_file_where_the_workspace_goes_stops_the_run(): void
    {
        $generation = $this->generation();
        $path = $this->pathFor($generation);

        mkdir(dirname($path), 0o700, true);
        file_put_contents($path, "not a directory\n");

        $exception = $this->refusal($generation);

        self::assertStringContainsString('is not a directory', $exception->getMessage());
        self::assertStringContainsString('will not delete or overwrite it', implode("\n", $exception->hints()));
        self::assertStringEqualsFile($path, "not a directory\n");
    }

    #[Test]
    public function an_empty_directory_is_not_a_reason_to_stop(): void
    {
        $generation = $this->generation();
        $path = $this->pathFor($generation);

        // What an interrupted `mkdir`, or a user preparing the path, leaves.
        mkdir($path, 0o700, true);

        $worktree = $this->worktrees()->prepare($generation, $this->base());

        self::assertTrue($worktree->wasCreated());
        self::assertSame($this->head, trim(Git::run($worktree->path(), 'rev-parse', 'HEAD')->standardOutput));
    }

    #[Test]
    public function two_passes_at_one_task_get_separate_workspaces(): void
    {
        $task = TaskId::fromDescription('Add a greeting');
        $worktrees = $this->worktrees();

        $first = $worktrees->prepare(TaskGeneration::first($task), $this->base());
        $second = $worktrees->prepare(TaskGeneration::of($task, 2), $this->base());

        // Reopening a finished task cannot land in the old pass's directory.
        self::assertNotSame($first->path(), $second->path());
        self::assertSame($this->pathFor(TaskGeneration::first($task)), $first->path());
        self::assertSame($this->pathFor(TaskGeneration::of($task, 2)), $second->path());
        self::assertDirectoryExists($first->path());
        self::assertDirectoryExists($second->path());
    }

    #[Test]
    public function every_task_in_a_run_is_created_from_the_one_resolved_commit(): void
    {
        $base = $this->base();
        $worktrees = $this->worktrees();

        $first = $worktrees->prepare($this->generation('Add a greeting'), $base);

        // The configured reference moves mid-run; the recorded commit does not.
        $this->project->write('src/Foo.php', "<?php\n// moved on\n");
        Git::run($this->project->root(), 'add', 'src/Foo.php');
        $moved = Git::commit($this->project->root(), 'Later commit');
        self::assertNotSame($this->head, $moved);

        $second = $worktrees->prepare($this->generation('Add a farewell'), $base);

        self::assertSame($this->head, trim(Git::run($second->path(), 'rev-parse', 'HEAD')->standardOutput));
        self::assertStringEqualsFile($second->path() . '/src/Foo.php', "<?php\n// original\n");
        self::assertSame(
            trim(Git::run($first->path(), 'rev-parse', 'HEAD')->standardOutput),
            trim(Git::run($second->path(), 'rev-parse', 'HEAD')->standardOutput),
        );
    }

    #[Test]
    public function a_base_commit_the_repository_does_not_have_is_reported_as_gits_refusal(): void
    {
        $generation = $this->generation();
        $absent = BaseCommit::of('HEAD', str_repeat('a', 40));

        try {
            $this->worktrees()->prepare($generation, $absent);
        } catch (WorktreeException $exception) {
            self::assertStringContainsString('could not be created at', $exception->getMessage());
            self::assertStringContainsString($this->pathFor($generation), $exception->getMessage());
            self::assertStringContainsString('invalid reference', strtolower($exception->getMessage()));
            self::assertStringContainsString('aaaaaaaaaaaa', implode("\n", $exception->hints()));

            // Nothing half-created is left behind for the next run to adopt.
            self::assertNull($this->worktrees()->existing($generation));

            return;
        }

        self::fail('the missing base commit was accepted');
    }

    #[Test]
    public function the_worktrees_git_knows_about_are_reported(): void
    {
        $worktrees = $this->worktrees();
        $generation = $this->generation();

        self::assertSame(1, $worktrees->registered()->count());
        self::assertNull($this->worktrees()->existing($generation));

        $prepared = $worktrees->prepare($generation, $this->base());
        $registered = $worktrees->registered();

        self::assertSame(2, $registered->count());

        $main = $registered->main();
        self::assertNotNull($main);
        self::assertSame($this->project->root(), $main->path());
        self::assertSame('main', $main->branch());

        $existing = $worktrees->existing($generation);
        self::assertNotNull($existing);
        self::assertSame($prepared->path(), $existing->path());
        self::assertTrue($existing->isDetached());
        self::assertSame($this->head, $existing->head());
        self::assertFalse($existing->isMain());
        self::assertFalse($existing->isLocked());
    }

    #[Test]
    public function the_workspaces_directory_is_created_with_the_mode_the_package_chose(): void
    {
        self::assertDirectoryDoesNotExist($this->project->path('.ralph/runtime/worktrees'));

        $this->worktrees()->prepare($this->generation(), $this->base());

        $root = $this->project->path('.ralph/runtime/worktrees');
        self::assertDirectoryExists($root);
        // The same 0700 `ralph init` gives the runtime directory: a workspace
        // holds the project's source.
        self::assertSame('0700', substr(sprintf('%o', fileperms($root)), -4));
    }

    #[Test]
    public function preparing_a_workspace_leaves_the_controlling_checkout_alone(): void
    {
        $before = $this->snapshot();

        $this->worktrees()->prepare($this->generation(), $this->base());

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function a_pass_describes_its_workspace_in_one_line(): void
    {
        $generation = $this->generation();
        $worktrees = $this->worktrees();

        $created = $worktrees->prepare($generation, $this->base());
        self::assertStringContainsString($generation->slug(), $created->describe());
        self::assertStringContainsString('created by this run', $created->describe());
        self::assertStringContainsString($this->base()->shortSha(), $created->describe());

        self::assertStringContainsString(
            'already present, left in place',
            $worktrees->prepare($generation, $this->base())->describe(),
        );
    }

    #[Test]
    public function the_layout_and_the_path_are_reported_before_anything_is_created(): void
    {
        $worktrees = $this->worktrees();
        $generation = $this->generation();

        self::assertSame($this->project->path('.ralph/runtime/worktrees'), $worktrees->layout()->root());
        self::assertSame($this->pathFor($generation), $worktrees->pathFor($generation));
        self::assertDirectoryDoesNotExist($worktrees->pathFor($generation));
    }

    private function worktrees(): TaskWorktrees
    {
        return TaskWorktrees::forProject(
            (new ConfigurationLoader())->fromArray([], $this->project->root()),
        );
    }

    private function base(): BaseCommit
    {
        return BaseCommit::of('HEAD', $this->head);
    }

    private function generation(string $description = 'Add a greeting'): TaskGeneration
    {
        return TaskGeneration::first(TaskId::fromDescription($description));
    }

    private function pathFor(TaskGeneration $generation): string
    {
        return $this->project->path('.ralph/runtime/worktrees/' . $generation->slug());
    }

    private function refusal(TaskGeneration $generation): WorktreeException
    {
        try {
            $this->worktrees()->prepare($generation, $this->base());
        } catch (WorktreeException $exception) {
            return $exception;
        }

        self::fail('the occupied workspace path was accepted');
    }

    /**
     * Everything about the controlling checkout a careless worktree command
     * would have changed.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $root = $this->project->root();

        return [
            'head' => Git::run($root, 'rev-parse', 'HEAD')->standardOutput,
            'branch' => Git::run($root, 'rev-parse', '--abbrev-ref', 'HEAD')->standardOutput,
            'status' => Git::run($root, 'status', '--porcelain=v1')->standardOutput,
            'foo' => (string) file_get_contents($root . '/src/Foo.php'),
            'branches' => Git::run($root, 'for-each-ref', '--format=%(refname) %(objectname)')->standardOutput,
        ];
    }

    /**
     * A repository with one commit and a tracked file, and that commit's SHA.
     */
    private function givenACommittedProject(): string
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        $this->project->write('src/Foo.php', "<?php\n// original\n");
        $this->project->write('.gitignore', "/.ralph/\n");
        Git::run($this->project->root(), 'add', 'src/Foo.php', '.gitignore');

        return Git::commit($this->project->root());
    }

    private static function removeDirectory(string $path): void
    {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? self::removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
