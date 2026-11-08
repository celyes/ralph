<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\BranchRestorer;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Re-creating a pass's branch at a commit the repository still has.
 *
 * The one write of PROJECT.md section H, item 5's branch reconciliation, and
 * the whole point of it being its own class is what it must not be able to do.
 * So the two cases that matter are here: a name nothing holds is created, and a
 * name something already holds is reported rather than taken — asserted by
 * reading the reference back out of Git, which is the only thing that would
 * show a `--force` creeping in.
 */
#[CoversClass(BranchRestorer::class)]
#[CoversClass(BranchException::class)]
final class BranchRestorerTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this creates real references.');
        }

        $this->project = TemporaryProject::create();
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        $this->project->write('README.md', "An example project.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function a_branch_is_created_at_the_commit_it_is_given(): void
    {
        $sink = new RecordingSink();
        $commit = $this->headOfTheProject();

        $restored = $this->restorer()->restore($this->branch(), $commit, $sink);

        self::assertSame($commit, $restored);
        self::assertSame($commit, $this->tipOf($this->branch()->value()));
        self::assertStringContainsString('re-created the branch', implode("\n", $sink->notes));
    }

    /**
     * The case this must never win. Git's own refusal is what stops it, and the
     * message has to say that the commit is still safe.
     */
    #[Test]
    public function a_name_something_else_already_holds_is_reported_rather_than_taken(): void
    {
        $branch = $this->branch();
        Git::run($this->project->root(), 'branch', $branch->value());
        $held = $this->tipOf($branch->value());

        $this->project->write('README.md', "Changed.\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), "A second commit\n");
        $elsewhere = $this->headOfTheProject();

        try {
            $this->restorer()->restore($branch, $elsewhere);
        } catch (BranchException $exception) {
            self::assertStringContainsString('could not be re-created at', $exception->getMessage());
            self::assertStringContainsString(
                'creates the reference and never moves or deletes one',
                implode("\n", $exception->hints()),
            );
            self::assertSame($held, $this->tipOf($branch->value()), 'the branch was left where it was');

            return;
        }

        self::fail('A branch that already exists was expected to be refused.');
    }

    #[Test]
    public function a_branch_cannot_be_restored_without_a_commit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->restorer()->restore($this->branch(), '  ');
    }

    private function restorer(): BranchRestorer
    {
        return BranchRestorer::forProject($this->configuration());
    }

    private function branch(): TaskBranch
    {
        return BranchNamer::forProject($this->configuration())->resolve(
            TaskGeneration::first(TaskId::fromDescription('Add a greeting command')),
            'Add a greeting command',
        );
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->fromArray([], $this->project->root());
    }

    private function headOfTheProject(): string
    {
        return trim(Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput);
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
}
