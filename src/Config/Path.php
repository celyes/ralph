<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * Path handling for configured locations.
 *
 * Separate from realpath() because configured paths routinely do not exist
 * yet: `ralph init` is the thing that creates them, and `status` has to be able
 * to report where a missing file was expected.
 */
final class Path
{
    public static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows-style roots are recognised so that a configuration written on
        // Windows is reported as absolute rather than silently joined to the
        // project root.
        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * Whether a path is the given root or a location below it.
     *
     * Both sides are normalised first, so `..` segments cannot smuggle a path
     * out of the root: a configured `../../tasks.md` is recognised as outside
     * the repository instead of being treated as a harmless relative path.
     * Textual, like the rest of this class, so it works for paths that do not
     * exist yet — and deliberately not symlink-aware, which is a filesystem
     * question for preflight rather than a configuration one.
     */
    public static function isInside(string $root, string $path): bool
    {
        $root = self::normalize($root);
        $path = self::normalize($path);

        if ($root === $path) {
            return true;
        }

        return str_starts_with($path, rtrim($root, '/') . '/');
    }

    /**
     * Collapse separators and resolve `.` and `..` segments textually.
     *
     * Textual on purpose: the result must be predictable for paths that do not
     * exist, and must not follow symlinks.
     */
    public static function normalize(string $path): string
    {
        $windowsRoot = '';
        if (preg_match('#^([A-Za-z]:)[\\\\/]#', $path, $matches) === 1) {
            $windowsRoot = $matches[1];
            $path = substr($path, strlen($windowsRoot));
        }

        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $last = end($segments);
                if ($last !== false && $last !== '..') {
                    array_pop($segments);

                    continue;
                }

                if ($absolute) {
                    // Above the root is the root.
                    continue;
                }
            }

            $segments[] = $segment;
        }

        $joined = implode('/', $segments);

        if ($windowsRoot !== '') {
            return $windowsRoot . '/' . $joined;
        }

        if ($absolute) {
            return '/' . $joined;
        }

        return $joined === '' ? '.' : $joined;
    }

    private function __construct()
    {
    }
}
