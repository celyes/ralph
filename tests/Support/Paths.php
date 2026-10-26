<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

/**
 * Filesystem locations used by the test suite.
 */
final class Paths
{
    /**
     * Absolute path to the package root (the directory holding composer.json).
     */
    public static function packageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Absolute path to a file or directory inside the package root.
     */
    public static function inPackage(string $relative): string
    {
        return self::packageRoot() . \DIRECTORY_SEPARATOR . \ltrim($relative, '/');
    }

    private function __construct()
    {
    }
}
