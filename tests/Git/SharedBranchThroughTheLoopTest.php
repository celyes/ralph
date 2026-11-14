<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\SharedBranch;
use Celyes\RalphPhp\Git\SharedBranches;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A shared-branch run, read out of the repository afterwards.
 *
 * {@see SharedBranchesTest} covers the readings against real branches; this is
 * the same mode driven through a real `ralph` subprocess, so that what is
 * asserted is what a user would see: `git branch`, `git log`, the run's own
 * row, and the branch line the run prints before its first task.
 *
 * Three things are being established, all from PROJECT.md section K, item 3:
 *
 *  - the branch is created **once per run**, not once per task, so a run leaves
 *    one `ralph/shared` and no `ralph/TASK-…` beside it;
 *  - the branch is **validated before an agent starts**, so a name somebody
 *    else's work holds costs nothing but a refusal;
 *  - the branch is **recorded on the run**, so `status` names it and a later
 *    command continues it rather than deriving a name again.
 *
 * Section K, item 4 adds the fourth, and it is the one a user notices: the
 * tasks of a run are committed to that branch **in order**, each built on what
 * the ones before it committed, with a commit kept per task. The cases below
 * assert it from the repository — two commits, one parent chain, and the first
 * task's file inside the second task's commit — rather than from the run's own
 * account of itself.
 */
