<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\CheckoutException;
use Celyes\RalphPhp\Git\CheckoutInspection;
use Celyes\RalphPhp\Git\ControllingCheckout;
use Celyes\RalphPhp\Git\ManagedPaths;
use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkingTreeStatus;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Inspecting the checkout a run was started in, against real repositories.
 *
 * Every test prepares an actual repository and asks the actual `git`: the
 * subject is what a working tree reports about itself, so a double would only
 * confirm that the double was configured as the test expected — and the format
 * the parser reads is Git's, not this package's.
 *
 * The central test is not any single refusal but
 * {@see self::nothing_about_the_users_changes_is_touched_by_a_refusal()}. The
 * requirement of PROJECT.md section F, item 1 is twofold: unrelated
 * uncommitted changes stop a run, *and* they are not stashed, discarded or
 * committed on the way. The second half is a claim about what did not happen,
 * so it is asserted over the whole repository — contents, index, commit, stash
 * list, reflog — rather than inferred from the absence of a call.
 */
#[CoversClass(ControllingCheckout::class)]
#[CoversClass(CheckoutInspection::class)]
#[CoversClass(Repository::class)]
#[CoversClass(CheckoutException::class)]
final class ControllingCheckoutTest extends TestCase
{
    private TemporaryProject $project;

    private Environment $environment;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this is about a real working tree.');
        }

        $this->project = TemporaryProject::create();
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
        $this->project->remove();
    }

    #[Test]
    public function a_clean_checkout_may_start_a_run(): void
    {
        $this->givenACommittedProject();

        $inspection = $this->checkout()->requireRunnable();

        self::assertTrue($inspection->isRunnable());
        self::assertTrue($inspection->isClean());
        self::assertSame([], $inspection->unrelated());
        self::assertSame([], $inspection->managed());
        self::assertSame($this->project->root() . ' has no uncommitted changes', $inspection->summary());
        self::assertSame([], $inspection->hints());
    }

    #[Test]
    public function an_uncommitted_change_to_the_projects_own_file_stops_the_run(): void
    {
        $this->givenACommittedProject();
        $this->project->write('src/Foo.php', "<?php\n// edited\n");

        $exception = $this->refusal();

        // One change, said as one: the sentence is the first thing a user sees
        // of a refusal, and agreement is the difference between a message that
        // reads as written and one that reads as assembled.
        self::assertStringContainsString(
            '1 uncommitted change that is not part of Ralph PHP\'s own files',
            $exception->getMessage(),
        );
        self::assertStringContainsString('modified: src/Foo.php', $exception->getMessage());
        self::assertSame(78, $exception->exitCode());

        $inspection = self::inspectionOf($exception);
        self::assertFalse($inspection->isRunnable());
        self::assertSame(['src/Foo.php'], self::paths($inspection->unrelated()));
    }

    #[Test]
    public function the_refusal_says_what_the_user_can_do_and_what_the_package_will_not(): void
    {
        $this->givenACommittedProject();
        $this->project->write('src/Foo.php', "<?php\n// edited\n");

        $hints = implode("\n", $this->refusal()->hints());

        self::assertStringContainsString('git stash', $hints);
        self::assertStringContainsString('will not stash, discard or commit them', $hints);
        self::assertStringContainsString('worktree created from the configured base commit', $hints);
        self::assertStringContainsString('git status', $hints);
        self::assertStringContainsString('.gitignore', $hints);
    }

    #[Test]
    public function nothing_about_the_users_changes_is_touched_by_a_refusal(): void
    {
        $this->givenACommittedProject();

        // One of each kind of uncommitted work, so the assertion covers the
        // index as well as the working tree.
        $this->project->write('src/Foo.php', "<?php\n// precious unsaved work\n");
        $this->project->write('src/Added.php', "<?php\n// staged but not committed\n");
        $this->project->write('scratch.txt', "notes I have not committed\n");
        Git::run($this->project->root(), 'add', 'src/Added.php');
        unlink($this->project->path('README.md'));

        $before = $this->snapshot();

        $exception = $this->refusal();

        self::assertSame($before, $this->snapshot(), 'the refusal changed the repository');

        // And the work is still there to be read, not merely unchanged in a
        // listing: the failure this guards against is work tidied away
        // somewhere helpful.
        self::assertStringContainsString(
            'precious unsaved work',
            (string) file_get_contents($this->project->path('src/Foo.php')),
        );
        self::assertStringContainsString(
            'notes I have not committed',
            (string) file_get_contents($this->project->path('scratch.txt')),
        );
        self::assertSame('', trim(Git::run($this->project->root(), 'stash', 'list')->standardOutput));

        self::assertSame(
            ['README.md', 'scratch.txt', 'src/Added.php', 'src/Foo.php'],
            self::sorted(self::paths(self::inspectionOf($exception)->unrelated())),
        );
    }

    #[Test]
    public function an_untracked_file_stops_the_run(): void
    {
        $this->givenACommittedProject();
        $this->project->write('scratch.php', "<?php\n");

        self::assertStringContainsString('untracked file: scratch.php', $this->refusal()->getMessage());
    }

    #[Test]
    public function an_untracked_directory_stops_the_run_as_one_entry(): void
    {
        $this->givenACommittedProject();
        $this->project->write('new-feature/One.php', "<?php\n");
        $this->project->write('new-feature/Two.php', "<?php\n");

        $exception = $this->refusal();

        self::assertStringContainsString('untracked directory: new-feature/', $exception->getMessage());
        self::assertSame(['new-feature/'], self::paths(self::inspectionOf($exception)->unrelated()));
    }

    #[Test]
    public function a_staged_change_stops_the_run(): void
    {
        $this->givenACommittedProject();
        $this->project->write('src/Foo.php', "<?php\n// edited\n");
        Git::run($this->project->root(), 'add', 'src/Foo.php');

        $exception = $this->refusal();

        self::assertStringContainsString('modified (staged): src/Foo.php', $exception->getMessage());
        self::assertTrue(self::inspectionOf($exception)->unrelated()[0]->isStaged());
    }

    #[Test]
    public function an_unresolved_conflict_stops_the_run(): void
    {
        $this->givenACommittedProject();
        $root = $this->project->root();

        Git::run($root, 'checkout', '--quiet', '-b', 'other');
        $this->project->write('src/Foo.php', "<?php\n// theirs\n");
        Git::run($root, 'add', 'src/Foo.php');
        Git::commit($root, 'Theirs');
        Git::run($root, 'checkout', '--quiet', 'main');
        $this->project->write('src/Foo.php', "<?php\n// ours\n");
        Git::run($root, 'add', 'src/Foo.php');
        Git::commit($root, 'Ours');
        Git::tryRun($root, 'merge', 'other');

        $exception = $this->refusal();

        self::assertStringContainsString('unmerged: src/Foo.php', $exception->getMessage());
        self::assertTrue(self::inspectionOf($exception)->unrelated()[0]->isUnmerged());
    }

    #[Test]
    public function a_real_rename_is_reported_with_the_path_it_came_from(): void
    {
        $this->givenACommittedProject();
        Git::run($this->project->root(), 'mv', 'src/Foo.php', 'src/Renamed.php');

        $change = self::inspectionOf($this->refusal())->unrelated()[0];

        self::assertSame('src/Renamed.php', $change->path());
        self::assertSame('src/Foo.php', $change->originalPath());
    }

    #[Test]
    public function the_packages_own_uncommitted_files_do_not_stop_a_run(): void
    {
        $this->givenACommittedProject(trackChecklist: true);

        // What a finished task leaves here: a ticked checkbox in the checklist
        // and runtime data. PROJECT.md section 4 requires these to be told
        // apart from the user's edits, and never committed on their behalf.
        $this->project->write('.ralph/tasks.md', "- [x] Add a greeting\n");
        $this->project->write('.ralph/runtime/logs/TASK-1/worker.log', "started\n");
        $this->project->write('.ralph/config.json.bak', "{}\n");
        $this->project->write('.ralph/CONTEXT.md', "# Conventions\n\nEdited since the commit.\n");

        $head = Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput;

        $inspection = $this->checkout()->requireRunnable();

        self::assertTrue($inspection->isRunnable());
        self::assertFalse($inspection->isClean());
        self::assertSame([], $inspection->unrelated());
        // The runtime directory is ignored here, so Git does not report it at
        // all; everything it does report is the package's.
        self::assertSame(
            ['.ralph/CONTEXT.md', '.ralph/config.json.bak', '.ralph/tasks.md'],
            self::sorted(self::paths($inspection->managed())),
        );
        self::assertStringContainsString('all of them to Ralph PHP\'s own files', $inspection->summary());
        self::assertSame([], $inspection->details());

        // Starting a run does not commit them, which is the other half of the
        // same requirement.
        self::assertSame($head, Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput);
        self::assertStringContainsString(
            '.ralph/tasks.md',
            Git::run($this->project->root(), 'status', '--porcelain')->standardOutput,
        );
    }

    #[Test]
    public function a_project_whose_runtime_data_is_not_ignored_is_still_runnable(): void
    {
        // A project that has not added the ignore rules, or has removed them:
        // Git reports the runtime directory and the checklist as untracked, and
        // neither is the user's work.
        $this->givenACommittedProject(ignoreRuntime: false);
        $this->project->write('.ralph/runtime/state.sqlite', "not really a database\n");

        $inspection = $this->checkout()->requireRunnable();

        self::assertTrue($inspection->isRunnable());
        self::assertSame(
            ['.ralph/runtime/', '.ralph/tasks.md'],
            self::sorted(self::paths($inspection->managed())),
        );
    }

    #[Test]
    public function a_checklist_configured_outside_the_packages_directory_is_still_the_packages(): void
    {
        $this->givenACommittedProject();
        $this->project->write('docs/backlog.md', "- [ ] Something\n");
        Git::run($this->project->root(), 'add', 'docs/backlog.md');
        Git::commit($this->project->root(), 'Track the backlog');
        $this->project->write('docs/backlog.md', "- [x] Something\n");

        $inspection = $this->checkout(['tasks' => 'docs/backlog.md'])->requireRunnable();

        self::assertTrue($inspection->isRunnable());
        self::assertSame(['docs/backlog.md'], self::paths($inspection->managed()));

        // The same edit is the user's own work under the default configuration.
        self::assertFalse($this->checkout()->inspect()->isRunnable());
    }

    #[Test]
    public function a_file_moved_out_of_the_packages_directory_is_the_projects_change(): void
    {
        $this->givenACommittedProject(trackChecklist: true);
        Git::run($this->project->root(), 'mv', '.ralph/tasks.md', 'tasks.md');

        $change = self::inspectionOf($this->refusal())->unrelated()[0];

        self::assertSame('tasks.md', $change->path());
        self::assertSame('.ralph/tasks.md', $change->originalPath());
    }

    #[Test]
    public function a_file_moved_into_the_packages_directory_is_the_projects_change_too(): void
    {
        // The same rule from the other side, and the one that matters more: a
        // source file that has been moved into `.ralph/` is a file the project
        // has lost, and judging the change by its destination alone would let
        // a run start as though nothing had happened to it.
        $this->givenACommittedProject();
        Git::run($this->project->root(), 'mv', 'src/Foo.php', '.ralph/Foo.php');

        $change = self::inspectionOf($this->refusal())->unrelated()[0];

        self::assertSame('.ralph/Foo.php', $change->path());
        self::assertSame('src/Foo.php', $change->originalPath());
    }

    #[Test]
    public function the_projects_changes_are_reported_and_the_packages_are_not_mixed_in(): void
    {
        $this->givenACommittedProject(trackChecklist: true);
        $this->project->write('.ralph/tasks.md', "- [x] Add a greeting\n");
        $this->project->write('src/Foo.php', "<?php\n// edited\n");

        $exception = $this->refusal();
        $inspection = self::inspectionOf($exception);

        self::assertSame(['src/Foo.php'], self::paths($inspection->unrelated()));
        self::assertSame(['.ralph/tasks.md'], self::paths($inspection->managed()));
        self::assertStringNotContainsString('tasks.md', $exception->getMessage());
        self::assertSame(['modified: src/Foo.php'], $inspection->details());
    }

    #[Test]
    public function ignored_files_are_not_the_users_uncommitted_work(): void
    {
        $this->givenACommittedProject();
        $this->project->write('.gitignore', "/.ralph/runtime/\n/.ralph/tasks.md\n/vendor/\n*.log\n");
        Git::run($this->project->root(), 'add', '.gitignore');
        Git::commit($this->project->root(), 'Ignore generated files');

        $this->project->write('vendor/autoload.php', "<?php\n");
        $this->project->write('build.log', "noise\n");
        $this->project->write('.ralph/runtime/state.sqlite', "not really a database\n");

        self::assertTrue($this->checkout()->requireRunnable()->isClean());
    }

    #[Test]
    public function a_repository_configured_to_hide_untracked_files_cannot_hide_them_here(): void
    {
        $this->givenACommittedProject();

        // A real setting, used in large repositories to make `git status` fast.
        // A run that honoured it would call a checkout full of new files clean.
        Git::run($this->project->root(), 'config', 'status.showUntrackedFiles', 'no');
        $this->project->write('scratch.php', "<?php\n");

        self::assertStringContainsString('untracked file: scratch.php', $this->refusal()->getMessage());
    }

    #[Test]
    public function a_path_git_would_otherwise_quote_is_read_as_the_path_it_is(): void
    {
        $this->givenACommittedProject();

        // Without -z, Git prints these inside quotes with escapes, and a
        // non-ASCII character as an octal escape of its bytes. All three are
        // legal filenames, and the refusal has to name them as they are.
        $awkward = ['a file with spaces.php', 'quote".php', 'naïve.php'];

        foreach ($awkward as $name) {
            $this->project->write($name, "<?php\n");
        }

        $exception = $this->refusal();

        self::assertSame(
            self::sorted($awkward),
            self::sorted(self::paths(self::inspectionOf($exception)->unrelated())),
        );

        foreach ($awkward as $name) {
            self::assertStringContainsString('untracked file: ' . $name, $exception->getMessage());
        }
    }

    #[Test]
    public function a_long_list_of_changes_is_counted_exactly_and_listed_in_part(): void
    {
        $this->givenACommittedProject();

        $total = CheckoutInspection::CHANGES_IN_MESSAGE + 3;

        for ($index = 1; $index <= $total; $index++) {
            $this->project->write(sprintf('src/File%02d.php', $index), "<?php\n");
        }

        $inspection = self::inspectionOf($this->refusal());

        self::assertCount($total, $inspection->unrelated());
        self::assertStringContainsString(
            sprintf('%d uncommitted changes that are not part of', $total),
            $inspection->summary(),
        );
        self::assertCount(CheckoutInspection::CHANGES_IN_MESSAGE + 1, $inspection->details());
        self::assertSame('and 3 more', $inspection->details()[CheckoutInspection::CHANGES_IN_MESSAGE]);
    }

    #[Test]
    public function inspecting_reports_a_dirty_checkout_without_refusing_it(): void
    {
        $this->givenACommittedProject();
        $this->project->write('src/Foo.php', "<?php\n// edited\n");

        $inspection = $this->checkout()->inspect();

        self::assertFalse($inspection->isRunnable());
        self::assertSame([
            'working_tree' => $this->project->root(),
            'runnable' => false,
            'clean' => false,
            'unrelated' => [[
                'code' => ' M',
                'path' => 'src/Foo.php',
                'original_path' => null,
                'description' => 'modified: src/Foo.php',
            ]],
            'managed' => [],
        ], $inspection->toArray());
    }

    #[Test]
    public function a_checkout_that_cannot_be_read_stops_the_run_too(): void
    {
        // A directory that is not a repository: the run knows nothing about
        // what is uncommitted in it, and proceeding would be proceeding on an
        // assumption.
        $this->project->write('src/Foo.php', "<?php\n");

        try {
            $this->checkout()->requireRunnable();
            self::fail('a directory that is not a repository was accepted');
        } catch (CheckoutException $exception) {
            self::assertStringContainsString('could not be read', $exception->getMessage());
            self::assertStringContainsString('not a git repository', strtolower($exception->getMessage()));
            self::assertNull($exception->inspection());
            self::assertStringContainsString(
                'will not start a run it cannot tell apart from your own uncommitted work',
                implode("\n", $exception->hints()),
            );
        }
    }

    #[Test]
    public function inspecting_does_not_write_to_the_repositorys_index(): void
    {
        $this->givenACommittedProject();

        // A tracked file whose timestamp no longer matches the index is what
        // makes `git status` want to write a refreshed one, so this is the
        // state in which the difference is observable at all.
        $this->staleTheIndex();
        $index = $this->project->path('.git/index');
        $before = (string) md5_file($index);

        $this->checkout()->requireRunnable();

        self::assertSame($before, md5_file($index), 'inspecting the checkout rewrote the index');

        // And the same command without the option does rewrite it, so the
        // assertion above is about the option rather than about this repository
        // happening never to need a refresh.
        $this->repository()->run(...WorkingTreeStatus::COMMAND);
        self::assertNotSame($before, md5_file($index));
    }

    #[Test]
    public function a_redirected_environment_cannot_point_the_inspection_elsewhere(): void
    {
        $this->givenACommittedProject();
        $this->project->write('src/Foo.php', "<?php\n// edited\n");

        $elsewhere = TemporaryProject::create();

        try {
            Git::initialise($elsewhere->root());
            Git::setIdentity($elsewhere->root());
            Git::commit($elsewhere->root());

            $this->environment->set('GIT_DIR', $elsewhere->path('.git'));
            $this->environment->set('GIT_WORK_TREE', $elsewhere->root());

            $exception = $this->refusal();

            self::assertStringContainsString('modified: src/Foo.php', $exception->getMessage());
            self::assertStringContainsString($this->project->root(), $exception->getMessage());
        } finally {
            $elsewhere->remove();
        }
    }

    #[Test]
    public function the_inspection_runs_the_git_it_was_given(): void
    {
        $this->givenACommittedProject();

        $shim = $this->project->write('tools/git', "#!/bin/sh\nprintf '?? from-the-shim\\0'\n");
        chmod($shim, 0o700);

        $inspection = (new ControllingCheckout(
            Repository::at($this->project->root(), $shim),
            ManagedPaths::forProject($this->configuration()),
        ))->inspect();

        self::assertSame(['from-the-shim'], self::paths($inspection->unrelated()));
    }

    #[Test]
    public function a_repository_handle_needs_an_absolute_directory_that_exists(): void
    {
        $this->givenACommittedProject();

        foreach (['', '   ', 'relative/path', '.'] as $path) {
            try {
                Repository::at($path);
                self::fail(sprintf('accepted "%s" as a repository path', $path));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must be absolute', $exception->getMessage());
            }
        }

        try {
            Repository::at($this->project->path('no/such/directory'));
            self::fail('accepted a directory that does not exist');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('is not a directory that exists', $exception->getMessage());
        }

        try {
            Repository::at($this->project->root(), '  ');
            self::fail('accepted a repository without a git executable');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('needs a git executable', $exception->getMessage());
        }

        // A trailing separator is a spelling of the same directory.
        self::assertSame(
            $this->project->root(),
            Repository::at($this->project->root() . '/')->workingTree(),
        );
    }

    /**
     * An initialised project: a repository with a commit, an identity, source
     * files, and the package's own files as `ralph init` leaves them — the
     * configuration and the context file committed, the checklist and the
     * runtime directory ignored.
     *
     * @param bool $trackChecklist whether the checklist is committed too, which
     *        PROJECT.md section 4 allows users to choose and which is what
     *        makes a ticked checkbox visible to `git status`
     * @param bool $ignoreRuntime whether the ignore rules are in place at all;
     *        a project that declined them has its runtime data reported as
     *        untracked
     */
    private function givenACommittedProject(bool $trackChecklist = false, bool $ignoreRuntime = true): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        $this->project->write('src/Foo.php', "<?php\n// original\n");
        $this->project->write('README.md', "# Project\n");
        $this->project->writeConfigArray([]);
        $this->project->write('.ralph/tasks.md', "- [ ] Add a greeting\n");
        $this->project->write('.ralph/CONTEXT.md', "# Conventions\n");

        $tracked = ['src/Foo.php', 'README.md', '.ralph/config.json', '.ralph/CONTEXT.md'];

        if ($ignoreRuntime) {
            $this->project->write('.gitignore', "/.ralph/runtime/\n/.ralph/tasks.md\n");
            $tracked[] = '.gitignore';
        }

        Git::run($this->project->root(), 'add', ...$tracked);

        if ($trackChecklist) {
            Git::run($this->project->root(), 'add', '--force', '.ralph/tasks.md');
        }

        Git::commit($this->project->root(), 'Initial commit');
    }

    #[Test]
    public function a_command_that_writes_a_tree_may_be_given_longer_than_a_question(): void
    {
        $this->givenACommittedProject();

        // Checking a worktree out copies a whole tree, which can outlast a
        // limit chosen for a rev-parse.
        $result = $this->repository()->runWithin(120, 'rev-parse', 'HEAD');

        self::assertTrue($result->succeeded());
        self::assertSame(40, strlen($result->trimmedOutput()));
    }

    #[Test]
    public function a_timeout_that_is_not_a_limit_is_refused(): void
    {
        $this->givenACommittedProject();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive timeout');

        // Zero means "no limit" to one process runner and "already expired" to
        // another, and neither is what a caller asking for longer meant.
        $this->repository()->runWithin(0, 'rev-parse', 'HEAD');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function checkout(array $settings = []): ControllingCheckout
    {
        return ControllingCheckout::forProject($this->configuration($settings));
    }

    private function repository(): Repository
    {
        return Repository::at($this->project->root());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    /**
     * The refusal a run would be stopped by, failing the test if there is none.
     */
    private function refusal(): CheckoutException
    {
        try {
            $this->checkout()->requireRunnable();
        } catch (CheckoutException $exception) {
            return $exception;
        }

        self::fail('the checkout was accepted');
    }

    /**
     * Everything about the repository a convenience would have changed: the
     * commit, the index, the tracked contents, the untracked paths, the stash
     * and the reflog.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $root = $this->project->root();

        return [
            'head' => Git::run($root, 'rev-parse', 'HEAD')->standardOutput,
            'status' => Git::run($root, 'status', '--porcelain=v1')->standardOutput,
            'staged' => Git::run($root, 'diff', '--cached')->standardOutput,
            'unstaged' => Git::run($root, 'diff')->standardOutput,
            'stash' => Git::run($root, 'stash', 'list')->standardOutput,
            'reflog' => Git::run($root, 'reflog', '--format=%H %gs')->standardOutput,
        ];
    }

    /**
     * Make the index disagree with the working tree's timestamps without
     * changing any content, which is the state `git status` would refresh.
     */
    private function staleTheIndex(): void
    {
        touch($this->project->path('src/Foo.php'), time() - 86400);
        touch($this->project->path('README.md'), time() - 86400);
    }

    private static function inspectionOf(CheckoutException $exception): CheckoutInspection
    {
        $inspection = $exception->inspection();

        self::assertInstanceOf(CheckoutInspection::class, $inspection);

        return $inspection;
    }

    /**
     * @param list<WorkingTreeChange> $changes
     *
     * @return list<string>
     */
    private static function paths(array $changes): array
    {
        return array_map(static fn (WorkingTreeChange $change): string => $change->path(), $changes);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        sort($paths);

        return $paths;
    }
}
