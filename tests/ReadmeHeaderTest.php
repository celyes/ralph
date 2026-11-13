<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests;

use Celyes\RalphPhp\Tests\Support\Paths;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * That the README's header still describes this package.
 *
 * The header states six facts a reader judges the package by before reading a
 * word of it: its version, its installs, its CI result, the size of its suite,
 * the PHP it needs and its licence. Five of those are badges a service fills in
 * from live data, so they cannot go stale — but they can point at the wrong
 * package, and then they report somebody else's numbers convincingly. The
 * sixth, the number of tests, is a number written into the file, and the way it
 * fails is by staying still while the suite grows.
 *
 * So the badge URLs are asserted against the name and licence in
 * `composer.json`, and the test count against the tests PHPUnit actually
 * finds. The illustration is asserted to exist, because a header image that
 * 404s is worse than none.
 */
#[CoversNothing]
final class ReadmeHeaderTest extends TestCase
{
    /**
     * The README's path, relative to the package root.
     */
    private const string README = 'README.md';

    /**
     * The header illustration's path, relative to the package root.
     */
    private const string ILLUSTRATION = 'docs/ralph-wiggum.svg';

    #[Test]
    public function the_header_illustration_exists_and_is_shown(): void
    {
        self::assertFileExists(
            Paths::inPackage(self::ILLUSTRATION),
            'The README shows an illustration that is not in the repository.',
        );

        self::assertStringContainsString(
            'src="' . self::ILLUSTRATION . '"',
            $this->readme(),
            self::README . ' does not show ' . self::ILLUSTRATION . '.',
        );
    }

    #[Test]
    public function the_illustration_ships_with_the_package(): void
    {
        $attributes = (string) \file_get_contents(Paths::inPackage('.gitattributes'));

        foreach (\explode("\n", $attributes) as $line) {
            if (!\str_contains($line, 'export-ignore')) {
                continue;
            }

            $path = \trim(\explode(' ', \trim($line), 2)[0], '/');

            if ($path === '') {
                continue;
            }

            self::assertStringStartsNotWith(
                $path,
                self::ILLUSTRATION,
                'The illustration is excluded from distribution archives by ' . \trim($line) . '.',
            );
        }
    }

    #[Test]
    public function the_badges_name_this_package(): void
    {
        /** @var array{name: string, license: string} $manifest */
        $manifest = \json_decode(
            (string) \file_get_contents(Paths::inPackage('composer.json')),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $readme = $this->readme();

        // Every shields.io badge that reads Packagist, and the stats page the
        // installs badge links to, have to carry the published package name.
        \preg_match_all('#https://img\.shields\.io/packagist/[^"\s]+#', $readme, $matches);

        self::assertNotSame([], $matches[0], 'The README has no Packagist badges.');

        foreach ($matches[0] as $badge) {
            self::assertStringContainsString(
                $manifest['name'],
                $badge,
                'A badge reads a package other than ' . $manifest['name'] . ': ' . $badge,
            );
        }

        self::assertStringContainsString(
            'https://packagist.org/packages/' . $manifest['name'],
            $readme,
            'The version badge does not link to the package on Packagist.',
        );
    }

    #[Test]
    public function the_test_count_badge_matches_the_suite(): void
    {
        $match = [];

        self::assertSame(
            1,
            \preg_match('#/badge/tests-(\d+)%20passing-#', $this->readme(), $match),
            'The README does not state how many tests the suite has.',
        );

        $stated = (int) ($match[1] ?? '');

        self::assertSame(
            $this->testsInTheSuite(),
            $stated,
            'The README says the suite has ' . $stated . ' tests; PHPUnit finds a different number.',
        );
    }

    /**
     * How many tests PHPUnit finds, without running any of them.
     *
     * `--list-tests` resolves data providers, so this is the count a reader
     * sees at the end of a run rather than a count of test methods.
     */
    private function testsInTheSuite(): int
    {
        $listing = new Process(
            [\PHP_BINARY, Paths::inPackage('vendor/bin/phpunit'), '--list-tests'],
            Paths::packageRoot(),
        );

        $listing->setTimeout(120.0);
        $listing->run();

        self::assertTrue($listing->isSuccessful(), 'PHPUnit could not list the suite: ' . $listing->getErrorOutput());

        return (int) \preg_match_all('#^ - \S#m', $listing->getOutput());
    }

    private function readme(): string
    {
        return (string) \file_get_contents(Paths::inPackage(self::README));
    }
}
