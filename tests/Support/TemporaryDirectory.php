<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * Throwaway directories for tests that need a real filesystem.
 *
 * The installation tests cannot work in memory: Composer has to write a real
 * project, and the installed binary has to be executed. Later sections need the
 * same thing for temporary Git repositories and worktrees.
 */
final class TemporaryDirectory
{
    /**
     * Create an empty directory under the system temporary directory.
     *
     * The name is randomised so concurrent runs of the suite cannot collide,
     * and the mode is restrictive because these directories hold generated
     * project files.
     */
    public static function create(string $prefix): string
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new RuntimeException('The system temporary directory does not exist.');
        }

        $path = $base . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0o700, true) || !is_dir($path)) {
            throw new RuntimeException('Unable to create the temporary directory ' . $path . '.');
        }

        return $path;
    }

    /**
     * Delete a directory created by {@see create()} and everything in it.
     *
     * Refuses to touch anything outside the system temporary directory: these
     * helpers delete recursively, and a wrong path would take a real checkout
     * with it.
     */
    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $resolved = realpath($path);
        $base = realpath(sys_get_temp_dir());

        if ($resolved === false || $base === false || !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to remove ' . $path . ': it is not inside ' . sys_get_temp_dir() . '.');
        }

        self::removeRecursively($resolved);
    }

    /**
     * Delete a directory and its contents.
     */
    private static function removeRecursively(string $path): void
    {
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('Unable to read the directory ' . $path . '.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            // A directory symlink must be unlinked, not descended into and
            // removed: Composer's path repositories can create them.
            if (is_link($child)) {
                unlink($child);

                continue;
            }

            if (is_dir($child)) {
                self::removeRecursively($child);

                continue;
            }

            // Composer leaves some installed files read-only, which stops the
            // delete on filesystems that honour it.
            if (!is_writable($child)) {
                chmod($child, 0o600);
            }

            unlink($child);
        }

        rmdir($path);
    }

    private function __construct()
    {
    }
}
