<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;

/**
 * One `ralph` subcommand.
 *
 * Implementations receive an already parsed command line and return the process
 * exit status, or throw a {@see \Celyes\RalphPhp\Exception\RalphException} with
 * a readable message. The application catches and reports those.
 */
interface CommandInterface
{
    /**
     * The name used on the command line.
     */
    public function name(): string;

    /**
     * One-line description shown in the command list.
     */
    public function summary(): string;

    /**
     * Detailed help for this command: usage line, arguments and options.
     *
     * @return list<string>
     */
    public function help(): array;

    /**
     * Long option names that take a separate value (`--agent codex`).
     *
     * @return list<string>
     */
    public function valueOptions(): array;

    /**
     * Execute the command.
     */
    public function run(Input $input, Output $output): int;
}
