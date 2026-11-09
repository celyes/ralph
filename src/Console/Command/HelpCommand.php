<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Console\CommandRegistry;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
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
            $output->lines($this->overview());

            return ExitCode::SUCCESS;
        }

        $command = $this->registry->get($requested);
        if ($command === null) {
            throw new UsageException(
                sprintf('Unknown command "%s".', $requested),
                ['Run "ralph help" to list the available commands.'],
            );
        }

        $output->lines($command->help());

        return ExitCode::SUCCESS;
    }

    /**
     * Help for the application as a whole.
     *
     * @return list<string>
     */
    public function overview(): array
    {
        $lines = [
            sprintf('%s %s', Ralph::NAME, Ralph::VERSION),
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

        return [
            ...$lines,
            '',
            'Global options:',
            '  -h, --help     Display this help, or help for the given command.',
            '  -V, --version  Display the version and runtime information.',
            '  -v, --verbose  Include stack traces in error output.',
            '',
            'Run "ralph help <command>" for details about one command.',
        ];
    }
}
