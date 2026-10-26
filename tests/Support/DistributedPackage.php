<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * A copy of the package as a consumer receives it.
 *
 * Installation tests must not install the package checkout itself: it carries a
 * 100 MB `vendor/` and the development-only files that `.gitattributes`
 * excludes from distribution, so installing it would be slow and would prove
 * that something works which consumers never get.
 *
 * The file set is derived from `composer.json` rather than hard-coded, so it
 * follows the package's own declaration of what it needs at runtime: the
 * manifest, the licence, the PSR-4 source roots, and the registered binaries.
 * Development autoloading (`autoload-dev`) is deliberately left out, because
 * Composer never installs a dependency's development files either.
 */
final class DistributedPackage
{
    /**
     * Write the distributable file set into an existing directory.
     */
    public static function copyTo(string $destination): void
    {
        if (!is_dir($destination)) {
            throw new RuntimeException('The destination ' . $destination . ' is not a directory.');
        }

        foreach (self::paths() as $relative) {
            $source = Paths::inPackage($relative);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;

            if (is_dir($source)) {
                self::copyDirectory($source, $target);

                continue;
            }

            if (!is_file($source)) {
                throw new RuntimeException(
                    'The package is missing ' . $relative . ', which its composer.json declares.',
                );
            }

            self::copyFile($source, $target);
        }
    }

    /**
     * Paths, relative to the package root, that a consumer's installation holds.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $manifest = self::manifest();

        $paths = ['composer.json', 'LICENSE'];

        $autoload = $manifest['autoload'] ?? null;
        $sourceRoots = is_array($autoload) ? $autoload['psr-4'] ?? null : null;

        foreach (self::stringList($sourceRoots, 'autoload.psr-4') as $path) {
            $paths[] = $path;
        }

        foreach (self::stringList($manifest['bin'] ?? null, 'bin') as $path) {
            $paths[] = $path;
        }

        $unique = [];
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');

            if ($path !== '' && !in_array($path, $unique, true)) {
                $unique[] = $path;
            }
        }

        return $unique;
    }

    /**
     * The package's own composer.json, decoded.
     *
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = Paths::inPackage('composer.json');

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $path . '.');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException($path . ' does not contain a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Flatten a manifest section that holds paths.
     *
     * Composer accepts a string or a list of strings in both of the sections
     * this reads (each `autoload.psr-4` value, and each `bin` entry), so both
     * shapes are handled. $label names the section in error messages.
     *
     * @return list<string>
     */
    private static function stringList(mixed $section, string $label): array
    {
        if (!is_array($section)) {
            throw new RuntimeException('composer.json has no usable ' . $label . ' section.');
        }

        $values = [];

        /** @var mixed $entry */
        foreach ($section as $entry) {
            /** @var mixed $value */
            foreach (is_array($entry) ? $entry : [$entry] as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('composer.json has a non-string path in ' . $label . '.');
                }

                $values[] = $value;
            }
        }

        return $values;
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        self::createDirectory($destination);

        $entries = scandir($source);
        if ($entries === false) {
            throw new RuntimeException('Unable to read the directory ' . $source . '.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $source . DIRECTORY_SEPARATOR . $entry;
            $target = $destination . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                self::copyDirectory($child, $target);

                continue;
            }

            self::copyFile($child, $target);
        }
    }

    private static function copyFile(string $source, string $destination): void
    {
        self::createDirectory(\dirname($destination));

        if (!copy($source, $destination)) {
            throw new RuntimeException('Unable to copy ' . $source . ' to ' . $destination . '.');
        }

        // The executable bit on bin/ralph is part of what is being verified:
        // copy() does not carry permissions over.
        if (is_executable($source)) {
            chmod($destination, 0o755);
        }
    }

    private static function createDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the directory ' . $path . '.');
        }
    }

    private function __construct()
    {
    }
}
