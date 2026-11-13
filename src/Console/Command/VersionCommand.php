<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Ralph;

/**
 * Prints the package version together with the runtime it is running on.
 *
 * The runtime lines exist because most support questions about an agent loop
 * start with "which PHP and which binary", and bug reports should carry that
 * without a second round trip.
 */
final class VersionCommand implements CommandInterface
{
    public function name(): string
    {
        return 'version';
    }

    public function summary(): string
    {
        return 'Display the Ralph PHP version and runtime information.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph version',
            '',
            'Prints the package name and version, followed by the PHP version and',
            'binary in use. The same output is produced by "ralph --version".',
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
        $style = $output->style();

        $output->line(sprintf(
            '%s %s %s',
            $style->heading(Ralph::NAME),
            $style->identifier(Ralph::VERSION),
            $style->muted('(' . Ralph::PACKAGE . ')'),
        ));
        $output->line($style->muted(sprintf('PHP %s (%s)', PHP_VERSION, PHP_BINARY)));

        return ExitCode::SUCCESS;
    }
}
