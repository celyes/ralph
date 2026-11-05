<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\ExcludedChange;
use Celyes\RalphPhp\Git\ExclusionReason;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\StagedFile;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the staged diff summary out of what Git prints.
 *
 * {@see WorkspaceStagerTest} covers this against real repositories, which is
 * where the formats are proved to be the ones Git actually produces. What is
 * left for a parser test is everything Git is not supposed to produce: a status
 * with no path, a rename missing half of itself, a line count that is not a
 * number. None of those can be provoked from a repository, and all of them must
 * raise rather than parse — a record of what a commit contains that is quietly
 * short is worse than none, because it will be believed.
 */
#[CoversClass(StagedChanges::class)]
#[CoversClass(StagedFile::class)]
#[CoversClass(ExcludedChange::class)]
final class StagedChangesTest extends TestCase
{
    #[Test]
    public function the_two_commands_ask_the_same_diff_two_questions(): void
    {
        // The arguments and the parser are one decision. --find-renames is
        // explicit so that a repository with diff.renames=false cannot turn
        // this summary into a description of the user's diff configuration.
        self::assertSame(
            ['diff', '--cached', '--name-status', '--find-renames', '-z'],
            StagedChanges::NAME_STATUS_COMMAND,
        );
        self::assertSame(
            ['diff', '--cached', '--numstat', '--find-renames', '-z'],
            StagedChanges::NUMSTAT_COMMAND,
        );
    }

    #[Test]
    public function statuses_and_line_counts_are_joined_by_path(): void
    {
        $changes = StagedChanges::parse(
            '/w',
            self::records('M', 'src/Greeting.php', 'A', 'src/Farewell.php', 'D', 'README.md'),
            self::records("3\t1\tsrc/Greeting.php", "7\t0\tsrc/Farewell.php", "0\t2\tREADME.md"),
        );

        self::assertFalse($changes->isEmpty());
        self::assertSame(3, $changes->count());
        self::assertSame('/w', $changes->workspace());
        self::assertSame(
            ['src/Greeting.php', 'src/Farewell.php', 'README.md'],
            $changes->paths(),
        );
        self::assertSame(10, $changes->insertions());
        self::assertSame(3, $changes->deletions());
        self::assertSame('M', $changes->files()[0]->status());
        self::assertSame(3, $changes->files()[0]->insertions());
        self::assertSame(1, $changes->files()[0]->deletions());
        self::assertSame('modified: src/Greeting.php (+3, -1)', $changes->files()[0]->describe());
    }

    #[Test]
    public function a_rename_is_read_from_both_outputs_as_one_file(): void
    {
        // Each output spells a rename differently: name-status gives the
        // status then two paths, numstat gives the counts with an empty path
        // field and then the two paths.
        $changes = StagedChanges::parse(
            '/w',
            self::records('R087', 'src/Greeting.php', 'src/Salutation.php'),
            self::records("2\t1\t", 'src/Greeting.php', 'src/Salutation.php'),
        );

        self::assertCount(1, $changes->files());

        $file = $changes->files()[0];
        self::assertSame('R087', $file->status());
        self::assertSame('src/Salutation.php', $file->path());
        self::assertSame('src/Greeting.php', $file->originalPath());
        self::assertSame(2, $file->insertions());
        self::assertSame(1, $file->deletions());
        self::assertSame(
            'renamed: src/Salutation.php (was src/Greeting.php) (+2, -1)',
            $file->describe(),
        );
    }

    #[Test]
    public function a_path_with_a_newline_in_it_survives_being_read(): void
    {
        // The reason for -z: this path would be printed quoted and escaped in
        // any other format, and a parser that forgot to undo that would read
        // one unusual filename as two.
        $path = "src/odd\nname.php";

        $changes = StagedChanges::parse(
            '/w',
            self::records('A', $path),
            self::records("1\t0\t" . $path),
        );

        self::assertSame([$path], $changes->paths());
    }

