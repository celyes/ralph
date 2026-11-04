<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\BaseCommit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The recorded base commit, as a value.
 *
 * Its whole job is to be the full commit id rather than the reference that
 * named it, so the tests that matter are the ones that refuse anything less
 * than a full object name: an abbreviation stored here would mean a different
 * commit as soon as the repository grew one sharing its prefix.
 */
#[CoversClass(BaseCommit::class)]
final class BaseCommitTest extends TestCase
{
    private const string SHA = '9ee342dd51faa1fbb0b159b5dde9e1aa45bdf481';

    #[Test]
    public function it_records_the_reference_and_the_commit_it_resolved_to(): void
    {
        $base = BaseCommit::of('main', self::SHA);

        self::assertSame('main', $base->reference());
        self::assertSame(self::SHA, $base->sha());
        self::assertSame('9ee342dd51fa', $base->shortSha());
        self::assertSame(self::SHA, (string) $base);
    }

    #[Test]
    public function a_reference_is_named_alongside_the_commit_for_a_reader(): void
    {
        self::assertSame('main (9ee342dd51fa)', BaseCommit::of('main', self::SHA)->describe());
        self::assertSame('HEAD (9ee342dd51fa)', BaseCommit::of('HEAD', self::SHA)->describe());
    }

    #[Test]
    public function a_commit_named_by_its_own_id_is_not_printed_twice(): void
    {
        self::assertTrue(BaseCommit::of(self::SHA, self::SHA)->wasNamedDirectly());
        self::assertSame('9ee342dd51fa', BaseCommit::of(self::SHA, self::SHA)->describe());

        // An abbreviation is a legitimate way to configure a base, and it is
        // still the commit itself rather than a name for it.
        self::assertTrue(BaseCommit::of('9EE342D', self::SHA)->wasNamedDirectly());
        self::assertSame('9ee342dd51fa', BaseCommit::of('9ee342d', self::SHA)->describe());

        self::assertFalse(BaseCommit::of('main', self::SHA)->wasNamedDirectly());
    }

    #[Test]
    public function a_commit_id_is_stored_in_the_spelling_every_comparison_uses(): void
    {
        $base = BaseCommit::of(' main ', '  ' . strtoupper(self::SHA) . "\n");

        self::assertSame('main', $base->reference());
        self::assertSame(self::SHA, $base->sha());
    }

    #[Test]
    public function two_values_are_equal_when_they_name_the_same_commit(): void
    {
        $other = str_repeat('a', 40);

        // Two runs that reached the commit through different references are
        // building on the same thing, which is the question that gets asked.
        self::assertTrue(BaseCommit::of('main', self::SHA)->equals(BaseCommit::of('HEAD', self::SHA)));
        self::assertFalse(BaseCommit::of('main', self::SHA)->equals(BaseCommit::of('main', $other)));
    }

    #[Test]
    public function a_repository_hashing_with_sha256_is_not_refused_for_it(): void
    {
        $sha256 = str_repeat('ab', 32);

        self::assertSame($sha256, BaseCommit::of('HEAD', $sha256)->sha());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableShas(): iterable
    {
        yield 'empty' => [''];
        yield 'abbreviated' => ['9ee342d'];
        yield 'one character short' => [substr(self::SHA, 1)];
        yield 'one character long' => [self::SHA . '0'];
        yield 'not hexadecimal' => [str_repeat('g', 40)];
        yield 'a reference instead' => ['refs/heads/main'];
        yield 'a length between the two algorithms' => [str_repeat('a', 50)];
    }

    #[Test]
    #[DataProvider('unusableShas')]
    public function only_a_full_object_name_is_a_base_commit(string $sha): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a full commit id');

        BaseCommit::of('main', $sha);
    }

    #[Test]
    public function a_base_commit_remembers_where_it_came_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must record the reference');

        BaseCommit::of('   ', self::SHA);
    }
}
