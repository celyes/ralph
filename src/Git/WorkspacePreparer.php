<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Config\PreparationCommand;
use Celyes\RalphPhp\Config\WorkspaceSettings;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessRunner;

/**
 * Makes a freshly created task worktree a directory the project can actually be
 * built, tested and worked on in (PROJECT.md section F, item 5).
 *
 * {@see TaskWorktrees} produces a checkout of the repository's *tracked* files
 * and nothing else, which for a PHP project is not a working project: there is
 * no `vendor/`, because dependencies are installed rather than committed, and
 * none of the local files Git never carried — a git-ignored `phpunit.xml` beside
 * the committed `phpunit.xml.dist`, a `.env`, an `auth.json`. Sending an agent
 * in before that is fixed means sending it somewhere `composer test` fails for
 * reasons that are nothing to do with its task.
 *
 * Copies first, then commands, because an install may need the credential or
 * the environment file to work at all. Both come from
 * {@see WorkspaceSettings}, which documents the division: commands for what is
 * reproducible from committed files, copies for what exists only on this
 * machine.
 *
 * Three rules make the difference between this and a shell script:
 *
 *  - **It only ever writes inside the workspace.** The controlling checkout is
 *    read from and never written to, which is the promise
 *    {@see ControllingCheckout} makes about the directory the user is sitting
 *    in; a workspace path that is the project root is refused rather than
 *    prepared.
 *  - **It never overwrites a file that is already there.** An adopted workspace
 *    — an interrupted run's, which resume continues in — may hold a copied file
 *    the agent has since edited, and re-copying would silently discard that
 *    edit. A file already present is recorded as such and left alone.
 *  - **A failure stops the task before the agent starts.** Preparation that
 *    half worked is not a workspace to work in, so there is no partial result:
 *    either a {@see WorkspacePreparation} describing a usable workspace, or a
 *    {@see PreparationException} naming what to fix.
 *
 * Commands run on every preparation, including in an adopted workspace, and so
 * must be safe to run twice — which `composer install` is, and which the
 * documentation states as the contract for whatever a project lists. The
 * alternative is worse: a workspace an interrupted run created but never
 * finished installing into would be handed to an agent as though it were ready.
 *
 * Both settings are empty by default, so the commonest preparation is none at
 * all: a project that needs nothing beyond its tracked files gets its workspace
 * as Git leaves it, with no command run and nothing copied.
 */
final class WorkspacePreparer
{
    /**
     * Mode for directories created to hold a copied file.
     *
     * Deliberately not the 0700 the runtime directory gets: these are the
     * project's own directories inside a checkout — `config/`, `tests/` — and
     * they should look like the ones Git creates. The umask applies, as it does
     * to a checkout.
     */
    private const int DIRECTORY_MODE = 0o777;

