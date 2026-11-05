<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\StagingException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\ExcludedChange;
use Celyes\RalphPhp\Git\ExclusionReason;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\StagedFile;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\ManagedPaths;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspacePreparer;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkingTreeStatus;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deciding what a verified task's commit will contain, against real
 * repositories and real worktrees.
 *
 * The whole item is about a difference Git itself does not draw — between the
 * agent's work, the package's own files and the local files preparation copied
 * in, all of which are sitting uncommitted in the same directory — so these
 * tests create the worktree with {@see TaskWorktrees} and, where a copied file
 * is the point, put it there with {@see WorkspacePreparer}. A fixture directory
 * would be a directory in which the three kinds had already been told apart.
 *
 * Two assertions recur, and they are the two ways this step can do real harm.
 * The index is read back from Git with `diff --cached --name-only` rather than
 * only from the returned summary, because a summary is a claim and the index is
 * what becomes a commit; and the controlling checkout — its HEAD, its working
 * tree, its own index — is asserted unchanged by everything, including every
 * refusal.
 */
#[CoversClass(WorkspaceStager::class)]
#[CoversClass(StagedChanges::class)]
#[CoversClass(StagedFile::class)]
#[CoversClass(ExcludedChange::class)]
#[CoversClass(ExclusionReason::class)]
#[CoversClass(StagingException::class)]
final class WorkspaceStagerTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this stages changes in real linked worktrees.');
        }

        $this->project = TemporaryProject::create();
        $this->givenACommittedProject();
    }

    protected function tearDown(): void
    {
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
    }

    #[Test]
    public function the_tasks_own_work_is_staged_and_summarised(): void
    {
        $workspace = $this->givenAWorkspace();

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");
        $this->inWorkspace($workspace, 'src/Farewell.php', "<?php\n");
        unlink($workspace->path() . '/README.md');

        $changes = $this->stager()->stage($workspace);

        self::assertFalse($changes->isEmpty());
        self::assertSame($workspace->path(), $changes->workspace());
        self::assertSame(['README.md', 'src/Farewell.php', 'src/Greeting.php'], $this->staged($workspace));
        self::assertSame([], $changes->excluded());

        $byPath = $this->byPath($changes);

        self::assertSame('D', $byPath['README.md']->status());
        self::assertSame(0, $byPath['README.md']->insertions());
        self::assertSame(1, $byPath['README.md']->deletions());
        self::assertSame('A', $byPath['src/Farewell.php']->status());
        self::assertSame('M', $byPath['src/Greeting.php']->status());

        self::assertSame(3, $changes->count());
        self::assertSame(3, $changes->insertions());
        self::assertSame(1, $changes->deletions());
        self::assertStringContainsString('3 file(s) staged', $changes->summary());
        self::assertStringContainsString('+3 -1', $changes->summary());
        self::assertStringContainsString('deleted: README.md (+0, -1)', implode("\n", $changes->details()));
    }

    #[Test]
    public function ralph_phps_own_files_never_reach_a_tasks_commit(): void
    {
        // The configured checklist is outside .ralph as well as in it, because
        // a project may put it anywhere and the exclusion follows the setting.
        $configuration = $this->configuration(['tasks' => 'TASKS.md']);
        $workspace = $this->givenAWorkspace($configuration);

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");
        $this->inWorkspace($workspace, 'TASKS.md', "- [x] Add a greeting\n");
        $this->inWorkspace($workspace, '.ralph/config.json', '{"agent":"codex"}');
        $this->inWorkspace($workspace, '.ralph/CONTEXT.md', "Conventions.\n");

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame(['src/Greeting.php'], $this->staged($workspace));

        // The agent ticking its own box is the case this protects against: the
        // runner ticks it in the controlling checkout, after the commit.
        $excluded = $this->excludedBy($changes);
        self::assertSame(ExclusionReason::Runtime, $excluded['TASKS.md']->reason());
        self::assertSame(ExclusionReason::Runtime, $excluded['.ralph/config.json']->reason());
        self::assertSame(ExclusionReason::Runtime, $excluded['.ralph/CONTEXT.md']->reason());
        self::assertStringContainsString('Ralph PHP\'s own files', $excluded['TASKS.md']->describe());
    }

    #[Test]
    public function a_local_file_copied_into_the_workspace_is_never_committed(): void
    {
        // Put there the way a run puts it there, since the point is that a file
        // the package itself placed in the workspace cannot be committed by it.
        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['./.env']]]);

        $workspace = $this->givenAWorkspace($configuration);
        $preparation = WorkspacePreparer::forProject($configuration)->prepare($workspace);
        self::assertSame(['./.env'], $preparation->copied());

        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame(['src/Greeting.php'], $this->staged($workspace));

        $excluded = $this->excludedBy($changes);
        self::assertSame(ExclusionReason::Local, $excluded['.env']->reason());
        self::assertSame('local', $excluded['.env']->toArray()['reason']);
        self::assertStringContainsString('a local file copied into the workspace', $excluded['.env']->describe());

        // The credential is still there: excluding it from a commit is not
        // deleting it, and the agent may need it for the next attempt.
        self::assertFileExists($workspace->path() . '/.env');
    }

    #[Test]
    public function a_copied_file_is_excluded_on_a_resumed_run_that_did_not_copy_it(): void
    {
        // The exclusion reads the setting, not what preparation did this time:
        // in an adopted workspace the file is reported as already present, and
        // an exclusion that depended on the copy would stop excluding it.
        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]);

        $workspace = $this->givenAWorkspace($configuration);
        $this->inWorkspace($workspace, '.env', "TOKEN=edited-by-the-agent\n");

        $preparation = WorkspacePreparer::forProject($configuration)->prepare($workspace);
        self::assertSame(['.env'], $preparation->alreadyPresent());

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame([], $this->staged($workspace));
        self::assertTrue($changes->isEmpty());
        self::assertSame(ExclusionReason::Local, $this->excludedBy($changes)['.env']->reason());
    }

    #[Test]
    public function a_copied_file_inside_a_wholly_untracked_directory_is_still_excluded(): void
    {
        // Why the workspace is read with --untracked-files=all. Git summarises
        // a directory it knows nothing about as a single entry, and staging
        // that entry whole would commit the credential sitting inside it along
        // with the work beside it.
        $this->project->write('config/local.env', "TOKEN=shhh\n");
        $configuration = $this->configuration([
            'workspace' => ['prepare' => [], 'copy' => ['config/local.env']],
        ]);

        $workspace = $this->givenAWorkspace($configuration);
        WorkspacePreparer::forProject($configuration)->prepare($workspace);
        $this->inWorkspace($workspace, 'config/app.php', "<?php\n");

        // Neither the directory nor anything in it is tracked in the base
        // commit, which is what makes Git want to summarise it.
        self::assertSame([], $this->stagedIn($workspace->path()));

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame(['config/app.php'], $this->staged($workspace));
        self::assertSame(
            ExclusionReason::Local,
            $this->excludedBy($changes)['config/local.env']->reason(),
        );
    }

    #[Test]
    public function a_filename_that_looks_like_a_pattern_is_staged_as_a_filename(): void
    {
        // A path handed to Git is a pathspec, and `[` and `*` are pattern
        // syntax in one. A path that came *from* Git is a filename, so it is
        // passed as one; without that, this file is a pattern matching nothing
        // and Git refuses the whole batch.
        $workspace = $this->givenAWorkspace();

        $this->inWorkspace($workspace, 'src/Options[test].php', "<?php\n");
        $this->inWorkspace($workspace, 'src/Wild*card.php', "<?php\n");

        $changes = $this->stager()->stage($workspace);

        self::assertSame(['src/Options[test].php', 'src/Wild*card.php'], $this->staged($workspace));
        self::assertSame(2, $changes->count());
    }

    #[Test]
    public function an_ignored_path_is_never_seen_and_never_staged(): void
    {
        $workspace = $this->givenAWorkspace();

        // What a preparation command produces, in a project that ignores it.
        $this->inWorkspace($workspace, 'vendor/autoload.php', "<?php\n");
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $changes = $this->stager()->stage($workspace);

        self::assertSame(['src/Greeting.php'], $this->staged($workspace));

        // Not even recorded as excluded: git status does not report it, so the
        // project's own ignore rules did the work and the package added nothing.
        self::assertSame([], $changes->excluded());
    }

    #[Test]
    public function what_the_agent_put_in_the_index_itself_does_not_decide_the_commit(): void
    {
        $workspace = $this->givenAWorkspace();

        $this->project->write('.env', "TOKEN=shhh\n");
        $this->inWorkspace($workspace, '.env', "TOKEN=shhh\n");
        $this->inWorkspace($workspace, 'vendor/autoload.php', "<?php\n");
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        // An agent is free to run git itself, and --force gets an ignored file
        // into the index. Staging starts from a known index, so neither lands.
        Git::run($workspace->path(), 'add', '--force', '.env', 'vendor/autoload.php');

        $changes = WorkspaceStager::forProject(
            $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]),
        )->stage($workspace);

        self::assertSame(['src/Greeting.php'], $this->staged($workspace));
        self::assertSame(['src/Greeting.php'], $changes->paths());

        // Clearing the index moved no files: both are still on disk.
        self::assertFileExists($workspace->path() . '/.env');
        self::assertFileExists($workspace->path() . '/vendor/autoload.php');
    }

    #[Test]
    public function a_deleted_path_that_looks_like_a_pattern_cannot_drag_a_credential_in(): void
    {
        // The case --literal-pathspecs exists for, and the only one in which
        // Git's pathspec matching reaches past the path it was given: when the
        // literal file is absent — which is exactly what a deletion is — Git
        // falls back to matching the name as a glob, and "`.en?`" matches
        // ".env". Staging a deletion would then publish the credential beside
        // it.
        $pattern = '.en?';

        if (@file_put_contents($this->project->path($pattern), "placeholder\n") === false) {
            self::markTestSkipped('This filesystem cannot hold a file named ' . $pattern . '.');
        }

        Git::run($this->project->root(), 'add', '--', $pattern);
        Git::commit($this->project->root(), 'Add a file with an awkward name');

        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]);

        $workspace = $this->givenAWorkspace($configuration);
        WorkspacePreparer::forProject($configuration)->prepare($workspace);

        // What the agent did: removed the file. The credential is untouched.
        unlink($workspace->path() . '/' . $pattern);

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame([$pattern], $this->staged($workspace));
        self::assertSame(ExclusionReason::Local, $this->excludedBy($changes)['.env']->reason());
    }

    #[Test]
    public function a_rename_is_staged_whole_and_reported_with_both_paths(): void
    {
        $workspace = $this->givenAWorkspace();

        Git::run($workspace->path(), 'mv', 'src/Greeting.php', 'src/Salutation.php');

        $changes = $this->stager()->stage($workspace);

        self::assertSame(['src/Greeting.php', 'src/Salutation.php'], $this->staged($workspace));
        self::assertCount(1, $changes->files());

        $file = $changes->files()[0];
        self::assertStringStartsWith('R', $file->status());
        self::assertSame('src/Salutation.php', $file->path());
        self::assertSame('src/Greeting.php', $file->originalPath());
        self::assertSame(['src/Salutation.php', 'src/Greeting.php'], $file->paths());
        self::assertStringContainsString('(was src/Greeting.php)', $file->describe());
    }

    #[Test]
    public function a_file_moved_out_of_an_excluded_path_is_staged_under_its_new_name(): void
    {
        // The documented limit of a path-based exclusion, pinned here so that
        // it is a known behaviour rather than a surprise. Git pairs a rename
        // only in the index, and staging clears the index first, so a move
        // arrives as a deletion of the excluded path plus an untracked file —
        // and the untracked file is not an excluded path.
        $configuration = $this->configuration(['tasks' => 'TASKS.md']);
        $workspace = $this->givenAWorkspace($configuration);

        $this->inWorkspace($workspace, 'TASKS.md', "- [ ] Add a greeting\n");
        Git::run($workspace->path(), 'add', '--', 'TASKS.md');
        Git::run($workspace->path(), 'commit', '--quiet', '--message', 'Track the checklist');

        $this->inWorkspace($workspace, 'docs/.keep', '');
        Git::run($workspace->path(), 'mv', 'TASKS.md', 'docs/TASKS.md');

        $changes = WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame(['docs/.keep', 'docs/TASKS.md'], $this->staged($workspace));

        // The deletion of the excluded path is still excluded, so the commit
        // adds the new file and leaves the old one where it was. What catches
        // this is the staged diff summary and the user's review of the commit.
        self::assertSame(ExclusionReason::Runtime, $this->excludedBy($changes)['TASKS.md']->reason());
    }

    #[Test]
    public function a_rename_git_reports_as_one_change_is_excluded_whole(): void
    {
        // The pairing itself, exercised where Git does produce a pair: the
        // index of a rename it has already recorded. Either half being
        // excluded excludes the change, since staging only the deletion would
        // commit a removal with nothing to replace it.
        $configuration = $this->configuration(['tasks' => 'TASKS.md']);

        $change = WorkingTreeChange::of('R', ' ', '.ralph/tasks.md', 'TASKS.md');
        $project = WorkingTreeChange::of('R', ' ', 'docs/guide.md', 'README.md');

        $status = WorkingTreeStatus::parse(sprintf(
            "R  %s\0%s\0R  %s\0%s\0",
            $change->path(),
            (string) $change->originalPath(),
            $project->path(),
            (string) $project->originalPath(),
        ));

        self::assertCount(2, $status->changes());
        self::assertSame(['.ralph/tasks.md', 'TASKS.md'], $status->changes()[0]->paths());

        // Both halves of the first are Ralph PHP's; the second is the
        // project's and belongs in the commit.
        $managed = ManagedPaths::forProject($configuration);
        self::assertTrue($managed->covers('.ralph/tasks.md'));
        self::assertTrue($managed->covers('TASKS.md'));
        self::assertFalse($managed->covers('docs/guide.md'));
        self::assertFalse($managed->covers('README.md'));
    }

    #[Test]
    public function a_binary_file_is_staged_without_line_counts(): void
    {
        $workspace = $this->givenAWorkspace();

        $this->inWorkspace($workspace, 'logo.png', "\x00\x01\x02binary\n");

        $changes = $this->stager()->stage($workspace);

        $file = $this->byPath($changes)['logo.png'];
        self::assertTrue($file->isBinary());
        self::assertNull($file->insertions());
        self::assertNull($file->deletions());
        self::assertStringContainsString('(binary)', $file->describe());

        // A binary file contributes nothing to the totals rather than zero
        // lines, and the count of files is still right.
        self::assertSame(1, $changes->count());
        self::assertSame(0, $changes->insertions());
        self::assertTrue($changes->toArray()['files'][0]['binary']);
    }

    #[Test]
    public function a_workspace_the_agent_left_untouched_stages_nothing(): void
    {
        $workspace = $this->givenAWorkspace();

        $changes = $this->stager()->stage($workspace);

        self::assertTrue($changes->isEmpty());
        self::assertSame([], $changes->files());
        self::assertSame([], $changes->paths());
        self::assertSame([], $this->staged($workspace));
        self::assertStringContainsString('has nothing staged', $changes->summary());
        self::assertSame([], $changes->details());
    }

    #[Test]
    public function more_paths_than_one_command_takes_are_all_staged(): void
    {
        $workspace = $this->givenAWorkspace();

        $expected = [];

        for ($number = 1; $number <= 250; $number++) {
            $relative = sprintf('src/Generated%03d.php', $number);
            $this->inWorkspace($workspace, $relative, "<?php\n");
            $expected[] = $relative;
        }

        $changes = $this->stager()->stage($workspace);

        self::assertSame($expected, $this->staged($workspace));
        self::assertSame(250, $changes->count());
        self::assertStringContainsString('and 240 more', implode("\n", $changes->details()));
    }

    #[Test]
    public function an_unresolved_conflict_is_refused_with_the_work_left_where_it_is(): void
    {
        $workspace = $this->givenAWorkspace();

        // A task workspace cannot produce this on its own, so it is produced
        // the way something else would: a merge, in the workspace.
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// the task\n");
        Git::run($workspace->path(), 'commit', '--quiet', '--all', '--message', 'The task');

        $this->project->write('src/Greeting.php', "<?php\n\n// somebody else\n");
        Git::run($this->project->root(), 'commit', '--quiet', '--all', '--message', 'Somebody else');

        $merge = Git::tryRun($workspace->path(), 'merge', '--no-edit', $this->defaultBranch());
        self::assertNotSame(0, $merge->exitCode, 'Expected the merge to conflict.');

        try {
            $this->stager()->stage($workspace);
            self::fail('Expected an unresolved conflict to be refused.');
        } catch (StagingException $exception) {
            self::assertStringContainsString('unresolved conflict(s)', $exception->getMessage());
            self::assertStringContainsString('src/Greeting.php', $exception->getMessage());

            $hints = implode("\n", $exception->hints());
            self::assertStringContainsString('Nothing was staged and nothing was committed', $hints);
            self::assertStringContainsString('would be guessing', $hints);
            self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        }

        // The conflict is still a conflict: refusing did not resolve it by
        // staging one side, which is what clearing the index first would do.
        self::assertStringContainsString(
            'UU src/Greeting.php',
            Git::run($workspace->path(), 'status', '--porcelain')->standardOutput,
        );
    }

    #[Test]
    public function the_project_directory_itself_is_never_staged_in(): void
    {
        // Defence in depth: TaskWorktrees cannot produce this, and the cost of
        // being wrong is the user's own uncommitted work in a task's commit.
        $this->project->write('notes.md', "mine\n");

        $root = TaskWorktree::adopted($this->generation(), $this->project->root(), $this->base());

        try {
            $this->stager()->stage($root);
            self::fail('Expected the project directory to be refused.');
        } catch (StagingException $exception) {
            self::assertStringContainsString('cannot be staged in the project directory itself', $exception->getMessage());
            self::assertStringContainsString('linked worktree', implode("\n", $exception->hints()));
        }

        self::assertSame([], $this->stagedIn($this->project->root()));
    }

    #[Test]
    public function a_workspace_that_has_gone_is_reported_rather_than_recreated(): void
    {
        $workspace = $this->givenAWorkspace();
        Git::run($this->project->root(), 'worktree', 'remove', '--force', $workspace->path());

        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('is not there, so its changes cannot be staged');

        $this->stager()->stage($workspace);
    }

    #[Test]
    public function the_decisions_are_written_to_the_attempts_log(): void
    {
        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]);

        $workspace = $this->givenAWorkspace($configuration);
        $this->inWorkspace($workspace, '.env', "TOKEN=shhh\n");
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $sink = new RecordingSink();
        WorkspaceStager::forProject($configuration)->stage($workspace, $sink);

        $notes = implode("\n", $sink->notes);
        self::assertStringContainsString('staging: left out untracked file: .env', $notes);
        self::assertStringContainsString('staging: 1 file(s) staged', $notes);

        // The sink belongs to whoever opened the attempt's log.
        self::assertSame(0, $sink->closes);
    }

    #[Test]
    public function a_workspace_with_nothing_of_its_own_says_so_in_the_log(): void
    {
        $configuration = $this->configuration(['tasks' => 'TASKS.md']);
        $workspace = $this->givenAWorkspace($configuration);
        $this->inWorkspace($workspace, 'TASKS.md', "- [x] Add a greeting\n");

        $sink = new RecordingSink();
        $changes = WorkspaceStager::forProject($configuration)->stage($workspace, $sink);

        self::assertStringContainsString(
            'staging: nothing of the task\'s own to stage',
            implode("\n", $sink->notes),
        );
        self::assertStringContainsString('1 change(s) excluded', $changes->summary());
    }

    #[Test]
    public function the_users_checkout_is_untouched_by_staging_a_task(): void
    {
        $this->project->write('.env', "TOKEN=shhh\n");
        $configuration = $this->configuration(['workspace' => ['prepare' => [], 'copy' => ['.env']]]);

        $workspace = $this->givenAWorkspace($configuration);
        $this->inWorkspace($workspace, '.env', "TOKEN=shhh\n");
        $this->inWorkspace($workspace, 'src/Greeting.php', "<?php\n\n// changed\n");

        $before = $this->snapshotOfTheCheckout();

        WorkspaceStager::forProject($configuration)->stage($workspace);

        self::assertSame($before, $this->snapshotOfTheCheckout());
    }

    /**
     * A repository with one commit and the ignore rules a project has, which is
     * what a task workspace is created from.
     */
    private function givenACommittedProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        // Line endings are pinned so that the line counts asserted here are
        // the same on a checkout configured for CRLF.
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('README.md', "An example project.\n");
        $this->project->write('src/Greeting.php', "<?php\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    /**
     * A real linked worktree for one task, created the way a run creates it.
     */
    private function givenAWorkspace(?Configuration $configuration = null): TaskWorktree
    {
        $configuration ??= $this->configuration();

        return TaskWorktrees::forProject($configuration)->prepare($this->generation(), $this->base());
    }

    /**
     * The branch the project's first commit landed on, which this Git chose.
     */
    private function defaultBranch(): string
    {
        return trim(
            Git::run($this->project->root(), 'rev-parse', '--abbrev-ref', 'HEAD')->standardOutput,
        );
    }

    private function stager(): WorkspaceStager
    {
        return WorkspaceStager::forProject($this->configuration());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    private function base(): BaseCommit
    {
        return BaseResolver::forProject($this->configuration())->resolve();
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first(TaskId::fromDescription('Add a greeting command'));
    }

    /**
     * Write a file in the workspace, the way an agent would.
     */
    private function inWorkspace(TaskWorktree $workspace, string $relative, string $contents): void
    {
        $path = $workspace->path() . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            self::fail('Could not create ' . $directory);
        }

        file_put_contents($path, $contents);
    }

    /**
     * What is actually in the workspace's index, read back from Git.
     *
     * @return list<string>
     */
    private function staged(TaskWorktree $workspace): array
    {
        return $this->stagedIn($workspace->path());
    }

    /**
     * @return list<string>
     */
    private function stagedIn(string $directory): array
    {
        // --no-renames, because what is being asserted is the index's own
        // contents: a rename reported as one path would hide whether the
        // deletion it depends on was staged with it.
        $output = trim(
            Git::run($directory, 'diff', '--cached', '--name-only', '--no-renames')->standardOutput,
        );

        if ($output === '') {
            return [];
        }

        $paths = explode("\n", $output);
        sort($paths);

        return array_map('trim', $paths);
    }

    /**
     * @return array<string, StagedFile>
     */
    private function byPath(StagedChanges $changes): array
    {
        $files = [];

        foreach ($changes->files() as $file) {
            $files[$file->path()] = $file;
        }

        return $files;
    }

    /**
     * @return array<string, ExcludedChange>
     */
    private function excludedBy(StagedChanges $changes): array
    {
        $excluded = [];

        foreach ($changes->excluded() as $change) {
            $excluded[$change->path()] = $change;
        }

        return $excluded;
    }

    /**
     * Everything about the controlling checkout a run must not change: its
     * HEAD, its working tree and its own index.
     *
     * @return array<string, string>
     */
    private function snapshotOfTheCheckout(): array
    {
        return [
            'head' => Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput,
            'status' => Git::run($this->project->root(), 'status', '--porcelain')->standardOutput,
            'index' => Git::run($this->project->root(), 'diff', '--cached', '--name-status')->standardOutput,
        ];
    }
}
