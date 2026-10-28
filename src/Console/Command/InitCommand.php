<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\CommandUnavailableException;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Init\InitializationReport;
use Celyes\RalphPhp\Init\InitializedPath;
use Celyes\RalphPhp\Init\ProjectInitializer;

/**
 * `ralph init`: set up the current project.
 *
 * Operates on the current working directory, like `git init` and
 * `composer init`, so that what gets written is wherever the user is standing
 * and no path has to be read from the command line.
 *
 * Reports every path it is responsible for, created or kept, because a user who
 * runs this in an already-initialised project needs to see that their own files
 * were left alone.
 */
final class InitCommand implements CommandInterface
{
    public function __construct(private readonly ProjectInitializer $initializer = new ProjectInitializer())
    {
    }

    public function name(): string
    {
        return 'init';
    }

    public function summary(): string
    {
        return 'Create the project configuration, task checklist, and context file.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph init',
            '',
            'Creates, in the current directory:',
            '',
            $this->helpRow(Defaults::CONFIG_FILE, 'every setting, at its default value'),
            $this->helpRow(Defaults::TASKS_FILE, 'the task checklist, with instructions and no tasks'),
            $this->helpRow(Defaults::CONTEXT_FILE, 'project instructions passed to the agent'),
            $this->helpRow(Defaults::RUNTIME_DIRECTORY . '/', 'state, logs, memory and task worktrees'),
            '',
            'Files that already exist are kept as they are, and the configured task and',
            'context paths of an existing configuration are honoured. Nothing is read from',
            'or written to the Git repository.',
        ];
    }

    /**
     * One line of the help text's path table.
     */
    private function helpRow(string $path, string $purpose): string
    {
        return sprintf('  %-20s %s', $path, $purpose);
    }

    /**
     * @return list<string>
     */
    public function valueOptions(): array
    {
        return [];
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $directory = getcwd();
        if ($directory === false) {
            throw new FilesystemException(
                'Unable to determine the current directory.',
                ['Run "ralph init" from the project directory.'],
            );
        }

        $report = $this->initializer->initialize($directory);

        $this->report($report, $output);

        return ExitCode::SUCCESS;
    }

    /**
     * Fail on anything this command does not accept, instead of ignoring it.
     *
     * `--force` is singled out because the help text of earlier builds promised
     * it: a user who types it is asking for their files to be replaced, and
     * silently not replacing them would be the worst of the three answers.
     * Overwriting is PROJECT.md section B, item 5.
     */
    private function rejectUnsupportedInput(Input $input): void
    {
        if ($input->hasOption('f', 'force')) {
            throw new CommandUnavailableException(
                'Overwriting existing files is not implemented in this build.',
                [
                    'Existing files are always kept; delete the one you want regenerated and run "ralph init" again.',
                    'The explicit overwrite option is specified in PROJECT.md section B, item 5.',
                ],
            );
        }

        $unrecognised = array_values(array_diff($input->optionNames(), ['h', 'help', 'v', 'verbose']));
        if ($unrecognised !== []) {
            throw new UsageException(
                sprintf(
                    'Unknown %s %s for "ralph init".',
                    count($unrecognised) === 1 ? 'option' : 'options',
                    implode(', ', array_map(
                        static fn (string $option): string => '"' . (strlen($option) === 1 ? '-' : '--') . $option . '"',
                        $unrecognised,
                    )),
                ),
                ['Run "ralph help init" to see the supported usage.'],
            );
        }

        if ($input->arguments() !== []) {
            throw new UsageException(
                sprintf('"ralph init" takes no arguments, but "%s" was given.', $input->arguments()[0]),
                ['It initialises the current directory. Change into the project first.'],
            );
        }
    }

    /**
     * Print what was done, then what the user has to do next.
     */
    private function report(InitializationReport $report, Output $output): void
    {
        $output->line(sprintf('Initialising Ralph PHP in %s', $report->projectRoot()));
        $output->line();

        foreach ($report->paths() as $path) {
            $output->line(sprintf('  %-8s %s', $path->outcome()->label(), $path->label()));
        }

        $output->line();

        // Only kept *files* are called out: they hold content the user wrote,
        // and the point of the sentence is that initialisation did not touch
        // it. A directory that already existed is not news.
        $keptFiles = array_values(array_filter(
            $report->kept(),
            static fn (InitializedPath $path): bool => !$path->isDirectory(),
        ));

        if ($keptFiles !== []) {
            $output->line(sprintf(
                'Your existing %s %s left as %s.',
                $this->list(array_map(
                    static fn (InitializedPath $path): string => $path->label(),
                    $keptFiles,
                )),
                count($keptFiles) === 1 ? 'was' : 'were',
                count($keptFiles) === 1 ? 'it is' : 'they are',
            ));
            $output->line('Delete a file and run "ralph init" again to write it from the template.');
            $output->line();
        } elseif ($report->created() === []) {
            $output->line('Everything was already in place, so nothing was written.');
            $output->line();
        }

        // The paths come from the report rather than from the defaults: a
        // project that configures its checklist elsewhere must be told where
        // its own checklist is.
        $output->lines([
            'Next steps:',
            sprintf('  1. Describe the project in %s.', $report->context()->label()),
            sprintf(
                '  2. Write the work you want done in %s, one task per checklist item.',
                $report->tasks()->label(),
            ),
            sprintf(
                '  3. Check the "checks", "git" and "agent" settings in %s: a task is',
                $report->configuration()->label(),
            ),
            '     completed only once its verification commands pass.',
            '  4. Run "ralph run".',
        ]);
    }

    /**
     * @param list<string> $items
     */
    private function list(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
