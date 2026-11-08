<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\Console\Command\HelpCommand;
use Celyes\RalphPhp\Console\Command\InitCommand;
use Celyes\RalphPhp\Console\Command\PlannedCommand;
use Celyes\RalphPhp\Console\Command\PreflightCommand;
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

    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly HelpCommand $help,
        private readonly Output $output,
    ) {
    }

    /**
     * Build the application with the commands this build exposes.
     *
     * Commands specified by the product definition but not implemented yet are
     * registered as {@see PlannedCommand}s so the documented interface is
     * discoverable and invoking one says so plainly.
     */
    public static function create(?Output $output = null): self
    {
        $registry = new CommandRegistry();
        $help = new HelpCommand($registry);

        $registry->add(new InitCommand());
        $registry->add(new PreflightCommand());

        foreach (self::plannedCommands() as $planned) {
            $registry->add($planned);
        }

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
            $output->lines($command->help());

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

        $unrecognised = array_values(array_diff(
            $input->optionNames(),
            ['h', 'help', self::VERBOSE_SHORT, self::VERBOSE_LONG],
        ));

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

        $output->lines($this->help->overview());

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
        $recognised = $exception instanceof RalphException;

        $output->diagnosticLine($recognised
            ? 'ralph: ' . $exception->getMessage()
            : sprintf('ralph: unexpected error: %s: %s', $exception::class, $exception->getMessage()));

        if ($recognised) {
            foreach ($exception->hints() as $hint) {
                $output->diagnosticLine('  ' . $hint);
            }
        } else {
            $output->diagnosticLine('  This is a defect in Ralph PHP; please report it with the output below.');
        }

        if (!$verbose) {
            if (!$recognised) {
                $output->diagnosticLine('  Re-run with --verbose for a stack trace.');
            }

            return $exitCode;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $output->diagnosticLine();
            $output->diagnosticLine(sprintf(
                '%s%s: %s',
                $current === $exception ? '' : 'Caused by ',
                $current::class,
                $current->getMessage(),
            ));
            $output->diagnosticLine(sprintf('  at %s:%d', $current->getFile(), $current->getLine()));
            $output->diagnosticLine($current->getTraceAsString());
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

    /**
     * Commands defined by the product specification whose implementations are
     * still ahead on the delegation checklist.
     *
     * @return list<PlannedCommand>
     */
    private static function plannedCommands(): array
    {
        return [
            new PlannedCommand(
                'run',
                'Process pending tasks within the configured limits.',
                [
                    'Usage: ralph run [--agent NAME]',
                    '',
                    'Selects the next pending task from the checklist, prepares its workspace and',
                    'context, invokes the configured coding agent, runs the verification commands,',
                    'commits verified changes, optionally pushes, and checks the task off.',
                    '',
                    'Options:',
                    '  --agent NAME  Use this agent for the run instead of the configured default.',
                ],
                'PROJECT.md sections G and I',
                ['agent'],
            ),
            new PlannedCommand(
                'status',
                'Display tasks, IDs, state, attempts, branches, and commit references.',
                [
                    'Usage: ralph status [--json]',
                    '',
                    'Shows the selected agent, each task\'s phase and state, completed and remaining',
                    'counts, attempt counts, branch names, and check results.',
                    '',
                    'Options:',
                    '  --json  Emit machine-readable output with a schema version.',
                ],
                'PROJECT.md section I',
            ),
            new PlannedCommand(
                'logs',
                'Display a task\'s execution and verification logs.',
                [
                    'Usage: ralph logs TASK-ID',
                    '',
                    'Prints the worker output and verification output recorded for the given task.',
                ],
                'PROJECT.md section I',
            ),
            new PlannedCommand(
                'resume',
                'Reconcile an interrupted run and continue from its recorded phase.',
                [
                    'Usage: ralph resume [--agent NAME]',
                    '',
                    'Reconciles worktree, branch and recorded commit state after an interruption and',
                    'continues with the agent and settings recorded for the original run. An edit to',
                    'the agent settings in .ralph/config.json since that run was opened is reported',
                    'and not applied: the task is finished as it was started.',
                    '',
                    'Options:',
                    '  --agent NAME  Confirm the agent the interrupted run recorded. A resume cannot',
                    '                change agents, so naming a different one is refused.',
                    '',
                    'To use a different agent, reconcile the interrupted work first — "ralph status"',
                    'shows what is outstanding — and then begin a new run with it:',
                    '',
                    '  ralph run --agent NAME',
                    '',
                    'A new run starts with the configured limits whole. Tasks earlier runs completed',
                    'stay completed and are not taken on again, and their commits and attempt history',
                    'stay attributed to the agent that earned them.',
                ],
                'PROJECT.md section H',
                ['agent'],
            ),
            new PlannedCommand(
                'retry',
                'Retry a failed or blocked task while preserving its history.',
                [
                    'Usage: ralph retry TASK-ID',
                    '',
                    'Starts a new attempt for a failed or blocked task, keeping its earlier attempts,',
                    'logs and blocker details.',
                ],
                'PROJECT.md section H',
            ),
        ];
    }
}
