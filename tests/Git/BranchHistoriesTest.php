<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Git\BranchHistories;
use Celyes\RalphPhp\Git\BranchHistory;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a branch holds past a commit, and whose each of those commits is
 * (PROJECT.md section K, item 5).
 *
 * The reading a shared branch's reconciliation rests on, tested against real
 * commits and a real state store because it is exactly the agreement between
 * the two that is the subject: Git supplies the order and the ancestry, the
 * store supplies the ownership, and the interesting cases are the ones where
 * only one of them has heard of a commit.
 *
 * The commits are made with `git commit-tree` and `git update-ref`, which is
 * how a branch gains a commit without the controlling checkout being moved
 * anywhere — the same technique {@see SharedBranchesTest} uses, and it keeps
 * the test about history rather than about checkouts.
 */
#[CoversClass(BranchHistories::class)]
#[CoversClass(BranchHistory::class)]
final class BranchHistoriesTest extends TestCase
{
    private const string BRANCH = 'ralph/shared';

    private TemporaryProject $project;

    private string $base;

    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this reads real commit histories.');
        }

        $this->project = TemporaryProject::create();
        $this->base = $this->givenACommittedProject();

        $this->database = (new StateBootstrap())->openInMemory()->database();
        $clock = FrozenClock::at('2026-11-14T10:00:00+00:00');
        $this->tasks = new TaskStore($this->database, $clock);
        $this->generations = new GenerationStore($this->database, $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    #[Test]
    public function a_tip_that_is_the_commit_asked_about_holds_nothing_past_it(): void
    {
        $history = $this->histories()->beyond($this->base, $this->base);

        self::assertTrue($history->isEmpty());
        self::assertTrue($history->isAccountedFor());
        self::assertSame(0, $history->total());
        self::assertSame([], $history->passes());
        self::assertNull($history->stranger());
        self::assertNull($history->reason());
        self::assertStringContainsString('holds nothing past', $history->describe());
    }

    /**
     * The ordinary advance of a shared-branch run: a commit per task, each one
     * with a row naming the pass that made it.
     */
    #[Test]
    public function commits_this_project_recorded_are_accounted_for(): void
    {
        $first = $this->givenARecordedCommit('Add a greeting');
        $second = $this->givenARecordedCommit('Add a farewell');

        $history = $this->histories()->beyond($this->base, $second['sha']);

        self::assertTrue($history->isAccountedFor());
        self::assertSame(2, $history->total());
        self::assertSame([$first['slug'], $second['slug']], $history->passes());
        self::assertFalse($history->wasTooLong());
        self::assertStringContainsString('has moved 2 commits past', $history->describe());
        self::assertStringContainsString($first['slug'], $history->describe());
    }

    /**
     * The reading stops at the commit it cannot account for, and keeps what
     * that commit says about itself: a person is being told which commit to go
     * and look at.
     */
    #[Test]
    public function a_commit_no_row_accounts_for_is_the_answer(): void
    {
        $stranger = $this->givenACommit('Something somebody did by hand');

        $history = $this->histories()->beyond($this->base, $stranger);

        self::assertFalse($history->isAccountedFor());
        self::assertSame($stranger, $history->stranger()?->sha());
        self::assertTrue($history->strangerIsFirst());
        self::assertSame('Something somebody did by hand', $history->stranger()?->subject());
        self::assertStringContainsString('no pass of this project recorded', (string) $history->reason());
        self::assertStringContainsString(
            'Something somebody did by hand',
            implode("\n", $history->details()),
        );
    }

    /**
     * Where the stranger sits is what a crash recovery turns on: a pass commits
     * directly onto the commit it started from, so an unaccounted-for commit
     * with recorded work beneath it cannot be that pass's.
     */
    #[Test]
    public function a_stranger_above_recorded_work_is_not_the_first_commit_past_the_base(): void
    {
        $recorded = $this->givenARecordedCommit('Add a greeting');
        $stranger = $this->givenACommit('Something somebody did by hand');

        $history = $this->histories()->beyond($this->base, $stranger);

        self::assertFalse($history->isAccountedFor());
        self::assertFalse($history->strangerIsFirst());
        self::assertSame($stranger, $history->stranger()?->sha());
        self::assertSame([$recorded['slug']], $history->passes());
        self::assertSame(2, $history->total());
    }

    /**
     * Read forwards: the first answer is about the commit immediately past the
     * one asked about, whichever end of the branch the stranger is at.
     */
    #[Test]
    public function the_range_is_read_from_the_commit_asked_about_rather_than_from_the_tip(): void
    {
        $recorded = $this->givenARecordedCommit('Add a greeting');
        $this->givenACommit('Something somebody did by hand');

        $history = $this->histories()->beyond($recorded['sha'], $this->tipOf(self::BRANCH));

        self::assertSame(1, $history->total());
        self::assertTrue($history->strangerIsFirst());
    }

    /**
     * A commit adopted into a row a moment ago is already accounted for, which
     * is why the reader takes the run's own store rather than opening one.
     */
    #[Test]
    public function a_commit_becomes_accounted_for_as_soon_as_a_row_names_it(): void
    {
        $pass = $this->givenAPass('Add a greeting');
        $commit = $this->givenACommit('Add a greeting');

        self::assertFalse($this->histories()->beyond($this->base, $commit)->isAccountedFor());

        $this->generations->recordCommit($pass, $commit);

        self::assertTrue($this->histories()->beyond($this->base, $commit)->isAccountedFor());
    }

    /**
     * A range longer than the reader walks is refused rather than answered from
     * the part of it that was read. Asserted on the value, because producing
     * five hundred commits to prove it would test Git's patience rather than
     * this package's reasoning.
     */
    #[Test]
    public function a_range_longer_than_the_reader_walks_cannot_be_vouched_for(): void
    {
        $history = BranchHistory::tooLong($this->base, 'deadbeef', BranchHistory::MAX_READ + 1, ['TASK-1-g1']);

        self::assertFalse($history->isAccountedFor());
        self::assertTrue($history->wasTooLong());
        self::assertNull($history->stranger());
        self::assertStringContainsString('more than reconciliation reads', (string) $history->reason());
        self::assertStringContainsString((string) BranchHistory::MAX_READ, (string) $history->reason());
        self::assertSame(BranchHistory::MAX_READ + 1, $history->total());
    }

    #[Test]
    public function the_reading_serialises_for_a_diagnostic(): void
    {
        $recorded = $this->givenARecordedCommit('Add a greeting');

        $serialised = $this->histories()->beyond($this->base, $recorded['sha'])->toArray();

        self::assertSame($this->base, $serialised['from']);
        self::assertSame($recorded['sha'], $serialised['tip']);
        self::assertSame(1, $serialised['total']);
        self::assertTrue($serialised['accounted_for']);
        self::assertSame([$recorded['slug']], $serialised['passes']);
        self::assertNull($serialised['stranger']);
        self::assertFalse($serialised['too_long']);
    }

    private function histories(): BranchHistories
    {
        return BranchHistories::forProject($this->configuration(), $this->generations);
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->fromArray(
            ['git' => ['strategy' => 'shared']],
            $this->project->root(),
        );
    }

    /**
     * A commit on the branch with a pass's row naming it, as a finished task
     * of a shared-branch run leaves behind.
     *
     * @return array{sha: string, slug: string}
     */
    private function givenARecordedCommit(string $description): array
    {
        $pass = $this->givenAPass($description);
        $commit = $this->givenACommit($description);
        $this->generations->recordCommit($pass, $commit);

        return ['sha' => $commit, 'slug' => $pass->slug()];
    }

    private function givenAPass(string $description): GenerationRecord
    {
        $snapshot = TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            '- [ ] ' . $description,
            [],
        )));
        $this->tasks->record($snapshot);

        return $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'HEAD',
            $this->base,
        );
    }

    /**
     * A commit on the branch, made without checking it out so the controlling
     * checkout stays where it is.
     */
    private function givenACommit(string $subject): string
    {
        $parent = $this->tipOf(self::BRANCH) ?? $this->base;
        $tree = trim(Git::run($this->project->root(), 'rev-parse', $parent . '^{tree}')->standardOutput);

        $commit = trim(Git::run(
            $this->project->root(),
            '-c',
            'user.name=Test User',
            '-c',
            'user.email=test@example.com',
            'commit-tree',
            $tree,
            '-p',
            $parent,
            '-m',
            $subject,
        )->standardOutput);

        Git::run($this->project->root(), 'update-ref', 'refs/heads/' . self::BRANCH, $commit);

        return $commit;
    }

    private function tipOf(string $branch): ?string
    {
        $result = Git::tryRun(
            $this->project->root(),
            'rev-parse',
            '--verify',
            '--quiet',
            'refs/heads/' . $branch,
        );

        return $result->exitCode === 0 ? trim($result->standardOutput) : null;
    }

    private function givenACommittedProject(): string
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        $this->project->write('src/Foo.php', "<?php\n// original\n");
        $this->project->write('.gitignore', "/.ralph/\n");
        Git::run($this->project->root(), 'add', 'src/Foo.php', '.gitignore');

        return Git::commit($this->project->root());
    }
}
