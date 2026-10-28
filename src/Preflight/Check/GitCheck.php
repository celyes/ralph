<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\GitProbe;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Ralph;

/**
 * That `git` is installed and new enough.
 *
 * Checked first among the Git checks, and remembered for the rest: everything
 * the runner does to a repository goes through this executable, so the version
 * is a requirement of the package rather than a detail. The minimum and the
 * features it comes from are documented on {@see Ralph::MINIMUM_GIT_VERSION}.
 *
 * A version string that cannot be parsed is a warning rather than a failure.
 * Distributions and vendors append their own text to it, and refusing to run
 * because a build calls itself something unexpected would be a worse failure
 * than proceeding with a Git that is almost certainly fine.
 */
final class GitCheck implements PreflightCheck
{
    /**
     * Key the resolved executable is remembered under, for the checks that
     * query the repository through {@see GitProbe}.
     */
    public const string EXECUTABLE = 'git.executable';

    /**
     * The command name. There is no setting for it: a project does not choose
     * which Git it is versioned with, and a `git` that is not on PATH is an
     * environment problem rather than a configurable one.
     */
    private const string COMMAND = 'git';

    public function __construct(
        private readonly GitProbe $git = new GitProbe(),
        private readonly ExecutableFinder $executables = new ExecutableFinder(),
    ) {
    }

    public function id(): CheckId
    {
        return CheckId::Git;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $executable = $this->executables->find(self::COMMAND, $context->projectRoot());

        if ($executable === null) {
            return CheckResult::failed(
                $this->id(),
                sprintf('"%s" was not found in PATH', self::COMMAND),
                [sprintf('searched %s', $this->executables->describeSearchPath())],
                [
                    'Install Git: Ralph PHP branches, commits and creates worktrees through it.',
                    sprintf('Ralph PHP requires Git %s or newer.', Ralph::MINIMUM_GIT_VERSION),
                ],
            );
        }

        // Remembered before the version query, which is itself issued through
        // the probe and so needs the path.
        $context->remember(self::EXECUTABLE, $executable);

        $result = $this->git->run($context, '--version');

        if (!$result->succeeded()) {
            return CheckResult::failed(
                $this->id(),
                sprintf('"%s --version" failed: %s', $executable, $result->failureReason()),
                [sprintf('executable: %s', $executable)],
                ['Check that the installation is intact: "git --version" must work.'],
            );
        }

        $reported = $result->firstOutputLine();
        $version = self::versionIn($reported);
        $details = [
            sprintf('executable: %s', $executable),
            sprintf('requires Git %s or newer', Ralph::MINIMUM_GIT_VERSION),
        ];

        if ($version === null) {
            return CheckResult::warning(
                $this->id(),
                sprintf('found %s, whose version could not be read', $executable),
                [...$details, sprintf('reported: %s', $reported)],
                [sprintf('Ralph PHP requires Git %s or newer; check it yourself.', Ralph::MINIMUM_GIT_VERSION)],
            );
        }

        if (version_compare($version, Ralph::MINIMUM_GIT_VERSION, '<')) {
            return CheckResult::failed(
                $this->id(),
                sprintf('Git %s is older than the required %s', $version, Ralph::MINIMUM_GIT_VERSION),
                [...$details, sprintf('reported: %s', $reported)],
                ['Upgrade Git, or point PATH at a newer one.'],
            );
        }

        return CheckResult::passed($this->id(), sprintf('Git %s', $version), $details);
    }

    /**
     * The version number in `git version ...`, or null when the output does not
     * contain a recognisable one.
     *
     * Only the numeric prefix is taken: builds append their own text, as in
     * "git version 2.39.3 (Apple Git-145)" and "2.43.0.windows.1", and
     * version_compare() reads those suffixes as pre-release markers that make a
     * released Git look older than it is.
     */
    private static function versionIn(string $reported): ?string
    {
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $reported, $matches) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
    }
}
