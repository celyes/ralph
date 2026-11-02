<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Support\UnverifiedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What happens to a sentence a model wrote on its way into the package.
 *
 * Two properties are under test and they are not interchangeable. The text has
 * to arrive *recognisable* — an agent's summary that has been paraphrased is no
 * longer evidence of what the agent said. And it has to arrive with no checkbox
 * in it, because a checkbox is how this package says a task is done, and the
 * one thing a model's note must not be able to do is say that.
 */
#[CoversClass(UnverifiedText::class)]
final class UnverifiedTextTest extends TestCase
{
    #[Test]
    public function text_with_nothing_to_defuse_arrives_as_it_was_written(): void
    {
        $claim = UnverifiedText::of("Added the parser.\n\nIt needs a test for empty input.");

        self::assertSame(
            "Added the parser.\n\nIt needs a test for empty input.",
            $claim->text(),
            'An agent\'s summary was rewritten, so it is no longer evidence of what the agent said.',
        );
        self::assertSame(0, $claim->defusedMarkers());
        self::assertFalse($claim->wasTrimmed());
        self::assertFalse($claim->wasAltered());
        self::assertNull($claim->notice());
        self::assertFalse($claim->isEmpty());
    }

    #[Test]
    public function a_ticked_box_becomes_a_claim(): void
    {
        $claim = UnverifiedText::of('- [x] Implemented the adapter and the tests pass');

        self::assertSame(
            '- claimed done: Implemented the adapter and the tests pass',
            $claim->text(),
        );
        self::assertSame(1, $claim->defusedMarkers());
        self::assertStringNotContainsString('[x]', $claim->text());
    }

