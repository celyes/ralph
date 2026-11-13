<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Console\CommandRegistry;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Ralph;

/**
 * Prints the command list, or the detailed help of one command.
 *
 * Also the output of `ralph` with no arguments and of `ralph --help`, and of
 * `ralph <command> --help` for the detailed form.
 */
final class HelpCommand implements CommandInterface
{
    public function __construct(private readonly CommandRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'help';
    }

    public function summary(): string
    {
        return 'Display help for Ralph PHP or for one command.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph help [COMMAND]',
            '',
            'Without COMMAND, lists every command with a one-line description.',
            'With COMMAND, prints that command\'s usage, arguments and options.',
        ];
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
        $requested = $input->argument(0);

        if ($requested === null) {
            $output->lines($this->overview($output->style()));

            return ExitCode::SUCCESS;
        }

        $command = $this->registry->get($requested);
        if ($command === null) {
            throw new UsageException(
                sprintf('Unknown command "%s".', $requested),
                ['Run "ralph help" to list the available commands.'],
            );
        }

        $output->lines($output->style()->helpText($command->help()));

        return ExitCode::SUCCESS;
    }

    /**
     * Help for the application as a whole.
     *
     * The styling is a parameter rather than something read off an output,
     * because this is also what {@see \Celyes\RalphPhp\Console\Application}
     * prints for `ralph` with no arguments, and the two callers write to
     * different streams. Nothing given is plain text, which is what the tests
     * and a redirected `ralph help` get.
     *
     * @return list<string>
     */
    public function overview(?Style $style = null): array
    {
        $style ??= Style::plain();

        $lines = [
            $style->heading(sprintf('%s %s', Ralph::NAME, Ralph::VERSION)),
            'A Ralph-style development loop: reads tasks from a Markdown checklist,',
            'delegates them to a coding agent, verifies the result, and commits the work.',
            '',
            'Usage:',
            '  ralph <command> [options]',
            '',
            'Commands:',
        ];

        $width = 0;
        foreach ($this->registry->names() as $name) {
            $width = max($width, strlen($name));
        }

        foreach ($this->registry->all() as $command) {
            $lines[] = sprintf('  %-' . max($width, 1) . 's  %s', $command->name(), $command->summary());
        }

        // The product line above is styled on its own, so the pass is applied
        // to everything after it: the headings, the command names, the option
        // names and the exit statuses are all shapes it recognises.
        return [
            $lines[0],
            ...$style->helpText([
                ...array_slice($lines, 1),
                '',
                'Global options:',
                '  -h, --help      Display this help, or help for the given command.',
                '  -V, --version   Display the version and runtime information.',
                '  -v, --verbose   Include stack traces in error output.',
                '      --color     Colour the output even when it is not a terminal.',
                '      --no-color  Never colour the output; "NO_COLOR" in the environment',
                '                  does the same for every invocation.',
                '',
                'Exit codes:',
                ...$this->exitCodes(),
                '',
                'Run "ralph help <command>" for details about one command.',
            ]),
        ];
    }

    /**
     * The documented exit statuses, every one of them.
     *
     * Listed in `ralph help` rather than only in the README because this is
     * what somebody wiring `ralph` into a script has to hand, and because
     * generating the lines from {@see ExitCode::documented()} means the help
     * cannot drift from the constants the commands return (PROJECT.md section
     * I, item 5). Each command's own help says which of these it can produce.
     *
     * @return list<string>
     */
    private function exitCodes(): array
    {
        $lines = [];

        foreach (ExitCode::documented() as $code => $meaning) {
            $lines[] = sprintf('  %-3d  %s', $code, $meaning);
        }

        return $lines;
    }
}
