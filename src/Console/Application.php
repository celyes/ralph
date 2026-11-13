<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\Console\Command\HelpCommand;
use Celyes\RalphPhp\Console\Command\InitCommand;
use Celyes\RalphPhp\Console\Command\LogsCommand;
use Celyes\RalphPhp\Console\Command\PreflightCommand;
use Celyes\RalphPhp\Console\Command\ResumeCommand;
use Celyes\RalphPhp\Console\Command\RetryCommand;
use Celyes\RalphPhp\Console\Command\RunCommand;
use Celyes\RalphPhp\Console\Command\StatusCommand;
use Celyes\RalphPhp\Console\Command\VersionCommand;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Exception\UsageException;
use ErrorException;
use Throwable;

/**
 * The `ralph` command-line application.
 *
 * Owns argument parsing, command dispatch, and error reporting. It never calls
 * exit() or reads superglobals: `bin/ralph` passes argv in and uses the returned
 * status, and tests drive it with their own argv and streams.
 *
 * Every failure leaves through one place, {@see self::report()}, so that a
 * recognised failure is a readable message with an accurate exit status and an
 * unexpected one is clearly labelled a bug instead of a raw PHP trace.
 */
final class Application
{
    private const string VERBOSE_SHORT = 'v';
    private const string VERBOSE_LONG = 'verbose';

