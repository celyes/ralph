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
     * Package version, as a plain three-part number.
     *
     * Composer and Packagist read a release's version from its Git tag and not
     * from here, so this is only what the package says about itself: what
     * `ralph --version` prints and what a bug report quotes. Tagging a release
     * means setting this to the tag's number first, which is why it carries no
     * `-dev` suffix. 0.1.0 was tagged while this still read `0.1.0-dev`, and
     * so every copy of that release reported a version nobody had released.
     */
    public const string VERSION = '0.1.4';

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
     * Extensions the package uses where they are there, and works without.
     *
     * `pcntl` and `posix` are what make Ctrl+C a handled event rather than a
     * dead process: the first installs the signal handler, the second reaches
     * the children an agent spawned (PROJECT.md section H, item 4). Without
     * them a run still works and still records everything else; an interruption
     * simply ends the process where it stands, leaving work for `ralph resume`
     * to reconcile rather than a run that wrote itself up on the way out.
     *
     * Suggested in `composer.json` and reported by preflight for that reason,
     * and required by neither: a build without them can do every other thing
     * this package does, and refusing to install there would be refusing over a
     * courtesy.
     *
     * @var array<string, string>
     */
    public const array OPTIONAL_EXTENSIONS = [
        'pcntl' => 'handles Ctrl+C by stopping the agent and recording the interruption',
        'posix' => 'reaches the processes an agent started, so none is left behind',
    ];

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
