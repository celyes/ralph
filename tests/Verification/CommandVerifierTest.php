<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Verification;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Exception\VerificationException;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\CommandVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Running a task's configured checks.
 *
 * Real subprocesses, like {@see \Celyes\RalphPhp\Tests\Process\ProcessRunnerTest}
 * and for the same reason: what is under test is whether a command's verdict
 * survives the trip from the operating system into the row the runner acts on —
 * its status, its timeout, the end of its output — and a doubled process would
 * assert that this class passes its own values back to itself.
 *
 * The commands are short PHP programs run through {@see PHP_BINARY}, so the
 * suite needs nothing installed that it is not already running under.
 */
#[CoversClass(CommandVerifier::class)]
final class CommandVerifierTest extends TestCase
{
    private const string SECRET = 'sk-ralph-0123456789-never-printed';

    private CommandVerifier $verifier;

    private string $workspace;

    protected function setUp(): void
    {
        $this->verifier = new CommandVerifier();
        $this->workspace = TemporaryDirectory::create('ralph-checks');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->workspace);
    }

    #[Test]
    public function it_runs_every_check_and_reports_each_one_in_order(): void
    {
        $report = $this->verifier->verify(
            [
                $this->check('lint', 'fwrite(STDOUT, "no syntax errors");'),
                $this->check('tests', 'fwrite(STDOUT, "OK (3 tests)");'),
            ],
            $this->workspace,
        );

        self::assertTrue($report->passed(), $report->describe());
        self::assertSame(2, $report->count());
        self::assertSame(['lint', 'tests'], array_map(
            static fn (CheckRun $run): string => $run->name(),
            $report->runs(),
        ));
        self::assertSame('no syntax errors', $report->runs()[0]->output());
        self::assertSame('OK (3 tests)', $report->runs()[1]->output());
    }

    #[Test]
    public function it_captures_the_command_its_exit_code_and_the_timeout_it_was_held_to(): void
    {
        $check = new VerificationCommand('tests', [PHP_BINARY, '-r', 'exit(0);'], 42);

        $run = $this->verifier->verify([$check], $this->workspace)->runs()[0];

        self::assertSame([PHP_BINARY, '-r', 'exit(0);'], $run->command());
        self::assertSame(0, $run->exitCode());
        self::assertSame(42, $run->timeoutSeconds());
        self::assertSame($this->workspace, $run->directory());
        self::assertSame(CheckOutcome::Passed, $run->outcome());
        self::assertNotNull($run->durationMs());
        self::assertGreaterThanOrEqual(0, (int) $run->durationMs());
    }

    #[Test]
    public function it_runs_the_checks_in_the_task_workspace(): void
    {
        file_put_contents($this->workspace . '/marker.txt', 'the agent wrote this');

        $report = $this->verifier->verify(
            [$this->check('where', 'fwrite(STDOUT, file_get_contents("marker.txt"));')],
            $this->workspace,
        );

        self::assertTrue($report->passed(), $report->describe());
        self::assertSame('the agent wrote this', $report->runs()[0]->output());
    }

    #[Test]
    public function it_reports_a_command_that_exited_non_zero_as_a_failure(): void
    {
        $report = $this->verifier->verify(
            [$this->check('tests', 'fwrite(STDOUT, "1) ExampleTest::it_works\nFailed.\n"); exit(2);')],
            $this->workspace,
        );

        self::assertTrue($report->failed());
        $run = $report->runs()[0];
        self::assertSame(CheckOutcome::Failed, $run->outcome());
        self::assertSame(2, $run->exitCode());
        self::assertStringContainsString('Failed.', (string) $run->output());
    }

    #[Test]
    public function it_stops_at_the_first_failure_and_reports_the_rest_as_not_run(): void
    {
        $report = $this->verifier->verify(
            [
                $this->check('lint', 'exit(0);'),
                $this->check('tests', 'fwrite(STDERR, "boom"); exit(1);'),
                $this->check('types', 'file_put_contents("types-ran.txt", "x");'),
            ],
            $this->workspace,
        );

        self::assertTrue($report->failed());
        self::assertSame('tests', $report->firstFailure()?->name());
        self::assertSame(['types'], array_map(
            static fn (CheckRun $run): string => $run->name(),
            $report->notRun(),
        ));
        self::assertSame(1, $report->passedCount());

        // Not run means not started, not "run and ignored": the third check
        // would have left a file behind.
        self::assertFileDoesNotExist($this->workspace . '/types-ran.txt');

        $skipped = $report->notRun()[0];
        self::assertNull($skipped->exitCode());
        self::assertNull($skipped->output());
        self::assertFalse($skipped->ran());
    }

    #[Test]
    public function it_reports_a_command_killed_at_its_timeout_apart_from_a_failing_one(): void
    {
        $check = new VerificationCommand(
            'tests',
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "starting the suite\n"); sleep(30);'],
            1,
        );

        $run = $this->verifier->verify([$check], $this->workspace)->runs()[0];

        self::assertSame(CheckOutcome::TimedOut, $run->outcome());
        self::assertTrue($run->isFailure());
        self::assertNull($run->exitCode(), 'A killed command never reported a status.');
        self::assertStringContainsString(
            'starting the suite',
            (string) $run->output(),
            'What it printed before it was stopped is usually the explanation.',
        );
        self::assertStringContainsString('timed out', $run->summary());
    }

    #[Test]
    public function it_keeps_both_streams_in_the_excerpt(): void
    {
        $run = $this->verifier->verify(
            [$this->check('tests', 'fwrite(STDOUT, "summary line"); fwrite(STDERR, "PHP Fatal error");')],
            $this->workspace,
        )->runs()[0];

        self::assertStringContainsString('summary line', (string) $run->output());
        self::assertStringContainsString('PHP Fatal error', (string) $run->output());
    }

    #[Test]
    public function it_keeps_the_end_of_output_a_test_suite_buried_in_noise(): void
    {
        $run = $this->verifier->verify(
            [$this->check(
                'tests',
                'fwrite(STDOUT, str_repeat("passing line\n", 1000)); fwrite(STDOUT, "THE ASSERTION THAT FAILED"); exit(1);',
            )],
            $this->workspace,
        )->runs()[0];

        $output = (string) $run->output();

        self::assertLessThanOrEqual(CheckRun::OUTPUT_CHARACTERS + 1, mb_strlen($output));
        self::assertStringEndsWith('THE ASSERTION THAT FAILED', $output);
    }

    #[Test]
    public function it_streams_each_check_to_the_sink_while_it_runs(): void
    {
        $sink = new RecordingSink();

        $this->verifier->verify(
            [
                $this->check('lint', 'fwrite(STDOUT, "linting"); exit(0);'),
                $this->check('tests', 'fwrite(STDERR, "it broke"); exit(1);'),
                $this->check('types', 'exit(0);'),
            ],
            $this->workspace,
            $sink,
        );

        self::assertSame('linting', $sink->textOf(OutputStream::Standard));
        self::assertSame('it broke', $sink->textOf(OutputStream::Error));

        $notes = implode("\n", $sink->notes);
        self::assertStringContainsString('check lint: ' . PHP_BINARY, $notes);
        self::assertStringContainsString('timeout 600s', $notes);
        self::assertStringContainsString('check lint passed', $notes);
        self::assertStringContainsString('check tests failed (exit 1)', $notes);
        self::assertStringContainsString('check types: not run, because an earlier check failed', $notes);

        // The sink belongs to the attempt, which is still going: the agent's
        // output and the checks' output are one file in arrival order.
        self::assertSame(0, $sink->closes);
    }

    #[Test]
    public function it_timestamps_each_check_from_the_clock(): void
    {
        $clock = FrozenClock::at('2026-11-06T09:30:00+00:00');
        $verifier = new CommandVerifier(clock: $clock);

        $run = $verifier->verify([$this->check('tests', 'exit(0);')], $this->workspace)->runs()[0];

        self::assertSame('2026-11-06T09:30:00+00:00', $run->startedAt()?->value());
        self::assertSame('2026-11-06T09:30:00+00:00', $run->finishedAt()?->value());
    }

    #[Test]
    public function it_hands_the_failing_output_to_the_next_attempts_evidence(): void
    {
        $report = $this->verifier->verify(
            [
                $this->check('tests', 'fwrite(STDOUT, "Failed asserting that true is false."); exit(1);'),
                $this->check('types', 'exit(0);'),
            ],
            $this->workspace,
        );

        $evidence = $report->evidence();

        self::assertFalse($evidence->isEmpty());
        self::assertStringContainsString('Failed asserting that true is false.', $evidence->toMarkdown());
        self::assertStringContainsString('types', $evidence->toMarkdown());
    }

    #[Test]
    public function it_refuses_a_check_whose_program_is_not_installed(): void
    {
        $check = new VerificationCommand('tests', ['ralph-definitely-not-installed', '--all']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('ralph-definitely-not-installed');

        $this->verifier->verify([$check], $this->workspace);
    }

    #[Test]
    public function it_refuses_a_missing_program_before_running_the_checks_behind_it(): void
    {
        try {
            $this->verifier->verify(
                [
                    new VerificationCommand('tests', ['ralph-definitely-not-installed']),
                    $this->check('types', 'file_put_contents("types-ran.txt", "x");'),
                ],
                $this->workspace,
            );

            self::fail('A check that cannot run is a setup failure, not a verdict.');
        } catch (VerificationException $refusal) {
            self::assertStringContainsString('could not be run', $refusal->getMessage());
            self::assertStringContainsString(
                'Nothing was verified, so the task was not judged on this.',
                implode("\n", $refusal->hints()),
            );
            self::assertFileDoesNotExist($this->workspace . '/types-ran.txt');
        }
    }

    #[Test]
    public function it_refuses_a_relative_program_that_the_workspace_does_not_have(): void
    {
        $check = new VerificationCommand('tests', ['vendor/bin/phpunit']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('is not an executable file in the workspace');

        $this->verifier->verify([$check], $this->workspace);
    }

    #[Test]
    public function it_runs_a_program_the_workspace_provides_itself(): void
    {
        mkdir($this->workspace . '/vendor/bin', 0o700, true);
        file_put_contents(
            $this->workspace . '/vendor/bin/phpunit',
            sprintf("#!%s\n<?php fwrite(STDOUT, \"OK (1 test)\");\n", PHP_BINARY),
        );
        chmod($this->workspace . '/vendor/bin/phpunit', 0o700);

        $report = $this->verifier->verify(
            [new VerificationCommand('tests', ['vendor/bin/phpunit'])],
            $this->workspace,
        );

        self::assertTrue($report->passed(), $report->describe());
        self::assertSame('OK (1 test)', $report->runs()[0]->output());
    }

    #[Test]
    public function it_refuses_when_there_are_no_checks_to_run(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('no verification commands');

        $this->verifier->verify([], $this->workspace);
    }

    #[Test]
    public function it_refuses_when_the_workspace_is_not_there(): void
    {
        $missing = $this->workspace . '/never-created';

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('it is not a directory');

        $this->verifier->verify([$this->check('tests', 'exit(0);')], $missing);
    }

    #[Test]
    public function it_describes_a_passing_and_a_failing_sequence_for_the_terminal(): void
    {
        $passing = $this->verifier->verify(
            [$this->check('lint', 'exit(0);'), $this->check('tests', 'exit(0);')],
            $this->workspace,
        );

        self::assertStringContainsString('2 of 2 checks passed', $passing->describe());

        $failing = $this->verifier->verify(
            [$this->check('lint', 'exit(0);'), $this->check('tests', 'exit(1);')],
            $this->workspace,
        );

        self::assertStringContainsString('tests failed', $failing->describe());
        self::assertStringContainsString('1 of 2 checks passed', $failing->describe());
    }

    /**
     * A check that runs a short PHP program.
     */
    /**
     * A check's excerpt is stored, shown by `ralph logs` and handed to the next
     * attempt, so a declared secret that a suite printed comes out of it
     * (PROJECT.md section I, item 6).
     *
     * The streamed copy is the other half of the item and is asserted beside
     * it: what went to the sink while the check was running is the command's
     * own bytes, kept as they arrived.
     */
    #[Test]
    public function a_declared_secret_is_kept_out_of_the_excerpt_but_not_out_of_the_stream(): void
    {
        $sink = new RecordingSink();

        $run = $this->verifier->verify(
            [$this->check('tests', 'fwrite(STDOUT, "configured with ' . self::SECRET . '"); exit(1);')],
            $this->workspace,
            $sink,
            $this->secrets(),
        )->runs()[0];

        self::assertSame('configured with [redacted TEST_KEY]', $run->output());
        self::assertStringContainsString(self::SECRET, $sink->textOf(OutputStream::Standard));
    }

    /**
     * Without secrets the excerpt is what the command printed, which is every
     * check until a run resolves the project's declared variables.
     */
    #[Test]
    public function a_check_run_without_secrets_keeps_what_it_printed(): void
    {
        $run = $this->verifier->verify(
            [$this->check('tests', 'fwrite(STDOUT, "configured with ' . self::SECRET . '");')],
            $this->workspace,
        )->runs()[0];

        self::assertSame('configured with ' . self::SECRET, $run->output());
    }

    /**
     * The run's secrets, built the way production builds them.
     */
    private function secrets(): Secrets
    {
        return AgentEnvironment::of(
            new AgentDefinition('fixture', 'codex', 'codex', environment: ['TEST_KEY']),
            static fn (string $name): string|false => $name === 'TEST_KEY' ? self::SECRET : false,
        )->secrets();
    }

    private function check(string $name, string $program, int $timeout = 600): VerificationCommand
    {
        return new VerificationCommand($name, [PHP_BINARY, '-r', $program], $timeout);
    }
}
