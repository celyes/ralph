<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Init\IgnoreFileOutcome;
use Celyes\RalphPhp\Init\IgnoreFileWriter;
use Celyes\RalphPhp\Init\InitializationMode;
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
 * Reports every path it is responsible for, created, kept or replaced, because
 * a user who runs this in an already-initialised project needs to see what
 * happened to their own files — and, when they asked for them to be replaced,
 * where the previous content went. The ignore rules are listed too: they are
 * lines appended to a file the project already owned.
 */
final class InitCommand implements CommandInterface
{
    /**
     * Options this command understands, beyond the global ones.
     *
     * @var list<string>
     */
    private const array OPTIONS = ['f', 'force'];

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
            'Usage: ralph init [--force]',
            '',
            'Creates, in the current directory:',
            '',
            $this->helpRow(Defaults::CONFIG_FILE, 'every setting, at its default value'),
            $this->helpRow(Defaults::TASKS_FILE, 'the task checklist, with instructions and no tasks'),
            $this->helpRow(Defaults::CONTEXT_FILE, 'project instructions passed to the agent'),
            $this->helpRow(Defaults::RUNTIME_DIRECTORY . '/', 'state, logs, memory and task worktrees'),
            $this->helpRow(IgnoreFileWriter::FILE, 'rules for the two above, appended if missing'),
            '',
            'Files that already exist are kept as they are, and the configured task and',
            'context paths of an existing configuration are honoured. To regenerate a single',
            'file, delete it and run "ralph init" again.',
            '',
            'Options:',
            $this->helpRow('-f, --force', 'reset all three files to the templates'),
            '',
            'With --force, each file whose content differs from the template is copied to',
            '"<name>.bak" before being written, so the previous content stays recoverable.',
            'Because the configuration is reset too, the checklist and context file are',
            'written at the default paths above; files a previous configuration pointed',
            'somewhere else are left alone and reported as no longer referenced.',
            '',
            'The ignore file is never rewritten, in either mode: missing rules are appended',
            'and nothing already in it is touched. A rule negated with "!" counts as decided,',
            'so tracking the checklist yourself is a choice "ralph init" will not undo.',
            '',
            'Nothing is read from or written to the Git repository itself: no command here',
            'runs git.',
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

        $mode = $input->hasOption('f', 'force')
            ? InitializationMode::Overwrite
            : InitializationMode::Preserve;

        $this->report($this->initializer->initialize($directory, $mode), $output);

