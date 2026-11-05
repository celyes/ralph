<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\PublicationException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommit;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskPublication;
use Celyes\RalphPhp\Git\TaskPublisher;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Publishing a verified pass's commit, against a real repository and a real
 * local bare remote.
 *
 * The item has two halves and the second one is where the tests concentrate:
 * *retain local commits and publication status when a push fails*. So every
 * failing case asserts three things rather than one — the local commit is still
 * on its branch at the SHA the row recorded, the row says the publication
 * failed and why, and the pass is not finalisable, which is what keeps the
 * checkbox unticked. A test that only asserted the exception or the return
 * value would pass just as happily against a publisher that dropped the commit
 * on the floor.
 *
 * A local bare repository is the remote throughout (PROJECT.md section J,
 * item 6): it exercises the real `git push`, the real refspec and the real
 * rejection messages, with no network and no credentials (section J, item 8).
 * What a bare remote on the same disk cannot reproduce is an authentication
 * failure or a slow link, and those are the two cases named in the hints rather
 * than asserted here.
 */
#[CoversClass(TaskPublisher::class)]
#[CoversClass(TaskPublication::class)]
#[CoversClass(PublicationException::class)]
final class TaskPublisherTest extends TestCase
{
    private const string DESCRIPTION = 'Add a greeting command';

    private TemporaryProject $project;

