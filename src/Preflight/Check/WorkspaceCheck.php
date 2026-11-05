<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Config\PreparationCommand;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\ComposerScripts;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ExecutableFinder;

/**
 * That a new task worktree could be made usable.
 *
 * A linked worktree holds the repository's tracked files and nothing else, so
 * every task workspace starts without a `vendor/` and without whatever local
 * files Git never carried. The `workspace` settings close that gap
 * ({@see \Celyes\RalphPhp\Git\WorkspacePreparer}), and this is the part of
 * their correctness that can be established before a run: whether the programs
 * the preparation commands name are installed, and whether the files they are
 * told to copy actually exist here to be copied.
 *
 * Worth checking up front because of *when* the alternative fails. Preparation
 * happens after the base commit is resolved and the worktree is created, and
 * failing there means a workspace on disk and a task that has not started — for
 * a missing `.env` or an uninstalled Composer, both of which are visible now.
 *
 * As with {@see VerificationCheck}, the commands are resolved and never
 * executed: running an install here would do minutes of network work in the
 * wrong directory, since what has to be installed is a worktree that does not
 * exist yet.
 *
 * One limitation is inherent and is documented rather than worked around: a
 * command that resolves is not a command that will succeed. `composer install`
 * can still fail in a fresh worktree over a private repository it cannot
 * authenticate to or a platform requirement this PHP does not meet. That
 * failure is reported by preparation, against the command that produced it.
 */
final class WorkspaceCheck implements PreflightCheck
{
    public function __construct(private readonly ExecutableFinder $executables = new ExecutableFinder())
    {
    }

    public function id(): CheckId
    {
        return CheckId::Workspace;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $workspace = $context->configuration()->workspace();

        if ($workspace->isEmpty()) {
            return CheckResult::passed(
                $this->id(),
                'task workspaces need no preparation',
                ['"workspace" configures no commands and no files to copy.'],
            );
        }

        $root = $context->projectRoot();
        $details = [];
        $problems = [];
        $hints = [];

        foreach ($workspace->prepare() as $command) {
            $problem = $this->commandProblem($command, $root);

            if ($problem === null) {
                $details[] = sprintf(
                    '%s: "%s" runs %s',
                    $command->name(),
                    $command->describe(),
                    (string) $this->executables->find($command->command()[0], $root),
                );

                continue;
            }

            [$summary, $commandHints] = $problem;
            $problems[] = $command->name();
            $details[] = sprintf('%s: "%s" cannot run: %s', $command->name(), $command->describe(), $summary);
            $hints = [...$hints, ...$commandHints];
        }

        foreach ($workspace->copy() as $relative) {
            $problem = $this->fileProblem($root, $relative);

            if ($problem === null) {
                $details[] = sprintf('copies %s', $relative);

                continue;
            }

            [$summary, $fileHints] = $problem;
            $problems[] = $relative;
            $details[] = sprintf('%s cannot be copied: %s', $relative, $summary);
            $hints = [...$hints, ...$fileHints];
        }

        if ($problems !== []) {
            return CheckResult::failed(
                $this->id(),
                sprintf(
                    '%d of the "workspace" %s cannot be used: %s',
                    count($problems),
                    count($problems) === 1 ? 'setting' : 'settings',
                    implode(', ', $problems),
                ),
                $details,
                $hints,
            );
        }

        return CheckResult::passed($this->id(), $this->summary($workspace->prepare(), $workspace->copy()), $details);
    }

    /**
     * Why a preparation command could not run, or null when it could.
     *
     * @return array{string, list<string>}|null
     */
    private function commandProblem(PreparationCommand $command, string $root): ?array
    {
        $executable = $command->command()[0];
        $resolved = $this->executables->find($executable, $root);

        if ($resolved === null) {
            return [
                ExecutableFinder::isSearched($executable)
                    ? sprintf('"%s" was not found in PATH', $executable)
                    : sprintf('"%s" is not an executable file', $executable),
                [sprintf(
                    'Install "%s", or change "workspace.prepare" to the commands this project needs.',
                    $executable,
                )],
            ];
        }

        if (!ComposerScripts::isComposer($resolved)) {
            return null;
        }

        if (!ComposerScripts::projectHasComposerJson($root)) {
            return [
                sprintf('there is no composer.json in %s', $root),
                [
                    'The default preparation command installs Composer dependencies, because a task worktree '
                        . 'never has a "vendor" of its own.',
                    'Either add a composer.json, or set "workspace.prepare" to what this project needs — "[]" '
                        . 'if a plain checkout is already usable.',
                ],
            ];
        }

        $script = ComposerScripts::scriptIn(array_slice($command->command(), 1));
        $defined = $script === null ? null : ComposerScripts::definedIn($root);

        if ($script === null || $defined === null || in_array($script, $defined, true)) {
            return null;
        }

        return [
            sprintf('composer.json defines no "%s" script', $script),
            [
                $defined === []
                    ? sprintf('%s defines no scripts at all.', ComposerScripts::fileIn($root))
                    : sprintf('Scripts it does define: %s.', implode(', ', $defined)),
                sprintf('Add a "%s" script, or change "workspace.prepare" to the command it would run.', $script),
            ],
        ];
    }

    /**
     * Why a configured file could not be copied into a workspace, or null when
     * it could.
     *
     * The same three refusals {@see \Celyes\RalphPhp\Git\WorkspacePreparer}
     * makes, asked here so they are reported before a worktree is created
     * rather than after. A path that is valid in the configuration can still be
     * absent on this machine, which is exactly the situation this setting
     * exists for: the files it lists are the ones no commit carries.
     *
     * @return array{string, list<string>}|null
     */
    private function fileProblem(string $root, string $relative): ?array
    {
        $source = Path::normalize($root . '/' . $relative);

        if (is_link($source)) {
            return [
                sprintf('%s is a symbolic link', $source),
                ['Ralph PHP copies regular files only: list the file the link points at.'],
            ];
        }

        if (!file_exists($source)) {
            return [
                sprintf('there is no %s in %s', $relative, $root),
                [
                    'Files under "workspace.copy" exist on this machine and in no commit, so a task workspace '
                        . 'can only get them from your checkout.',
                    sprintf('Create %s, or remove it from "workspace.copy".', $relative),
                ],
            ];
        }

        if (is_dir($source)) {
            return [
                sprintf('%s is a directory', $source),
                [
                    'Ralph PHP copies regular files only.',
                    'List the files it holds, or produce it in the workspace with a "workspace.prepare" '
                        . 'command — which is the better answer for anything an install can rebuild.',
                ],
            ];
        }

        if (!is_readable($source)) {
            return [
                sprintf('%s cannot be read', $source),
                [sprintf('Check the permissions of %s.', $source)],
            ];
        }

        return null;
    }

    /**
     * What a passing check says it established.
     *
     * @param list<PreparationCommand> $prepare
     * @param list<string> $copy
     */
    private function summary(array $prepare, array $copy): string
    {
        $parts = [];

        if ($prepare !== []) {
            $parts[] = sprintf(
                '%d preparation %s can run',
                count($prepare),
                count($prepare) === 1 ? 'command' : 'commands',
            );
        }

        if ($copy !== []) {
            $parts[] = sprintf(
                '%d %s can be copied in',
                count($copy),
                count($copy) === 1 ? 'file' : 'files',
            );
        }

        return implode(', ', $parts);
    }
}
