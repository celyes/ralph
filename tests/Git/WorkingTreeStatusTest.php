<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkingTreeStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading `git status --porcelain=v1 -z`.
 *
 * The output is built by hand here rather than taken from a repository,
 * because the cases worth testing are the ones a repository makes awkward to
 * produce on demand — a filename containing a newline, a conflict, a record
 * Git could not have written. {@see ControllingCheckoutTest} asserts the same
 * parser against the real command, which is what keeps these fixtures honest
 * about the format.
 */
#[CoversClass(WorkingTreeStatus::class)]
#[CoversClass(WorkingTreeChange::class)]
final class WorkingTreeStatusTest extends TestCase
{
    #[Test]
    public function a_clean_working_tree_reports_nothing(): void
    {
        $status = WorkingTreeStatus::parse('');

        self::assertTrue($status->isClean());
        self::assertSame(0, $status->count());
        self::assertSame([], $status->changes());
    }

    #[Test]
    public function the_two_status_characters_are_both_kept(): void
    {
        $status = WorkingTreeStatus::parse(self::records('MM src/Foo.php'));

        $change = $status->changes()[0];

        self::assertSame('MM', $change->code());
        self::assertSame('src/Foo.php', $change->path());
        self::assertTrue($change->isStaged());
        self::assertFalse($change->isUntracked());
        self::assertFalse($change->isUnmerged());
        self::assertSame('modified (staged), modified (unstaged): src/Foo.php', $change->describe());
    }

    #[Test]
    public function a_change_only_in_the_working_tree_is_not_staged(): void
    {
        $change = WorkingTreeStatus::parse(self::records(' M src/Foo.php'))->changes()[0];

        self::assertFalse($change->isStaged());
        self::assertSame('modified: src/Foo.php', $change->describe());
    }

    #[Test]
    public function every_status_character_is_named_in_words(): void
    {
        $descriptions = self::describe(
            'M  modified.php',
            'A  added.php',
            'D  deleted.php',
            'T  retyped.php',
            ' M unstaged.php',
            ' D gone.php',
            'AD vanished.php',
        );

        self::assertSame([
            'modified (staged): modified.php',
            'added (staged): added.php',
            'deleted (staged): deleted.php',
            'type changed (staged): retyped.php',
            'modified: unstaged.php',
            'deleted: gone.php',
            'added (staged), deleted (unstaged): vanished.php',
        ], $descriptions);
    }

    #[Test]
    public function a_status_character_this_version_does_not_know_is_still_reported(): void
    {
        // Not a status Git emits today. The point is that an added one is
        // reported as a change to a named path rather than dropped.
        $change = WorkingTreeStatus::parse(self::records('X  surprising.php'))->changes()[0];

        self::assertSame('X ', $change->code());
        self::assertSame('changed (staged): surprising.php', $change->describe());
    }

    #[Test]
    public function a_rename_carries_the_path_it_came_from(): void
    {
        $status = WorkingTreeStatus::parse(self::records('R  sub/new.txt', 'sub/old.txt'));

        $change = $status->changes()[0];

        self::assertSame(1, $status->count());
        self::assertSame('sub/new.txt', $change->path());
        self::assertSame('sub/old.txt', $change->originalPath());
        self::assertSame(['sub/new.txt', 'sub/old.txt'], $change->paths());
        self::assertSame('renamed (staged): sub/new.txt (was sub/old.txt)', $change->describe());
    }

    #[Test]
    public function a_copy_carries_the_path_it_came_from(): void
    {
        $change = WorkingTreeStatus::parse(self::records('C  copy.txt', 'original.txt'))->changes()[0];

        self::assertSame('original.txt', $change->originalPath());
        self::assertSame('copied (staged): copy.txt (was original.txt)', $change->describe());
    }

    #[Test]
    public function a_second_path_is_only_consumed_by_the_record_that_has_one(): void
    {
        $status = WorkingTreeStatus::parse(self::records(
            'R  sub/new.txt',
            'sub/old.txt',
            ' M after.php',
            '?? last.php',
        ));

        self::assertSame(3, $status->count());
        self::assertSame(['sub/new.txt', 'after.php', 'last.php'], self::paths($status));
        self::assertNull($status->changes()[1]->originalPath());
    }