    public function __construct(
        private readonly WorkspaceSettings $settings,
        private readonly string $projectRoot,
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The preparer for a project, reading its `workspace` settings.
     */
    public static function forProject(
        Configuration $configuration,
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self($configuration->workspace(), $configuration->projectRoot(), $processes);
    }

    /**
     * Prepare a task's workspace, and report what that took.
     *
     * @param OutputSink|null $sink where a command's output is forwarded as it
     *        arrives — the attempt's log and the terminal — or null to collect
     *        it without showing it. Never closed here: the caller that opened
     *        the log owns it.
     *
     * @throws PreparationException when the workspace cannot be made usable
     */
    public function prepare(TaskWorktree $worktree, ?OutputSink $sink = null): WorkspacePreparation
    {
        $path = $worktree->path();

        $this->requireWorkspace($path);

        if ($this->settings->isEmpty()) {
            return WorkspacePreparation::nothingToDo($path);
        }

        [$copied, $alreadyPresent] = $this->copyFiles($path, $sink);

        return new WorkspacePreparation($path, $copied, $alreadyPresent, $this->runCommands($path, $sink));
    }

    /**
     * Refuse anything that is not a task workspace to write into.
     *
     * The project root is checked by identity rather than containment: a
     * workspace *is* inside the project, under the runtime directory, so the
     * question is only whether this is the one directory the package has
     * promised not to write to. Both refusals are defence in depth —
     * {@see TaskWorktrees} cannot return either — and both are cheap enough to
     * be worth having before a command runs with a working directory.
     *
     * @throws PreparationException when the path is not a usable workspace
     */
    private function requireWorkspace(string $path): void
    {
        if (Path::normalize($path) === Path::normalize($this->projectRoot)) {
            throw new PreparationException(
                sprintf('A task workspace cannot be the project directory itself, %s.', $path),
                [
                    'Ralph PHP prepares and works in a linked worktree so that your own checkout is left '
                        . 'alone, and will not install into it or copy files over it.',
                ],
            );
        }

        if (!is_dir($path)) {
            throw new PreparationException(
                sprintf('The task workspace %s does not exist, so it cannot be prepared.', $path),
                ['Run the command again to create it.'],
            );
        }
    }

    /**
     * Copy the configured files in, skipping those the workspace already has.
     *
     * @return array{list<string>, list<string>} the paths copied, and the paths left alone
     *
     * @throws PreparationException when a configured file cannot be copied
     */
    private function copyFiles(string $workspace, ?OutputSink $sink): array
    {
        $copied = [];
        $alreadyPresent = [];

        foreach ($this->settings->copy() as $relative) {
            $source = Path::normalize($this->projectRoot . '/' . $relative);
            $destination = Path::normalize($workspace . '/' . $relative);

            // A broken symlink is not "absent": file_exists() follows the link,
            // so the link itself is asked about first and either way the
            // destination is left as it is.
            if (is_link($destination) || file_exists($destination)) {
                $alreadyPresent[] = $relative;
                $sink?->note(sprintf('workspace: %s is already present, left as it is', $relative));

                continue;
            }

            $this->copyFile($relative, $source, $destination);

            $copied[] = $relative;
            $sink?->note(sprintf('workspace: copied %s', $relative));
        }

        return [$copied, $alreadyPresent];
    }

    /**
     * Copy one configured file into the workspace.
     *
     * Regular files only. A directory is refused rather than copied
     * recursively: the directory a project would reach for is `vendor/`, and
     * copying that is the wrong answer to a question `composer install` already
     * answers reproducibly — while a recursive copy would also have to decide
     * what to do about the symlinks, the sockets and the half-written files a
     * build directory can contain. A symlink is refused for the same reason it
     * would be unsafe to resolve: what it points at may be outside the project
     * entirely.
     *
     * The mode is copied after the contents so that a 0600 `.env` arrives as a
     * 0600 `.env`: preparation must not be the thing that makes a credential
     * readable to the rest of the machine.
     *
     * @throws PreparationException when the file is not there, is not a file, or
     *                              cannot be written
     */
    private function copyFile(string $relative, string $source, string $destination): void
    {
        if (is_link($source)) {
            throw $this->cannotCopy(
                $relative,
                sprintf('%s is a symbolic link', $source),
                'Ralph PHP copies regular files only: it cannot know whether a link points inside the project.',
                sprintf('List the file the link points at, or create %s in the workspace with a prepare command.', $relative),
            );
        }

        if (!file_exists($source)) {
            throw $this->cannotCopy(
                $relative,
                sprintf('there is no %s in %s', $relative, $this->projectRoot),
                'Files under "workspace.copy" are the ones that exist on this machine and in no commit, so a '
                    . 'task workspace can only get them from your checkout.',
                sprintf('Create %s, or remove it from "workspace.copy".', $relative),
            );
        }

        if (is_dir($source)) {
            throw $this->cannotCopy(
                $relative,
                sprintf('%s is a directory', $source),
                'Ralph PHP copies regular files only.',
                'List the files it holds, or produce it in the workspace with a "workspace.prepare" command — '
                    . 'which is the better answer for anything an install can rebuild, such as "vendor".',
            );
        }

        $directory = dirname($destination);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            throw $this->cannotCopy(
                $relative,
                sprintf('%s could not be created', $directory),
                'Check that the task workspace is writable.',
            );
        }

        if (!@copy($source, $destination)) {
            throw $this->cannotCopy(
                $relative,
                sprintf('%s could not be written', $destination),
                sprintf('Check that %s is readable and the task workspace is writable.', $source),
            );
        }

        $mode = @fileperms($source);

        if ($mode !== false) {
            @chmod($destination, $mode & 0o7777);
        }
    }

    /**
     * Run the configured commands in the workspace, in order, stopping at the
     * first failure.
     *
     * The working directory is the workspace and is passed explicitly, so a
     * command that reads `composer.json` reads the workspace's own. Nothing is
     * interpolated into a shell: each command is the argument array the
     * configuration holds.
     *
     * @return list<PreparationStep>
     *
     * @throws PreparationException when a command fails or times out
     */
    private function runCommands(string $workspace, ?OutputSink $sink): array
    {
        $steps = [];

        foreach ($this->settings->prepare() as $command) {
            $sink?->note(sprintf('workspace: %s — %s', $command->name(), $command->describe()));

            $result = $this->processes->run(
                $command->command(),
                $workspace,
                $command->timeout(),
                sink: $sink,
            );

            $step = PreparationStep::of($command, $result);

            if (!$step->succeeded()) {
                throw new PreparationException(
                    sprintf(
                        'the workspace at %s could not be prepared: "%s" failed: %s',
                        $workspace,
                        $command->describe(),
                        $result->failureReason(),
                    ),
                    [
                        sprintf(
                            'It is "workspace.prepare" entry "%s"%s.',
                            $command->name(),
                            $result->timedOut()
                                ? sprintf(', and it was stopped after %d seconds', $command->timeout())
                                : sprintf(', and it exited with status %d', $result->exitCode() ?? -1),
                        ),
                        sprintf('Run it yourself in %s to see what it reports.', $workspace),
                        'A workspace without its dependencies cannot pass the verification commands, so no '
                            . 'agent was started for this task.',
                    ],
                );
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * @param string ...$hints
     */
    private function cannotCopy(string $relative, string $problem, string ...$hints): PreparationException
    {
        return new PreparationException(
            sprintf('the configured file "%s" could not be copied into the task workspace: %s.', $relative, $problem),
            array_values($hints),
        );
    }
}
