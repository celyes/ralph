<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The result of a command, as a value.
 *
 * Constructed directly here rather than by running something: what is under
 * test is how a result is *read* — which is the part preflight, the verification
 * runner and the adapters all depend on, and the part that has to hold for
 * combinations a single real command cannot produce.
 */
#[CoversClass(ProcessResult::class)]
final class ProcessResultTest extends TestCase
{
    #[Test]
    public function only_a_finished_command_with_a_zero_status_succeeded(): void
    {
        self::assertTrue($this->outcomeOf(0)->succeeded());
        self::assertFalse($this->outcomeOf(1)->succeeded());
        self::assertFalse($this->outcomeOf(null)->succeeded());

        // A command that was killed for overrunning its timeout has not
        // succeeded, whatever status was reaped for it.
        self::assertFalse($this->outcomeOf(0, timedOut: true)->succeeded());
    }

    #[Test]
    public function output_is_offered_trimmed_and_by_the_line(): void
    {
        $result = $this->outcomeOf(0, standardOutput: "  2.43.0\r\nextra\n");

        self::assertSame("  2.43.0\r\nextra\n", $result->standardOutput());
        self::assertSame("2.43.0\r\nextra", $result->trimmedOutput());
        self::assertSame('2.43.0', $result->firstOutputLine());
        self::assertSame('', $this->outcomeOf(0)->firstOutputLine());
    }

    #[Test]
    public function a_failure_is_explained_by_the_first_line_of_error_output(): void
    {
        $result = $this->outcomeOf(128, errorOutput: "fatal: not a git repository\nAdvice follows.\n");

        self::assertSame('not a git repository', $result->failureReason());
    }

    #[Test]
    public function a_failure_falls_back_to_ordinary_output_and_then_to_the_status(): void
    {
        self::assertSame(
            'something went wrong',
            $this->outcomeOf(2, standardOutput: "something went wrong\n")->failureReason(),
        );

        self::assertSame('it exited with status 3', $this->outcomeOf(3)->failureReason());
    }

    #[Test]
    public function a_timeout_explains_itself_as_one(): void
    {
        $result = $this->outcomeOf(null, timedOut: true, seconds: 20.0);

        // Even though the command did write something: it was stopped, which is
        // the reason, and the partial output is in the result for the reader.
        self::assertSame('it did not finish within 20 seconds', $result->failureReason());
        self::assertTrue($result->timedOut());
        self::assertSame(20.0, $result->seconds());
    }

    #[Test]
    public function it_describes_the_whole_invocation_for_diagnostics(): void
    {
        $described = $this->outcomeOf(1, standardOutput: 'out', errorOutput: 'err')->describe();

        self::assertStringContainsString('git rev-parse HEAD', $described);
        self::assertStringContainsString('exit code: 1', $described);
        self::assertStringContainsString('out', $described);
        self::assertStringContainsString('err', $described);

        self::assertStringContainsString('exit code: timed out', $this->outcomeOf(null, timedOut: true)->describe());
        self::assertStringContainsString('(empty)', $this->outcomeOf(0)->describe());
    }

    #[Test]
    public function it_keeps_the_command_it_was_given(): void
    {
        self::assertSame(['git', 'rev-parse', 'HEAD'], $this->outcomeOf(0)->command());
    }

    private function outcomeOf(
        ?int $exitCode,
        string $standardOutput = '',
        string $errorOutput = '',
        bool $timedOut = false,
        float $seconds = 0.5,
    ): ProcessResult {
        return new ProcessResult(
            ['git', 'rev-parse', 'HEAD'],
            $exitCode,
            $standardOutput,
            $errorOutput,
            $timedOut,
            $seconds,
        );
    }
}