    #[Test]
    public function an_untracked_file_and_an_untracked_directory_are_told_apart(): void
    {
        $status = WorkingTreeStatus::parse(self::records('?? notes.txt', '?? nested/'));

        self::assertSame('untracked file: notes.txt', $status->changes()[0]->describe());
        self::assertSame('untracked directory: nested/', $status->changes()[1]->describe());

        foreach ($status->changes() as $change) {
            self::assertTrue($change->isUntracked());
            self::assertFalse($change->isStaged(), 'an untracked path cannot be staged');
            self::assertFalse($change->isUnmerged());
        }
    }

    #[Test]
    public function every_shape_of_conflict_is_recognised_as_unmerged(): void
    {
        $status = WorkingTreeStatus::parse(self::records(
            'UU both.php',
            'AU mine.php',
            'UD theirs.php',
            'AA added-by-both.php',
            'DD deleted-by-both.php',
        ));

        foreach ($status->changes() as $change) {
            self::assertTrue($change->isUnmerged(), $change->code() . ' is a conflict');
            self::assertSame('unmerged: ' . $change->path(), $change->describe());
        }
    }

    #[Test]
    public function a_path_is_taken_verbatim_whatever_is_in_it(): void
    {
        // The reason for -z: none of these can be read back out of the quoted
        // form `git status --porcelain` prints without it.
        $awkward = [
            'with space.php',
            "with\nnewline.php",
            'with"quote".php',
            'with\\backslash.php',
            'with→arrow.php',
        ];

        $status = WorkingTreeStatus::parse(self::records(...array_map(
            static fn (string $path): string => ' M ' . $path,
            $awkward,
        )));

        self::assertSame($awkward, self::paths($status));
    }

    #[Test]
    public function changes_keep_the_order_git_reported_them_in(): void
    {
        $status = WorkingTreeStatus::parse(self::records(' M b.php', ' M a.php', '?? c.php'));

        self::assertSame(['b.php', 'a.php', 'c.php'], self::paths($status));
    }

    #[Test]
    public function a_record_that_is_not_a_status_pair_and_a_path_is_refused(): void
    {
        foreach (['M', 'M  ', 'MM', 'MMsrc/Foo.php', '   '] as $record) {
            try {
                WorkingTreeStatus::parse($record . "\0");
                self::fail(sprintf('parsed "%s" instead of refusing it', $record));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('change this cannot read', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_rename_without_the_path_it_came_from_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"sub/new.txt" as renamed or copied without saying where it came from');

        WorkingTreeStatus::parse(self::records('R  sub/new.txt'));
    }

    #[Test]
    public function the_command_asks_for_the_format_this_parses(): void
    {
        // The arguments and the parser are one decision: a reader of the
        // porcelain format must be the thing that requested it, and untracked
        // files must be asked for rather than left to the repository's own
        // configuration.
        self::assertSame(
            ['status', '--porcelain=v1', '-z', '--untracked-files=normal'],
            WorkingTreeStatus::COMMAND,
        );
    }

    #[Test]
    public function a_change_is_reported_as_data_as_well_as_prose(): void
    {
        $change = WorkingTreeStatus::parse(self::records('R  new.txt', 'old.txt'))->changes()[0];

        self::assertSame([
            'code' => 'R ',
            'path' => 'new.txt',
            'original_path' => 'old.txt',
            'description' => 'renamed (staged): new.txt (was old.txt)',
        ], $change->toArray());
    }

    #[Test]
    public function a_change_needs_single_character_statuses_and_a_path(): void
    {
        foreach ([['MM', ' '], [' ', ''], ['', ' ']] as [$index, $worktree]) {
            try {
                WorkingTreeChange::of($index, $worktree, 'src/Foo.php');
                self::fail('accepted a status that is not one character');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('is one character', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a path');

        WorkingTreeChange::of('M', ' ', '');
    }

    /**
     * NUL-terminated records, as Git writes them.
     */
    private static function records(string ...$records): string
    {
        $output = '';

        foreach ($records as $record) {
            $output .= $record . "\0";
        }

        return $output;
    }

    /**
     * @return list<string>
     */
    private static function describe(string ...$records): array
    {
        return array_map(
            static fn (WorkingTreeChange $change): string => $change->describe(),
            WorkingTreeStatus::parse(self::records(...$records))->changes(),
        );
    }

    /**
     * @return list<string>
     */
    private static function paths(WorkingTreeStatus $status): array
    {
        return array_map(static fn (WorkingTreeChange $change): string => $change->path(), $status->changes());
    }
}
