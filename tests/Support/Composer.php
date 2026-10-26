<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * Runs Composer against a throwaway project.
 *
 * Every run is isolated from the developer's own Composer setup: a temporary
 * home and cache, no interaction, and a fixed root version. Nothing here may
 * read or write the real `~/.config/composer`, because the global-installation
 * test installs a package into the home directory it is given.
 */
final class Composer
{
    /**
     * The Composer executable, or null when it is not installed.
     *
     * COMPOSER_BINARY is set by Composer itself when it runs a script, so the
     * suite follows it when invoked through `composer test`; otherwise PATH is
     * searched, accepting the phar name a manual installation may leave.
     */
    public static function binary(): ?string
    {
        $configured = getenv('COMPOSER_BINARY');
        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach (['composer', 'composer.phar'] as $name) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Run a Composer command in $workingDirectory with $home as COMPOSER_HOME.
     *
     * @param list<string> $arguments
     */
    public static function run(array $arguments, string $workingDirectory, string $home): CliResult
    {
        $binary = self::binary();
        if ($binary === null) {
            throw new RuntimeException('Composer is not available on this machine.');
        }

        $command = [$binary, ...$arguments, '--no-interaction', '--no-ansi'];

        return Cli::runCommand($command, $workingDirectory, [
            'COMPOSER_HOME' => $home,
            'COMPOSER_CACHE_DIR' => $home . DIRECTORY_SEPARATOR . 'cache',
            // The throwaway projects are not Git repositories, so Composer
            // cannot infer their version; saying so keeps a warning out of the
            // captured output.
            'COMPOSER_ROOT_VERSION' => '1.0.0',
            // CI images commonly run as root, where Composer otherwise refuses
            // to install or warns at length.
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            // Inherited Composer settings must not reach these runs: COMPOSER
            // would redirect it to another manifest, and the directory
            // variables would move the install out of the temporary project.
            'COMPOSER' => false,
            'COMPOSER_VENDOR_DIR' => false,
            'COMPOSER_BIN_DIR' => false,
        ]);
    }

    private function __construct()
    {
    }
}
