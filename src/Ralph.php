<?php

declare(strict_types=1);

namespace Celyes\RalphPhp;

/**
 * Package metadata for Ralph PHP.
 *
 * Kept free of dependencies so it can be used by the CLI bootstrap, by
 * preflight checks, and by commit messages without loading the runner.
 */
final class Ralph
{
    /**
     * Composer package name.
     */
    public const string PACKAGE = 'celyes/ralph-php';

    /**
     * Human-readable application name shown in CLI output.
     */
    public const string NAME = 'Ralph PHP';

    /**
     * Package version. Updated when a release is tagged.
     */
    public const string VERSION = '0.1.0-dev';

    /**
     * Directory, relative to the user's repository root, holding all project
     * configuration and runtime data.
     */
    public const string PROJECT_DIRECTORY = '.ralph';

    private function __construct()
    {
    }
}
