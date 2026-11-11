<?php

declare(strict_types=1);

namespace RalphPhpExample\TextTools\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RalphPhpExample\TextTools\Excerpt;

final class ExcerptTest extends TestCase
{
    #[Test]
    public function it_returns_a_short_text_unchanged(): void
    {
        self::assertSame('Already short.', (new Excerpt(40))->from('Already short.'));
    }

    #[Test]
    public function it_collapses_whitespace(): void
    {
        self::assertSame('One two three.', (new Excerpt(40))->from("One\n\ttwo   three.\n"));
    }

    #[Test]
    public function it_appends_an_ellipsis_to_a_text_it_had_to_cut(): void
    {
        $excerpt = (new Excerpt(10))->from('Ralph PHP reads a checklist.');

        self::assertStringEndsWith(Excerpt::ELLIPSIS, $excerpt);
        self::assertStringStartsWith('Ralph PHP', $excerpt);
    }

    #[Test]
    public function it_counts_characters_rather_than_bytes(): void
    {
        self::assertSame('Héritage' . Excerpt::ELLIPSIS, (new Excerpt(8))->from('Héritage ancienne'));
    }

    #[Test]
    public function it_rejects_a_length_below_one_character(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Excerpt(0);
    }
}
