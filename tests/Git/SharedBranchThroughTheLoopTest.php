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
 * The second task of a run stops here, and deliberately: carrying one task's
 * commit forward into the next task's workspace is section K, item 4, and
 * until it exists a second commit would move the branch onto history that does
 * not contain the first task's work. The case asserts the refusal *and* that
 * the first task's commit is still on the branch, which is the half that
 * matters.
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
     * The interim limit of the mode, asserted rather than left to be discovered:
     * the run's second task stops, and everything the first one committed is
     * exactly where it was.
     */
    #[Test]
    public function the_second_task_of_a_shared_run_stops_without_moving_the_branch(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "# Project\n\nThe import command.\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $result = $this->project->ralph(['run']);
        $committed = $this->project->headOf(self::SHARED);

        // The run worked through the checklist and reports what did not pass,
        // which is how a run ends when one of its tasks is refused.
        self::assertStringContainsString('1 of 2 did not pass', $result->allOutput(), $result->describe());
        self::assertStringContainsString(
            'so a commit here would leave ' . substr($committed, 0, 12) . ' unreachable',
            $result->allOutput(),
            $result->describe(),
        );
        self::assertStringContainsString('section K, item 4', $result->allOutput());

        // The first task's work: committed, ticked, and still the tip.
        self::assertCount(1, $this->project->commitsOn(self::SHARED));
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        // The second task's: nothing, and still pending.
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->project->checklist());
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertSame($committed, $this->project->headOf(self::SHARED));
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
     * A second run finds the branch its predecessor left and continues it,
     * rather than starting a branch of its own.
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
            ->withConfiguration(['git' => ['strategy' => 'shared']]);

        $this->project->ralph(['run']);
        $committed = $this->project->headOf(self::SHARED);

        $again = $this->project->ralph(['run']);

        self::assertStringContainsString(
            sprintf('branch ralph/shared (continuing %s)', substr($committed, 0, 12)),
            $again->standardOutput,
            $again->describe(),
        );

        // Still one branch, still one commit on it: a run that could not take
        // the next task on did not take anything away either.
        self::assertSame([self::SHARED], $this->project->taskBranches());
        self::assertSame($committed, $this->project->headOf(self::SHARED));
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
