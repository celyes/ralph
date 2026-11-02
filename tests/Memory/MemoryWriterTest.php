<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Memory\AttemptSummary;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryNote;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Memory\MemoryStore;
use Celyes\RalphPhp\Memory\MemoryWriter;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Putting one attempt's note on disk for the next attempt to find.
 *
 * Against a real directory, like the reader's tests and for the same reason:
 * what is under test is the name a note gets, that a note already there is
 * never replaced, and that a run killed mid-write leaves nothing rather than
 * half a note — all properties of a filesystem.
 *
 * The *name* is most of what is tested here, because the name is the whole of
 * the ordering contract: the reader sorts file names as bytes and hands the
 * notes over oldest-first, so a sequence that did not grow, or grew in the
 * wrong place in the name, would quietly show the next attempt its history
 * backwards.
 */
#[CoversClass(MemoryWriter::class)]
final class MemoryWriterTest extends TestCase
{
    private TemporaryProject $project;

    private FrozenClock $clock;

    private MemoryWriter $writer;

    private MemoryScope $task;

    private TaskGeneration $generation;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->clock = FrozenClock::at('2026-11-01T09:30:00+00:00');
        $this->writer = MemoryWriter::in($this->project->root(), $this->clock);
        $this->generation = TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option'));
        $this->task = MemoryScope::forTask($this->generation->task());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function notes_are_written_where_the_reader_looks_for_them(): void
    {
        self::assertSame(
            $this->project->path(Defaults::MEMORY_DIRECTORY),
            $this->writer->directory(),
            'The writer and the reader disagree about where memory lives, so every note '
            . 'written would be invisible to the attempt meant to read it.',
        );
    }

    #[Test]
    public function a_note_is_named_with_its_sequence_then_its_moment_then_its_subject(): void
    {
        $note = $this->writer->write($this->task, 'attempt-02-candidate', 'The option parser rejected it.');

        self::assertSame('0001-20261101T093000Z-attempt-02-candidate.md', $note->name());
        self::assertSame(
            $this->project->path(Defaults::MEMORY_DIRECTORY . '/' . $this->task->directory())
                . '/0001-20261101T093000Z-attempt-02-candidate.md',
            $note->path(),
        );
        self::assertFileExists($note->path());
    }

    #[Test]
    public function the_sequence_continues_from_the_notes_already_there(): void
    {
        $this->existing('0007-20261030T120000Z-attempt-01-failed.md');

        $note = $this->writer->write($this->task, 'attempt-02-failed', 'The second thing tried.');

        self::assertStringStartsWith('0008-', $note->name());
    }

    #[Test]
    public function names_the_package_did_not_write_take_no_sequence(): void
    {
        $this->existing('my-own-notes.md');
        $this->existing('README');

        $note = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');

        self::assertStringStartsWith('0001-', $note->name());
    }

    /**
     * The reason the name carries a counter and not only a timestamp.
     */
    #[Test]
    public function a_clock_corrected_backwards_still_files_the_later_note_later(): void
    {
        $first = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');

        $second = $this->writer->write(
            $this->task,
            'attempt-02-failed',
            'The second thing tried.',
            Timestamp::fromStorage('2026-10-30T12:00:00+00:00'),
        );

        self::assertStringStartsWith('0001-20261101T093000Z-', $first->name());
        self::assertStringStartsWith('0002-20261030T120000Z-', $second->name());
        self::assertLessThan(
            0,
            strcmp($first->name(), $second->name()),
            'The note written second sorts before the note written first, so the next attempt '
            . 'would read its history in the wrong order after a clock correction.',
        );
    }

    #[Test]
    public function each_scope_counts_its_own_notes(): void
    {
        $this->writer->write($this->task, 'attempt-01-failed', 'What this task tried.');

        $note = $this->writer->write(MemoryScope::project(), 'conventions', 'The suite needs a database.');

        self::assertStringStartsWith('0001-', $note->name());
        self::assertTrue($note->scope()->isProject());
        self::assertSame(
            $this->project->path(Defaults::MEMORY_DIRECTORY . '/' . MemoryScope::PROJECT),
            dirname($note->path()),
        );
    }

