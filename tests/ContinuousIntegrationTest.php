<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests;

use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Paths;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * That continuous integration runs this suite the way the package is declared.
 *
 * A workflow file is code nobody runs locally: it is only ever executed by a
 * service, on a push, and the way it fails is by quietly testing less than it
 * appears to. The three ways that happens here are a PHP version added to
 * `composer.json` and forgotten in the matrix, an extension the suite needs
 * that the runner does not have — which turns real coverage into skipped tests
 * that still print as a pass — and a narrowing flag on the PHPUnit command.
 *
 * So the matrix is asserted against {@see Ralph::MINIMUM_PHP_VERSION} rather
 * than against a copy of itself, and the suite's own requirements are asserted
 * against what the workflow installs. The last test holds the eighth release
 * acceptance criterion: the suite runs without live model credentials, which
 * means CI must not have any.
 */
#[CoversNothing]
final class ContinuousIntegrationTest extends TestCase
{
    /**
     * The workflow's path, relative to the package root.
     */
    private const string WORKFLOW = '.github/workflows/ci.yml';

    #[Test]
    public function the_matrix_covers_every_supported_php_version(): void
    {
        $versions = $this->phpMatrix();

        self::assertNotSame([], $versions, 'The test matrix has no PHP versions.');

        $minimum = $this->majorMinor(Ralph::MINIMUM_PHP_VERSION);

        self::assertSame(
            $minimum,
            $versions[0],
            self::WORKFLOW . ' does not start its matrix at the minimum supported PHP ' . $minimum . '.',
        );

        $sorted = $versions;
        usort($sorted, static fn (string $one, string $other): int => version_compare($one, $other));

        self::assertSame($sorted, $versions, 'The matrix versions are not in ascending order.');
        self::assertSame(array_values(array_unique($versions)), $versions, 'The matrix repeats a PHP version.');

        // `^8.3` admits 8.3 and anything above it below 9.0, so a version at
        // or above the minimum is supported only while its major still matches.
        $major = explode('.', $minimum)[0];

        foreach ($versions as $version) {
            self::assertTrue(
                version_compare($version, $minimum, '>=') && explode('.', $version)[0] === $major,
                'The matrix tests PHP ' . $version . ', which the package does not support.',
            );
        }
    }

    #[Test]
    public function the_matrix_versions_are_quoted(): void
    {
        // `php-version: 8.30` and `php-version: 8.3` are the same YAML number
        // and two different PHP versions, so an unquoted entry is a silent
        // change of what is being tested. Quoting is the only defence, and the
        // parser is what can tell.
        foreach ($this->matrixEntries() as $position => $version) {
            self::assertIsString(
                $version,
                'Matrix entry ' . ($position + 1) . ' is not quoted, so YAML reads it as a number.',
            );
        }
    }

    #[Test]
    public function continuous_integration_runs_the_whole_suite(): void
    {
        $commands = [];

        foreach ($this->steps() as $step) {
            $command = $step['run'] ?? null;

            if (is_string($command)) {
                $commands[] = $command;
            }
        }

        $suite = null;

        foreach ($commands as $command) {
            if (str_contains($command, 'phpunit')) {
                $suite = $command;
            }
        }

        if ($suite === null) {
            self::fail(self::WORKFLOW . ' never runs phpunit: ' . implode('; ', $commands));
        }

        // Anything that selects a subset leaves part of the suite unrun while
        // the job still reports success.
        foreach (['--group', '--exclude-group', '--filter', '--testsuite', '--stop-on-failure'] as $narrowing) {
            self::assertStringNotContainsString(
                $narrowing,
                $suite,
                'The PHPUnit command runs less than the whole suite: ' . $suite,
            );
        }
    }

    #[Test]
    public function the_runner_has_every_extension_the_suite_needs(): void
    {
        $installed = $this->installedExtensions();

        // The optional two are included deliberately: without pcntl and posix
        // the interruption and process-tree tests skip themselves, and CI that
        // skips them is not evidence that Ctrl+C is handled.
        $needed = [...Ralph::REQUIRED_EXTENSIONS, ...array_keys(Ralph::OPTIONAL_EXTENSIONS)];

        foreach ($needed as $extension) {
            self::assertContains(
                $extension,
                $installed,
                self::WORKFLOW . ' does not install ext-' . $extension . ', which the suite uses.',
            );
        }
    }

