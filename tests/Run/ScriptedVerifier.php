<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\VerificationReport;
use Celyes\RalphPhp\Verification\Verifier;

/**
 * A verifier that reports what the test told it to, and remembers what it was
 * asked.
 *
 * The loop's tests are about the loop, so what the checks *are* must not decide
 * whether they pass: a real check would make every case here depend on a test
 * suite being installed inside a temporary worktree. This is the other half of
 * the reason {@see Verifier} is an interface.
 *
 * What it does do faithfully is the sequence: a failure stops the run of checks
 * and the ones behind it are reported as not run, which is what the runner
 * stores and what the next attempt is told.
 */
final class ScriptedVerifier implements Verifier
{
    /**
     * Every call, in order: the directory and the checks it was handed.
     *
     * @var list<array{directory: string, checks: list<string>}>
     */
    public array $calls = [];

    /**
     * What the workspace held when the checks ran, keyed by relative path.
     *
     * Read at verification time rather than afterwards, because the question
     * these tests ask of it is whether the checks see the agent's work *before*
     * anything is staged or committed.
     *
     * @var array<string, string>
     */
    public array $sawInWorkspace = [];

    /**
     * @param list<bool>   $verdicts one per call, the last repeating: true
     *        passes every check, false fails the first and leaves the rest
     *        unreached
     * @param list<string> $watching relative paths to read out of the workspace
     *        each time the checks run
     * @param (callable(): void)|null $while something to do while the checks
     *        are nominally running
     */
    private function __construct(
        private readonly array $verdicts,
        private readonly array $watching = [],
        private readonly bool $unrunnable = false,
        private $while = null,
    ) {
    }

    /**
     * A verifier whose checks all pass.
     */
    public static function passing(string ...$watching): self
    {
        return new self([true], array_values($watching));
    }

    /**
     * A verifier whose first check fails.
     */
    public static function failing(): self
    {
        return new self([false]);
    }

    /**
     * A verifier that passes on the given calls and fails on the others.
     *
     * @param list<bool> $verdicts
     */
    public static function scripted(array $verdicts): self
    {
        return new self($verdicts);
    }

    /**
     * A verifier that reports a pass, having first done something while the
     * checks were nominally running.
     *
     * The only way to put a moment *inside* a verification run, which the
     * interruption cases need: a run stopped while the checks are going has its
     * checks cancelled, and what the package does with a half-finished check is
     * the thing under test (PROJECT.md section H, item 4). The verdict is
     * reported as a pass so that nothing about the outcome depends on this
     * verifier having anything to say about the work.
     *
     * @param callable(): void $while
     */
    public static function passingWhile(callable $while): self
    {
        return new self([true], [], false, $while);
    }

    /**
     * A verifier that cannot run the checks at all, which is a setup failure
     * and not a verdict.
     */
    public static function unrunnable(): self
    {
        return new self([true], [], true);
    }

    public function verify(array $checks, string $directory, ?OutputSink $sink = null): VerificationReport
    {
        $call = count($this->calls);
        $this->calls[] = [
            'directory' => $directory,
            'checks' => array_map(static fn (VerificationCommand $check): string => $check->name(), $checks),
        ];

        foreach ($this->watching as $relative) {
            $path = $directory . DIRECTORY_SEPARATOR . $relative;

            if (is_file($path)) {
                $this->sawInWorkspace[$relative] = (string) file_get_contents($path);
            }
        }

        if ($this->unrunnable) {
            throw new PreparationException(
                'The checks could not be run in ' . $directory . '.',
                ['This is a setup failure, not a verdict on the work.'],
            );
        }

        $sink?->note('scripted verification in ' . $directory);

        if ($this->while !== null) {
            ($this->while)();
        }

        $passes = $this->verdicts[$call] ?? $this->verdicts[count($this->verdicts) - 1] ?? true;
        $runs = [];
        $stopped = false;

        foreach ($checks as $check) {
            if ($stopped) {
                $runs[] = CheckRun::notRun($check, $directory);

                continue;
            }

            if ($passes) {
                $runs[] = CheckRun::of($check, CheckOutcome::Passed, $directory, 0, 0.25, "OK (1 test)\n");

                continue;
            }

            $stopped = true;
            $runs[] = CheckRun::of(
                $check,
                CheckOutcome::Failed,
                $directory,
                1,
                0.5,
                "1) ImportCommandTest::a_dry_run_changes_nothing\nFailed asserting that true is false.\n",
            );
        }

        return VerificationReport::of(...$runs);
    }
}
