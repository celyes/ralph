<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\ControllingCheckout;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a run does to a repository, read out of the repository afterwards.
 *
 * PROJECT.md section J, item 5: temporary Git repositories and the fixture
 * worker, holding branches, worktrees, verification failures, commits,
 * interruptions and the user's own uncommitted work. Each of the six has a unit
 * suite — {@see BranchNamerTest} and {@see TaskBranchesTest} for names and
 * ownership, {@see TaskWorktreesTest} and {@see WorktreeCleanerTest} for
 * workspaces, {@see WorkspaceStagerTest} and {@see TaskCommitterTest} for what
 * is staged and committed, {@see ControllingCheckoutTest} for the refusal — and
 * {@see \Celyes\RalphPhp\Tests\Run\RunnerTest} drives the loop over them in
 * process.
 *
 * What is left is the part only a real `ralph` subprocess in a real repository
 * can answer, and it is the part a user cares about:
 *
 * - An isolated workspace is only isolated if the agent really wrote in it and
 *   the checkout the user is sitting in never moved.
 * - A commit is only traceable if `git log` holds the identifiers, and only
 *   safe if what the agent did to Ralph PHP's own files stayed out of it.
 * - A failing check is only a failure if nothing reached Git and the work is
 *   still on disk to look at.
 * - A refusal — somebody else's uncommitted change, somebody else's branch —
 *   is only a refusal if the thing it would not touch is byte for byte as it
 *   was, and no agent was started.
 *
 * So the loop here is a subprocess, the checks are real commands that really
 * pass or really fail, and every assertion is something a user could see for
 * themselves: `git log`, `git status`, `git worktree list`, the files on disk,
 * and the rows the next command will believe. No agent CLI, no credentials and
 * no network (section J, item 8).
 */
