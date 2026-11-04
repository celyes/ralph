<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Resolving the configured `git.base` against real repositories.
 *
 * Which references resolve, and to what, is Git's answer rather than this
 * package's, so every test asks the actual `git`: an annotated tag peeling to
 * the commit it marks, a branch name resolving in a repository where a
 * same-named file exists, a reference that is a tree rather than a commit. A
 * double would only confirm the double.
 */
#[CoversClass(BaseResolver::class)]
#[CoversClass(BaseCommit::class)]
final class BaseResolverTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this is about what a repository resolves.');
        }

        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_default_base_resolves_to_the_commit_head_names(): void
    {
        $sha = $this->givenACommit();

        $base = $this->resolver()->resolve();

        self::assertSame('HEAD', $base->reference());
        self::assertSame($sha, $base->sha());
    }

    #[Test]
    public function a_branch_resolves_to_its_tip_rather_than_to_its_name(): void
    {
        // The point of recording the SHA: the reference moves, the recorded
        // commit does not.
        $first = $this->givenACommit();
        $resolved = $this->resolver(['git' => ['base' => 'main']])->resolve();
        self::assertSame($first, $resolved->sha());

        $second = Git::commit($this->project->root(), 'Second commit');
        self::assertNotSame($first, $second);

        $again = $this->resolver(['git' => ['base' => 'main']])->resolve();
        self::assertSame($second, $again->sha());
        // And the value already in a caller's hands still names the old one.
        self::assertSame($first, $resolved->sha());
    }

    #[Test]
    public function an_annotated_tag_resolves_to_the_commit_it_marks(): void
    {
        $sha = $this->givenACommit();
        Git::run(
            $this->project->root(),
            '-c',
            'user.name=Test User',
            '-c',
            'user.email=test@example.com',
            'tag',
            '--annotate',
            '--message',
            'Release',
            'v1.0',
        );

        // A tag object is not a commit; `^{commit}` is what makes the answer
        // one, rather than failing later inside `git worktree add`.
        self::assertSame($sha, $this->resolver(['git' => ['base' => 'v1.0']])->resolve()->sha());
    }

    #[Test]
    public function an_abbreviated_commit_is_recorded_in_full(): void
    {
        $sha = $this->givenACommit();

        $base = $this->resolver(['git' => ['base' => substr($sha, 0, 7)]])->resolve();

        self::assertSame($sha, $base->sha());
        self::assertSame(substr($sha, 0, 7), $base->reference());
        self::assertTrue($base->wasNamedDirectly());
    }

    #[Test]
    public function a_reference_that_names_no_commit_is_refused_with_the_file_to_edit(): void
    {
        $this->givenACommit();

        $exception = $this->refusal(['git' => ['base' => 'release']]);

        self::assertStringContainsString('"release" does not name a commit', $exception->getMessage());
        self::assertStringContainsString($this->project->root(), $exception->getMessage());
        self::assertStringContainsString('"git.base"', implode("\n", $exception->hints()));
        self::assertStringContainsString('.ralph/config.json', implode("\n", $exception->hints()));
        self::assertSame(78, $exception->exitCode());
    }

    #[Test]
    public function a_reference_naming_something_that_is_not_a_commit_is_refused(): void
    {
        $this->givenACommit();
        $tree = trim(Git::run($this->project->root(), 'rev-parse', 'HEAD^{tree}')->standardOutput);

        $exception = $this->refusal(['git' => ['base' => $tree]]);

        self::assertStringContainsString('does not name a commit', $exception->getMessage());
    }

    #[Test]
    public function a_repository_with_no_commits_says_so_rather_than_blaming_the_default(): void
    {
        Git::initialise($this->project->root());
        $this->project->writeConfigArray([]);

        $exception = $this->refusal();

        // "HEAD does not name a commit" is true here and describes the default
        // setting rather than the problem.
        self::assertStringContainsString('has no commits yet', $exception->getMessage());
        self::assertStringNotContainsString('does not name a commit', $exception->getMessage());
        self::assertStringContainsString('Create the first commit', implode("\n", $exception->hints()));
        self::assertStringContainsString('git.base: "HEAD"', implode("\n", $exception->hints()));
    }

    #[Test]
    public function a_directory_that_is_not_a_repository_is_refused_with_gits_own_reason(): void
    {
        $this->project->writeConfigArray([]);

        $exception = $this->refusal();

        // Not "the branch is missing": nothing was asked successfully at all.
        self::assertStringContainsString('could not be resolved', $exception->getMessage());
        self::assertStringContainsString('not a git repository', strtolower($exception->getMessage()));
        self::assertStringContainsString('will not start tasks from a commit it could not confirm', implode("\n", $exception->hints()));
    }

    #[Test]
    public function a_reference_beginning_with_a_dash_reaches_git_as_a_reference(): void
    {
        $this->givenACommit();

        // Configuration validation rejects such a value, so it cannot be
        // loaded through the loader; this is the second lock on the same door,
        // at the place where the text meets a command line.
        $exception = $this->refusalFrom(BaseResolver::of($this->repository(), '--all'));

        self::assertStringContainsString('"--all" does not name a commit', $exception->getMessage());
        // Read as an option, `--all` would have listed every reference and
        // exited zero.
        self::assertStringNotContainsString('refs/heads', $exception->getMessage());
    }

    #[Test]
    public function a_base_that_was_not_configured_at_all_is_refused_before_git_is_asked(): void
    {
        $this->givenACommit();

        $exception = $this->refusalFrom(BaseResolver::of($this->repository(), '  '));

        self::assertStringContainsString('No base reference is configured', $exception->getMessage());
        self::assertStringContainsString('.ralph/config.json', implode("\n", $exception->hints()));
    }

    #[Test]
    public function the_reference_is_reported_before_it_is_resolved(): void
    {
        $this->givenACommit();

        self::assertSame('main', $this->resolver(['git' => ['base' => 'main']])->reference());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolver(array $settings = []): BaseResolver
    {
        return BaseResolver::forProject((new ConfigurationLoader())->fromArray($settings, $this->project->root()));
    }

    private function repository(): Repository
    {
        return Repository::at($this->project->root());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function refusal(array $settings = []): ConfigurationException
    {
        return $this->refusalFrom($this->resolver($settings));
    }

    /**
     * The refusal a run would be stopped by, failing the test if there is none.
     */
    private function refusalFrom(BaseResolver $resolver): ConfigurationException
    {
        try {
            $resolver->resolve();
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('the base reference was accepted');
    }

    /**
     * A repository with one commit, and its SHA.
     */
    private function givenACommit(): string
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        $this->project->writeConfigArray([]);

        return Git::commit($this->project->root());
    }
}
