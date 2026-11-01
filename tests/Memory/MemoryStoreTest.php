<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryNote;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Memory\MemorySelection;
use Celyes\RalphPhp\Memory\MemoryStore;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading notes out of the memory directory, within a budget.
 *
 * Against a real directory: the behaviour under test is which files are picked
 * up, in which order, and what a bound leaves out, and all three are properties
 * of a filesystem rather than of a parser.
 */
#[CoversClass(MemoryStore::class)]
#[CoversClass(MemoryNote::class)]
#[CoversClass(MemorySelection::class)]
final class MemoryStoreTest extends TestCase
{
    private TemporaryProject $project;

    private MemoryStore $store;

    private MemoryScope $task;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->store = MemoryStore::in($this->project->root());
        $this->task = MemoryScope::forTask(TaskId::fromDescription('Add a --dry-run option'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function memory_lives_under_the_runtime_directory_of_the_project(): void
    {
        self::assertSame(
            $this->project->path(Defaults::MEMORY_DIRECTORY),
            $this->store->directory(),
        );
    }

    #[Test]
    public function a_project_that_has_never_run_recalls_nothing(): void
    {
        $selection = $this->store->notesFor($this->task, new MemoryBudget());

        self::assertTrue($selection->isEmpty());
        self::assertSame(0, $selection->available());
        self::assertSame(0, $selection->omitted());
        self::assertSame(0, $selection->length());
        self::assertSame(0, $this->store->countFor($this->task));
    }

    #[Test]
    public function a_scopes_notes_are_returned_oldest_first(): void
    {
        $this->note($this->task, '0002-attempt.md', 'The second thing tried.');
        $this->note($this->task, '0001-attempt.md', 'The first thing tried.');
        $this->note($this->task, '0003-attempt.md', 'The third thing tried.');

        $notes = $this->store->notesFor($this->task, new MemoryBudget())->notes();

        self::assertSame(
            ['0001-attempt.md', '0002-attempt.md', '0003-attempt.md'],
            array_map(static fn (MemoryNote $note): string => $note->name(), $notes),
        );
        self::assertSame('The first thing tried.', $notes[0]->text()->text());
    }

    #[Test]
    public function one_scope_does_not_see_anothers_notes(): void
    {
        $other = MemoryScope::forTask(TaskId::fromDescription('Something else entirely'));

        $this->note($this->task, '0001-attempt.md', 'Mine.');
        $this->note($other, '0001-attempt.md', 'Not mine.');
        $this->note(MemoryScope::project(), '0001-tooling.md', 'Everyone\'s.');

        self::assertSame(['Mine.'], $this->texts($this->store->notesFor($this->task, new MemoryBudget())));
        self::assertSame(['Not mine.'], $this->texts($this->store->notesFor($other, new MemoryBudget())));
        self::assertSame(
            ['Everyone\'s.'],
            $this->texts($this->store->notesFor(MemoryScope::project(), new MemoryBudget())),
        );
    }

    #[Test]
    public function the_note_count_bound_keeps_the_newest_and_reports_the_rest_as_omitted(): void
    {
        foreach (range(1, 5) as $number) {
            $this->note($this->task, sprintf('%04d-attempt.md', $number), sprintf('Attempt %d.', $number));
        }

        $selection = $this->store->notesFor($this->task, new MemoryBudget(notes: 2));

        self::assertSame(['Attempt 4.', 'Attempt 5.'], $this->texts($selection));
        self::assertSame(5, $selection->available());
        self::assertSame(3, $selection->omitted());
    }

    #[Test]
    public function the_shared_character_bound_also_keeps_the_newest(): void
    {
        $this->note($this->task, '0001-attempt.md', str_repeat('old. ', 100));
        $this->note($this->task, '0002-attempt.md', str_repeat('new. ', 100));

        $selection = $this->store->notesFor(
            $this->task,
            new MemoryBudget(noteCharacters: 600, totalNoteCharacters: 600),
        );

        self::assertCount(1, $selection->notes());
        self::assertSame('0002-attempt.md', $selection->notes()[0]->name());
        self::assertSame(1, $selection->omitted());
    }

    #[Test]
    public function a_note_longer_than_its_own_bound_is_trimmed_rather_than_dropped(): void
    {
        $this->note($this->task, '0001-attempt.md', str_repeat("a line about the failure\n", 50));

        $note = $this->store->notesFor($this->task, new MemoryBudget(noteCharacters: 300))->notes()[0];

        self::assertTrue($note->text()->wasTrimmed());
        self::assertLessThanOrEqual(300, $note->length());
        self::assertSame(1250, $note->text()->originalLength());
    }

    #[Test]
    public function what_is_left_of_the_budget_is_not_spent_on_a_fragment_of_a_note(): void
    {
        $this->note($this->task, '0001-attempt.md', str_repeat('x', 400));
        $this->note($this->task, '0002-attempt.md', str_repeat('y', 400));

        // 450 characters is enough for the newest note and leaves 50, which is
        // too little to say anything with.
        $selection = $this->store->notesFor(
            $this->task,
            new MemoryBudget(noteCharacters: 400, totalNoteCharacters: 450),
        );

        self::assertSame([str_repeat('y', 400)], $this->texts($selection));
        self::assertSame(1, $selection->omitted());
    }

    #[Test]
    public function the_last_note_that_fits_is_trimmed_to_what_is_left_of_the_total(): void
    {
        foreach (range(1, 3) as $number) {
            $this->note($this->task, sprintf('%04d-attempt.md', $number), str_repeat('x', 400));
        }

        $selection = $this->store->notesFor(
            $this->task,
            new MemoryBudget(noteCharacters: 400, totalNoteCharacters: 1000),
        );

        // Two notes whole and the third held to the remaining 200: the shared
        // total is a total, not a per-note allowance applied three times.
        self::assertCount(3, $selection->notes());
        self::assertSame(1000, $selection->length());
        self::assertSame(200, $selection->notes()[0]->length());
        self::assertTrue($selection->notes()[0]->text()->wasTrimmed());
        self::assertFalse($selection->notes()[2]->text()->wasTrimmed());
    }

    #[Test]
    public function a_deliberately_tiny_budget_still_yields_its_one_note(): void
    {
        $this->note($this->task, '0001-attempt.md', str_repeat('z', 400));

        $selection = $this->store->notesFor(
            $this->task,
            new MemoryBudget(noteCharacters: 50, totalNoteCharacters: 50),
        );

        self::assertCount(1, $selection->notes());
        self::assertSame(50, $selection->notes()[0]->length());
    }

    #[Test]
    public function only_markdown_files_are_read(): void
    {
        $this->note($this->task, '0001-attempt.md', 'A note.');
        $this->note($this->task, '0002-attempt.MD', 'Also a note.');
        $this->note($this->task, '0003-attempt.md.tmp', 'An interrupted write.');
        $this->note($this->task, '0004-attempt.txt', 'Not a note.');
        $this->note($this->task, '.0005-hidden.md', 'Hidden.');
        mkdir($this->task->directoryIn($this->store->directory()) . '/0006-a-directory.md');

        $selection = $this->store->notesFor($this->task, new MemoryBudget());

        self::assertSame(['A note.', 'Also a note.'], $this->texts($selection));
        self::assertSame(2, $selection->available());
    }

    #[Test]
    public function an_empty_note_is_skipped_without_spending_the_budget(): void
    {
        $this->note($this->task, '0001-attempt.md', "\n  \n");
        $this->note($this->task, '0002-attempt.md', 'Something worth keeping.');

        $selection = $this->store->notesFor($this->task, new MemoryBudget());

        self::assertSame(['Something worth keeping.'], $this->texts($selection));
        self::assertSame(2, $selection->available());
    }

    #[Test]
    public function a_note_is_labelled_by_its_scope_and_file_name(): void
    {
        $this->note($this->task, '0001-attempt.md', 'A note.');

        $note = $this->store->notesFor($this->task, new MemoryBudget())->notes()[0];

        self::assertSame($this->task->label() . '/0001-attempt.md', $note->label());
        self::assertSame(
            $this->task->directoryIn($this->store->directory()) . '/0001-attempt.md',
            $note->path(),
        );
        self::assertTrue($this->task->equals($note->scope()));
    }

    #[Test]
    public function the_notes_in_a_scope_can_be_counted_without_reading_them(): void
    {
        $this->note($this->task, '0001-attempt.md', 'A note.');
        $this->note($this->task, '0002-attempt.md', 'Another.');

        self::assertSame(2, $this->store->countFor($this->task));
        self::assertSame(0, $this->store->countFor(MemoryScope::project()));
    }

    #[Test]
    public function an_unreadable_note_stops_the_attempt_rather_than_being_skipped(): void
    {
        $path = $this->note($this->task, '0001-attempt.md', 'The evidence from the last failure.');

        if (!chmod($path, 0o000) || is_readable($path)) {
            chmod($path, 0o600);
            self::markTestSkipped('This user can read a file with no permissions.');
        }

        try {
            $this->store->notesFor($this->task, new MemoryBudget());
            self::fail('Expected a FilesystemException.');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString('Unable to read the memory note', $exception->getMessage());
            self::assertStringContainsString('0001-attempt.md', $exception->getMessage());
        } finally {
            chmod($path, 0o600);
        }
    }

    /**
     * Write a note into a scope's directory.
     */
    private function note(MemoryScope $scope, string $name, string $contents): string
    {
        return $this->project->write(
            Defaults::MEMORY_DIRECTORY . '/' . $scope->directory() . '/' . $name,
            $contents,
        );
    }

    /**
     * @return list<string>
     */
    private function texts(MemorySelection $selection): array
    {
        return array_map(
            static fn (MemoryNote $note): string => $note->text()->text(),
            $selection->notes(),
        );
    }
}
