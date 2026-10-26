<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Installation;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\CliResult;
use Celyes\RalphPhp\Tests\Support\Composer;
use Celyes\RalphPhp\Tests\Support\InstallationWorkspace;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The package installs and runs in both supported Composer layouts.
 *
 * Section 2 of the PRD requires installation "as a Composer development
 * dependency or global tool", which are two different layouts: as a dependency
 * the binary is proxied from the project's `vendor/bin/`, and as a global tool
 * it is proxied from the Composer home directory and run from working
 * directories that have nothing to do with it. Only an actual install shows
 * that the binary is exposed, that its autoloader discovery picks the right
 * installation, and that the declared platform requirements gate it.
 *
 * Each test installs into a temporary directory with a temporary Composer home,
 * so the developer's own global Composer setup is never read or modified.
 */
#[Group('installation')]
final class ComposerInstallationTest extends TestCase
{
    private static ?InstallationWorkspace $workspace = null;

    public static function setUpBeforeClass(): void
    {
        if (Composer::binary() === null) {
            self::markTestSkipped('Composer is not installed; installation cannot be verified.');
        }

        self::$workspace = InstallationWorkspace::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$workspace?->remove();
        self::$workspace = null;
    }

    #[Test]
    public function it_installs_as_a_project_development_dependency(): string
    {
        $project = self::workspace()->project('consumer');

        $install = Composer::run(
            ['require', '--dev', Ralph::PACKAGE . ':*@dev'],
            $project,
            self::workspace()->directory('consumer-home'),
        );

        self::assertSame(0, $install->exitCode, $install->describe());

        $binary = $project . '/vendor/bin/ralph';
        self::assertFileExists($binary, $install->describe());
        self::assertTrue(is_executable($binary), $binary . ' is not executable.');

        // The package is installed where a consumer's autoloader finds it, and
        // its own development dependencies are not dragged into the consumer.
        self::assertFileExists($project . '/vendor/' . Ralph::PACKAGE . '/src/Ralph.php');
        self::assertDirectoryDoesNotExist($project . '/vendor/phpunit');

        $manifest = self::readManifest($project . '/composer.json');
        $requireDev = $manifest['require-dev'] ?? null;
        if (!is_array($requireDev)) {
            self::fail('composer require --dev left no require-dev section: ' . $install->describe());
        }
        self::assertArrayHasKey(Ralph::PACKAGE, $requireDev);

        self::assertReportsItsVersion(self::runBinary($binary, ['--version']));

        return $binary;
    }

    #[Test]
    #[Depends('it_installs_as_a_project_development_dependency')]
    public function the_dependency_installation_reports_usage_errors(string $binary): void
    {
        // Exit status and stream separation have to survive installation:
        // Composer's bin proxy is a different entry point from the checkout's
        // bin/ralph, and a consumer's scripts depend on both.
        $result = self::runBinary($binary, ['frobnicate']);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertSame('', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Unknown command "frobnicate".', $result->errorOutput, $result->describe());
    }

    #[Test]
    public function it_installs_as_a_global_composer_tool(): string
    {
        $home = self::workspace()->composerHome('global-home');

        $install = Composer::run(['global', 'require', Ralph::PACKAGE . ':*@dev'], $home, $home);

        self::assertSame(0, $install->exitCode, $install->describe());

        $binary = $home . '/vendor/bin/ralph';
        self::assertFileExists($binary, $install->describe());
        self::assertTrue(is_executable($binary), $binary . ' is not executable.');

        // A global tool is run from whatever directory the user happens to be
        // in, so the working directory must not take part in finding the
        // installation.
        $elsewhere = self::workspace()->directory('elsewhere');
        self::assertReportsItsVersion(self::runBinary($binary, ['--version'], $elsewhere));

        // The plain binary, rather than Composer's bin proxy, is what runs when
        // the global bin directory is symlinked into PATH by hand.
        self::assertReportsItsVersion(
            self::runBinary($home . '/vendor/' . Ralph::PACKAGE . '/bin/ralph', ['--version'], $elsewhere),
        );

        return $binary;
    }

    #[Test]
    #[Depends('it_installs_as_a_global_composer_tool')]
    public function the_global_tool_ignores_an_unrelated_project_autoloader(string $binary): void
    {
        // A project the user might be sitting in, with its own Composer
        // installation that knows nothing about this package.
        $project = self::workspace()->directory('unrelated-project');
        self::workspace()->write(
            $project . '/vendor/autoload.php',
            '<?php throw new RuntimeException("The unrelated project autoloader was loaded.");' . PHP_EOL,
        );

        $result = self::runBinary($binary, ['--version'], $project, [
            // Set by anyone whose projects keep dependencies somewhere other
            // than vendor/. It describes the project Composer is pointed at,
            // not the installation being run, so the global tool must ignore it
            // while its own autoloader is reachable.
            'COMPOSER_VENDOR_DIR' => $project . '/vendor',
        ]);

        self::assertReportsItsVersion($result);
    }

    #[Test]
    public function it_refuses_to_install_on_an_unsupported_php_version(): void
    {
        $project = self::workspace()->project('old-php', [
            'require-dev' => [Ralph::PACKAGE => '*@dev'],
            'config' => ['platform' => ['php' => '8.2.99']],
        ]);

        $install = Composer::run(['install'], $project, self::workspace()->directory('old-php-home'));

        self::assertNotSame(0, $install->exitCode, $install->describe());
        self::assertStringContainsString('requires php ^8.3', $install->allOutput(), $install->describe());
        self::assertDirectoryDoesNotExist($project . '/vendor/' . Ralph::PACKAGE);
    }

    #[Test]
    public function it_refuses_to_install_without_a_declared_extension(): void
    {
        $project = self::workspace()->project('no-sqlite', [
            'require-dev' => [Ralph::PACKAGE => '*@dev'],
            // SQLite holds the execution state, so an installation without it
            // could never run.
            'config' => ['platform' => ['ext-pdo_sqlite' => false]],
        ]);

        $install = Composer::run(['install'], $project, self::workspace()->directory('no-sqlite-home'));

        self::assertNotSame(0, $install->exitCode, $install->describe());
        self::assertStringContainsString('ext-pdo_sqlite', $install->allOutput(), $install->describe());
        self::assertDirectoryDoesNotExist($project . '/vendor/' . Ralph::PACKAGE);
    }

    /**
     * Run an installed binary on the runtime the suite is running on.
     *
     * The shebang is bypassed on purpose: `php` on PATH is not necessarily the
     * PHP that the test is measuring. The executable bit and the installed
     * layout are asserted separately.
     *
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    private static function runBinary(
        string $binary,
        array $arguments,
        ?string $workingDirectory = null,
        array $environment = [],
    ): CliResult {
        return Cli::runCommand([PHP_BINARY, $binary, ...$arguments], $workingDirectory, $environment);
    }

    private static function assertReportsItsVersion(CliResult $result): void
    {
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());
        self::assertSame(
            sprintf('%s %s (%s)', Ralph::NAME, Ralph::VERSION, Ralph::PACKAGE),
            $result->standardOutputLines()[0] ?? '',
            $result->describe(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function readManifest(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Unable to read ' . $path . '.');

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path . ' does not contain a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function workspace(): InstallationWorkspace
    {
        $workspace = self::$workspace;
        self::assertNotNull($workspace, 'The installation workspace was not created.');

        return $workspace;
    }
}
