<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\TaskPublisher;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\Phase;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Optional pushes, read out of the repository they were pushed to.
 *
 * PROJECT.md section J, item 6: a local bare Git remote, rejected pushes, and a
 * publication-only retry. {@see TaskPublisherTest} holds the publisher itself to
 * all three against a real bare remote, and
 * {@see \Celyes\RalphPhp\Tests\Run\RunnerTest} drives the loop over a refused
 * push and its retry in process. What is left is what only a real `ralph`
 * subprocess can answer, and it is what a user who turned pushing on actually
 * sees:
 *
 * - whether the commit is in the *other* repository afterwards, and only that
 *   branch of it;
 * - whether a remote that rejects the push leaves the local work whole, the box
 *   as the user left it, and a status that says what is outstanding;
 * - whether a retry really does push the same commit without the agent, a
 *   second attempt or a second commit — and whether the command the refusal
 *   tells the user to run is the one that does it;
 * - and what the process exits with in each case, which is the part a script
 *   calling `ralph run` reads.
 *
 * The remote is a bare repository on the same disk: the real `git push`, the
 * real fast-forward rule and the real rejection text, with no network and no
 * credentials (section J, item 8). Every assertion about publication is read
 * from the remote repository rather than from the row that claims it or from
 * the pushing side's `refs/remotes/`, because a publication that did not arrive
 * is exactly the failure being ruled out. An authentication failure and a slow
 * link are the two cases a bare remote cannot produce, and they are not
 * asserted here.
 */
