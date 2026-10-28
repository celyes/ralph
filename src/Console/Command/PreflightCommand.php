<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Preflight\CheckOutcome;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\Preflight;
use Celyes\RalphPhp\Preflight\PreflightReport;

/**
 * `ralph preflight`: report whether the environment can run the loop.
 *
 * `run` and `resume` will perform the same checks before they start work, and
 * stop on a failure. Having them as a command of their own is what makes the
 * answer available *before* committing to a run, and what makes an environment
 * problem diagnosable in one invocation that changes nothing: it is the command
 * to run after installing the package, after setting an agent up, and when a
 * run refuses to start.
 *
 * Every check is reported, not only the failures, because the passing ones are
 * the answer to "which agent, which base commit, which checks would this
 * actually use". Failures are then repeated with their details and hints, so a
 * long report does not bury the part the user has to act on.
 */
final class PreflightCommand implements CommandInterface
{
    /**
     * Options this command understands, beyond the global ones.
     *
     * @var list<string>
     */
    private const array OPTIONS = ['agent', 'verbose', 'v'];

    public function __construct(
        private readonly Preflight $preflight = new Preflight(),
        private readonly ConfigurationLoader $loader = new ConfigurationLoader(),
    ) {
    }

    public function name(): string
    {
        return 'preflight';
    }

    public function summary(): string
    {
        return 'Check that this machine and project can run the loop.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph preflight [--agent NAME]',
            '',
            'Checks, against the current directory and its configuration:',
            '',
            '  php          the PHP version running ralph',
            '  extensions   the PHP extensions the package requires',
            '  git          that git is installed and new enough',
            '  repository   that this directory is the root of a non-bare working tree',
            '  base         that "git.base" names a commit task branches can start from',
            '  identity     that git can determine a commit author and committer',
            '  agent        that the selected agent\'s executable and credentials are present',
            '  checks       that every configured verification command could run',
            '',
            'Options:',
            '  --agent NAME  Check the named agent instead of the project\'s default.',
            '',
            'Nothing is executed and nothing is written: the verification commands and the',
            'agent are resolved, not run, so this is safe to use at any time, including',
            'while a run is interrupted.',
            '',
            'Exits 0 when a run could start, even with warnings, and 78 when a check failed.',
            '"run" and "resume" will perform the same checks before they start work.',
        ];
    }

    /**
     * @return list<string>
     */
    public function valueOptions(): array
    {
        return ['agent'];
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $directory = getcwd();
        if ($directory === false) {
            throw new FilesystemException(
                'Unable to determine the current directory.',
                ['Run "ralph preflight" from the project directory.'],
            );
        }

        // The defaults are used when the project has no configuration file:
        // every check still means something, and a user who has not run
        // "ralph init" yet is exactly who benefits from being told that their
        // agent is not installed.
        $configuration = $this->loader->loadOrDefaults($directory);
        $requested = $input->hasOption('agent') ? (string) $input->option('agent') : null;

        $report = $this->preflight->run($configuration, $requested);

        $this->report($report, $output);

        return $report->isUsable() ? ExitCode::SUCCESS : ExitCode::CONFIGURATION;
    }

    /**
     * Fail on anything this command does not accept, instead of ignoring it.
     */
    private function rejectUnsupportedInput(Input $input): void
    {
        $unrecognised = array_values(array_diff($input->optionNames(), [...self::OPTIONS, 'h', 'help']));

        if ($unrecognised !== []) {
            throw new UsageException(
                sprintf(
                    'Unknown %s %s for "ralph preflight".',
                    count($unrecognised) === 1 ? 'option' : 'options',
                    implode(', ', array_map(
                        static fn (string $option): string => '"' . (strlen($option) === 1 ? '-' : '--') . $option . '"',
                        $unrecognised,
                    )),
                ),
                ['Run "ralph help preflight" to see the supported usage.'],
            );
        }

        if ($input->arguments() !== []) {
            throw new UsageException(
                sprintf('"ralph preflight" takes no arguments, but "%s" was given.', $input->arguments()[0]),
                ['It checks the current directory. Change into the project first.'],
            );
        }
    }

    /**
     * Print the table, then the problems, then the verdict.
     */
    private function report(PreflightReport $report, Output $output): void
    {
        $output->line(sprintf('Preflight checks for %s', $report->projectRoot()));
        $output->line(sprintf(
            '  configuration  %s',
            $report->configurationSource() ?? 'defaults (no configuration file)',
        ));
        $output->line(sprintf('  agent          %s', $report->agent()->describe()));
        $output->line();

        $width = 0;
        foreach ($report->results() as $result) {
            $width = max($width, strlen($result->id()->label()));
        }

        foreach ($report->results() as $result) {
            $output->line(sprintf(
                '  %-4s  %-' . max($width, 1) . 's  %s',
                $result->outcome()->label(),
                $result->id()->label(),
                $result->summary(),
            ));
        }

        $output->line();

        $this->reportProblems($report, $output);
        $this->reportVerdict($report, $output);
    }

    /**
     * Expand the results the user has to act on.
     *
     * Failures first and in full. Warnings follow, with their hints, because a
     * warning means a check could not confirm something — which the user may be
     * able to confirm themselves.
     */
    private function reportProblems(PreflightReport $report, Output $output): void
    {
        foreach ([...$report->failures(), ...$report->warnings()] as $result) {
            // Failures go to the diagnostic stream, like every other reason a
            // command could not do what it was asked; a warning is information
            // about a run that can still start, so it stays with the report.
            $failed = $result->outcome() === CheckOutcome::Failed;
            $write = static function (string $line) use ($output, $failed): void {
                $failed ? $output->diagnosticLine($line) : $output->line($line);
            };

            $write(sprintf(
                '%s: %s',
                $result->outcome() === CheckOutcome::Failed ? 'Failed' : 'Warning',
                $result->summary(),
            ));

            foreach ($this->evidence($result) as $line) {
                $write('  ' . $line);
            }

            $write('');
        }
    }

    /**
     * A problem's details and hints, in reading order.
     *
     * @return list<string>
     */
    private function evidence(CheckResult $result): array
    {
        return [...$result->details(), ...$result->hints()];
    }

    private function reportVerdict(PreflightReport $report, Output $output): void
    {
        $failures = count($report->failures());

        if ($failures === 0) {
            $warnings = count($report->warnings());

            $output->line($warnings === 0
                ? 'Everything the loop needs is in place. Run "ralph run" to start.'
                : sprintf(
                    'A run can start: %d %s, nothing blocking. Run "ralph run" to start.',
                    $warnings,
                    $warnings === 1 ? 'warning' : 'warnings',
                ));

            return;
        }

        $output->diagnosticLine(sprintf(
            '%d %s failed, so a run cannot start. Fix %s and run "ralph preflight" again.',
            $failures,
            $failures === 1 ? 'check' : 'checks',
            $failures === 1 ? 'it' : 'them',
        ));
    }
}