    private string $remote;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private ?BaseCommit $base = null;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this pushes between real repositories.');
        }

        $this->project = TemporaryProject::create();
        $this->remote = TemporaryDirectory::create('ralph-remote-');

        $this->givenACommittedProject();
        $this->givenABareRemote();

        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->tasks = new TaskStore($this->database);
        $this->generations = new GenerationStore($this->database);
    }

    protected function tearDown(): void
    {
        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
        TemporaryDirectory::remove($this->remote);
    }

    #[Test]
    public function a_pass_that_owes_a_push_publishes_its_commit_to_the_configured_remote(): void
    {
        $commit = $this->givenACommittedPass();

        $published = $this->publisher()->publish($commit->recorded());

        self::assertTrue($published->wasAttempted());
        self::assertTrue($published->isPublished());
        self::assertTrue($published->isSettled());
        self::assertFalse($published->hasFailed());
        self::assertSame('origin', $published->remote());
        self::assertSame($commit->sha(), $published->sha());
        self::assertSame($commit->branch()->value(), $published->branch());
        self::assertNull($published->error());

        // Read out of the remote rather than from the value that claims it: a
        // colleague can now fetch this branch at this commit.
        self::assertSame($commit->sha(), $this->remoteTipOf($commit->branch()->value()));
    }

    /**
     * The other half of a published pass: the row, which is what resume and
     * `ralph status` read.
     */
    #[Test]
    public function the_publication_is_recorded_on_the_passs_row(): void
    {
        $commit = $this->givenACommittedPass();

        $this->publisher()->publish($commit->recorded());

        $stored = $this->generations->get($commit->generation());

        self::assertSame(PushState::Published, $stored->push()->state());
        self::assertSame('origin', $stored->push()->remote());
        self::assertNull($stored->push()->error());
        self::assertTrue($stored->push()->isSatisfied());
        self::assertFalse($stored->awaitsPublication());

        // The Git-side conditions PROJECT.md puts on ticking a box are now all
        // met, which is the only reason publication exists as a step.
        self::assertTrue($stored->isFinalizable());
    }

    /**
     * Publishing is opt-in, and a pass that was never going to push must not
     * touch a remote — nor be held back by one.
     */
    #[Test]
    public function a_pass_that_owes_no_push_is_not_pushed_at_all(): void
    {
        $commit = $this->givenACommittedPass(publishing: false);

        $published = $this->publisher()->publish($commit->recorded());

        self::assertFalse($published->wasAttempted());
        self::assertFalse($published->isPublished());
        self::assertTrue($published->isSettled());
        self::assertNull($published->remote());
        self::assertNull($published->sha());

        self::assertSame([], $this->remoteBranches());
        self::assertSame(PushState::NotRequired, $this->generations->get($commit->generation())->push()->state());
        self::assertTrue($this->generations->get($commit->generation())->isFinalizable());
    }

    /**
     * Acceptance criterion 6 at this layer: a resumed run that finds the work
     * already out does not push it again, and could not even if it tried.
     */
    #[Test]
    public function a_pass_that_is_already_published_is_not_pushed_again(): void
    {
        $commit = $this->givenACommittedPass();
        $published = $this->publisher()->publish($commit->recorded());

        // Any second push would now fail outright, which is what makes this
        // assertion about behaviour rather than about Git's idempotence.
        Git::run($this->project->root(), 'remote', 'remove', 'origin');

        $again = $this->publisher()->publish($published->recorded());

        self::assertFalse($again->wasAttempted());
        self::assertTrue($again->isPublished());
        self::assertSame($commit->sha(), $again->sha());
        self::assertSame($commit->branch()->value(), $again->branch());
        self::assertSame(PushState::Published, $this->generations->get($commit->generation())->push()->state());
    }

    /**
     * The item's second half. A remote holding a branch of the same name with
     * other commits on it rejects an ordinary push, and everything local is
     * left exactly as it was.
     */
    #[Test]
    public function a_rejected_push_is_recorded_as_a_failure_and_keeps_the_local_commit(): void
    {
        $commit = $this->givenACommittedPass();
        $theirs = $this->givenTheRemoteAlreadyHas($commit->branch()->value());

        $refused = $this->publisher()->publish($commit->recorded());

        self::assertTrue($refused->wasAttempted());
        self::assertTrue($refused->hasFailed());
        self::assertFalse($refused->isSettled());
        self::assertSame('origin', $refused->remote());
        self::assertNotNull($refused->error());
        self::assertStringContainsString('rejected', (string) $refused->error());

        // More than the first line: Git explains a non-fast-forward over
        // several, and the lines after the first are the ones that say what to
        // do about it.
        self::assertStringContainsString('failed to push', (string) $refused->error());

        // The local commit, the branch and the remote are all untouched: a
        // rejected push is reported, never won.
        self::assertSame($commit->sha(), $this->tipOf($commit->branch()->value()));
        self::assertSame($theirs, $this->remoteTipOf($commit->branch()->value()));

        $stored = $this->generations->get($commit->generation());

        self::assertSame(PushState::Failed, $stored->push()->state());
        self::assertSame($commit->sha(), $stored->commitSha());
        self::assertTrue($stored->awaitsPublication());
        self::assertFalse($stored->isFinalizable());
    }

    /**
     * A remote that is not configured is a user's mistake to put right, not a
     * crash: the same recorded failure, with a hint that names it.
     */
    #[Test]
    public function a_remote_that_is_not_configured_is_recorded_as_a_failed_push(): void
    {
        $commit = $this->givenACommittedPass();
        Git::run($this->project->root(), 'remote', 'remove', 'origin');

        $sink = new RecordingSink();
        $refused = $this->publisher()->publish($commit->recorded(), $sink);
        $notes = implode("\n", $sink->notes);

        self::assertTrue($refused->hasFailed());
        self::assertStringContainsString('origin', (string) $refused->error());
        self::assertStringContainsString('no remotes', $notes);
        self::assertStringContainsString('git remote add origin', $notes);
        self::assertStringContainsString('resume retries the push', $notes);

        self::assertSame($commit->sha(), $this->tipOf($commit->branch()->value()));
        self::assertSame(PushState::Failed, $this->generations->get($commit->generation())->push()->state());
    }

    /**
     * A remote whose URL no longer answers: the failure Git reports is kept
     * whole, because its advice is the part that helps.
     */
    #[Test]
    public function the_reason_the_remote_gave_is_kept_on_the_row(): void
    {
        $commit = $this->givenACommittedPass();
        Git::run($this->project->root(), 'remote', 'set-url', 'origin', $this->remote . '-gone');

        $refused = $this->publisher()->publish($commit->recorded());
        $reason = (string) $this->generations->get($commit->generation())->push()->error();

        self::assertTrue($refused->hasFailed());
        self::assertSame($refused->error(), $reason);
        self::assertStringContainsString('does not appear to be a git repository', $reason);
    }

    /**
     * Ordinary pushes, which means a rejected one stays rejected.
     */
    #[Test]
    public function a_rejected_push_is_never_forced(): void
    {
        $commit = $this->givenACommittedPass();
        $theirs = $this->givenTheRemoteAlreadyHas($commit->branch()->value());

        $this->publisher()->publish($commit->recorded());

        self::assertSame($theirs, $this->remoteTipOf($commit->branch()->value()));
        self::assertSame(
            [$commit->branch()->value()],
            $this->remoteBranches(),
        );
    }

    /**
     * An upstream is `branch.<name>.*` configuration in the user's repository,
     * which publishing a task branch was not asked to write.
     */
    #[Test]
    public function publishing_does_not_write_branch_configuration_into_the_repository(): void
    {
        $commit = $this->givenACommittedPass();

        $this->publisher()->publish($commit->recorded());

        self::assertSame(
            '',
            trim(Git::tryRun(
                $this->project->root(),
                'config',
                '--get-regexp',
                '^branch\.' . preg_quote($commit->branch()->value(), '/') . '\.',
            )->standardOutput),
        );
    }

    /**
     * A verified task that changed nothing is a no-change result with no commit
     * behind it (PROJECT.md section G, item 6), so there is nothing a remote
     * could be given — and nothing to hold the task back either.
     */
    #[Test]
    public function a_pass_that_changed_nothing_has_its_publication_settled_rather_than_owed(): void
    {
        $pass = $this->generations->recordNoChange($this->givenAPass());

        $sink = new RecordingSink();
        $published = $this->publisher()->publish($pass, $sink);

        self::assertFalse($published->wasAttempted());
        self::assertTrue($published->isSettled());
        self::assertNull($published->sha());
        self::assertStringContainsString('no commit to publish', implode("\n", $sink->notes));

        self::assertSame([], $this->remoteBranches());

        $stored = $this->generations->get($pass->generation());

        self::assertSame(PushState::NotRequired, $stored->push()->state());
        self::assertTrue($stored->isFinalizable());
    }

    /**
     * Publishing before there is an implementation to publish is a defect, and
     * one that must not reach a remote.
     */
    #[Test]
    public function a_pass_with_no_commit_is_refused(): void
    {
        $exception = $this->refusal($this->givenAPass());

        self::assertStringContainsString('has no recorded commit', $exception->getMessage());
        self::assertStringContainsString('published after it has committed', implode("\n", $exception->hints()));
        self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        self::assertSame([], $this->remoteBranches());
    }

    #[Test]
    public function a_row_that_records_a_commit_but_no_branch_is_refused(): void
    {
        $pass = $this->generations->recordCommit($this->givenAPass(), $this->base()->sha());

        $exception = $this->refusal($pass);

        self::assertStringContainsString('no branch', $exception->getMessage());
        self::assertSame([], $this->remoteBranches());
        self::assertSame(PushState::Pending, $this->generations->get($pass->generation())->push()->state());
    }

    /**
     * The branch is the reference the commit is published as, so one that is
     * not there is reconciliation (PROJECT.md section F, item 9) rather than
     * something to invent on the remote.
     */
    #[Test]
    public function a_branch_that_is_no_longer_in_the_repository_is_refused(): void
    {
        $commit = $this->givenACommittedPass();
        $branch = $commit->branch()->value();

        // The worktree holds the branch checked out, so it goes first.
        Git::run($this->project->root(), 'worktree', 'remove', '--force', $this->worktreePathOf($commit));
        Git::run($this->project->root(), 'branch', '--delete', '--force', $branch);

        $exception = $this->refusal($commit->recorded());

        self::assertStringContainsString('is not in the repository', $exception->getMessage());
        self::assertStringContainsString('git branch ' . $branch . ' ' . $commit->sha(), implode("\n", $exception->hints()));

        // The commit survives its branch, which is why the hint can offer to
        // put the branch back.
        self::assertSame('commit', $this->objectTypeOf($commit->sha()));
        self::assertSame([], $this->remoteBranches());
        self::assertSame(PushState::Pending, $this->generations->get($commit->generation())->push()->state());
    }

    /**
     * Ralph PHP publishes the commit it recorded and nothing else: a branch
     * somebody has added to is reported rather than sent to a remote other
     * people fetch.
     */
    #[Test]
    public function a_branch_that_has_moved_past_the_recorded_commit_is_refused(): void
    {
        $commit = $this->givenACommittedPass();
        $workspace = $this->worktreePathOf($commit);

        file_put_contents($workspace . '/EXTRA.md', "A commit nobody here has seen.\n");
        Git::run($workspace, 'add', 'EXTRA.md');
        $theirs = Git::commit($workspace, 'An extra commit');

        $exception = $this->refusal($commit->recorded());

        self::assertStringContainsString('have diverged', $exception->getMessage());
        self::assertStringContainsString(substr($commit->sha(), 0, TaskCommit::SHORT_LENGTH), $exception->getMessage());
        self::assertStringContainsString('publishes the commit it recorded', implode("\n", $exception->hints()));

        self::assertSame($theirs, $this->tipOf($commit->branch()->value()));
        self::assertSame([], $this->remoteBranches());
        self::assertSame(PushState::Pending, $this->generations->get($commit->generation())->push()->state());
    }

    /**
     * The push is the one Git command here that can run for minutes, so its
     * output goes to the log as it arrives rather than afterwards.
     */
    #[Test]
    public function the_push_is_noted_and_its_output_streamed_to_the_log(): void
    {
        $commit = $this->givenACommittedPass();

        $sink = new RecordingSink();
        $this->publisher()->publish($commit->recorded(), $sink);

        $notes = implode("\n", $sink->notes);
        $output = $sink->textOf(OutputStream::Error) . $sink->textOf(OutputStream::Standard);

        self::assertStringContainsString('push: ' . $commit->generation()->slug(), $notes);
        self::assertStringContainsString($commit->shortSha(), $notes);
        self::assertStringContainsString('published', $notes);
        self::assertStringContainsString('new branch', $output);

        // An attempt's log spans more than one command; the caller that opened
        // it closes it.
        self::assertSame(0, $sink->closes);
    }

    /**
     * The push runs in the controlling checkout, which the user is sitting in,
     * so it had better leave it alone.
     */
    #[Test]
    public function the_users_checkout_is_untouched_by_a_push(): void
    {
        $commit = $this->givenACommittedPass();
        $this->project->write('NOTES.md', "The user's own uncommitted work.\n");

        $before = $this->snapshotOfTheCheckout();

        $this->publisher()->publish($commit->recorded());

        self::assertSame($before, $this->snapshotOfTheCheckout());
    }

    /**
     * The one place `git.push` becomes a pass's obligation; from then on the
     * row is the authority.
     */
    #[Test]
    public function the_expectation_a_pass_starts_with_comes_from_the_configuration(): void
    {
        $enabled = TaskPublisher::expectationFor($this->configuration(['git' => [
            'push' => ['enabled' => true, 'remote' => 'upstream'],
        ]]));

        self::assertSame(PushState::Pending, $enabled->state());
        self::assertSame('upstream', $enabled->remote());

        $disabled = TaskPublisher::expectationFor($this->configuration());

        self::assertSame(PushState::NotRequired, $disabled->state());
        self::assertNull($disabled->remote());
    }

    /**
     * A configuration edited while a run is in progress — by a worker, even —
     * cannot change what a committed pass is judged by.
     */
    #[Test]
    public function the_row_decides_whether_to_push_rather_than_the_current_configuration(): void
    {
        $commit = $this->givenACommittedPass();

        // The pass owes a push; the configuration now says publication is off.
        $published = TaskPublisher::forProject($this->configuration(), $this->generations)
            ->publish($commit->recorded());

        self::assertTrue($published->wasAttempted());
        self::assertSame($commit->sha(), $this->remoteTipOf($commit->branch()->value()));
    }

    #[Test]
    public function the_publication_serialises_for_a_record_of_the_run(): void
    {
        $commit = $this->givenACommittedPass();

        $published = $this->publisher()->publish($commit->recorded());
        $written = $published->toArray();

        self::assertSame($commit->generation()->slug(), $written['generation']);
        self::assertSame('published', $written['state']);
        self::assertSame('origin', $written['remote']);
        self::assertSame($commit->sha(), $written['sha']);
        self::assertSame($commit->branch()->value(), $written['branch']);
        self::assertTrue($written['attempted']);
        self::assertNull($written['error']);

        self::assertSame(
            sprintf(
                '%s published %s as %s on "origin"',
                $commit->generation()->label(),
                $commit->shortSha(),
                $commit->branch()->value(),
            ),
            $published->describe(),
        );
    }

    private function publisher(): TaskPublisher
    {
        return TaskPublisher::forProject($this->publishingConfiguration(), $this->generations);
    }

    private function refusal(GenerationRecord $pass): PublicationException
    {
        try {
            $this->publisher()->publish($pass);
        } catch (PublicationException $exception) {
            return $exception;
        }

        self::fail('The publication was expected to be refused.');
    }

    /**
     * A repository with one commit and the ignore rules a project has, which is
     * what a task workspace is created from.
     */
    private function givenACommittedProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        $this->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $this->project->write('README.md', "An example project.\n");
        $this->project->write('src/Greeting.php', "<?php\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    /**
     * A bare repository on the same disk, which is a real remote as far as
     * `git push` is concerned and needs neither a network nor a credential.
     */
    private function givenABareRemote(): void
    {
        Git::initialise($this->remote, bare: true);
        Git::run($this->project->root(), 'remote', 'add', 'origin', $this->remote);
    }

    /**
     * Somebody else's commit, already on the remote under the name this pass
     * is about to publish.
     */
    private function givenTheRemoteAlreadyHas(string $branch): string
    {
        $directory = TemporaryDirectory::create('ralph-theirs-');

        Git::run($directory, 'clone', '--quiet', $this->remote, '.');
        Git::setIdentity($directory);
        file_put_contents($directory . '/THEIRS.md', "Somebody else's work.\n");
        Git::run($directory, 'add', 'THEIRS.md');
        Git::run($directory, 'switch', '--create', $branch);
        $sha = Git::commit($directory, 'Their work');
        Git::run($directory, 'push', '--quiet', 'origin', $branch);

        TemporaryDirectory::remove($directory);

        return $sha;
    }

    /**
     * A pass that has committed, which is the state publication finds a task
     * in: the agent has run, the checks have passed, and the commit is on the
     * pass's own branch.
     */
    private function givenACommittedPass(bool $publishing = true): TaskCommit
    {
        $configuration = $publishing ? $this->publishingConfiguration() : $this->configuration();
        $pass = $this->givenAPass($publishing);
        $workspace = $this->givenAWorkspace($pass, $configuration);

        file_put_contents($workspace->path() . '/src/Greeting.php', "<?php\n\n// changed\n");

        $current = $this->generations->get($pass->generation());
        $branch = BranchNamer::forProject($configuration)->forRecord($current, self::DESCRIPTION);

        return TaskCommitter::forProject($configuration, $this->generations)->commit(
            $current,
            $workspace,
            TaskBranches::forProject($configuration)->require($branch, $current),
            WorkspaceStager::forProject($configuration)->stage($workspace),
            CommitMessage::forGeneration($current, $this->snapshot(self::DESCRIPTION)),
        );
    }

    /**
     * A recorded pass that has spent an attempt, owing whatever publication its
     * run's configuration implies.
     */
    private function givenAPass(bool $publishing = true): GenerationRecord
    {
        $snapshot = $this->snapshot(self::DESCRIPTION);
        $this->tasks->record($snapshot);

        return $this->generations->beginAttempt($this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base()->sha(),
            TaskPublisher::expectationFor($publishing ? $this->publishingConfiguration() : $this->configuration()),
            $this->insertRun(),
        ));
    }

    /**
     * A real linked worktree for the pass, created the way a run creates it.
     */
    private function givenAWorkspace(GenerationRecord $pass, Configuration $configuration): TaskWorktree
    {
        $workspace = TaskWorktrees::forProject($configuration)->prepare($pass->generation(), $this->base());

        $this->generations->recordWorktree($pass, $workspace->ownership());

        return $workspace;
    }

    private function snapshot(string $description): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));
    }

    private function publishingConfiguration(): Configuration
    {
        return $this->configuration(['git' => ['push' => ['enabled' => true, 'remote' => 'origin']]]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings = []): Configuration
    {
        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    /**
     * The commit the run resolved, resolved once.
     */
    private function base(): BaseCommit
    {
        return $this->base ??= BaseResolver::forProject($this->configuration())->resolve();
    }

    /**
     * A run row, for the column the run identifier references.
     */
    private function insertRun(): int
    {
        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-0001',
                'running',
                $agent['agent'],
                $agent['adapter'],
                'project_default',
                $agent['settings'],
                '{}',
                '[]',
                '.ralph/tasks.md',
                'HEAD',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }

    private function worktreePathOf(TaskCommit $commit): string
    {
        return $commit->changes()->workspace();
    }

    private function tipOf(string $branch): ?string
    {
        $result = Git::tryRun($this->project->root(), 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch);

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    private function objectTypeOf(string $sha): string
    {
        return trim(Git::run($this->project->root(), 'cat-file', '-t', $sha)->standardOutput);
    }

    private function remoteTipOf(string $branch): ?string
    {
        $result = Git::tryRun($this->remote, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch);

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    /**
     * The branches the remote holds, which for a fresh bare repository is
     * nothing at all.
     *
     * @return list<string>
     */
    private function remoteBranches(): array
    {
        $output = trim(Git::run($this->remote, 'for-each-ref', '--format=%(refname:short)', 'refs/heads/')->standardOutput);
        $names = [];

        foreach (explode("\n", $output) as $line) {
            $name = trim($line);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * HEAD, the working tree and the index of the checkout the user is sitting
     * in.
     */
    private function snapshotOfTheCheckout(): string
    {
        return implode("\n", [
            trim(Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput),
            trim(Git::run($this->project->root(), 'status', '--porcelain=v1')->standardOutput),
            trim(Git::run($this->project->root(), 'diff', '--cached', '--name-status')->standardOutput),
        ]);
    }
}
