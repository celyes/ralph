<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\CommandUnavailableException;

/**
 * A command that the package documents but this build does not implement yet.
 *
 * Registering the planned commands keeps `ralph help` honest in a development
 * build: the documented interface is discoverable, invoking it says plainly that
 * the implementation is pending, and nothing pretends to do work it cannot do.
 * Each is replaced by its real implementation as the corresponding checklist
 * item lands.
 */
final class PlannedCommand implements CommandInterface
{
    /**
     * @param list<string> $help detailed help lines
     * @param list<string> $valueOptions long option names that will take a value
     */
    public function __construct(
        private readonly string $name,
        private readonly string $summary,
        private readonly array $help,
        private readonly string $plannedIn,
        private readonly array $valueOptions = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            ...$this->help,
            '',
            sprintf('Not implemented yet. Planned behaviour is specified in %s.', $this->plannedIn),
        ];
    }

    /**
     * @return list<string>
     */
    public function valueOptions(): array
    {
        return $this->valueOptions;
    }

    public function run(Input $input, Output $output): int
    {
        throw new CommandUnavailableException(
            sprintf('The "%s" command is not implemented in this build.', $this->name),
            [
                sprintf('Its planned behaviour is specified in %s.', $this->plannedIn),
                sprintf('Run "ralph help %s" to see the intended usage.', $this->name),
            ],
        );
    }
}