    /**
     * The global options every command tolerates without declaring them.
     *
     * They are answered here rather than by the command, so a command's own
     * option list says what the command does and nothing about how its output
     * is decorated.
     *
     * @var list<string>
     */
    public const array GLOBAL_OPTIONS = [
        'h',
        'help',
        self::VERBOSE_SHORT,
        self::VERBOSE_LONG,
        'color',
        'colour',
        'no-color',
        'no-colour',
    ];

    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly HelpCommand $help,
        private readonly Output $output,
    ) {
    }

    /**
     * Build the application with the commands this build exposes.
     */
    public static function create(?Output $output = null): self
    {
        $registry = new CommandRegistry();
        $help = new HelpCommand($registry);

        // Registered in the order `ralph help` lists them, which is the order
        // a user meets them in: set the project up, check the environment, run
        // the loop, then read and continue what it did.
        $registry->add(new InitCommand());
        $registry->add(new PreflightCommand());
        $registry->add(new RunCommand());
        $registry->add(new StatusCommand());
        $registry->add(new LogsCommand());
        $registry->add(new ResumeCommand());
        $registry->add(new RetryCommand());

        $registry->add($help);
        $registry->add(new VersionCommand());

        return new self($registry, $help, $output ?? Output::toStandardStreams());
    }

    /**
     * Run the application and return the process exit status.
     *
     * @param list<string> $argv the full argv, including the script name
     */
    public function run(array $argv): int
    {
        $tokens = array_slice($argv, 1);
        $verbose = $this->isVerbose($tokens);

        // Before anything is dispatched, and before anything can fail: the
        // output that would report a failure is the output this decides.
        $colour = ColourChoice::requestedIn($tokens);

        if ($colour !== null) {
            $this->output->useColour($colour);
        }

        $restoreErrorHandler = $this->convertErrorsToExceptions();

        try {
            return $this->dispatch($tokens);
        } catch (RalphException $exception) {
            return $this->report($exception, $exception->exitCode(), $verbose);
        } catch (Throwable $exception) {
            return $this->report($exception, ExitCode::SOFTWARE, $verbose);
        } finally {
            $restoreErrorHandler();
        }
    }

    /**
     * The commands exposed by this build.
     */
    public function registry(): CommandRegistry
    {
        return $this->registry;
    }

    /**
     * Resolve the command line and execute it.
     *
     * @param list<string> $tokens argv without the script name
     */
    private function dispatch(array $tokens): int
    {
        $output = $this->output;
        $name = Input::commandName($tokens);

        if ($name === null) {
            return $this->dispatchWithoutCommand($tokens, $output);
        }

        $command = $this->registry->get($name);
        if ($command === null) {
            throw $this->unknownCommand($name);
        }

        $input = Input::parse($tokens, $command->valueOptions());

        if ($input->hasOption('h', 'help')) {
            $output->lines($output->style()->helpText($command->help()));

            return ExitCode::SUCCESS;
        }

        return $command->run($input, $output);
    }

    /**
     * Handle `ralph`, `ralph --help` and `ralph --version`, where there is no
     * command to resolve options against.
     *
     * @param list<string> $tokens
     */
    private function dispatchWithoutCommand(array $tokens, Output $output): int
    {
        $input = Input::parse($tokens);

        if ($input->hasOption('V', 'version')) {
            $version = $this->registry->get('version') ?? new VersionCommand();

            return $version->run($input, $output);
        }

        $unrecognised = array_values(array_diff($input->optionNames(), self::GLOBAL_OPTIONS));

        if ($unrecognised !== []) {
            throw new UsageException(
                sprintf(
                    'Unknown %s %s.',
                    count($unrecognised) === 1 ? 'option' : 'options',
                    implode(', ', array_map(
                        static fn (string $option): string => '"' . (strlen($option) === 1 ? '-' : '--') . $option . '"',
                        $unrecognised,
                    )),
                ),
                ['Run "ralph help" to list the available commands and options.'],
            );
        }

        $arguments = $input->arguments();
        if ($arguments !== []) {
            throw $this->unknownCommand($arguments[0]);
        }

        $output->lines($this->help->overview($output->style()));

        return ExitCode::SUCCESS;
    }

    private function unknownCommand(string $name): UsageException
    {
        $hints = [];

        $suggestions = $this->registry->suggestionsFor($name);
        if ($suggestions !== []) {
            $hints[] = sprintf(
                'Did you mean %s?',
                implode(' or ', array_map(static fn (string $s): string => '"' . $s . '"', $suggestions)),
            );
        }

        $hints[] = 'Run "ralph help" to list the available commands.';

        return new UsageException(sprintf('Unknown command "%s".', $name), $hints);
    }

    /**
     * Print a failure and return its exit status.
     */
    private function report(Throwable $exception, int $exitCode, bool $verbose): int
    {
        $output = $this->output;
        $style = $output->diagnosticStyle();
        $recognised = $exception instanceof RalphException;

        // The whole line, not just its prefix: this is the one thing the user
        // has to read, and it is being read off the bottom of however much
        // output came before it.
        $output->diagnosticLine($style->alert($recognised
            ? 'ralph: ' . $exception->getMessage()
            : sprintf('ralph: unexpected error: %s: %s', $exception::class, $exception->getMessage())));

        if ($recognised) {
            foreach ($exception->hints() as $hint) {
                $output->diagnosticLine('  ' . $style->muted($hint));
            }
        } else {
            $output->diagnosticLine(
                '  ' . $style->warn('This is a defect in Ralph PHP; please report it with the output below.'),
            );
        }

        if (!$verbose) {
            if (!$recognised) {
                $output->diagnosticLine('  ' . $style->muted('Re-run with --verbose for a stack trace.'));
            }

            return $exitCode;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $output->diagnosticLine();
            $output->diagnosticLine($style->heading(sprintf(
                '%s%s: %s',
                $current === $exception ? '' : 'Caused by ',
                $current::class,
                $current->getMessage(),
            )));
            $output->diagnosticLine($style->muted(sprintf('  at %s:%d', $current->getFile(), $current->getLine())));
            $output->diagnosticLine($style->muted($current->getTraceAsString()));
        }

        return $exitCode;
    }

    /**
     * Whether the raw tokens ask for verbose error output.
     *
     * Checked against raw tokens rather than parsed input because parsing can
     * itself fail, and that failure should still honour the flag.
     *
     * @param list<string> $tokens
     */
    private function isVerbose(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--') {
                return false;
            }

            if ($token === '--' . self::VERBOSE_LONG) {
                return true;
            }

            if (preg_match('/^-[A-Za-z]+$/', $token) === 1 && str_contains($token, self::VERBOSE_SHORT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn PHP warnings and notices into exceptions for the duration of a run,
     * so that they are reported through {@see self::report()} instead of being
     * printed in the middle of command output.
     *
     * @return callable(): void restores the previous handler
     */
    private function convertErrorsToExceptions(): callable
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // Deprecations are reported by PHP itself: a deprecation raised deep
            // in a dependency is not a reason to abandon a run.
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        return static function (): void {
            restore_error_handler();
        };
    }
}