    #[Test]
    public function a_binary_file_has_no_line_counts_rather_than_none_changed(): void
    {
        $changes = StagedChanges::parse(
            '/w',
            self::records('A', 'logo.png', 'M', 'README.md'),
            self::records("-\t-\tlogo.png", "1\t1\tREADME.md"),
        );

        self::assertTrue($changes->files()[0]->isBinary());
        self::assertNull($changes->files()[0]->insertions());
        self::assertNull($changes->files()[0]->deletions());
        self::assertFalse($changes->files()[1]->isBinary());

        // A binary file contributes nothing to the totals, and is still a file.
        self::assertSame(2, $changes->count());
        self::assertSame(1, $changes->insertions());
        self::assertSame(1, $changes->deletions());
    }

    #[Test]
    public function an_empty_index_is_read_as_nothing_staged(): void
    {
        $changes = StagedChanges::parse('/w', '', '');

        self::assertTrue($changes->isEmpty());
        self::assertSame(0, $changes->count());
        self::assertSame([], $changes->files());
        self::assertSame([], $changes->details());
        self::assertSame('/w has nothing staged', $changes->summary());
    }

    #[Test]
    public function the_exclusions_are_carried_beside_what_was_staged(): void
    {
        // The half that cannot be reconstructed afterwards: once the commit
        // exists, a file that was never changed and one that was changed and
        // left out look exactly the same.
        $excluded = [
            ExcludedChange::of(
                WorkingTreeChange::of('?', '?', '.ralph/tasks.md'),
                ExclusionReason::Runtime,
            ),
            ExcludedChange::of(
                WorkingTreeChange::of(' ', 'M', '.env'),
                ExclusionReason::Local,
            ),
        ];

        $changes = StagedChanges::parse(
            '/w',
            self::records('M', 'src/Greeting.php'),
            self::records("1\t0\tsrc/Greeting.php"),
            $excluded,
        );

        self::assertCount(2, $changes->excluded());
        self::assertStringContainsString('2 change(s) excluded', $changes->summary());
        self::assertSame(
            'untracked file: .ralph/tasks.md — Ralph PHP\'s own files',
            $changes->excluded()[0]->describe(),
        );
        self::assertSame('.env', $changes->excluded()[1]->path());
        self::assertSame('local', $changes->excluded()[1]->toArray()['reason']);

        // The change's own state is kept, not only the fact that it was
        // skipped: "a credential was modified and left out" is a different
        // thing to read afterwards than "a credential was left out".
        self::assertSame(
            'modified: .env — a local file copied into the workspace',
            $changes->excluded()[1]->describe(),
        );
    }

    #[Test]
    public function nothing_staged_is_not_the_same_as_nothing_happening(): void
    {
        $changes = StagedChanges::nothingStaged('/w', [
            ExcludedChange::of(WorkingTreeChange::of('?', '?', '.env'), ExclusionReason::Local),
        ]);

        self::assertTrue($changes->isEmpty());
        self::assertSame('/w has nothing staged (1 change(s) excluded)', $changes->summary());
        self::assertCount(1, $changes->excluded());
    }

    #[Test]
    public function a_long_summary_is_bounded_and_says_how_much_it_left_out(): void
    {
        $statuses = [];
        $counts = [];

        for ($number = 1; $number <= 12; $number++) {
            $path = sprintf('src/File%02d.php', $number);
            $statuses[] = 'A';
            $statuses[] = $path;
            $counts[] = "1\t0\t" . $path;
        }

        $changes = StagedChanges::parse('/w', self::records(...$statuses), self::records(...$counts));

        self::assertCount(11, $changes->details());
        self::assertSame('and 2 more', $changes->details()[10]);
        self::assertSame(12, $changes->count());
    }

    #[Test]
    public function a_status_without_the_path_it_concerns_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('as "M" without naming the path it concerns');

