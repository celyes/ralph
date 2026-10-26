<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Ralph;
use RuntimeException;

/**
 * A throwaway project directory for tests that read or write project files.
 *
 * Configuration loading is filesystem behaviour — a missing file, an unreadable
 * file and a path resolved against the project root are all part of what is
 * under test — so these tests use a real directory rather than a stub.
 */
final class TemporaryProject
{
    private function __construct(private readonly string $root)
    {
    }

    public static function create(): self
    {
        return new self(TemporaryDirectory::create('ralph-project'));
    }

    /**
     * Absolute path to the project root.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Absolute path to a location inside the project.
     */
    public function path(string $relative): string
    {
        return $this->root . DIRECTORY_SEPARATOR . ltrim($relative, '/');
    }

    /**
     * Write `.ralph/config.json` verbatim, so a test can supply invalid JSON.
     */
    public function writeConfig(string $contents): string
    {
        return $this->write(Ralph::PROJECT_DIRECTORY . '/config.json', $contents);
    }

    /**
     * Write `.ralph/config.json` from a settings array.
     *
     * @param array<string, mixed> $settings
     */
    public function writeConfigArray(array $settings): string
    {
        return $this->writeConfig(json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * Write any file in the project, creating parent directories.
     */
    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write ' . $path . '.');
        }

        return $path;
    }

    public function remove(): void
    {
        TemporaryDirectory::remove($this->root);
    }
}
