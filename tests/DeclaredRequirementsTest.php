<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * That the package's requirements are stated consistently.
 *
 * The same two requirements are written down in three places, each of which
 * needs them at a moment the others cannot serve: `composer.json` is enforced
 * when the package is installed, `bin/ralph` has to refuse an unsupported
 * runtime before an autoloader exists, and {@see Ralph} is what preflight
 * reports to the user. Nothing keeps them in step but this test — and three
 * disagreeing answers to "what does this need" is a worse failure than any one
 * of them being wrong.
 */
#[CoversClass(Ralph::class)]
final class DeclaredRequirementsTest extends TestCase
{
    #[Test]
    public function the_declared_extensions_are_the_ones_composer_requires(): void
    {
        $required = [];

        foreach (array_keys($this->requirements()) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $required[] = substr($package, 4);
            }
        }

        sort($required);
        $declared = Ralph::REQUIRED_EXTENSIONS;
        sort($declared);

        self::assertSame(
            $required,
            $declared,
            'composer.json and Ralph::REQUIRED_EXTENSIONS disagree about the required extensions.',
        );
    }

    #[Test]
    public function the_minimum_php_version_is_the_one_composer_requires(): void
    {
        $constraint = $this->requirements()['php'] ?? null;

        self::assertSame(
            '^' . $this->majorMinor(Ralph::MINIMUM_PHP_VERSION),
            $constraint,
            'composer.json and Ralph::MINIMUM_PHP_VERSION disagree about the PHP requirement.',
        );
    }

    #[Test]
    public function the_binary_refuses_the_same_runtime_the_package_declares(): void
    {
        $binary = (string) file_get_contents(Paths::inPackage('bin/ralph'));

        self::assertStringContainsString(
            "define('RALPH_MINIMUM_PHP_VERSION', '" . Ralph::MINIMUM_PHP_VERSION . "');",
            $binary,
            'bin/ralph and Ralph::MINIMUM_PHP_VERSION disagree about the minimum PHP version.',
        );

        self::assertStringContainsString(
            "define('RALPH_EXIT_ENVIRONMENT', " . ExitCode::CONFIGURATION . ');',
            $binary,
            'bin/ralph and ExitCode::CONFIGURATION disagree about the environment failure status.',
        );
    }

    #[Test]
    public function the_minimum_git_version_is_a_version_number(): void
    {
        // Preflight compares against it with version_compare(), and reports it
        // to the user as the requirement, so it has to be a plain three-part
        // version rather than a constraint.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Ralph::MINIMUM_GIT_VERSION);
    }

    /**
     * The package's own `require` section, as names mapped to constraints.
     *
     * @return array<string, string>
     */
    private function requirements(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(Paths::inPackage('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded) || !is_array($decoded['require'] ?? null)) {
            self::fail('composer.json has no "require" section.');
        }

        $requirements = [];

        foreach ($decoded['require'] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $requirements[$package] = $constraint;
            }
        }

        return $requirements;
    }

    private function majorMinor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }
}
