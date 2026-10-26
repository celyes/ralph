<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Application;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The CLI starts.
 *
 * This is the suite's smoke test: it proves that the real binary boots on this
 * runtime and that the application can be driven in-process, which is the
 * precondition for every later test. It deliberately asserts on start-up only
 * — command behaviour belongs with the commands' own tests.
 */
#[CoversClass(Application::class)]
final class CliStartsTest extends TestCase
{
    #[Test]
    public function the_binary_runs_and_reports_its_version(): void
    {
        $result = Cli::run(['--version']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());
        self::assertSame(
            sprintf('%s %s (%s)', Ralph::NAME, Ralph::VERSION, Ralph::PACKAGE),
            $result->standardOutputLines()[0] ?? '',
            $result->describe(),
        );
    }

    #[Test]
    public function the_binary_runs_without_arguments_and_lists_its_commands(): void
    {
        $result = Cli::run();

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());
        self::assertStringContainsString('ralph <command> [options]', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Commands:', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_application_can_be_driven_in_process(): void
    {
        $output = new CapturingOutput();

        $status = Application::create($output->output())->run(['ralph', 'version']);

        self::assertSame(ExitCode::SUCCESS, $status);
        self::assertSame('', $output->errorOutput());
        self::assertStringContainsString(Ralph::VERSION, $output->standardOutput());
    }

    #[Test]
    public function an_unknown_command_fails_without_writing_to_standard_output(): void
    {
        $output = new CapturingOutput();

        $status = Application::create($output->output())->run(['ralph', 'frobnicate']);

        self::assertSame(ExitCode::USAGE, $status);
        self::assertSame('', $output->standardOutput());
        self::assertStringContainsString('Unknown command "frobnicate".', $output->errorOutput());
    }
}