        StagedChanges::parse('/w', self::records('M'), self::records("1\t0\tsrc/Greeting.php"));
    }

    #[Test]
    public function a_rename_missing_half_of_itself_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without naming both halves of the path it concerns');

        StagedChanges::parse(
            '/w',
            self::records('R100', 'src/Greeting.php'),
            self::records("0\t0\t", 'src/Greeting.php', 'src/Salutation.php'),
        );
    }

    #[Test]
    public function a_staged_path_with_no_line_counts_is_refused(): void
    {
        // The two outputs describe the same diff, so a path in one and not the
        // other means they were not taken of the same index.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"src/Farewell.php" as staged but gave no line counts');

        StagedChanges::parse(
            '/w',
            self::records('M', 'src/Greeting.php', 'A', 'src/Farewell.php'),
            self::records("1\t0\tsrc/Greeting.php"),
        );
    }

    #[Test]
    public function line_counts_that_are_not_two_numbers_and_a_path_are_refused(): void
    {
        foreach (["1\tsrc/Greeting.php", 'src/Greeting.php', "1\t"] as $record) {
            try {
                StagedChanges::parse('/w', self::records('M', 'src/Greeting.php'), self::records($record));
                self::fail(sprintf('parsed "%s" instead of refusing it', $record));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('in a form this cannot read', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_line_count_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reported "lots" as a staged file\'s line count');

        StagedChanges::parse(
            '/w',
            self::records('M', 'src/Greeting.php'),
            self::records("lots\t0\tsrc/Greeting.php"),
        );
    }

    #[Test]
    public function a_renamed_files_line_counts_without_both_paths_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line counts of a renamed file without naming both halves');

        StagedChanges::parse(
            '/w',
            self::records('R100', 'src/Greeting.php', 'src/Salutation.php'),
            self::records("0\t0\t", 'src/Greeting.php'),
        );
    }

    #[Test]
    public function a_staged_file_needs_a_status_a_path_and_counts_that_make_sense(): void
    {
        foreach ([['', 'src/Greeting.php'], ['M', '']] as [$status, $path]) {
            try {
                StagedFile::of($status, $path);
                self::fail('accepted a staged file with nothing to say about it');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('A staged file must', $exception->getMessage());
            }
        }

        try {
            StagedFile::of('M', 'src/Greeting.php', null, -1, 0);
            self::fail('accepted a negative number of insertions');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot have -1 insertions', $exception->getMessage());
        }
    }

    #[Test]
    public function an_unfamiliar_status_is_described_as_a_change_rather_than_as_a_letter(): void
    {
        self::assertSame(
            'changed: src/Greeting.php (+1, -0)',
            StagedFile::of('X', 'src/Greeting.php', null, 1, 0)->describe(),
        );
        self::assertSame(
            'type changed: src/Greeting.php (+0, -0)',
            StagedFile::of('T', 'src/Greeting.php', null, 0, 0)->describe(),
        );
    }

    #[Test]
    public function the_summary_is_available_as_data_as_well_as_prose(): void
    {
        $changes = StagedChanges::parse(
            '/w',
            self::records('M', 'src/Greeting.php'),
            self::records("3\t1\tsrc/Greeting.php"),
            [ExcludedChange::of(WorkingTreeChange::of('?', '?', '.env'), ExclusionReason::Local)],
        );

        self::assertSame([
            'workspace' => '/w',
            'files' => [[
                'status' => 'M',
                'path' => 'src/Greeting.php',
                'original_path' => null,
                'insertions' => 3,
                'deletions' => 1,
                'binary' => false,
            ]],
            'excluded' => [[
                'reason' => 'local',
                'code' => '??',
                'path' => '.env',
                'original_path' => null,
                'description' => 'untracked file: .env',
            ]],
            'count' => 1,
            'insertions' => 3,
            'deletions' => 1,
        ], $changes->toArray());
    }

    /**
     * NUL-terminated records, the way Git writes them under `-z`.
     */
    private static function records(string ...$records): string
    {
        return implode('', array_map(static fn (string $record): string => $record . "\0", $records));
    }
}
