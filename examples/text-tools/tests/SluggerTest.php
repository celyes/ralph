<?php

declare(strict_types=1);

namespace RalphPhpExample\TextTools\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RalphPhpExample\TextTools\Slugger;

final class SluggerTest extends TestCase
{
    #[Test]
    public function it_lowercases_a_title_and_joins_its_words(): void
    {
        self::assertSame('a-tiny-php-package', (new Slugger())->slugify('A Tiny PHP Package'));
    }

    #[Test]
    public function it_replaces_every_run_of_punctuation_with_one_separator(): void
    {
        self::assertSame('ralph-php-the-loop', (new Slugger())->slugify('Ralph PHP: the loop!'));
    }

    #[Test]
    public function it_leaves_no_separator_at_either_end(): void
    {
        self::assertSame('well-then', (new Slugger())->slugify('  -- well, then? --  '));
    }

    #[Test]
    public function it_keeps_digits_and_letters_outside_ascii(): void
    {
        self::assertSame('version-2-héritage', (new Slugger())->slugify('Version 2 — Héritage'));
    }

    #[Test]
    public function it_can_be_asked_for_underscores(): void
    {
        self::assertSame('a_tiny_package', (new Slugger('_'))->slugify('A tiny package'));
    }

    #[Test]
    public function it_returns_an_empty_string_for_a_title_with_nothing_sluggable(): void
    {
        self::assertSame('', (new Slugger())->slugify('--- !!! ---'));
    }

    #[Test]
    public function it_rejects_a_separator_it_cannot_use_safely(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Slugger('$');
    }
}
