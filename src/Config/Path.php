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
