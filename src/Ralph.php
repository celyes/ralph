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
     * Oldest PHP version the package supports.
     *
     * The same requirement as `composer.json`'s `php` constraint and the
     * literal in `bin/ralph`, which cannot load this class before it has an
     * autoloader. The test suite asserts that all three agree.
     */
    public const string MINIMUM_PHP_VERSION = '8.3.0';

    /**
     * PHP extensions the package needs, without the `ext-` prefix Composer
     * uses.
     *
     * Declared here as well as in `composer.json` because Composer's
     * requirement is checked when the package is installed, while preflight
     * has to answer the different question of whether the PHP binary that is
     * about to run a loop has them: a global tool installed under one PHP build
     * can easily be invoked under another.
     *
     * @var list<string>
     */
    public const array REQUIRED_EXTENSIONS = ['json', 'mbstring', 'pdo', 'pdo_sqlite'];

    /**
     * Oldest Git version the package supports.
     *
     * Chosen from the Git features the design commits to, newest first:
     * `--end-of-options` (2.24), which is how a configured reference is passed
     * to `git rev-parse` so that it can never be read as an option; `git
     * worktree remove` (2.17), the cleanup path for task workspaces; `git
     * worktree list --porcelain` (2.7); and `git worktree add` (2.5). 2.24 was
     * released in November 2019 and is older than the Git in every currently
     * supported Debian and Ubuntu release.
     */
    public const string MINIMUM_GIT_VERSION = '2.24.0';

    /**
     * Directory, relative to the user's repository root, holding all project
     * configuration and runtime data.
     */
    public const string PROJECT_DIRECTORY = '.ralph';

    private function __construct()
    {
    }
}
