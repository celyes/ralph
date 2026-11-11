<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * A throwaway filesystem for the installation tests.
 *
 * Holds one copy of the distributable package and as many consumer projects and
 * Composer homes as a test needs, all under a single temporary directory that is
 * deleted afterwards.
 *
 * Consumers reach the package through a Composer `path` repository with
 * symlinking turned off, so the install produces a real directory under
 * `vendor/celyes/ralph-php/` exactly as a download from Packagist would; a
 * symlinked install would instead point back at the checkout and leave the
 * installed layout untested.
 *
 * Packagist stays switched on, for the one thing it is needed for: the package
 * declares a runtime dependency (symfony/process), and an install that could
 * not resolve it would not be an installation test. Nothing else is fetched —
 * the package itself comes from the path repository, its development
 * dependencies are not a consumer's, and no credential or paid service is
 * involved. It does mean these tests need network access, which is stated on
 * {@see \Celyes\RalphPhp\Tests\Installation\ComposerInstallationTest}.
 */
final class InstallationWorkspace
{
    private function __construct(
        private readonly string $root,
        private readonly string $packageSource,
    ) {
    }

    /**
     * Create the workspace and copy the distributable package into it.
     */
    public static function create(): self
    {
        $root = TemporaryDirectory::create('ralph-installation');
        $packageSource = $root . DIRECTORY_SEPARATOR . 'package';

        if (!mkdir($packageSource, 0o755) && !is_dir($packageSource)) {
            throw new RuntimeException('Unable to create ' . $packageSource . '.');
        }

        DistributedPackage::copyTo($packageSource);

        return new self($root, $packageSource);
    }

    /**
     * The copy of the package that consumers install from.
     */
    public function packageSource(): string
    {
        return $this->packageSource;
    }

    /**
     * Create an empty directory in the workspace.
     */
    public function directory(string $name): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create ' . $path . '.');
        }

        return $path;
    }

    /**
     * Create a consumer project with a composer.json that can install the
     * package, merging $manifest over the defaults.
     *
     * @param array<string, mixed> $manifest
     * @return string the project directory
     */
    public function project(string $name, array $manifest = []): string
    {
        $path = $this->directory($name);

        $contents = json_encode(
            [
                'name' => 'ralph/' . $name,
                'description' => 'Throwaway project for the Ralph PHP installation tests.',
                'repositories' => $this->repositories(),
                ...$manifest,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $this->write($path . DIRECTORY_SEPARATOR . 'composer.json', $contents);

        return $path;
    }

    /**
     * Create a Composer home directory configured to install the package,
     * which is what `composer global` installs into.
     */
    public function composerHome(string $name): string
    {
        $path = $this->directory($name);

        $contents = json_encode(
            ['repositories' => $this->repositories()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $this->write($path . DIRECTORY_SEPARATOR . 'composer.json', $contents);

        return $path;
    }

    /**
     * Write a file inside the workspace, creating its directory.
     */
    public function write(string $path, string $contents): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write ' . $path . '.');
        }
    }

    /**
     * Delete the whole workspace.
     */
    public function remove(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repositories(): array
    {
        return [
            [
                'type' => 'path',
                'url' => $this->packageSource,
                'options' => ['symlink' => false],
            ],
        ];
    }
}
