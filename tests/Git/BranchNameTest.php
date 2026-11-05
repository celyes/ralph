<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\BranchName;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A branch name, checked against the rules Git applies.
 *
 * The point of the type is that it agrees with the installed `git` rather than
 * with this package's reading of the documentation, so the central test here
 * asks `git branch` about every candidate and requires the same verdict. That
 * is also how the two rules `git check-ref-format` does not report — `HEAD`,
 * and a leading `-` — were found.
 */
#[CoversClass(BranchName::class)]
final class BranchNameTest extends TestCase
{
    /**
     * Names Git accepts, and names it refuses, with the reason this package
     * gives for refusing.
     *
     * Asserted twice: once against the rules here, once against `git branch`
     * below.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function names(): array
    {
        return [
            'a generated task branch' => ['ralph/TASK-19E0214A-add-a-flag', null],
            'a reopened task branch' => ['ralph/TASK-19E0214A-g2-add-a-flag', null],
            'no prefix at all' => ['TASK-19E0214A', null],
            'several path segments' => ['ralph/tasks/19e0214a', null],
            'a dot inside a segment' => ['ralph/v1.2', null],
            'the word lock not at the end' => ['ralph/lock.file', null],
            'an at sign on its own' => ['@', null],
            'HEAD below a prefix' => ['ralph/HEAD', null],

            'empty' => ['', 'is empty'],
            'a space' => ['ralph/add a flag', 'contains a space'],
            'a tab' => ["ralph/add\tflag", 'contains a space'],
            'a tilde' => ['ralph/~1', 'contains a space'],
            'a caret' => ['ralph/a^', 'contains a space'],
            'a colon' => ['ralph:a', 'contains a space'],
            'a question mark' => ['ralph/a?', 'contains a space'],
            'an asterisk' => ['ralph/*', 'contains a space'],
            'an open bracket' => ['ralph/a[1]', 'contains a space'],
            'a backslash' => ['ralph\\a', 'contains a space'],
            'a range' => ['ralph/a..b', 'contains ".."'],
            'a reflog entry' => ['ralph/a@{1}', 'contains "@{"'],
            'a leading dash' => ['-ralph/a', 'starts with "-"'],
            'HEAD itself' => ['HEAD', 'is "HEAD"'],
            'a leading slash' => ['/ralph/a', 'has an empty path segment'],
            'a trailing slash' => ['ralph/a/', 'has an empty path segment'],
            'a doubled slash' => ['ralph//a', 'has an empty path segment'],
            'a trailing dot' => ['ralph/a.', 'ends with "."'],
            'a hidden segment' => ['ralph/.a', 'has a segment starting with "."'],
            'a lock file' => ['ralph/a.lock', 'has a segment ending with ".lock"'],
        ];
    }

    #[Test]
    public function a_name_git_accepts_is_kept_as_it_is(): void
    {
        $name = BranchName::fromString('ralph/TASK-19E0214A-add-a-flag');

        self::assertSame('ralph/TASK-19E0214A-add-a-flag', $name->value());
        self::assertSame('ralph/TASK-19E0214A-add-a-flag', (string) $name);
        self::assertSame('refs/heads/ralph/TASK-19E0214A-add-a-flag', $name->reference());
    }

    #[Test]
    public function surrounding_whitespace_is_forgiven_because_git_output_adds_it(): void
    {
        self::assertSame('ralph/a', BranchName::fromString("  ralph/a\n")->value());
        self::assertTrue(BranchName::isValid("ralph/a\n"));
    }

    #[Test]
    #[DataProvider('names')]
    public function each_name_is_judged_by_gits_rules(string $name, ?string $problem): void
    {
        if ($problem === null) {
            self::assertNull(BranchName::problemWith($name), $name . ' should be usable');
            self::assertTrue(BranchName::isValid($name));
            self::assertSame($name, BranchName::fromString($name)->value());

            return;
        }

        self::assertFalse(BranchName::isValid($name), $name . ' should be refused');
        self::assertStringContainsString($problem, (string) BranchName::problemWith($name));
    }

    #[Test]
    public function a_refused_name_is_quoted_in_the_message_with_the_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"ralph/a.lock" is not a usable branch name: it has a segment ending with ".lock"');

        BranchName::fromString('ralph/a.lock');
    }

    #[Test]
    public function a_name_that_is_not_text_is_refused_before_it_reaches_a_database(): void
    {
        // Git stores a ref name as bytes and would accept this; a value written
        // to SQLite and serialised into a JSON event cannot carry it.
        self::assertFalse(BranchName::isValid("ralph/a\xC3"));
        self::assertStringContainsString('valid UTF-8', (string) BranchName::problemWith("ralph/a\xC3"));
    }

    #[Test]
    public function the_installed_git_agrees_about_every_name(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('git is not installed.');
        }

        $directory = TemporaryDirectory::create('ralph-branch-names');

        try {
            Git::initialise($directory);
            Git::commit($directory);

            foreach (self::names() as $case => [$name, $problem]) {
                // Git is asked for the branch rather than only for the
                // reference name: refs/heads/HEAD passes check-ref-format and
                // "git branch HEAD" does not.
                $accepted = Git::tryRun($directory, 'branch', '--', $name)->exitCode === 0;

                self::assertSame(
                    $problem === null,
                    $accepted,
                    sprintf('%s: "%s" is %s by this package and %s by git.', $case, $name, $problem === null ? 'accepted' : 'refused', $accepted ? 'accepted' : 'refused'),
                );
            }
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function a_prefix_is_the_only_part_of_a_spelling_that_is_trusted(): void
    {
        $name = BranchName::fromString('ralph/TASK-19E0214A');

        self::assertTrue($name->hasPrefix('ralph/'));
        self::assertFalse($name->hasPrefix('other/'));

        // An empty prefix would claim every branch in the repository.
        self::assertFalse($name->hasPrefix(''));
    }

    #[Test]
    public function two_names_are_equal_only_when_spelled_identically(): void
    {
        $name = BranchName::fromString('ralph/TASK-19E0214A');

        self::assertTrue($name->equals(BranchName::fromString('ralph/TASK-19E0214A')));
        self::assertFalse($name->equals(BranchName::fromString('ralph/task-19e0214a')));
    }
}
