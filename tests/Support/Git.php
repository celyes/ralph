<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * Drives real `git` for tests that need a repository.
 *
 * Preflight's Git checks read a repository's actual state — whether it is bare,
 * where its root is, whether a reference resolves, whether an identity is
 * configured — so a stub would only prove that the stub behaves as the test
 * expects. These helpers create throwaway repositories instead, which is also
 * what PROJECT.md section J asks of the branch, worktree and commit tests.
 *
 * Identity is never configured implicitly: a repository without one is exactly
 * what the identity check has to catch, so tests ask for it when they want it.
 */
final class Git
{
    /**
     * Whether `git` is available at all. Tests that need it skip without it.
     */
    public static function isAvailable(): bool
    {
        return self::tryRun(sys_get_temp_dir(), '--version')->exitCode === 0;
    }

    /**
     * The numeric version of the installed `git`, or null when it cannot be
     * read.
     */
    public static function version(): ?string
    {
        $result = self::tryRun(sys_get_temp_dir(), '--version');

        if ($result->exitCode !== 0 || preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $result->standardOutput, $m) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    /**
     * Create a repository in an existing directory.
     */
    public static function initialise(string $directory, bool $bare = false): void
    {
        $arguments = ['init', '--quiet'];

        if ($bare) {
            $arguments[] = '--bare';
        }

        // An explicit initial branch keeps the tests independent of the
        // machine's init.defaultBranch, and of the hint Git prints without it.
        $arguments[] = '--initial-branch=main';
        $arguments[] = '.';

        self::run($directory, ...$arguments);
    }

    /**
     * Configure the repository's own author identity.
     */
    public static function setIdentity(string $directory, string $name = 'Test User', string $email = 'test@example.com'): void
    {
        self::run($directory, 'config', 'user.name', $name);
        self::run($directory, 'config', 'user.email', $email);
    }

    /**
     * Create a commit, with an identity supplied for that command only, and
     * return its SHA.
     */
    public static function commit(string $directory, string $message = 'Initial commit'): string
    {
        self::run(
            $directory,
            '-c',
            'user.name=Test User',
            '-c',
            'user.email=test@example.com',
            'commit',
            '--quiet',
            '--allow-empty',
            '--message',
            $message,
        );

        return trim(self::run($directory, 'rev-parse', 'HEAD')->standardOutput);
    }

    /**
     * Run a git command and fail the test's setup if it does not succeed.
     */
    public static function run(string $directory, string ...$arguments): CliResult
    {
        $result = self::tryRun($directory, ...$arguments);

        if ($result->exitCode !== 0) {
            throw new RuntimeException('git failed during test setup: ' . $result->describe());
        }

        return $result;
    }

    /**
     * Run a git command and return its result, whatever it is.
     */
    public static function tryRun(string $directory, string ...$arguments): CliResult
    {
        $command = ['git'];

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        return Cli::runCommand($command, $directory, [
            // The repositories these tests create must not inherit a
            // redirection from the developer's shell, and must not be affected
            // by hooks or templates configured on the machine.
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_TEMPLATE_DIR' => '',
        ]);
    }

    private function __construct()
    {
    }
}