#[CoversClass(TaskPublisher::class)]
final class PublicationThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    /**
     * The file the worker edits, which the project already has, so what reaches
     * the remote is a change to the user's code rather than an addition beside
     * it.
     */
    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this pushes between real repositories.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * A verified task's commit reaches the remote, and the box is ticked.
     *
     * Section F, item 8's ordinary path, and acceptance criterion 5. Three
     * things are read out of the bare repository rather than out of the row:
     * the branch is there, it points at the commit the run recorded, and the
     * file on it holds what the agent wrote — so the push sent the
     * implementation and not merely a reference. The fourth assertion is the
     * set of branches, which is how "only the task branch was published" is
     * stated: a push that had sent `main` as well would pass the first three.
     */
    #[Test]
    public function a_verified_tasks_commit_reaches_the_remote_and_the_box_is_ticked(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withBareRemote()
            ->withConfiguration($this->pushingTo('origin'));

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $pass = $this->passFor(self::FIRST);
        $branch = (string) $pass->branch();
        $sha = (string) $pass->commitSha();

        self::assertSame([$branch], $this->project->remoteBranches(), 'The push sent more than the task branch.');
        self::assertSame($sha, $this->project->remoteHeadOf($branch));
        self::assertSame(self::EDIT, $this->contentInTheRemote($branch, self::EDITED));

        // The row agrees with the repository, and the task is finished.
        self::assertSame(PushState::Published, $pass->push()->state());
        self::assertSame('origin', $pass->push()->remote());
        self::assertNull($pass->push()->error());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
        self::assertSame(
            Completion::RunnerVerified,
            $this->project->journal()->tasks()->get($this->identify(self::FIRST))->completion(),
        );

        // The completion event belongs after the push, not beside the commit
        // (section I, item 4): by the time another program reads it, the branch
        // is on the remote.
        self::assertSame('completed', $this->outcomeOfTheFinishedTask());
    }

    /**
     * Pushing is opt-in: a project with a remote and no `git.push.enabled`
     * publishes nothing.
     *
     * The word in the item is *optional*, and the failure mode is a package
     * that pushes because a remote happens to exist. The remote is configured
     * and reachable here, so the only reason nothing arrives is that nothing
     * was asked for. The task still completes, which is the other half: a
     * publication that was never required cannot be a condition of the tick.
     */
    #[Test]
    public function nothing_is_published_unless_the_configuration_asks_for_it(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withBareRemote()
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame([], $this->project->remoteBranches(), 'A push was made without being asked for.');

        $pass = $this->passFor(self::FIRST);

        self::assertSame(PushState::NotRequired, $pass->push()->state());
        self::assertNotNull($pass->commitSha(), 'The commit was still made locally.');
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * A push the remote rejects keeps the commit, leaves the box alone, and says
     * what is outstanding.
     *
     * The rejection is a real non-fast-forward: somebody has already pushed
     * something of their own under the name this pass takes, which is the
     * collision a generated branch name invites. Git refuses it, and the three
     * things that have to survive that are each read from a different place —
     * the local branch still at the recorded commit, the *remote* branch still
     * at the commit it had, and the checklist still as the user wrote it.
     *
     * Then the part a person needs in order to act: `ralph status` names the
     * outstanding push and the next action, and the process exits non-zero, so
     * a script does not read a refused publication as a finished checklist.
     */
    #[Test]
    public function a_push_the_remote_rejects_keeps_the_commit_local_and_the_box_unticked(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withBareRemote()
            ->withConfiguration($this->pushingTo('origin'));

        $theirs = $this->givenTheRemoteAlreadyHas($this->branchFor(self::FIRST));

        $result = $this->project->ralph(['run']);

        $pass = $this->passFor(self::FIRST);
        $branch = (string) $pass->branch();
        $sha = (string) $pass->commitSha();

        // The work: committed locally, and still the only commit on its branch.
        self::assertSame($sha, $this->project->headOf($branch));
        self::assertCount(1, $this->project->commitsOn($branch));
        self::assertSame(self::EDIT, $this->committedContentOf($branch, self::EDITED));

        // The remote: exactly as its owner left it.
        self::assertSame($theirs, $this->project->remoteHeadOf($branch));
        self::assertSame([$branch], $this->project->remoteBranches());

        // The checklist: as the user wrote it.
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());

        // The row: the push failed, with the reason Git gave, and it is the
        // only thing the pass still owes.
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Publishing, $pass->phase());
        self::assertSame(PushState::Failed, $pass->push()->state());
        self::assertStringContainsString('rejected', (string) $pass->push()->error());
        self::assertTrue($pass->awaitsPublication());
        self::assertFalse($pass->isFinalizable());

        // What the run told the person watching it, and what it exited with.
        self::assertStringContainsString('the push to origin was refused', $result->allOutput(), $result->describe());
        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());

        // And what `ralph status` tells somebody who arrives afterwards: the
        // push named as outstanding, the reason, and a status of its own —
        // {@see ExitCode::EXECUTION}, because the project is holding a failed
        // pass now rather than merely having held one.
        $status = $this->project->ralph(['status']);

        self::assertSame(ExitCode::EXECUTION, $status->exitCode, $status->describe());
        self::assertStringContainsString('push push to "origin" failed', $status->standardOutput);
        self::assertStringContainsString(
            'stopped while pushing the commit to the remote',
            $status->standardOutput,
            $status->describe(),
        );

        $document = JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput);

        self::assertSame(PushState::Failed->value, $document->string('tasks.0.push'));
        self::assertSame($sha, $document->string('tasks.0.commit'));
        self::assertFalse($document->boolean('tasks.0.checked'));
    }

    /**
     * The item itself: a resume pushes the same commit and nothing else
     * happens.
     *
     * Section H, item 7, through a real subprocess — and through the command
     * that actually does it, which is not the one the refusal names.
     * The agent is given no further step on purpose — a resume that went back to
     * implementing would fail rather than quietly produce a second version — and
     * what is asserted is the whole shape of "only the push was retried": one
     * invocation of the agent in total, one attempt, one commit on the branch,
     * one checklist write, and the commit on the remote is the one the first run
     * made.
     */
    #[Test]
    public function a_pass_owing_only_its_publication_is_finished_by_retrying_the_push_alone(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withBareRemote()
            ->withConfiguration($this->pushingTo('origin'));

        $branch = $this->branchFor(self::FIRST);
        $this->givenTheRemoteAlreadyHas($branch);

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $refused->exitCode, $refused->describe());

        $committed = (string) $this->passFor(self::FIRST)->commitSha();

        // The person resolves the remote: the branch that was in the way is
        // theirs to remove, and removing it is what makes the push a
        // fast-forward of nothing.
        Git::run($this->project->remote(), 'update-ref', '-d', 'refs/heads/' . $branch);

        // `ralph resume` is what the refusal, the status report and `ralph
        // resume --help` all tell the user to run, and with the remote now
        // resolved it refuses: the run that owed the push ended on its own
        // terms, so its row says completed and there is nothing in it to
        // continue. Pinned rather than fixed — the capability is reachable
        // after an interruption and only this route into it is missing — and
        // recorded as a defect in PROGRESS.md, because it is the one
        // instruction a user in this position is given.
        $resumed = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::CONFIGURATION, $resumed->exitCode, $resumed->describe());
        self::assertStringContainsString('there is nothing in it to resume', $resumed->errorOutput);
        self::assertNull($this->project->remoteHeadOf($branch), 'The refused resume pushed anyway.');

        // What does finish it: another `ralph run`, which takes the task on,
        // finds the pass owing only its publication, and goes no further back
        // than the push.
        $retried = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $retried->exitCode, $retried->describe());
        self::assertStringContainsString('retrying only the push', $retried->standardOutput, $retried->describe());

        // The same commit, read out of the remote.
        self::assertSame($committed, $this->project->remoteHeadOf($branch));
        self::assertSame(self::EDIT, $this->contentInTheRemote($branch, self::EDITED));

        // Nothing was done twice.
        self::assertCount(1, $this->project->worker()->attempts(), 'The agent was invoked again.');
        self::assertCount(1, $this->project->commitsOn($branch), 'A second commit was made.');
        self::assertSame([$branch], $this->project->taskBranches(), 'A second branch was created.');

        $pass = $this->passFor(self::FIRST);

        self::assertSame($committed, $pass->commitSha());
        self::assertSame(1, $pass->attempts());
        self::assertSame(1, $pass->generation()->number());
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNull($pass->phase());
        self::assertSame(PushState::Published, $pass->push()->state());
        self::assertNull($pass->push()->error(), 'The refusal is still on the row.');
        self::assertNull($pass->blocker());

        // The box, written once, by the run that could finish the pass.
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
        self::assertCount(1, $this->project->journal()->checklists()->forGeneration($pass));

        // And the completion the stream announces belongs to the resumed run:
        // the first one finished without announcing one (section I, item 4).
        self::assertSame('completed', $this->outcomeOfTheFinishedTask());
    }

    /**
     * A remote that refuses a second time leaves the pass owing the same push
     * and nothing more.
     *
     * The failure this rules out is a retry that reads "the push failed again"
     * as "the task failed", goes back to the agent and commits the work twice.
     * The branch in the way is left in place, so the second push is refused for
     * the reason the first one was, and the pass is still retryable afterwards:
     * the attempt limit is not spent on somebody else's server.
     */
    #[Test]
    public function a_remote_that_refuses_again_leaves_the_same_push_outstanding(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withBareRemote()
            ->withConfiguration($this->pushingTo('origin'));

        $branch = $this->branchFor(self::FIRST);
        $theirs = $this->givenTheRemoteAlreadyHas($branch);

        $this->project->ralph(['run']);
        $committed = (string) $this->passFor(self::FIRST)->commitSha();

        $again = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $again->exitCode, $again->describe());
        self::assertStringContainsString('retrying only the push', $again->standardOutput, $again->describe());

        // Nothing moved: not the remote, not the branch, not the checklist.
        self::assertSame($theirs, $this->project->remoteHeadOf($branch));
        self::assertSame($committed, $this->project->headOf($branch));
        self::assertCount(1, $this->project->commitsOn($branch));
        self::assertCount(1, $this->project->worker()->attempts());
        self::assertStringContainsString('- [ ] ' . self::FIRST, $this->project->checklist());

        $pass = $this->passFor(self::FIRST);

        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertSame(Phase::Publishing, $pass->phase());
        self::assertSame(1, $pass->attempts(), 'An attempt was spent on the remote.');
        self::assertTrue($pass->awaitsPublication(), 'The push is no longer what the pass owes.');
    }

    /**
     * The pass recorded for a task, as the next command would read it.
     */
    private function passFor(string $description): GenerationRecord
    {
        $journal = $this->project->journal();
        $record = $journal->generations()->latestFor($this->identify($description));

        self::assertNotNull($record, 'No pass was recorded for: ' . $description);

        return $record;
    }

    /**
     * The identifier the loop knows a task by, which comes from its words.
     */
    private function identify(string $description): TaskId
    {
        return TaskId::fromDescription($description);
    }

    /**
     * The branch a first pass at a task will take, named with the package's own
     * namer so that a case is about the collision rather than about guessing a
     * spelling.
     */
    private function branchFor(string $description): string
    {
        return (new BranchNamer())
            ->nameFor(TaskGeneration::first($this->identify($description)), $description)
            ->value();
    }

    /**
     * Put a commit of somebody else's on the remote, under the name this pass
     * will want, and return its SHA.
     *
     * Pushed from a branch of its own and then forgotten locally: what the run
     * meets is a remote branch that is not an ancestor of the commit it will
     * make, which is the non-fast-forward Git refuses. Leaving a local branch of
     * that name behind would be a different case — section F, item 4's refusal,
     * before any agent runs — and it is held by
     * {@see WorkspacesThroughTheLoopTest}.
     */
    private function givenTheRemoteAlreadyHas(string $branch): string
    {
        $root = $this->project->root();

        Git::run($root, 'checkout', '--quiet', '-b', 'theirs');
        file_put_contents($this->project->path('src/Mine.php'), "<?php\n\n// mine\n");
        Git::run($root, 'add', '--all');
        $sha = Git::commit($root, 'My own start on this task');

        Git::run($root, 'push', '--quiet', 'origin', 'theirs:refs/heads/' . $branch);

        Git::run($root, 'checkout', '--quiet', '-');
        Git::run($root, 'branch', '--quiet', '--delete', '--force', 'theirs');

        return $sha;
    }

    /**
     * A file's contents on a branch of the remote repository.
     */
    private function contentInTheRemote(string $branch, string $file): string
    {
        return Git::run($this->project->remote(), 'show', $branch . ':' . $file)->standardOutput;
    }

    /**
     * A file's contents as they were committed on a local branch.
     */
    private function committedContentOf(string $branch, string $file): string
    {
        return Git::run($this->project->root(), 'show', $branch . ':' . $file)->standardOutput;
    }

    /**
     * The outcome carried by the last task event of the stream, which is where
     * a completion is announced once it is durable.
     */
    private function outcomeOfTheFinishedTask(): ?string
    {
        $outcome = null;

        foreach ($this->project->events() as $event) {
            if ($event->string('event') === 'task.finished') {
                $outcome = $event->string('outcome');
            }
        }

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function pushingTo(string $remote): array
    {
        return ['git' => ['push' => ['enabled' => true, 'remote' => $remote]]];
    }
}