    #[Test]
    public function continuous_integration_needs_no_credentials(): void
    {
        $workflow = $this->source();

        self::assertStringNotContainsString(
            'secrets.',
            $workflow,
            self::WORKFLOW . ' reads a repository secret; the suite must run without credentials.',
        );

        foreach (['API_KEY', 'TOKEN', 'ANTHROPIC', 'OPENAI'] as $credential) {
            self::assertStringNotContainsString(
                $credential,
                strtoupper($workflow),
                self::WORKFLOW . ' mentions ' . $credential . '; the suite must run without credentials.',
            );
        }
    }

    /**
     * The matrix PHP versions, as the strings they have to be.
     *
     * @return list<string>
     */
    private function phpMatrix(): array
    {
        $versions = [];

        foreach ($this->matrixEntries() as $entry) {
            if (!is_string($entry)) {
                self::fail('A matrix PHP version is not a string: ' . var_export($entry, true));
            }

            $versions[] = $entry;
        }

        return $versions;
    }

    /**
     * The matrix PHP versions exactly as YAML read them, quoting included.
     *
     * @return list<mixed>
     */
    private function matrixEntries(): array
    {
        $strategy = $this->job()['strategy'] ?? null;
        $matrix = is_array($strategy) ? $strategy['matrix'] ?? null : null;

        if (!is_array($matrix)) {
            self::fail('The test job has no build matrix.');
        }

        $versions = $matrix['php'] ?? null;

        if (!is_array($versions) || !array_is_list($versions)) {
            self::fail('The build matrix has no list of PHP versions.');
        }

        return $versions;
    }

    /**
     * The extension names the PHP setup step installs.
     *
     * @return list<string>
     */
    private function installedExtensions(): array
    {
        foreach ($this->steps() as $step) {
            $uses = $step['uses'] ?? null;

            if (!is_string($uses) || !str_contains($uses, 'setup-php')) {
                continue;
            }

            $with = $step['with'] ?? null;
            $extensions = is_array($with) ? $with['extensions'] ?? null : null;

            if (!is_string($extensions)) {
                self::fail('The PHP setup step installs no extensions.');
            }

            return array_values(array_filter(array_map('trim', explode(',', $extensions)), fn (string $name): bool => $name !== ''));
        }

        self::fail(self::WORKFLOW . ' has no step that sets PHP up.');
    }

    /**
     * The steps of the job that runs the suite.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $steps = $this->job()['steps'] ?? null;

        if (!is_array($steps) || !array_is_list($steps)) {
            self::fail('The test job has no steps.');
        }

        $found = [];

        foreach ($steps as $position => $step) {
            if (!is_array($step)) {
                self::fail('Step ' . ((int) $position + 1) . ' of the test job is not a mapping.');
            }

            /** @var array<string, mixed> $step */
            $found[] = $step;
        }

        return $found;
    }

    /**
     * The single job the workflow defines, which is the one running the suite.
     *
     * @return array<string, mixed>
     */
    private function job(): array
    {
        $jobs = $this->workflow()['jobs'] ?? null;

        if (!is_array($jobs)) {
            self::fail(self::WORKFLOW . ' defines no jobs.');
        }

        $job = $jobs['tests'] ?? null;

        if (!is_array($job)) {
            self::fail(self::WORKFLOW . ' has no "tests" job; it has: ' . implode(', ', array_map('strval', array_keys($jobs))));
        }

        /** @var array<string, mixed> $job */
        return $job;
    }

    /**
     * The workflow, parsed.
     *
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        try {
            $parsed = Yaml::parse($this->source());
        } catch (ParseException $failure) {
            self::fail(self::WORKFLOW . ' is not valid YAML: ' . $failure->getMessage());
        }

        if (!is_array($parsed)) {
            self::fail(self::WORKFLOW . ' does not describe a workflow.');
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    private function source(): string
    {
        $path = Paths::inPackage(self::WORKFLOW);

        if (!is_file($path)) {
            self::fail('There is no ' . self::WORKFLOW . ', so nothing runs the suite on a push.');
        }

        return (string) file_get_contents($path);
    }

    private function majorMinor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }
}
