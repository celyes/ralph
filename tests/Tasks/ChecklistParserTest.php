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
}
