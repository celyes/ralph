<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\Application;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Preflight\CheckOutcome;
use Celyes\RalphPhp\Preflight\Preflight;
use Celyes\RalphPhp\Preflight\PreflightReport;
use Celyes\RalphPhp\Support\Secrets;

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
    private const array OPTIONS = ['agent'];

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
            '  php            the PHP version running ralph',
            '  extensions     the PHP extensions the package requires',
            '  git            that git is installed and new enough',
            '  repository     that this directory is the root of a non-bare working tree',
            '  base           that "git.base" names a commit task branches can start from',
            '  identity       that git can determine a commit author and committer',
            '  agent          that the selected agent\'s executable and credentials are present',
            '  agent-version  that the installed agent CLI is new enough for its adapter',
            '  checks         that every configured verification command could run',
            '',
            'Options:',
            '  --agent NAME  Check the named agent instead of the project\'s default.',
            '',
            'Nothing is written, and no agent session is started: the verification commands',
            'are resolved rather than run, and the agent is only asked for its version, so',
            'this is safe to use at any time, including while a run is interrupted.',
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

        // A check reports what a command printed when it refused to run, so the
        // project's declared secrets come out of this command's lines too
        // (section I, item 6).
        $output->redact(Secrets::forProject($configuration));

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
        $unrecognised = array_values(array_diff(
            $input->optionNames(),
            [...self::OPTIONS, ...Application::GLOBAL_OPTIONS],
        ));

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
        $style = $output->style();

        $output->line(sprintf(
            '%s %s',
            $style->heading('Preflight checks for'),
            $style->identifier($report->projectRoot()),
        ));
        $output->line(sprintf(
            '  %s  %s',
            $style->label('configuration'),
            $report->configurationSource() ?? 'defaults (no configuration file)',
        ));
        $output->line(sprintf('  %s  %s', $style->label('agent        '), $report->agent()->describe()));
        $output->line();

        $width = 0;
        foreach ($report->results() as $result) {
            $width = max($width, strlen($result->id()->label()));
        }

        foreach ($report->results() as $result) {
            $output->line(sprintf(
                '  %s  %s  %s',
                $this->verdict($result->outcome(), $style),
                $style->column($result->id()->label(), max($width, 1), $style->label(...)),
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

            // The stream decides the styling, because the two halves of this
            // loop go to different ones: a failure is a reason the command
            // could not do what it was asked, and `ralph preflight 2>/dev/null`
            // is a user asking for only the warnings.
            $style = $failed ? $output->diagnosticStyle() : $output->style();

            $write(sprintf(
                '%s %s',
                $failed ? $style->alert('Failed:') : $style->warn('Warning:'),
                $result->summary(),
            ));

            // Details dimmed and hints not: the details are what the check
            // observed, which a reader may already know, and the hints are the
            // thing they have to go and do about it.
            foreach ($result->details() as $line) {
                $write('  ' . $style->muted($line));
            }

            foreach ($result->hints() as $line) {
                $write('  ' . $line);
            }

            $write('');
        }
    }

    /**
     * A check's verdict, in its column and its colour.
     *
     * The four words are four columns wide by construction
     * ({@see CheckOutcome}), so the width is the words' own rather than a
     * measurement; `skip` is dimmed because a skipped check is not a verdict
     * about anything — whatever it depended on has already failed above it.
     */
    private function verdict(CheckOutcome $outcome, Style $style): string
    {
        return $style->column($outcome->label(), 4, match ($outcome) {
            CheckOutcome::Passed => $style->pass(...),
            CheckOutcome::Warning => $style->warn(...),
            CheckOutcome::Failed => $style->fail(...),
            CheckOutcome::Skipped => $style->muted(...),
        });
    }

    private function reportVerdict(PreflightReport $report, Output $output): void
    {
        $failures = count($report->failures());

        if ($failures === 0) {
            $warnings = count($report->warnings());
            $style = $output->style();

            $output->line($warnings === 0
                ? $style->pass('Everything the loop needs is in place. Run "ralph run" to start.')
                : $style->warn(sprintf(
                    'A run can start: %d %s, nothing blocking. Run "ralph run" to start.',
                    $warnings,
                    $warnings === 1 ? 'warning' : 'warnings',
                )));

            return;
        }

        $output->diagnosticLine($output->diagnosticStyle()->alert(sprintf(
            '%d %s failed, so a run cannot start. Fix %s and run "ralph preflight" again.',
            $failures,
            $failures === 1 ? 'check' : 'checks',
            $failures === 1 ? 'it' : 'them',
        )));
    }
}
