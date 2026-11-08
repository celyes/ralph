<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Git\CommitReader;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading commits out of a real repository: what one says, and where it sits.
 *
 * The questions recovery asks of Git (PROJECT.md section H, item 5). Asserted
 * against commits `git commit` really wrote rather than against a stubbed
 * runner, because what is being tested is the agreement between this package's
 * commands and Git's answers — a `--format` that reflowed a message, or a
 * status read as "no" when it meant "could not tell", would both pass against a
 * stub and lose work against Git.
 */
#[CoversClass(CommitReader::class)]
#[CoversClass(CommitException::class)]
final class CommitReaderTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this reads real commits.');
        }

        $this->project = TemporaryProject::create();
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function a_commits_whole_message_is_read_back_as_it_was_written(): void
    {
        $message = "TASK-19E0214A: add a greeting\n\nThe task as it was worded.\n\n"
            . "Ralph-Task: TASK-19E0214A\nRalph-Generation: 1\n";
        $sha = $this->commit('README.md', "An example.\n", $message);

        $identity = $this->reader()->identify($sha);

        self::assertNotNull($identity);
        self::assertSame($sha, $identity->sha());
        self::assertSame('TASK-19E0214A: add a greeting', $identity->subject());
        self::assertTrue($identity->isRalphCommit());
        self::assertSame('TASK-19E0214A', $identity->task());
        self::assertSame(1, $identity->generation());
    }

    #[Test]
    public function a_branch_is_read_as_the_commit_it_points_at(): void
    {
        $base = $this->commit('README.md', "An example.\n", "Initial commit\n");
        Git::run($this->project->root(), 'switch', '--create', 'ralph/task-x');
        $tip = $this->commit('src/Greeting.php', "<?php\n", "Second commit\n");

        self::assertSame($tip, $this->reader()->resolve('refs/heads/ralph/task-x'));
        self::assertSame($base, $this->reader()->resolve($base));
        self::assertSame('Second commit', $this->reader()->identify('refs/heads/ralph/task-x')?->subject());
    }

    #[Test]
    public function a_reference_the_repository_does_not_have_is_no_commit(): void
    {
        $this->commit('README.md', "An example.\n", "Initial commit\n");

        self::assertNull($this->reader()->identify('refs/heads/ralph/task-gone'));
        self::assertNull($this->reader()->resolve('refs/heads/ralph/task-gone'));
        self::assertFalse($this->reader()->exists('refs/heads/ralph/task-gone'));
        self::assertNull($this->reader()->identify('0f1e2d3c4b5a69788796a5b4c3d2e1f009182736'));
        self::assertFalse($this->reader()->exists('not-a-reference-at-all'));
    }

    /**
     * A tag of a branch's name is the trap `^{commit}` and the fully qualified
     * reference exist for; a tree is not something a pass can have committed.
     */
    #[Test]
    public function a_reference_that_is_not_a_commit_is_no_commit(): void
    {
        $sha = $this->commit('README.md', "An example.\n", "Initial commit\n");
        $tree = trim(Git::run($this->project->root(), 'rev-parse', 'HEAD^{tree}')->standardOutput);

        self::assertNull($this->reader()->resolve($tree));
        self::assertSame($sha, $this->reader()->resolve('HEAD'));
    }

    /**
     * An annotated tag peels to the commit it is of, which is the one case
     * where `^{commit}` changes the answer rather than refusing it.
     */
    #[Test]
    public function an_annotated_tag_is_read_as_the_commit_it_names(): void
    {
        $sha = $this->commit('README.md', "An example.\n", "Initial commit\n");
        Git::run($this->project->root(), 'tag', '--annotate', '--message=release', 'v1');

        self::assertSame($sha, $this->reader()->resolve('refs/tags/v1'));
    }

    #[Test]
    public function one_commits_place_in_anothers_history_is_read_from_the_repository(): void
    {
        $base = $this->commit('README.md', "An example.\n", "Initial commit\n");
        Git::run($this->project->root(), 'switch', '--create', 'ralph/task-x');
        $first = $this->commit('src/Greeting.php', "<?php\n", "First\n");
        $second = $this->commit('src/Farewell.php', "<?php\n", "Second\n");

        $reader = $this->reader();

        self::assertTrue($reader->contains($second, $base));
        self::assertTrue($reader->contains($second, $second), 'a commit is its own ancestor');
        self::assertFalse($reader->contains($first, $second));

        self::assertSame(2, $reader->countBetween($base, $second));
        self::assertSame(1, $reader->countBetween($base, $first));
        self::assertSame(0, $reader->countBetween($second, $second));
        self::assertSame(0, $reader->countBetween($second, $base));
    }

    /**
     * A commit the repository does not have is refused rather than answered
     * "no": the answer decides whether work is adopted or abandoned.
     */
    #[Test]
    public function an_unanswerable_question_about_ancestry_is_refused(): void
    {
        $sha = $this->commit('README.md', "An example.\n", "Initial commit\n");
        $missing = '0f1e2d3c4b5a69788796a5b4c3d2e1f009182736';

        try {
            $this->reader()->contains($sha, $missing);
        } catch (CommitException $exception) {
            self::assertStringContainsString('is still in the history of', $exception->getMessage());
            self::assertStringContainsString(
                'will not reconcile a pass against a repository it could not read',
                implode("\n", $exception->hints()),
            );

            return;
        }

        self::fail('A question about a commit that is not there was expected to be refused.');
    }

    #[Test]
    public function an_unanswerable_count_is_refused(): void
    {
        $this->commit('README.md', "An example.\n", "Initial commit\n");

        $this->expectException(CommitException::class);
        $this->expectExceptionMessageMatches('/how far .* is past/');

        $this->reader()->countBetween('0f1e2d3c4b5a69788796a5b4c3d2e1f009182736', 'HEAD');
    }

    #[Test]
    public function an_empty_reference_is_a_programming_error_rather_than_a_missing_commit(): void
    {
        $this->commit('README.md', "An example.\n", "Initial commit\n");

        $this->expectException(InvalidArgumentException::class);

        $this->reader()->resolve('   ');
    }

    private function reader(): CommitReader
    {
        return CommitReader::forProject($this->configuration());
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->fromArray([], $this->project->root());
    }

    /**
     * A commit with a message of this test's choosing.
     */
    private function commit(string $relative, string $contents, string $message): string
    {
        $this->project->write($relative, $contents);
        Git::run($this->project->root(), 'add', '--all');
        Git::run($this->project->root(), 'commit', '--message', $message, '--cleanup=verbatim');

        return trim(Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput);
    }
}
