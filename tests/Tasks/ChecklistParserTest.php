<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Tasks\Checklist;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading top-level checklist items out of Markdown.
 *
 * The checklist is a file a human edits, so these tests are written as the
 * documents a user plausibly produces — a heading above the tasks, a worked
 * example in a code fence, Windows line endings, an item ticked by hand — and
 * assert what the parser makes of each.
 */
#[CoversClass(ChecklistParser::class)]
#[CoversClass(Checklist::class)]
#[CoversClass(ChecklistItem::class)]
final class ChecklistParserTest extends TestCase
{
    private ChecklistParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ChecklistParser();
    }

    #[Test]
    public function pending_and_checked_items_are_read_in_file_order(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [x] Extract the importer
            - [ ] Add a --dry-run option
            - [X] Document the exit codes
            - [ ] Cover the parser with tests
            MARKDOWN);

        self::assertSame(4, $checklist->count());
        self::assertFalse($checklist->isEmpty());
        self::assertSame(
            [
                ['Extract the importer', true],
                ['Add a --dry-run option', false],
                ['Document the exit codes', true],
                ['Cover the parser with tests', false],
            ],
            array_map(
                static fn (ChecklistItem $item): array => [$item->description(), $item->isChecked()],
                $checklist->items(),
            ),
        );
    }

    #[Test]
    public function an_item_records_where_it_was_read_from(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            # Tasks

            Some prose about the project.

            - [ ] Add a --dry-run option
            MARKDOWN);

        $item = $checklist->items()[0];

        self::assertSame(5, $item->line());
        self::assertSame('- [ ] Add a --dry-run option', $item->source());
    }

    #[Test]
    public function the_source_path_is_kept_with_the_result(): void
    {
        $checklist = $this->parser->parse("- [ ] Work\n", '/srv/project/.ralph/tasks.md');

        self::assertSame('/srv/project/.ralph/tasks.md', $checklist->source());
        self::assertNull($this->parser->parse("- [ ] Work\n")->source());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function items(): iterable
    {
        yield 'dash bullet' => ['- [ ] Work', 'Work'];
        yield 'asterisk bullet' => ['* [ ] Work', 'Work'];
        yield 'plus bullet' => ['+ [ ] Work', 'Work'];
        yield 'several spaces after the bullet' => ['-   [ ] Work', 'Work'];
        yield 'tab after the bullet' => ["-\t[ ] Work", 'Work'];
        yield 'several spaces after the checkbox' => ['- [ ]   Work', 'Work'];
        yield 'tab after the checkbox' => ["- [ ]\tWork", 'Work'];
        yield 'trailing whitespace is dropped' => ['- [ ] Work   ', 'Work'];
        yield 'inner markdown is kept' => ['- [ ] Fix `Importer::run()` **now**', 'Fix `Importer::run()` **now**'];
        yield 'brackets in the description' => ['- [ ] Handle [x] markers in input', 'Handle [x] markers in input'];
        yield 'non-ascii description' => ['- [ ] Gérer les accents', 'Gérer les accents'];
    }

    #[Test]
    #[DataProvider('items')]
    public function recognised_items(string $line, string $expected): void
    {
        $checklist = $this->parser->parse($line);

        self::assertSame(1, $checklist->count(), 'Expected the line to be read as one item.');
        self::assertSame($expected, $checklist->items()[0]->description());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonItems(): iterable
    {
        yield 'heading' => ['# Tasks'];
        yield 'prose' => ['Add a --dry-run option to the import command.'];
        yield 'prose mentioning the syntax' => ['Write tasks as - [ ] items.'];
        yield 'plain bullet' => ['- Add a --dry-run option'];
        yield 'bullet with no space before the checkbox' => ['-[ ] Work'];
        yield 'bullet with no space after the checkbox' => ['- [ ]Work'];
        yield 'empty checkbox marker' => ['- [] Work'];
        yield 'unsupported marker' => ['- [?] Work'];
        yield 'two-character marker' => ['- [xx] Work'];
        yield 'no description' => ['- [ ]'];
        yield 'whitespace description' => ['- [ ]    '];
        yield 'indented item' => ['  - [ ] Work'];
        yield 'tab-indented item' => ["\t- [ ] Work"];
        yield 'space before the bullet' => [' - [ ] Work'];
        yield 'ordered list item' => ['1. [ ] Work'];
        yield 'blockquoted item' => ['> - [ ] Work'];
        yield 'checkbox later in the line' => ['Later - [ ] Work'];
        yield 'blank line' => [''];
    }

    #[Test]
    #[DataProvider('nonItems')]
    public function lines_that_are_not_items(string $line): void
    {
        self::assertTrue($this->parser->parse($line)->isEmpty(), 'Expected no item from: ' . $line);
    }

    #[Test]
    public function items_inside_a_fenced_code_block_are_not_tasks(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            # Tasks

            Write tasks like this:

            ```markdown
            - [ ] Add a --dry-run option
            - [x] Something already done
            ```

            - [ ] The only real task
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame('The only real task', $checklist->items()[0]->description());
        self::assertSame(10, $checklist->items()[0]->line());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fences(): iterable
    {
        yield 'backticks' => ['```'];
        yield 'backticks with an info string' => ['```markdown'];
        yield 'more than three backticks' => ['`````'];
        yield 'tildes' => ['~~~'];
        yield 'tildes with an info string' => ['~~~ markdown'];
        yield 'indented fence' => ['   ```'];
    }

    #[Test]
    #[DataProvider('fences')]
    public function every_fence_spelling_hides_the_items_it_encloses(string $fence): void
    {
        $closing = str_contains($fence, '`') ? '`````' : '~~~~~';

        $checklist = $this->parser->parse(
            $fence . "\n- [ ] Hidden\n" . $closing . "\n- [ ] Visible\n",
        );

        self::assertSame(1, $checklist->count());
        self::assertSame('Visible', $checklist->items()[0]->description());
    }

    #[Test]
    public function a_fence_is_closed_only_by_its_own_character_and_length(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            ````
            - [ ] Hidden
            ~~~
            - [ ] Still hidden
            ```
            - [ ] Also still hidden
            ````
            - [ ] Visible
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame('Visible', $checklist->items()[0]->description());
    }

    #[Test]
    public function an_unclosed_fence_hides_everything_after_it(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Visible

            ```markdown
            - [ ] Hidden
            - [ ] Hidden too
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame('Visible', $checklist->items()[0]->description());
    }

    #[Test]
    public function a_code_span_starting_a_line_does_not_open_a_fence(): void
    {
        // The info string of a backtick fence cannot contain a backtick, so
        // this line is prose holding a code span, not the start of a block.
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            ``` `- [ ] x` ``` is how a literal checkbox is written inline.

            - [ ] Visible
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame('Visible', $checklist->items()[0]->description());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'unix' => ["\n"];
        yield 'windows' => ["\r\n"];
        yield 'classic mac' => ["\r"];
    }

    #[Test]
    #[DataProvider('lineEndings')]
    public function line_endings_are_recognised_and_kept_out_of_descriptions(string $ending): void
    {
        $checklist = $this->parser->parse(
            '# Tasks' . $ending . $ending . '- [ ] Work' . $ending . '- [x] Done' . $ending,
        );

        self::assertSame(2, $checklist->count());
        self::assertSame('Work', $checklist->items()[0]->description());
        self::assertSame(3, $checklist->items()[0]->line());
        self::assertSame('Done', $checklist->items()[1]->description());
        self::assertSame(4, $checklist->items()[1]->line());
    }

    #[Test]
    public function a_byte_order_mark_does_not_hide_the_first_item(): void
    {
        $checklist = $this->parser->parse("\xEF\xBB\xBF- [ ] Work\n");

        self::assertSame(1, $checklist->count());
        self::assertSame('Work', $checklist->items()[0]->description());
    }

    #[Test]
    public function an_empty_document_has_no_items(): void
    {
        self::assertTrue($this->parser->parse('')->isEmpty());
        self::assertSame(0, $this->parser->parse("\n\n")->count());
    }

    #[Test]
    public function contents_that_are_not_utf8_are_refused(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('/srv/project/.ralph/tasks.md is not valid UTF-8');

        $this->parser->parse("- [ ] Caf\xE9\n", '/srv/project/.ralph/tasks.md');
    }

    #[Test]
    public function the_checklist_written_by_ralph_init_has_no_tasks(): void
    {
        self::assertTrue(
            $this->parser->parse(Templates::tasks())->isEmpty(),
            'A freshly initialised project must have nothing pending: the template\'s example is fenced.',
        );
    }

    #[Test]
    public function a_task_written_on_its_own_has_no_details(): void
    {
        $item = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
            - [ ] Document it
            MARKDOWN)->items()[0];

        self::assertFalse($item->hasDetails());
        self::assertSame([], $item->detailLines());
        self::assertSame('', $item->details());
        self::assertSame($item->line(), $item->endLine());
    }

    #[Test]
    public function indented_bullets_below_a_task_become_its_details(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            # Tasks

            - [ ] Add a --dry-run option
              - Print what would change and exit 0.
              - Touch nothing on disk.
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(2, $checklist->count());

        $item = $checklist->items()[0];
        self::assertSame('Add a --dry-run option', $item->description());
        self::assertTrue($item->hasDetails());
        self::assertSame(
            ['  - Print what would change and exit 0.', '  - Touch nothing on disk.'],
            $item->detailLines(),
        );
        self::assertSame(
            "- Print what would change and exit 0.\n- Touch nothing on disk.",
            $item->details(),
        );
        self::assertSame(3, $item->line());
        self::assertSame(5, $item->endLine());

        self::assertFalse($checklist->items()[1]->hasDetails());
    }

    #[Test]
    public function an_indented_paragraph_below_a_task_becomes_its_details(): void
    {
        $item = $this->parser->parse(<<<'MARKDOWN'
            - [x] Extract the importer
              It has grown two responsibilities, so move the parsing half out
              into its own class and leave the writing half where it is.
            MARKDOWN)->items()[0];

        self::assertTrue($item->isChecked());
        self::assertSame(
            "It has grown two responsibilities, so move the parsing half out\ninto its own class and leave the writing half where it is.",
            $item->details(),
        );
    }

    #[Test]
    public function details_can_hold_several_paragraphs(): void
    {
        $item = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Add a --dry-run option

              Acceptance criteria:

              - The command exits 0 and writes nothing.
              - The summary names every file it would have changed.
            MARKDOWN)->items()[0];

        self::assertSame(
            <<<'DETAILS'

            Acceptance criteria:

            - The command exits 0 and writes nothing.
            - The summary names every file it would have changed.
            DETAILS,
            $item->details(),
        );
        self::assertSame(6, $item->endLine());
    }

    #[Test]
    public function blank_lines_before_the_next_task_are_not_details(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
              Print what would change.


            - [ ] Document the exit codes

            MARKDOWN);

        $item = $checklist->items()[0];
        self::assertSame(['  Print what would change.'], $item->detailLines());
        self::assertSame(2, $item->endLine());
        self::assertFalse($checklist->items()[1]->hasDetails());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function detailTerminators(): iterable
    {
        yield 'another task' => ['- [ ] Document the exit codes'];
        yield 'a heading' => ['## Later'];
        yield 'a paragraph' => ['Everything below is for the next release.'];
        yield 'a plain bullet' => ['- Not a task, and not indented either'];
        yield 'a fence' => ['```'];
    }

    #[Test]
    #[DataProvider('detailTerminators')]
    public function details_end_at_the_first_line_in_column_one(string $terminator): void
    {
        $item = $this->parser->parse(
            "- [ ] Add a --dry-run option\n  Print what would change.\n" . $terminator . "\n",
        )->items()[0];

        self::assertSame(['  Print what would change.'], $item->detailLines());
        self::assertSame(2, $item->endLine());
    }

    #[Test]
    public function details_keep_their_own_nesting_and_lose_the_indentation_they_share(): void
    {
        $item = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
                - Print what would change
                  - one line per file
                  - with the old and the new value
                - Exit 0
            MARKDOWN)->items()[0];

        self::assertSame(
            <<<'DETAILS'
            - Print what would change
              - one line per file
              - with the old and the new value
            - Exit 0
            DETAILS,
            $item->details(),
        );
    }

    #[Test]
    public function details_indented_with_tabs_are_dedented_by_the_prefix_they_share(): void
    {
        $item = $this->parser->parse(
            "- [ ] Add a --dry-run option\n\t- Print what would change\n\t\t- one line per file\n",
        )->items()[0];

        self::assertSame("- Print what would change\n\t- one line per file", $item->details());
    }

    #[Test]
    public function a_detail_block_mixing_tabs_and_spaces_only_loses_what_every_line_has(): void
    {
        // No tab width is assumed: the shared prefix of "\t" and "    " is
        // nothing, so both lines keep the whitespace they were written with.
        $item = $this->parser->parse(
            "- [ ] Add a --dry-run option\n\t- Tab indented\n    - Space indented\n",
        )->items()[0];

        self::assertSame("\t- Tab indented\n    - Space indented", $item->details());
    }

    #[Test]
    public function detail_lines_are_kept_exactly_as_read(): void
    {
        $item = $this->parser->parse(
            "- [ ] Add a --dry-run option\n  Print what would change.   \n   \n  Then exit 0.\n",
        )->items()[0];

        self::assertSame(
            ['  Print what would change.   ', '', '  Then exit 0.'],
            $item->detailLines(),
            'Detail lines keep their trailing spaces; a whitespace-only line is emptied.',
        );
    }

    #[Test]
    public function details_are_read_the_same_with_windows_line_endings(): void
    {
        $item = $this->parser->parse(
            "- [ ] Add a --dry-run option\r\n  Print what would change.\r\n  Then exit 0.\r\n",
        )->items()[0];

        self::assertSame("Print what would change.\nThen exit 0.", $item->details());
    }

    #[Test]
    public function an_indented_checkbox_becomes_a_detail_of_the_task_above_it(): void
    {
        // PROJECT.md section C, item 3 makes an unsupported nested checkable
        // subtask a readable error. Until then it is text under the task, which
        // at least keeps it in front of the agent instead of dropping it.
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Add a --dry-run option
              - [ ] Print what would change
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame('- [ ] Print what would change', $checklist->items()[0]->details());
    }

    #[Test]
    public function a_fenced_example_inside_details_is_kept_and_hides_its_checkboxes(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Document the checklist syntax
              Show the reader this:

              ```markdown
              - [ ] A task
                - a detail
              ```

              And nothing else.
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(2, $checklist->count(), 'The fenced example holds no tasks.');
        self::assertSame(
            <<<'DETAILS'
            Show the reader this:

            ```markdown
            - [ ] A task
              - a detail
            ```

            And nothing else.
            DETAILS,
            $checklist->items()[0]->details(),
        );
        self::assertSame(9, $checklist->items()[0]->endLine());
        self::assertSame(10, $checklist->items()[1]->line());
    }

    #[Test]
    public function a_column_one_checkbox_inside_a_detail_fence_is_not_a_task(): void
    {
        // Fenced content is free to sit in column one, and a user pasting the
        // syntax into an example may well leave it there. It belongs to the
        // example, so it is neither a task of its own nor the end of the
        // details — and because it shares no indentation with the lines around
        // it, the block is handed over exactly as written rather than dedented
        // to the point of breaking the example.
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Document the checklist syntax
              ```markdown
            - [ ] A task
              ```
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(2, $checklist->count());
        self::assertSame(
            "  ```markdown\n- [ ] A task\n  ```",
            $checklist->items()[0]->details(),
        );
        self::assertSame(4, $checklist->items()[0]->endLine());
        self::assertSame(5, $checklist->items()[1]->line());
    }

    #[Test]
    public function a_deeply_indented_fence_inside_details_hides_a_checkbox_in_column_one(): void
    {
        // A fence nested that far in is still a fence, so what it encloses is
        // code wherever it starts — including the one place a checkbox would
        // otherwise be read as a task of its own.
        $checklist = $this->parser->parse(
            "- [ ] Document the checklist syntax\n"
            . "      ```markdown\n"
            . "- [ ] Hidden by the example\n"
            . "      ```\n"
            . "- [ ] Document the exit codes\n",
        );

        self::assertSame(2, $checklist->count());
        self::assertSame(4, $checklist->items()[0]->endLine());
        self::assertSame('Document the exit codes', $checklist->items()[1]->description());
    }

    #[Test]
    public function a_deeply_indented_fence_inside_details_closes_at_its_own_indentation(): void
    {
        // Details are indented to begin with, so a fenced example nested under
        // a bullet of its own sits further in than the three spaces CommonMark
        // allows a top-level fence. Its closing line is indented just as far,
        // and has to be read as the close rather than as more code — otherwise
        // the example swallows every task below it.
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Document the checklist syntax
              - Show the reader this:

                ```markdown
                - [ ] A task
                ```

              - And nothing else.
            - [ ] Document the exit codes
            MARKDOWN);

        self::assertSame(2, $checklist->count());
        self::assertSame(8, $checklist->items()[0]->endLine());
        self::assertSame('Document the exit codes', $checklist->items()[1]->description());
        self::assertSame(
            <<<'DETAILS'
            - Show the reader this:

              ```markdown
              - [ ] A task
              ```

            - And nothing else.
            DETAILS,
            $checklist->items()[0]->details(),
        );
    }

    #[Test]
    public function an_unclosed_fence_inside_details_runs_to_the_end_of_the_document(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            - [ ] Document the checklist syntax
              ```markdown
              - [ ] Hidden
            - [ ] Hidden too
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertSame(4, $checklist->items()[0]->endLine());
    }

    #[Test]
    public function indented_lines_that_follow_no_task_are_ignored(): void
    {
        $checklist = $this->parser->parse(<<<'MARKDOWN'
            Notes on the release:

              - nothing here is a task
              - and nothing here attaches to one

            - [ ] Add a --dry-run option
            MARKDOWN);

        self::assertSame(1, $checklist->count());
        self::assertFalse($checklist->items()[0]->hasDetails());
        self::assertSame(6, $checklist->items()[0]->line());
    }
}
