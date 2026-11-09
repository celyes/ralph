<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Tasks\TaskId;

/**
 * What the five commands that work on a project have in common.
 *
 * `run`, `status`, `logs`, `resume` and `retry` all begin the same three ways:
 * they refuse a command line they do not understand, they operate on the
 * current directory, and they read the project's configuration from it. Those
 * three are here so that the commands differ only where they actually differ,
 * and so that a mistyped option is reported identically whichever of them was
 * asked for.
 *
 * Like `git` and `composer`, the directory is where the user is standing rather
 * than something read off the command line: a project path option would be a
 * second way to say the same thing, and a wrong one is much harder to notice
 * than being in the wrong directory.
 */
abstract class ProjectCommand implements CommandInterface
{
    public function __construct(protected readonly ConfigurationLoader $loader = new ConfigurationLoader())
    {
    }

    /**
     * Long option names that take a separate value.
     *
     * @return list<string>
     */
    public function valueOptions(): array
    {
        return [];
    }

    /**
     * Option names this command accepts, beyond `--help`.
     *
     * @return list<string>
     */
    protected function options(): array
    {
        return [];
    }

    /**
     * How many positional arguments this command takes.
     */
    protected function maximumArguments(): int
    {
        return 0;
    }

    /**
     * Fail on anything the command does not accept, instead of ignoring it.
     *
     * An ignored option is the worst outcome available here: a user who typed
     * `--agent claude` as `--agant claude` and saw a run start would reasonably
     * believe they had selected an agent.
     *
     * @throws UsageException when an option or an argument is not one this
     *                        command takes
     */
    protected function rejectUnsupportedInput(Input $input): void
    {
        $unrecognised = array_values(array_diff(
            $input->optionNames(),
            [...$this->options(), 'h', 'help', 'v', 'verbose'],
        ));

        if ($unrecognised !== []) {
            throw new UsageException(
                sprintf(
                    'Unknown %s %s for "ralph %s".',
                    count($unrecognised) === 1 ? 'option' : 'options',
                    implode(', ', array_map(
                        static fn (string $option): string => '"' . (strlen($option) === 1 ? '-' : '--') . $option . '"',
                        $unrecognised,
                    )),
                    $this->name(),
                ),
                [sprintf('Run "ralph help %s" to see the supported usage.', $this->name())],
            );
        }

        $arguments = $input->arguments();
        $allowed = $this->maximumArguments();

        if (count($arguments) > $allowed) {
            throw new UsageException(
                $allowed === 0
                    ? sprintf(
                        '"ralph %s" takes no arguments, but "%s" was given.',
                        $this->name(),
                        $arguments[0],
                    )
                    : sprintf(
                        '"ralph %s" takes %d argument%s, but %d were given.',
                        $this->name(),
                        $allowed,
                        $allowed === 1 ? '' : 's',
                        count($arguments),
                    ),
                [sprintf('Run "ralph help %s" to see the supported usage.', $this->name())],
            );
        }
    }

    /**
     * The directory the command operates on.
     *
     * @throws FilesystemException when the current directory cannot be
     *                             determined
     */
    protected function projectRoot(): string
    {
        $directory = getcwd();

        if ($directory === false) {
            throw new FilesystemException(
                'Unable to determine the current directory.',
                [sprintf('Run "ralph %s" from the project directory.', $this->name())],
            );
        }

        return $directory;
    }

    /**
     * The project's configuration, which it must have.
     *
     * For the commands that do work: a project with no `.ralph/config.json` has
     * not been initialised, and starting a run on the defaults would write a
     * state database, a branch and a worktree into a directory whose owner
     * never asked for any of it.
     *
     * @throws FilesystemException    when the current directory cannot be
     *                                determined
     * @throws \Celyes\RalphPhp\Exception\ConfigurationException when there is no
     *         configuration, or it is unusable
     */
    protected function configuration(): Configuration
    {
        return $this->loader->load($this->projectRoot());
    }

    /**
     * The project's configuration, or the defaults when it has none.
     *
     * For the commands that only report: a question about a project that was
     * never initialised has an answer, and it is more useful than a refusal.
     *
     * @throws FilesystemException when the current directory cannot be
     *                            determined
     */
    protected function configurationOrDefaults(): Configuration
    {
        return $this->loader->loadOrDefaults($this->projectRoot());
    }

    /**
     * The value of `--agent`, when it was given with one.
     *
     * Returned as given, including an empty string: what a name means is the
     * agent selection's business ({@see \Celyes\RalphPhp\Agent\AgentSelector}
     * and {@see \Celyes\RalphPhp\Run\AgentContinuity}), and the two refuse an
     * unusable one differently.
     *
     * @throws UsageException when `--agent` was used as a flag
     */
    protected function requestedAgent(Input $input): ?string
    {
        if (!$input->hasOption('agent')) {
            return null;
        }

        $name = $input->option('agent');

        if ($name === null) {
            throw new UsageException(
                'Option "--agent" requires an agent name.',
                [sprintf('For example: ralph %s --agent codex.', $this->name())],
            );
        }

        return $name;
    }

    /**
     * Read a task identifier off the command line.
     *
     * @throws UsageException when the argument is missing or is not an
     *                        identifier this package generates
     */
    protected function taskArgument(Input $input): TaskId
    {
        $given = $input->argument(0);

        if ($given === null || trim($given) === '') {
            throw new UsageException(
                sprintf('"ralph %s" needs a task identifier.', $this->name()),
                [
                    sprintf('Usage: ralph %s TASK-ID', $this->name()),
                    'Run "ralph status" to list the tasks with their identifiers.',
                ],
            );
        }

        $value = strtoupper(trim($given));

        if (!TaskId::isValid($value)) {
            throw new UsageException(
                sprintf('"%s" is not a task identifier.', $given),
                [
                    'Identifiers are generated from a task\'s own words and look like TASK-1A2B3C4D.',
                    'Run "ralph status" to list the tasks with their identifiers.',
                ],
            );
        }

        return TaskId::fromString($value);
    }
}
