<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\ComposerScripts;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ExecutableFinder;

/**
 * That every configured verification command could run.
 *
 * The checks are what a task's completion is decided by, so a project whose
 * checks cannot run has no way to complete a task — and would discover that
 * after the first implementation rather than before it. Configuration
 * validation has already established that each command is a non-empty argument
 * array; what is left is whether the machine has the program it names, and
 * whether a `composer` invocation names a script the project defines.
 *
 * The commands are resolved, never executed. Running the suite here would
 * double the cost of every run and, worse, would report a project's existing
 * test failures as a Ralph PHP problem. The checks run per attempt, in the
 * task's own worktree, which is where their result means something.
 *
 * One further limitation, worth stating because the check cannot cover it: a
 * command that resolves here is resolved against this working tree. Preparing a
 * fresh worktree so the same command works there — installing dependencies,
 * providing test configuration — is the subject of PROJECT.md section F.
 */
final class VerificationCheck implements PreflightCheck
{
    public function __construct(private readonly ExecutableFinder $executables = new ExecutableFinder())
    {
    }

    public function id(): CheckId
    {
        return CheckId::Checks;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $root = $context->projectRoot();
        $checks = $context->configuration()->checks();
        $details = [];
        $unusable = [];
        $hints = [];

        foreach ($checks as $check) {
            $resolved = $this->executables->find($check->command()[0], $root);
            $problem = $resolved === null
                ? $this->missingExecutable($check->command()[0])
                : $this->composerProblem($check, $root, $resolved);

            if ($problem === null) {
                $details[] = sprintf(
                    '%s: "%s" %s',
                    $check->name(),
                    $check->describe(),
                    $this->evidence($check, $root, (string) $resolved),
                );

                continue;
            }

            [$summary, $checkHints] = $problem;
            $unusable[] = $check->name();
            $details[] = sprintf('%s: "%s" cannot run: %s', $check->name(), $check->describe(), $summary);
            $hints = [...$hints, ...$checkHints];
        }

        if ($unusable !== []) {
            return CheckResult::failed(
                $this->id(),
                sprintf(
                    '%d of %d verification %s cannot run: %s',
                    count($unusable),
                    count($checks),
                    count($checks) === 1 ? 'command' : 'commands',
                    implode(', ', $unusable),
                ),
                $details,
                $hints,
            );
        }

        return CheckResult::passed(
            $this->id(),
            sprintf(
                '%d verification %s can run',
                count($checks),
                count($checks) === 1 ? 'command' : 'commands',
            ),
            $details,
        );
    }

    /**
     * The command names a program that is not there.
     *
     * @return array{string, list<string>}
     */
    private function missingExecutable(string $executable): array
    {
        return [
            ExecutableFinder::isSearched($executable)
                ? sprintf('"%s" was not found in PATH', $executable)
                : sprintf('"%s" is not an executable file', $executable),
            [sprintf('Install "%s", or change "checks" to a command this project can run.', $executable)],
        ];
    }

    /**
     * Why a Composer invocation could not do what the configuration asks, or
     * null when it could — including when the command is not Composer at all,
     * and when its arguments do not name a script this class can identify.
     *
     * @return array{string, list<string>}|null
     */
    private function composerProblem(VerificationCommand $check, string $root, string $resolved): ?array
    {
        if (!ComposerScripts::isComposer($resolved)) {
            return null;
        }

        if (!ComposerScripts::projectHasComposerJson($root)) {
            return [
                sprintf('there is no composer.json in %s', $root),
                ['Either add one, or configure a check that does not go through Composer.'],
            ];
        }

        $script = ComposerScripts::scriptIn(array_slice($check->command(), 1));
        if ($script === null) {
            return null;
        }

        $defined = ComposerScripts::definedIn($root);
        if ($defined === null || in_array($script, $defined, true)) {
            return null;
        }

        return [
            sprintf('composer.json defines no "%s" script', $script),
            [
                $defined === []
                    ? sprintf('%s defines no scripts at all.', ComposerScripts::fileIn($root))
                    : sprintf('Scripts it does define: %s.', implode(', ', $defined)),
                sprintf('Add a "%s" script, or change "checks" to the command it would run.', $script),
            ],
        ];
    }

    /**
     * What was found for a check that can run.
     */
    private function evidence(VerificationCommand $check, string $root, string $resolved): string
    {
        $script = ComposerScripts::isComposer($resolved)
            ? ComposerScripts::scriptIn(array_slice($check->command(), 1))
            : null;
        $defined = $script === null ? null : ComposerScripts::definedIn($root);

        if ($script === null || $defined === null || !in_array($script, $defined, true)) {
            return sprintf('runs %s', $resolved);
        }

        return sprintf('runs %s, and composer.json defines its "%s" script', $resolved, $script);
    }
}