    #[Test]
    public function an_unticked_box_keeps_the_sense_it_had(): void
    {
        $claim = UnverifiedText::of('- [ ] Still need to wire up the CLI');

        self::assertSame(
            '- claimed outstanding: Still need to wire up the CLI',
            $claim->text(),
            'An unticked box read as a claim of completion, which inverts what the agent said.',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markers(): array
    {
        return [
            'upper-case tick' => ['- [X] done', '- claimed done: done'],
            'asterisk bullet' => ['* [x] done', '* claimed done: done'],
            'plus bullet' => ['+ [ ] todo', '+ claimed outstanding: todo'],
            'numbered item' => ['1. [x] done', '1. claimed done: done'],
            'numbered bracket' => ['2) [ ] todo', '2) claimed outstanding: todo'],
            'indented item' => ["Notes:\n    - [x] done", "Notes:\n    - claimed done: done"],
            'tab indented' => ["Notes:\n\t- [x] done", "Notes:\n\t- claimed done: done"],
            'no bullet at all' => ['[x] done', '- claimed done: done'],
            'other marker character' => ['- [-] partly', '- claimed outstanding: partly'],
            'empty marker' => ['- [] nothing', '- claimed outstanding: nothing'],
            'no space after marker' => ['- [x]done', '- claimed done: done'],
        ];
    }

    #[Test]
    #[DataProvider('markers')]
    public function every_shape_of_checkbox_is_defused(string $written, string $expected): void
    {
        $claim = UnverifiedText::of($written);

        self::assertSame($expected, $claim->text());
        self::assertSame(1, $claim->defusedMarkers());
    }

    #[Test]
    public function every_marker_in_a_list_is_defused_and_counted(): void
    {
        $claim = UnverifiedText::of(<<<'MARKDOWN'
            Progress so far:

            - [x] parser
            - [x] tests
            - [ ] documentation
            MARKDOWN);

        self::assertSame(3, $claim->defusedMarkers());
        self::assertStringNotContainsString('[x]', $claim->text());
        self::assertStringNotContainsString('[ ]', $claim->text());
        self::assertStringContainsString('Progress so far:', $claim->text());
    }

    #[Test]
    public function a_link_at_the_start_of_a_line_is_not_a_checkbox(): void
    {
        $claim = UnverifiedText::of('[the README](README.md) explains the layout');

        self::assertSame('[the README](README.md) explains the layout', $claim->text());
        self::assertSame(0, $claim->defusedMarkers());
    }

    /**
     * The bound is what a checkbox *is*: a marker at the start of a line. Text
     * that mentions one in passing is prose, and rewriting prose would be
     * paraphrasing the agent.
     */
    #[Test]
    public function a_marker_quoted_mid_sentence_is_left_alone(): void
    {
        $claim = UnverifiedText::of('I would write - [x] in tasks.md but I did not');

        self::assertSame('I would write - [x] in tasks.md but I did not', $claim->text());
        self::assertSame(0, $claim->defusedMarkers());
    }

    #[Test]
    public function defusing_the_same_text_twice_changes_nothing_the_second_time(): void
    {
        $once = UnverifiedText::of("- [x] done\n- [ ] todo");
        $twice = UnverifiedText::of($once->text());

        self::assertSame($once->text(), $twice->text());
        self::assertSame(
            0,
            $twice->defusedMarkers(),
            'Stored text was defused again, so reading a column back would report markers that are gone.',
        );
    }

    #[Test]
    public function text_that_is_not_valid_utf_8_is_still_defused(): void
    {
        $claim = UnverifiedText::of("- [x] done \xB1\x31\x80 and more");

        self::assertSame(
            1,
            $claim->defusedMarkers(),
            'A worker writing invalid UTF-8 could smuggle a checkbox past the guard.',
        );
        self::assertStringNotContainsString('[x]', $claim->text());
    }

    #[Test]
    public function a_long_claim_is_cut_down_and_says_so(): void
    {
        $claim = UnverifiedText::of(str_repeat('a', 2000));

        self::assertSame(UnverifiedText::LIMIT, $claim->length());
        self::assertSame(2000, $claim->originalLength());
        self::assertTrue($claim->wasTrimmed());
        self::assertTrue($claim->wasAltered());
        self::assertStringEndsWith('…', $claim->text());
        self::assertStringContainsString('Trimmed to 800 of 2000 characters', (string) $claim->notice());
    }

    #[Test]
    public function text_exactly_at_the_limit_is_kept_whole(): void
    {
        $claim = UnverifiedText::of(str_repeat('a', UnverifiedText::LIMIT));

        self::assertFalse($claim->wasTrimmed());
        self::assertSame(UnverifiedText::LIMIT, $claim->length());
    }

    #[Test]
    public function a_limit_of_zero_still_keeps_something(): void
    {
        $claim = UnverifiedText::of('something happened', 0);

        self::assertFalse(
            $claim->isEmpty(),
            'A claim was dropped rather than shortened, which is how evidence disappears quietly.',
        );
    }

    #[Test]
    public function cutting_lands_between_characters(): void
    {
        $claim = UnverifiedText::of(str_repeat('é', 40), 10);

        self::assertSame(10, mb_strlen($claim->text()));
        self::assertSame($claim->text(), (string) mb_convert_encoding($claim->text(), 'UTF-8', 'UTF-8'));
    }

    #[Test]
    public function the_notice_reports_both_alterations_together(): void
    {
        $claim = UnverifiedText::of("- [x] done\n" . str_repeat('b', 2000));

        $notice = (string) $claim->notice();

        self::assertStringContainsString('1 checkbox marker was replaced', $notice);
        self::assertStringContainsString('trimmed to', $notice);
    }

    #[Test]
    public function the_notice_counts_several_markers_in_the_plural(): void
    {
        self::assertStringContainsString(
            '2 checkbox markers were replaced',
            (string) UnverifiedText::of("- [x] one\n- [ ] two")->notice(),
        );
    }

    #[Test]
    public function an_agent_that_said_nothing_is_an_empty_claim(): void
    {
        $none = UnverifiedText::none();

        self::assertTrue($none->isEmpty());
        self::assertSame('', $none->text());
        self::assertSame(0, $none->length());
        self::assertNull($none->notice());
        self::assertTrue(UnverifiedText::of("  \n ")->isEmpty());
    }

    #[Test]
    public function a_column_holding_nothing_reads_back_as_no_claim(): void
    {
        self::assertNull(UnverifiedText::recorded(null));
        self::assertNull(UnverifiedText::recorded('   '));
    }

    #[Test]
    public function what_is_stored_is_what_is_read_back(): void
    {
        $claim = UnverifiedText::of("- [x] parser written\nTests were not run.");
        $read = UnverifiedText::recorded($claim->toStorage());

        self::assertNotNull($read);
        self::assertTrue($read->equals($claim));
    }

    #[Test]
    public function surrounding_whitespace_is_not_kept(): void
    {
        self::assertSame('a claim', UnverifiedText::of("\n\n  a claim  \n\n")->text());
    }
}