    #[Test]
    public function a_note_is_never_replaced_by_a_later_one(): void
    {
        $first = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');
        $second = $this->writer->write($this->task, 'attempt-01-failed', 'A later note, same subject.');

        self::assertNotSame($first->path(), $second->path());
        self::assertSame("The first thing tried.\n", (string) file_get_contents($first->path()));
        self::assertSame("A later note, same subject.\n", (string) file_get_contents($second->path()));
    }

    #[Test]
    public function a_run_killed_mid_write_leaves_no_half_written_note(): void
    {
        $note = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');

        self::assertSame(
            [$note->name()],
            $this->contentsOf(dirname($note->path())),
            'Something other than the note was left in the directory; a leftover temporary '
            . 'file is a note the reader may pick up as the truth.',
        );
        self::assertFileDoesNotExist($note->path() . MemoryWriter::TEMPORARY_SUFFIX);
    }

    #[Test]
    public function the_note_on_disk_ends_with_exactly_one_newline(): void
    {
        $note = $this->writer->write($this->task, 'attempt-01-failed', "The first thing tried.\n\n\n");

        self::assertSame("The first thing tried.\n", (string) file_get_contents($note->path()));
    }

    #[Test]
    public function the_note_handed_back_carries_its_scope_and_its_whole_text(): void
    {
        $note = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');

        self::assertTrue($note->scope()->equals($this->task));
        self::assertSame('The first thing tried.', $note->text()->text());
        self::assertFalse($note->text()->wasTrimmed());
    }

    #[Test]
    public function notes_are_the_users_own_to_read(): void
    {
        $note = $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');

        clearstatcache();

        self::assertSame(0, ((int) fileperms($note->path())) & 0o077);
        self::assertSame(0, ((int) fileperms(dirname($note->path()))) & 0o077);
    }