#[CoversClass(SharedBranches::class)]
#[CoversClass(SharedBranch::class)]
final class SharedBranchThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    private const string SHARED = 'ralph/shared';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and a run commits in real worktrees.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * One branch for the run, the task's commit on it, and the branch recorded
     * against the run that established it.
     */
    #[Test]
    public function a_shared_run_commits_to_one_branch_it_establishes_before_the_first_task(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // Said before the first task, and said once: the branch is established
        // per run, so the line is not repeated per task.
        self::assertSame(1, substr_count($result->standardOutput, 'branch ralph/shared (new)'));

        // One branch, with no task in its name and no task branch beside it.
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertCount(1, $this->project->commitsOn(self::SHARED));
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        // And the branch belongs to the run, in the row a later command reads.
        $run = $this->project->journal()->runs()->latest();

        self::assertNotNull($run);
        self::assertSame(self::SHARED, $run->establishedSharedBranch());
        self::assertSame(self::SHARED, $run->sharedBranch());

        $status = $this->project->ralph(['status']);

        self::assertStringContainsString('one shared branch per run', $status->standardOutput);
        self::assertStringContainsString(self::SHARED, $status->standardOutput);
    }

    /**
     * The carry-forward (PROJECT.md section K, item 4): both tasks commit to
     * the one branch, in order, and the second task's workspace held what the
     * first one committed — which is what the repository says, and the reason
     * the mode exists.
     */
    #[Test]
    public function the_tasks_of_a_shared_run_commit_to_one_branch_in_order(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('2 of 2 tasks completed', $result->allOutput());

        // One branch, still established once, with a commit per task on it.
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertSame(1, substr_count($result->standardOutput, 'branch ralph/shared (new)'));

        $commits = $this->project->commitsOn(self::SHARED);

        self::assertCount(2, $commits);

        // In order: the second task's commit is the tip and the first task's is
        // its parent, which is what "sequentially, on one branch" has to mean.
        self::assertStringContainsString(self::SECOND, $commits[0]);
        self::assertStringContainsString(self::FIRST, $commits[1]);
        self::assertSame(
            $this->project->headOf(self::SHARED . '~1'),
            $this->project->headOf(self::SHARED . '^'),
        );

        // The carry-forward itself: the first task's file is in the tree the
        // second task committed, so its workspace was made from the branch tip
        // rather than from the run's base commit.
        self::assertStringContainsString(
            'from ' . self::SHARED,
            $result->allOutput(),
            $result->describe(),
        );
        self::assertSame(
            self::EDIT,
            $this->contentOf($this->project->headOf(self::SHARED), self::EDITED),
        );

        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
        self::assertStringContainsString('- [x] ' . self::SECOND, $this->project->checklist());
    }

    /**
     * A branch under the shared name that this project never wrote stops the run
     * before an agent is started, and is left exactly where it was.
     */
    #[Test]
    public function a_shared_name_somebody_else_holds_stops_the_run_before_an_agent_starts(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $theirs = $this->givenACommitOn(self::SHARED);
        $checklist = $this->project->checklist();

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());
        self::assertStringContainsString(
            'is not a branch this run may commit to',
            $refused->errorOutput,
            $refused->describe(),
        );
        self::assertStringContainsString(
            'will not reset, move or delete the shared branch',
            $refused->errorOutput,
        );

        // Their commit is where they left it, no agent ran, and the checklist
        // was not touched.
        self::assertSame($theirs, $this->project->headOf(self::SHARED));
        self::assertSame([], $this->project->worker()->attempts());
        self::assertSame($checklist, $this->project->checklist());
    }

    /**
     * A second run finds the branch its predecessor left, continues it rather
     * than starting one of its own, and builds its task on the commit it finds
     * there.
     */
    #[Test]
    public function a_later_run_continues_the_branch_an_earlier_one_committed_to(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            // One task per run, so the second task is a different run's work
            // on the branch the first run established.
            ->withConfiguration(['git' => ['strategy' => 'shared'], 'limits' => ['iterations' => 1]]);

        $this->project->ralph(['run']);
        $committed = $this->project->headOf(self::SHARED);

        $again = $this->project->ralph(['run']);

        self::assertStringContainsString(
            sprintf('branch ralph/shared (continuing %s)', substr($committed, 0, 12)),
            $again->standardOutput,
            $again->describe(),
        );

        // Still one branch, and the second run's commit sits on top of the
        // first run's rather than beside it.
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertSame($committed, $this->parentOf($this->project->headOf(self::SHARED)));
        self::assertSame(
            self::EDIT,
            $this->contentOf($this->project->headOf(self::SHARED), self::EDITED),
        );
    }

    /**
     * Per-task mode is what it was: the setting is the only thing that changes
     * which branches a run leaves behind.
     */
    #[Test]
    public function per_task_mode_still_gives_every_task_a_branch_of_its_own(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['strategy' => 'per-task']]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringNotContainsString('branch ralph/shared', $result->standardOutput);
        self::assertCount(2, $this->project->taskBranches());
        self::assertNotContains(self::SHARED, $this->project->taskBranches());
    }

    /**
     * The commit a commit sits on.
     */
    private function parentOf(string $commit): string
    {
        return trim(Git::run($this->project->root(), 'rev-parse', $commit . '^')->standardOutput);
    }

    /**
     * What a file holds in a commit's tree.
     *
     * The carry-forward is read from this rather than from a file listing: the
     * project starts with `src/Import.php` in it, so what proves the second
     * task was built on the first is the *first task's edit* being in the
     * second task's commit.
     */
    private function contentOf(string $commit, string $path): string
    {
        return Git::run($this->project->root(), 'show', $commit . ':' . $path)->standardOutput;
    }

    /**
     * A commit on a branch, made without checking it out, so the controlling
     * checkout stays where it is.
     */
    private function givenACommitOn(string $branch): string
    {
        $parent = $this->project->headOf('HEAD');
        $tree = trim(Git::run($this->project->root(), 'rev-parse', $parent . '^{tree}')->standardOutput);

        $commit = trim(Git::run(
            $this->project->root(),
            '-c',
            'user.name=Someone Else',
            '-c',
            'user.email=someone@example.com',
            'commit-tree',
            $tree,
            '-p',
            $parent,
            '-m',
            'Their own work on ' . $branch,
        )->standardOutput);

        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . $branch, $commit);

        return $commit;
    }
}
