<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Memory\BoundedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Holding context and memory to a character budget.
 */
#[CoversClass(BoundedText::class)]
final class BoundedTextTest extends TestCase
{
    #[Test]
    public function text_within_the_budget_is_passed_through_untouched(): void
    {
        $text = BoundedText::of("# Conventions\n\nTrailing whitespace and all.   \n", 1000);

        self::assertSame("# Conventions\n\nTrailing whitespace and all.   \n", $text->text());
        self::assertFalse($text->wasTrimmed());
        self::assertNull($text->notice());
        self::assertSame(47, $text->originalLength());
        self::assertSame(47, $text->length());
    }

    #[Test]
    public function text_exactly_at_the_budget_is_not_trimmed(): void
    {
        $text = BoundedText::of('0123456789', 10);

        self::assertSame('0123456789', $text->text());
        self::assertFalse($text->wasTrimmed());
    }

    #[Test]
    public function longer_text_is_cut_at_the_last_line_break_inside_the_budget(): void
    {
        $text = BoundedText::of("first line\nsecond line\nthird line\n", 24);

        self::assertSame("first line\nsecond line", $text->text());
        self::assertTrue($text->wasTrimmed());
        self::assertSame(34, $text->originalLength());
    }

    #[Test]
    public function a_first_line_longer_than_the_budget_is_cut_mid_line_rather_than_away(): void
    {
        // Backing off to the line break would leave two characters, so the cut
        // is taken where the budget runs out instead.
        $text = BoundedText::of("ab\nan extremely long single line that runs past the budget", 20);

        self::assertSame('ab', mb_substr($text->text(), 0, 2));
        self::assertSame(20, $text->length());
        self::assertTrue($text->wasTrimmed());
    }

    #[Test]
    public function the_budget_counts_characters_and_never_cuts_one_in_half(): void
    {
        $text = BoundedText::of(str_repeat('é', 10), 4);

        self::assertSame('éééé', $text->text());
        self::assertSame(4, $text->length());
        self::assertSame(10, $text->originalLength());
    }

    #[Test]
    public function a_spent_budget_yields_no_text_and_says_the_whole_thing_was_left_out(): void
    {
        $text = BoundedText::of('something worth reading', 0);

        self::assertSame('', $text->text());
        self::assertTrue($text->wasTrimmed());
        self::assertTrue($text->isEmpty());
        self::assertSame('Trimmed: 0 of 23 characters shown.', $text->notice());
    }

    #[Test]
    public function the_notice_reports_what_is_shown_against_what_was_there(): void
    {
        $text = BoundedText::of(str_repeat('a', 500), 100);

        self::assertSame('Trimmed: 100 of 500 characters shown.', $text->notice());
    }

    #[Test]
    public function whitespace_only_text_counts_as_nothing_to_pass_on(): void
    {
        self::assertTrue(BoundedText::of("\n   \n", 100)->isEmpty());
        self::assertFalse(BoundedText::of("\n a \n", 100)->isEmpty());
    }
}