    #[Test]
    public function a_slug_that_is_not_a_file_name_is_refused(): void
    {
        foreach (['Attempt-02', 'attempt 02', '-attempt', '', 'attempt_02', '../escape', 'attempt.02'] as $slug) {
            try {
                $this->writer->write($this->task, $slug, 'Something happened.');
                self::fail(sprintf('Expected "%s" to be refused as a note name.', $slug));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('lowercase words joined by hyphens', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_note_that_says_nothing_is_refused_and_writes_nothing(): void
    {
        foreach (['', "  \n\t "] as $contents) {
            try {
                $this->writer->write($this->task, 'attempt-01-failed', $contents);
                self::fail('Expected an empty note to be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must say something', $exception->getMessage());
            }
        }

        self::assertDirectoryDoesNotExist($this->task->directoryIn($this->writer->directory()));
    }

    #[Test]
    public function an_attempt_with_nothing_to_pass_on_leaves_no_note(): void
    {
        $empty = new AttemptSummary(
            $this->generation,
            1,
            AttemptOutcome::Candidate,
            'codex',
            UnverifiedText::none(),
            FailureEvidence::none(),
            Timestamp::fromStorage('2026-11-01T09:30:00+00:00'),
            null,
        );

        self::assertNull($this->writer->record($empty));
        self::assertDirectoryDoesNotExist($this->task->directoryIn($this->writer->directory()));
    }

    #[Test]
    public function a_finished_attempt_is_written_up_under_the_moment_it_was_recorded(): void
    {
        // The clock is later than the attempt: a note is written up after the
        // attempt it describes, and it is the attempt's own moment that dates
        // it, not the moment the writing happened to get around to it.
        $this->clock->advance(3_600);

        $note = $this->writer->record($this->summary());

        self::assertNotNull($note);
        self::assertSame('0001-20261101T093000Z-attempt-02-candidate.md', $note->name());
        self::assertTrue($note->scope()->equals($this->task));

        $written = (string) file_get_contents($note->path());
        self::assertStringContainsString('# ' . $this->generation->label() . ', attempt 2:', $written);
        self::assertStringContainsString('not a record of what passed', $written);
        self::assertStringContainsString('composer test failed (exit 1)', $written);
    }

    #[Test]
    public function a_blocked_attempt_is_named_for_what_stopped_it(): void
    {
        $note = $this->writer->record($this->summary(
            outcome: AttemptOutcome::Blocked,
            blocker: Blocker::from('codex', 'Which database should the suite use?', 'Answer in CONTEXT.md.'),
        ));

        self::assertNotNull($note);
        self::assertStringEndsWith('attempt-02-blocked.md', $note->name());
    }

    /**
     * The round trip, which is the only thing that proves the two halves of
     * memory agree: the writer owns the names, and the reader's ordering is
     * nothing but a comparison of them.
     */
    #[Test]
    public function the_notes_come_back_in_the_order_they_were_written(): void
    {
        $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');
        $this->clock->advance(600);
        $this->writer->write($this->task, 'attempt-02-failed', 'The second thing tried.');
        $this->clock->advance(600);
        $this->writer->write($this->task, 'attempt-03-failed', 'The third thing tried.');

        $selection = MemoryStore::in($this->project->root())->notesFor($this->task, new MemoryBudget());

        // Trimmed on the way in to the comparison: the reader hands back the
        // bytes of the file, and the writer closes every note with a newline.
        // What is under test is the order, not the whitespace.
        self::assertSame(
            ['The first thing tried.', 'The second thing tried.', 'The third thing tried.'],
            array_map(
                static fn (MemoryNote $note): string => trim($note->text()->text()),
                $selection->notes(),
            ),
        );
    }

    #[Test]
    public function a_file_where_the_notes_go_is_not_written_over(): void
    {
        $this->project->write(
            Defaults::MEMORY_DIRECTORY . '/' . $this->task->directory(),
            'a file someone put here',
        );

        try {
            $this->writer->write($this->task, 'attempt-01-failed', 'The first thing tried.');
            self::fail('Expected a FilesystemException.');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString('where Ralph PHP keeps its notes', $exception->getMessage());
            self::assertSame(
                'a file someone put here',
                (string) file_get_contents($this->project->path(
                    Defaults::MEMORY_DIRECTORY . '/' . $this->task->directory(),
                )),
            );
        }
    }

    #[Test]
    public function a_directory_that_cannot_be_listed_stops_the_write(): void
    {
        $directory = dirname($this->existing('0001-20261030T120000Z-attempt-01-failed.md'));

        if (!chmod($directory, 0o000) || is_readable($directory)) {
            chmod($directory, 0o700);
            self::markTestSkipped('This user can list a directory with no permissions.');
        }

        try {
            $this->writer->write($this->task, 'attempt-02-failed', 'The second thing tried.');
            self::fail('Expected a FilesystemException.');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString('Unable to read the memory directory', $exception->getMessage());
        } finally {
            chmod($directory, 0o700);
        }
    }

    private function summary(
        ?AttemptOutcome $outcome = AttemptOutcome::Candidate,
        ?Blocker $blocker = null,
    ): AttemptSummary {
        return new AttemptSummary(
            $this->generation,
            2,
            $outcome,
            'codex',
            UnverifiedText::of('I rewrote the option parser.'),
            FailureEvidence::of('composer test failed (exit 1)', 'FAILURES!'),
            Timestamp::fromStorage('2026-11-01T09:30:00+00:00'),
            $blocker,
        );
    }

    /**
     * A note already in the task's directory, written behind the writer's back.
     */
    private function existing(string $name): string
    {
        return $this->project->write(
            Defaults::MEMORY_DIRECTORY . '/' . $this->task->directory() . '/' . $name,
            'An earlier note.',
        );
    }

    /**
     * @return list<string>
     */
    private function contentsOf(string $directory): array
    {
        $entries = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($entries);

        /** @var list<string> $entries */
        return $entries;
    }
}