#[CoversClass(ControllingCheckout::class)]
#[CoversClass(TaskWorktrees::class)]
#[CoversClass(TaskBranches::class)]
#[CoversClass(WorkspaceStager::class)]
#[CoversClass(TaskCommitter::class)]
final class WorkspacesThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    /**
     * The file the worker edits, which exists in the project already, so the
     * commit is a change to the user's code rather than an addition beside it.
     */
    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    /**
     * The worker's own output, which is the moment a Ctrl+C has something to
     * interrupt.
     */
    private const string WORKING = ', mode slow';

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
     * The agent works in a worktree of its own, and the user's checkout does
     * not move.
     *
     * The promise of section F, item 2, asserted from both ends. The agent's
     * end is its journal: the directory it really ran in is under
     * `.ralph/runtime/worktrees/`, not the project root, and the edit it made
     * is in the commit — so the workspace it was given is the one the run
     * committed from.
     *
     * The user's end is the checkout they are sitting in. `HEAD` is the commit
     * it was on before the run, the file the agent rewrote still holds what the
     * user wrote, and the only uncommitted change in the whole checkout is the
     * checkbox the run ticked. That last assertion is deliberately the whole
     * `git status`, not a lookup: a run that had dropped a file, left a
     * half-applied patch or staged something would be caught by it, and nothing
     * weaker would.
     */
    #[Test]
    public function the_agent_works_in_a_worktree_of_its_own_and_the_users_checkout_does_not_move(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $before = $this->project->headOf('HEAD');
        $untouched = (string) file_get_contents($this->project->path(self::EDITED));

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // Where the agent really ran: its own workspace, inside the runtime
        // directory, and not the directory the user is standing in.
        $workspace = $this->project->worker()->attempt()->directory();

        self::assertStringStartsWith(
            $this->project->path('.ralph/runtime/worktrees') . DIRECTORY_SEPARATOR,
            $workspace,
        );
        self::assertNotSame($this->project->root(), $workspace);

        // And what it wrote there is what was committed, content and all. The
        // workspace itself is gone: a completed pass's scratch space is
        // removed, and the commit is where its work lives from then on.
        $branch = $this->branchFor(self::FIRST);
        self::assertSame([self::EDITED], $this->filesIn($branch));
        self::assertSame(self::EDIT, $this->committedContentOf($branch, self::EDITED));
        self::assertDirectoryDoesNotExist($workspace);

        // The user's checkout: same commit, same file, and the tick is the one
        // thing in it that changed.
        self::assertSame($before, $this->project->headOf('HEAD'), 'The run moved the user\'s HEAD.');
        self::assertSame(
            $untouched,
            (string) file_get_contents($this->project->path(self::EDITED)),
            'The agent\'s edit reached the checkout the user is working in.',
        );
        self::assertSame([' M .ralph/tasks.md'], $this->statusOfCheckout());
    }

    /**
     * A branch is named from the prefix, the task and its description, and
     * outlives the workspace it was written in.
     *
     * Section F, item 3. The prefix is the user's own setting rather than the
     * default, so the assertion is that the configured value is what reached
     * Git; the identity half is the task's ID, which is what connects a branch
     * in `git branch` to a line in the checklist; and the readable half is
     * there for the person scanning the list.
     *
     * Two tasks, because one branch cannot show that a name belongs to a task:
     * each gets its own, each starts from the base commit the run recorded
     * rather than from the other's work, and each carries exactly one commit.
     *
     * The last assertion is the one about ownership surviving. A completed
     * pass's workspace is removed (section F, item 10), and the branch must
     * still be there afterwards holding the commit — the workspace is scratch
     * space, the branch is the deliverable.
     */
    #[Test]
    public function a_task_branch_is_named_from_the_prefix_the_task_and_the_description_and_outlives_its_workspace(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['git' => ['branch_prefix' => 'agent/ralph-']]);

        $base = $this->project->headOf('HEAD');

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        foreach ([self::FIRST => self::EDITED, self::SECOND => 'README.md'] as $description => $file) {
            $pass = $this->passFor($description);
            $branch = (string) $pass->branch();
            $identifier = $this->identify($description)->value();

            self::assertStringStartsWith('agent/ralph-' . $identifier . '-', $branch);
            self::assertMatchesRegularExpression('#^agent/ralph-TASK-[0-9A-F]+-[a-z0-9-]+$#', $branch);

            // The readable half is the task's own words, reduced to what every
            // Git and every terminal spells the same way.
            self::assertStringContainsString('import-command', $branch);

            // Each branch is its own line of work: one commit, on the base the
            // pass recorded, holding only that task's file.
            self::assertSame($base, $pass->baseSha());
            self::assertSame($base, $this->parentOf((string) $pass->commitSha()));
            self::assertCount(1, $this->project->commitsOn($branch));
            self::assertSame([$file], $this->filesIn($branch));

            // The branch is what remains: the workspace it was written in has
            // been cleaned up, and the commit is still reachable by name.
            self::assertDirectoryDoesNotExist((string) $pass->worktree()->path());
            self::assertSame($pass->commitSha(), $this->project->headOf($branch));
        }

        // Two tasks, two branches, and every one of them under the prefix the
        // user configured — which is what makes a glob over them possible.
        $expected = [$this->branchFor(self::FIRST), $this->branchFor(self::SECOND)];
        sort($expected);

        self::assertSame($expected, $this->branchesUnder('agent/ralph-'));
        self::assertSame([], $this->project->taskBranches(), 'A branch was created under the default prefix.');
    }

    /**
     * A verified pass is committed once, on its own branch, with the
     * identifiers that trace it — and without the agent's edits to Ralph PHP's
     * own files.
     *
     * Sections F, items 6 and 7. The commit is read out of `git log`, because a
     * row claiming a commit is not a commit: the subject names the task, and
     * the trailers carry the task, the pass, the run, the attempt, the base
     * commit and the agent, which is every identifier a reviewer needs to get
     * from a commit back to the record of why it exists.
     *
     * The exclusion is the other half, and it is why the worker here also
     * writes in `.ralph/`. Both files it touches are tracked, so Git reports
     * them and only staging can leave them out: an agent that ticked its own
     * box must not have that tick committed as if it were verified work, and
     * the settings a run was configured with must not be rewritten by the thing
     * being run. What lands in the commit is the one file the task was about.
     */
    #[Test]
    public function a_verified_pass_is_committed_once_with_the_identifiers_that_trace_it(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([
                self::EDITED => self::EDIT,
                // The agent helping itself to the run's own files.
                '.ralph/tasks.md' => "# Tasks\n\n- [x] " . self::FIRST . "\n",
                '.ralph/config.json' => "{\n}\n",
            ]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $base = $this->project->headOf('HEAD');

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $pass = $this->passFor(self::FIRST);
        $branch = (string) $pass->branch();
        $identifier = $this->identify(self::FIRST)->value();

        self::assertCount(1, $this->project->commitsOn($branch), 'A verified pass commits once.');

        $message = $this->messageOf((string) $pass->commitSha());

        self::assertStringStartsWith($identifier . ': ', $message);
        self::assertStringContainsString(CommitMessage::TASK . ': ' . $identifier, $message);
        self::assertStringContainsString(CommitMessage::GENERATION . ': ' . $pass->number(), $message);
        self::assertStringContainsString(CommitMessage::RUN . ': ' . $pass->runId(), $message);
        self::assertStringContainsString(CommitMessage::ATTEMPT . ': ' . $pass->attempts(), $message);
        self::assertStringContainsString(CommitMessage::BASE . ': ' . $base, $message);
        self::assertStringContainsString(CommitMessage::AGENT . ': fixture', $message);

        // The task's own change, and nothing the agent did to Ralph PHP's files.
        self::assertSame([self::EDITED], $this->filesIn($branch));

        // The tick in the checklist is the run's, written in the controlling
        // checkout after the commit, and it is the only one.
        $checklist = $this->project->checklist();
        self::assertStringContainsString('- [x] ' . self::FIRST, $checklist);
        self::assertSame(1, substr_count($checklist, '- [x]'));
    }

    /**
     * A check that really fails leaves the work in its worktree and nothing in
     * the repository.
     *
     * The verification command here is a real process that really exits 1, so
     * the case is about a failing check rather than about a verifier that was
     * told to say no. What has to be true afterwards is a conjunction, and all
     * of it is visible from outside: the box is not ticked, no commit was made,
     * and the agent's work is still sitting in its workspace for a person to
     * look at (section F, item 10).
     *
     * The workspace is asserted to be a worktree Git itself knows about, with
     * the pass's branch checked out in it — that is what makes it inspectable
     * with ordinary commands, which is the whole reason it is kept.
     *
     * The check's own output is asserted to have survived into the evidence,
     * because the next attempt is given it (section G, item 4) and a user reads
     * it with `ralph logs`.
     */
    #[Test]
    public function a_check_that_really_fails_keeps_the_work_in_its_worktree_and_leaves_the_repository_alone(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['checks' => [[
                'name' => 'tests',
                'command' => ['php', '-r', 'fwrite(STDERR, "FAILED: no --dry-run option\n"); exit(1);'],
                'timeout' => 30,
            ]]]);

        $base = $this->project->headOf('HEAD');

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());

        // Nothing reached Git: no commit on the branch, and the checkout the
        // user is in is where it was.
        $pass = $this->passFor(self::FIRST);
        $branch = (string) $pass->branch();

        self::assertNull($pass->commitSha());
        self::assertSame([], $this->project->commitsOn($branch));
        self::assertSame($base, $this->project->headOf('HEAD'));
        self::assertSame([], $this->statusOfCheckout(), 'A failed pass changed the user\'s checkout.');
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());

        // The work is still there, in a worktree Git knows about, with the
        // pass's branch checked out in it.
        $workspace = (string) $pass->worktree()->path();

        self::assertDirectoryExists($workspace);
        self::assertSame(self::EDIT, (string) file_get_contents($workspace . '/' . self::EDITED));
        self::assertContains($workspace, $this->registeredWorktrees());
        self::assertSame($branch, $this->branchCheckedOutIn($workspace));

        // And what the check said is on the attempt, for the next one and for
        // the person reading the logs.
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Verifying, $pass->phase());

        $attempt = $this->project->journal()->attempts()->latestFor($pass);
        self::assertNotNull($attempt);
        self::assertStringContainsString(
            'FAILED: no --dry-run option',
            (string) $attempt->evidence()?->toMarkdown(),
        );
    }

    /**
     * A run stopped with Ctrl+C keeps its workspace and its branch, and the
     * resume commits once.
     *
     * The interruption is real: a `ralph run` subprocess, signalled while the
     * agent is working, which is the only way to produce the half-finished
     * state the recovery exists for. What it must leave behind is work nobody
     * has thrown away and nothing Git has been told about yet — the workspace
     * on disk, no commit, the user's checkout untouched.
     *
     * Then the resume, and the assertion that matters most in this suite: one
     * commit. A recovery that re-implemented the task or committed the same
     * work twice would still end with a ticked box and a green run, and the
     * only place the duplication would show is the branch's log.
     */
    #[Test]
    public function a_run_stopped_with_ctrl_c_keeps_its_workspace_and_the_resume_commits_once(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped(
                'The pcntl extension is not installed, so a signalled run cannot record its interruption.',
            );
        }

        $this->project
            ->withWorker(
                FixtureStep::slow(30),
                FixtureStep::success([self::EDITED => self::EDIT]),
            )
            ->withTasks([self::FIRST])
            ->withConfiguration(['limits' => ['attempts_per_task' => 2]]);

        $base = $this->project->headOf('HEAD');

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());

        $stopped = $this->passFor(self::FIRST);
        $workspace = (string) $stopped->worktree()->path();

        self::assertSame(LifecycleState::Interrupted, $stopped->state());
        self::assertNull($stopped->commitSha());
        self::assertDirectoryExists($workspace);
        self::assertContains($workspace, $this->registeredWorktrees());
        self::assertSame($base, $this->project->headOf('HEAD'));
        self::assertSame([], $this->statusOfCheckout(), 'An interrupted run left the user\'s checkout changed.');
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());

        $resumed = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::SUCCESS, $resumed->exitCode, $resumed->describe());

        $finished = $this->passFor(self::FIRST);
        $branch = (string) $finished->branch();

        // The same pass, carried on in the same workspace rather than started
        // again somewhere else: the second attempt the agent made was in the
        // directory the interrupted one kept, which the agent's own journal
        // says and the row no longer can — a completed pass has released it.
        self::assertSame($stopped->rowId(), $finished->rowId());

        $attempts = $this->project->worker()->attempts();

        self::assertCount(2, $attempts);
        self::assertSame(
            $workspace,
            $attempts[1]->directory(),
            'The resume did not continue in the workspace the interrupted pass kept.',
        );
        self::assertSame(LifecycleState::Completed, $finished->state());
        self::assertCount(1, $this->project->commitsOn($branch), 'The resumed pass committed twice.');
        self::assertSame([self::EDITED], $this->filesIn($branch));
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * Uncommitted work in the user's checkout refuses the run, and is still
     * there afterwards exactly as it was.
     *
     * Section F, item 1, and the reason it is a refusal rather than a
     * convenience: stashing, discarding and committing somebody else's work are
     * each one command away, and each of them can lose it. So the assertions
     * are about the file — byte for byte, still uncommitted, still the only
     * change in the checkout — and about the agent, which was never started.
     *
     * Then the two things that must *not* block, because a run that refused
     * them would be unusable: a path the project ignores, and a change to Ralph
     * PHP's own files, which are read from this checkout rather than missed by
     * the agent. The run that follows carries the task through, which is how
     * the case shows that the refusal was about the user's work specifically.
     */
    #[Test]
    public function unrelated_uncommitted_work_refuses_the_run_and_is_left_exactly_as_it_was(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $mine = "<?php\n\n// half-finished work of my own\n";
        file_put_contents($this->project->path(self::EDITED), $mine);

        $checklist = $this->project->checklist();

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());
        self::assertStringContainsString(
            'that are not part of Ralph PHP\'s own files',
            $refused->errorOutput,
            $refused->describe(),
        );
        self::assertStringContainsString(self::EDITED, $refused->errorOutput);
        self::assertStringContainsString('will not stash, discard or commit them', $refused->errorOutput);

        // The work is untouched: same bytes, still uncommitted, and the
        // checkout holds nothing else new.
        self::assertSame($mine, (string) file_get_contents($this->project->path(self::EDITED)));
        self::assertSame([' M ' . self::EDITED], $this->statusOfCheckout());
        self::assertSame($checklist, $this->project->checklist());

        // And nothing was started on top of it.
        self::assertSame([], $this->project->worker()->attempts());
        self::assertSame([], $this->project->taskBranches());
        self::assertDirectoryDoesNotExist($this->project->path('.ralph/runtime/worktrees'));

        // An ignored path and a change to Ralph PHP's own files are not the
        // user's unrelated work, and do not stop anything.
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'My own work');

        mkdir($this->project->path('vendor'), 0o700, true);
        file_put_contents($this->project->path('vendor/installed.php'), "<?php\n");
        $this->project->editChecklist($checklist . "\n<!-- a note to myself -->\n");

        $allowed = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $allowed->exitCode, $allowed->describe());
        self::assertCount(1, $this->project->worker()->attempts());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
        self::assertStringContainsString('<!-- a note to myself -->', $this->project->checklist());
    }

    /**
     * A branch under the name a pass would take, belonging to somebody else, is
     * refused rather than moved.
     *
     * Section F, item 4. The collision is not a mistake anybody made: a
     * generated name is derived from the task, so a person who branched for the
     * same task by hand — or a previous experiment under the same prefix — owns
     * the name the run would pick. The name is computed here with the package's
     * own {@see BranchNamer}, so the case is about what the run does when it
     * finds the name taken rather than about guessing a spelling.
     *
     * What must be true afterwards is that the other branch is exactly where it
     * was, commit and all, and that the refusal said whose it is and what the
     * user can do. `--force`, `reset --hard` and `branch -D` are each one
     * command away and each would put a commit out of reach.
     */
    #[Test]
    public function a_branch_that_belongs_to_somebody_else_is_refused_rather_than_moved(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        // The branch the pass would name, created by hand with work on it.
        $name = (new BranchNamer())
            ->nameFor(TaskGeneration::first($this->identify(self::FIRST)), self::FIRST)
            ->value();

        Git::run($this->project->root(), 'branch', $name);
        file_put_contents($this->project->path('src/Mine.php'), "<?php\n\n// mine\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'My own start on this task');
        $theirs = $this->project->headOf('HEAD');
        Git::run($this->project->root(), 'branch', '--force', $name, $theirs);
        Git::run($this->project->root(), 'reset', '--hard', 'HEAD~1');

        $result = $this->project->ralph(['run']);

        self::assertStringContainsString(
            $name . ' is not the branch of ' . $this->identify(self::FIRST)->value()
                . ' to use: it already exists and no pass at this task records it',
            $result->allOutput(),
            $result->describe(),
        );
        // The branch is where it was, with its commit still on it, and the
        // task was not carried out somewhere else instead.
        self::assertSame($theirs, $this->project->headOf($name));
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());
        self::assertSame([], $this->project->worker()->attempts(), 'An agent was started on a branch collision.');

        // No workspace was prepared either: the name is checked before the
        // worktree is created, so there is nothing to clean up and nothing of
        // the user's that was copied anywhere.
        $pass = $this->passFor(self::FIRST);

        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertNull($pass->worktree()->path());
        self::assertNotNull($pass->blocker());
        self::assertStringContainsString('is not the branch of', $pass->blocker()->reason());

        // What a user asking about the task is told: the pass that never
        // started, and the branch as the reason. The remedies the package has
        // for this — rename the branch, remove it, configure another prefix —
        // are not among them; see the note in PROGRESS.md.
        $logs = $this->project->ralph(['logs', $this->identify(self::FIRST)->value()]);

        self::assertSame(ExitCode::SUCCESS, $logs->exitCode, $logs->describe());
        self::assertStringContainsString('no attempt was started', $logs->standardOutput);
        self::assertStringContainsString(
            'is not the branch of ' . $this->identify(self::FIRST)->value() . ' to use',
            $logs->standardOutput,
            $logs->describe(),
        );

        // The run's own status, which is the documented mapping rather than a
        // comfortable one: the refusal spent no attempt, so the loop reached
        // the end of the checklist with a task it had not given up on, and
        // that is StopKind::Finished — exit 0 — even though the only task in
        // the list did not pass and no further `ralph run` can change that
        // while the branch stands. Pinned here because it is what a script
        // calling `ralph run` sees today.
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('1 of 1 did not pass', $result->standardOutput);
    }

    /**
     * The identifier the loop knows a task by, which comes from its words.
     */
    private function identify(string $description): TaskId
    {
        return TaskId::fromDescription($description);
    }

    /**
     * The newest pass at a task, where its state, branch and commit live.
     */
    private function passFor(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor($this->identify($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }

    /**
     * The branch a task's pass owns.
     */
    private function branchFor(string $description): string
    {
        return (string) $this->passFor($description)->branch();
    }

    /**
     * The files a branch adds to the project, which is the work in its commit.
     *
     * @return list<string>
     */
    private function filesIn(string $branch): array
    {
        if ($branch === '') {
            return [];
        }

        $diff = Git::tryRun($this->project->root(), 'diff', '--name-only', 'HEAD..' . $branch);

        return $diff->exitCode === 0 ? $diff->standardOutputLines() : [];
    }

    /**
     * A file's contents as they were committed on a branch.
     */
    private function committedContentOf(string $branch, string $file): string
    {
        return Git::run($this->project->root(), 'show', $branch . ':' . $file)->standardOutput;
    }

    /**
     * The branches in the repository under a prefix, in a stable order.
     *
     * For the prefix a case configured, which {@see LoopProject::taskBranches()}
     * cannot answer: it looks for the package's default.
     *
     * @return list<string>
     */
    private function branchesUnder(string $prefix): array
    {
        $branches = Git::run(
            $this->project->root(),
            'branch',
            '--list',
            $prefix . '*',
            '--format=%(refname:short)',
        )->standardOutputLines();

        sort($branches);

        return $branches;
    }

    /**
     * Everything uncommitted in the checkout the user is sitting in.
     *
     * The porcelain lines rather than a lookup: a case asserting the whole set
     * catches a file the run dropped, staged or left half-applied, and a case
     * asking about one path does not.
     *
     * @return list<string>
     */
    private function statusOfCheckout(): array
    {
        return Git::run($this->project->root(), 'status', '--porcelain')->standardOutputLines();
    }

    /**
     * The commit a commit sits on top of.
     */
    private function parentOf(string $commit): string
    {
        return trim(Git::run($this->project->root(), 'rev-parse', $commit . '^')->standardOutput);
    }

    /**
     * A commit's message in full, subject and trailers.
     */
    private function messageOf(string $commit): string
    {
        return Git::run($this->project->root(), 'log', '-1', '--format=%B', $commit)->standardOutput;
    }

    /**
     * The worktrees Git itself has registered for this repository.
     *
     * @return list<string>
     */
    private function registeredWorktrees(): array
    {
        $paths = [];

        foreach ($this->worktreeList() as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $paths[] = substr($line, strlen('worktree '));
            }
        }

        return $paths;
    }

    /**
     * The branch checked out in a registered worktree, or '' when none is.
     */
    private function branchCheckedOutIn(string $path): string
    {
        $found = false;

        foreach ($this->worktreeList() as $line) {
            if ($line === 'worktree ' . $path) {
                $found = true;

                continue;
            }

            if ($found && str_starts_with($line, 'branch ')) {
                return (string) preg_replace('#^refs/heads/#', '', substr($line, strlen('branch ')));
            }

            if ($found && $line === '') {
                break;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function worktreeList(): array
    {
        return Git::run($this->project->root(), 'worktree', 'list', '--porcelain')->standardOutputLines();
    }
}