        return ExitCode::SUCCESS;
    }

    /**
     * Fail on anything this command does not accept, instead of ignoring it.
     */
    private function rejectUnsupportedInput(Input $input): void
    {
        $unrecognised = array_values(array_diff(
            $input->optionNames(),
            [...self::OPTIONS, 'h', 'help', 'v', 'verbose'],
        ));

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

        $ignore = $report->ignoreFile();
        $output->line(sprintf('  %-8s %s', $ignore->outcome()->label(), $ignore->relativePath()));
        $output->line();

        $this->reportIgnoreFile($report, $output);
        $this->reportReplaced($report, $output);
        $this->reportKept($report, $output);
        $this->reportUnreferenced($report, $output);

        $output->line('Next steps:');

        foreach ($this->nextSteps($report) as $index => $step) {
            foreach ($step as $line => $text) {
                $output->line($line === 0
                    ? sprintf('  %d. %s', $index + 1, $text)
                    : '     ' . $text);
            }
        }
    }

    /**
     * What the user has to do, in the order they have to do it.
     *
     * The paths come from the report rather than from the defaults: a project
     * that configures its checklist elsewhere must be told where its own
     * checklist is.
     *
     * The commit step is here because leaving it out made the first `ralph run`
     * of a new project stop: initialisation writes ignore rules into a file the
     * user owns, a run refuses to start while the checkout holds uncommitted
     * changes that are not Ralph PHP's own, and the rules it had just written
     * were exactly such a change. The refusal is correct — a run must not
     * commit or stash a user's file — so the remedy belongs where the file was
     * written, before the reader meets a refusal caused by the command that
     * printed these steps. It appears only when something was in fact written:
     * the files under `.ralph/` are the package's own, and an untracked one is
     * never what stops a run.
     *
     * @return list<list<string>> steps, each one or more lines
     */
    private function nextSteps(InitializationReport $report): array
    {
        $steps = [
            [sprintf('Describe the project in %s.', $report->context()->label())],
            [sprintf(
                'Write the work you want done in %s, one task per checklist item.',
                $report->tasks()->label(),
            )],
            [
                sprintf(
                    'Check the "checks", "git" and "agent" settings in %s: a task is',
                    $report->configuration()->label(),
                ),
                'completed only once its verification commands pass.',
            ],
        ];

        if ($report->ignoreFile()->outcome()->wrote()) {
            $steps[] = [
                sprintf('Commit %s: a run will not start while the checkout', $report->ignoreFile()->relativePath()),
                'holds uncommitted changes it did not write, and these rules are one.',
            ];
        }

        $steps[] = ['Run "ralph preflight" to check this machine can do the work.'];
        $steps[] = ['Run "ralph run".'];

        return $steps;
    }

    /**
     * Name the ignore rules, because they are lines added to a file the user
     * owns — and say what to do when they could not be added at all.
     *
     * The rules are listed rather than summarised: whether the local task
     * checklist is tracked is the user's decision to make (PROJECT.md section
     * 4), and they cannot make it without seeing the rule.
     */
    private function reportIgnoreFile(InitializationReport $report, Output $output): void
    {
        $ignore = $report->ignoreFile();

        if ($ignore->outcome() === IgnoreFileOutcome::Skipped) {
            $output->diagnosticLine(sprintf(
                'The ignore rules were not written to %s: %s.',
                $ignore->path(),
                (string) $ignore->reason(),
            ));
            $output->diagnosticLine('Add these rules yourself to keep generated files out of commits:');

            foreach ($ignore->rules() as $rule) {
                $output->diagnosticLine('  ' . $rule);
            }

            $output->diagnosticLine();

            return;
        }

        if (!$ignore->wrote()) {
            $output->line(sprintf(
                '%s already decides every rule Ralph PHP needs, so it was left alone.',
                $ignore->relativePath(),
            ));
            $output->line();

            return;
        }

        $output->line(sprintf(
            'These rules %s %s, so the runtime data and the local checklist stay out of commits:',
            $ignore->outcome() === IgnoreFileOutcome::Created ? 'were written to' : 'were added to',
            $ignore->relativePath(),
        ));

        foreach ($ignore->rules() as $rule) {
            $output->line('  ' . $rule);
        }

        $output->line(
            'To track one of these yourself, negate its rule with "!": a deleted line is'
            . ' added again on the next "ralph init".',
        );
        $output->line();
    }

    /**
     * Name every backup, so recovering from a `--force` the user regrets needs
     * no guessing about where their content went.
     */
    private function reportReplaced(InitializationReport $report, Output $output): void
    {
        $replaced = $report->replaced();

        if ($replaced === []) {
            return;
        }

        $output->line(sprintf(
            '%s %s replaced with the %s. The previous content is in:',
            count($replaced) === 1 ? 'One file' : (string) count($replaced) . ' files',
            count($replaced) === 1 ? 'was' : 'were',
            count($replaced) === 1 ? 'template' : 'templates',
        ));

        foreach ($replaced as $path) {
            $output->line(sprintf('  %s', (string) $path->backupPath()));
        }

        $output->line();
    }

    /**
     * Only kept *files* are called out: they hold content the user wrote, and
     * the point of the sentence is that initialisation did not touch it. A
     * directory that already existed is not news.
     *
     * A kept file means something different in each mode, so it is worded
     * differently: preserving leaves the user's own content in place, whereas
     * overwriting keeps only a file that was already byte for byte the
     * template — which is why it has no backup to point at.
     */
    private function reportKept(InitializationReport $report, Output $output): void
    {
        $keptFiles = array_values(array_filter(
            $report->kept(),
            static fn (InitializedPath $path): bool => !$path->isDirectory(),
        ));

        if ($keptFiles === []) {
            if ($report->created() === [] && $report->replaced() === []) {
                $output->line('Everything was already in place, so nothing was written.');
                $output->line();
            }

            return;
        }

        $names = $this->list(array_map(
            static fn (InitializedPath $path): string => $path->label(),
            $keptFiles,
        ));
        $plural = count($keptFiles) !== 1;

        if ($report->mode()->overwrites()) {
            $output->line(sprintf(
                '%s already %s the %s, so %s not backed up.',
                $names,
                $plural ? 'match' : 'matches',
                $plural ? 'templates' : 'template',
                $plural ? 'they were' : 'it was',
            ));
            $output->line();

            return;
        }

        $output->line(sprintf(
            'Your existing %s %s left as %s.',
            $names,
            $plural ? 'were' : 'was',
            $plural ? 'they are' : 'it is',
        ));
        $output->line(
            'Delete a file and run "ralph init" again, or run "ralph init --force",'
            . ' to write it from the template.',
        );
        $output->line();
    }

    /**
     * Files the replaced configuration pointed at and the new one does not.
     *
     * Left on disk — `init` deletes nothing — but a user whose task list has
     * just stopped being read has to hear about it from the command that did
     * it, not from a run that reports no pending work.
     */
    private function reportUnreferenced(InitializationReport $report, Output $output): void
    {
        $unreferenced = $report->unreferencedPaths();

        if ($unreferenced === []) {
            return;
        }

        $output->line(sprintf(
            'The configuration that was replaced pointed at %s the default one does not:',
            count($unreferenced) === 1 ? 'a file' : 'files',
        ));

        foreach ($unreferenced as $path) {
            $output->line(sprintf('  %s', $path));
        }

        $output->line(sprintf(
            'Nothing was deleted, but Ralph PHP no longer reads %s.',
            count($unreferenced) === 1 ? 'it' : 'them',
        ));
        $output->line();
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
