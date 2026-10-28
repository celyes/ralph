<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\GitProbe;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;

/**
 * That the project directory is the root of a working tree Ralph PHP can work
 * in.
 *
 * Three ways it can fail, each needing its own answer:
 *
 *  - the directory is not in a repository at all, which `ralph init` does not
 *    create and must not be mistaken for a missing configuration;
 *  - the repository is bare, so there is no working tree to branch from;
 *  - the directory is *inside* a repository but is not its root, which is the
 *    common case of running the command from a subdirectory. Ralph PHP would
 *    then read `.ralph/` from one place and commit from another, so it is
 *    reported with the path to run from instead of being silently corrected:
 *    the project root is also where the configuration, the checklist and the
 *    runtime directory live, and moving the run is the user's decision.
 *
 * Writability is checked here too, because it is the same requirement from the
 * runner's side: the runtime directory, the logs and the checkbox updates are
 * all written into this directory.
 */
final class RepositoryCheck implements PreflightCheck
{
    public function __construct(private readonly GitProbe $git = new GitProbe())
    {
    }

    public function id(): CheckId
    {
        return CheckId::Repository;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $root = $context->projectRoot();
        $bare = $this->git->run($context, 'rev-parse', '--is-bare-repository');

        if (!$bare->succeeded()) {
            return CheckResult::failed(
                $this->id(),
                sprintf('%s is not in a Git repository', $root),
                [sprintf('git reported: %s', $bare->failureReason())],
                [
                    'Run "git init" in the project, or change into the repository first.',
                    'Ralph PHP works in an existing repository: it creates a branch and a worktree '
                        . 'for each task.',
                ],
            );
        }

        if ($bare->trimmedOutput() === 'true') {
            return CheckResult::failed(
                $this->id(),
                sprintf('%s is a bare repository', $root),
                [],
                [
                    'Ralph PHP needs a working tree: each task is implemented in a linked worktree '
                        . 'of it and verified there.',
                    'Clone the repository and run ralph in the clone.',
                ],
            );
        }

        $toplevel = $this->git->run($context, 'rev-parse', '--show-toplevel');

        if (!$toplevel->succeeded()) {
            return CheckResult::failed(
                $this->id(),
                sprintf('the working tree of %s could not be located', $root),
                [sprintf('git reported: %s', $toplevel->failureReason())],
                ['Run ralph from the repository\'s working tree.'],
            );
        }

        $repository = self::resolve($toplevel->trimmedOutput());

        if ($repository !== self::resolve($root)) {
            return CheckResult::failed(
                $this->id(),
                sprintf('%s is not the root of its repository', $root),
                [sprintf('repository root: %s', $repository)],
                [
                    sprintf('Run ralph from %s.', $repository),
                    'The configuration, the checklist and the runtime directory belong to the '
                        . 'repository root, and task commits are made there.',
                ],
            );
        }

        if (!is_writable($root)) {
            return CheckResult::failed(
                $this->id(),
                sprintf('%s is not writable', $root),
                [],
                [
                    'Ralph PHP writes its runtime directory, its logs and the checklist\'s checkboxes '
                        . 'inside the project.',
                ],
            );
        }

        return CheckResult::passed(
            $this->id(),
            sprintf('Git working tree at %s', $repository),
            $this->gitDirectory($context),
        );
    }

    /**
     * Where the repository keeps its data, as a detail.
     *
     * Worth reporting because it is the one part of the repository Ralph PHP
     * never touches directly but everything depends on, and because it is how a
     * user sees that they are standing in a linked worktree rather than the
     * main one.
     *
     * @return list<string>
     */
    private function gitDirectory(PreflightContext $context): array
    {
        $gitDirectory = $this->git->run($context, 'rev-parse', '--absolute-git-dir');

        if (!$gitDirectory->succeeded()) {
            return [];
        }

        $path = $gitDirectory->trimmedOutput();
        $details = [sprintf('git directory: %s', $path)];

        if (str_contains(str_replace('\\', '/', $path), '/worktrees/')) {
            $details[] = 'this working tree is itself a linked worktree';
        }

        return $details;
    }

    /**
     * Compare paths as the filesystem sees them.
     *
     * realpath() rather than a textual normalisation: the project root and the
     * path Git reports routinely differ only by a symlink — `/tmp` on macOS,
     * and any checkout reached through one — and reporting those as different
     * directories would send the user to a path they are already in.
     */
    private static function resolve(string $path): string
    {
        $resolved = realpath($path);

        return Path::normalize($resolved === false ? $path : $resolved);
    }
}
