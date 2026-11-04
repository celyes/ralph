<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\VersionRequirement;
use Celyes\RalphPhp\State\AttemptOutcome;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The fixture worker itself: what runs in the child process.
 *
 * PROJECT.md section E, item 12. This is the agent as far as the package is
 * concerned — an executable the adapters start, hand a briefing to, stream the
 * output of and read a result from — except that what it does is written down
 * in advance by the test instead of decided by a model. It is the counterpart
 * of {@see FixtureWorker}, which installs it and reads its journal.
 *
 * **Why a program and not a double.** The behaviour the loop has to get right
 * is almost entirely about a *process*: the directory it was started in, the
 * briefing it read, the status it exited with, that it was asked to stop and
 * then killed, that its half-finished edits are still in the worktree
 * afterwards. A PHP object standing in for an adapter demonstrates none of
 * that, and the parts of {@see \Celyes\RalphPhp\Process\ProcessRunner} and
 * {@see \Celyes\RalphPhp\Agent\ProcessAdapter} where those guarantees live are
 * exactly the parts it would skip.
 *
 * **Why it speaks the real protocol.** The report is built from
 * {@see ResultProtocol}'s own constants, so the fixture cannot drift into
 * printing a format nothing asks for, and every adapter reads it without
 * knowing it is a fixture. A test that needs malformed output scripts a step
 * that reports nothing ({@see FixtureStep::reportingNothing()}); it does not
 * need a worker that spells the protocol wrong, because that is
 * {@see \Celyes\RalphPhp\Tests\Agent\ReportReaderTest}'s subject.
 *
 * **Determinism.** Everything printed, written and returned comes from the
 * step. The clock is read only to wait, nothing is random, no network and no
 * credentials are involved (section J, item 8), and which step runs is decided
 * by counting the journal's attempts rather than by anything ambient — so the
 * same plan run twice does the same thing twice, and the last step repeats for
 * a third attempt rather than the plan running out.
 *
 * **It answers a version query.** Preflight asks an agent's executable for its
 * version before a run (section E, item 9), and a fixture that could not answer
 * would make every end-to-end test either skip preflight or warn its way past
 * it. A query is journaled and does **not** consume a step: it is not an
 * attempt, and a step spent on it would silently shift every later assertion.
 */
final class FixtureWorkerProgram
{
    /**
     * The plan's fields, written by {@see FixtureWorker} and read here.
     */
    public const string VERSION = 'version';
    public const string STEPS = 'steps';

    /**
     * The status the worker exits with when the fixture itself is broken — a
     * plan it cannot read, an edit it cannot write.
     *
     * Deliberately not 1: a test scripting a failure expects 1, and a fixture
     * that failed for its own reasons must not look like the failure the test
     * asked for.
     */
    public const int FIXTURE_ERROR = 70;

    /**
     * Run the worker once.
     *
     * @param string $journal where to record what happened
     * @param string $plan the installed plan file
     * @param list<string> $arguments the argument list the adapter built,
     *        without the program itself
     *
     * @return int the exit status the process should end with
     */
    public static function main(string $journal, string $plan, array $arguments): int
    {
        try {
            return self::run($journal, $plan, $arguments);
        } catch (Throwable $exception) {
            // On the error stream and named as the fixture's own, so that a
            // broken fixture reads as a broken fixture in an attempt's log
            // rather than as an agent that mysteriously failed.
            fwrite(STDERR, 'ralph fixture worker: ' . $exception->getMessage() . "\n");

            return self::FIXTURE_ERROR;
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RuntimeException when the plan cannot be read or a step cannot be
     *                          carried out
     */
    private static function run(string $journal, string $plan, array $arguments): int
    {
        [$version, $steps] = self::plan($plan);

        $directory = getcwd();
        $directory = $directory === false ? '' : $directory;

        if (self::isVersionQuery($arguments)) {
            FixtureRecord::append($journal, FixtureRecord::versionQuery($arguments, $directory));
            fwrite(STDOUT, sprintf("%s %s\n", FixtureWorker::NAME, $version));

            return 0;
        }

        // Read before anything is recorded or printed: the briefing is what an
        // agent is given, and a worker that acted first and read later could
        // not report what it was asked to do.
        $briefing = self::briefing();

        $records = FixtureRecord::read($journal);
        $number = min(FixtureRecord::attemptsIn($records), count($steps) - 1) + 1;
        $step = $steps[$number - 1];

        // Written before the step runs, so a worker that is killed in the
        // middle of one still leaves the evidence that it ran at all.
        FixtureRecord::append($journal, FixtureRecord::attempt($number, $step->mode(), $arguments, $directory, $briefing));

        fwrite(STDOUT, sprintf(
            "%s: step %d of %d, mode %s\n",
            FixtureWorker::NAME,
            $number,
            count($steps),
            $step->mode()->value,
        ));

        // Before the wait, so an interrupted attempt leaves a half-finished
        // workspace behind — which is what section F, item 10 preserves for
        // inspection and what section H's reconciliation has to cope with.
        self::apply($step, $directory);

        if ($step->mode()->waits()) {
            self::wait($step, $journal, $number);
        }

        if (!$step->reports()) {
            fwrite(STDOUT, "Nothing to report.\n");

            return $step->status();
        }

        fwrite(STDOUT, self::report($step));

        return $step->status();
    }

    /**
     * The installed plan: the version the worker answers with, and its steps.
     *
     * @return array{string, non-empty-list<FixtureStep>}
     *
     * @throws RuntimeException
     */
    private static function plan(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('no plan to follow at ' . $path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('the plan at ' . $path . ' is not JSON: ' . $exception->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('the plan at ' . $path . ' is not an object');
        }

        $version = $decoded[self::VERSION] ?? null;
        $raw = $decoded[self::STEPS] ?? null;

        if (!is_string($version) || !is_array($raw) || $raw === []) {
            throw new RuntimeException('the plan at ' . $path . ' states no version or no steps');
        }

        $steps = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('the plan at ' . $path . ' holds a step that is not an object');
            }

            $steps[] = FixtureStep::fromArray($entry);
        }

        return [$version, $steps];
    }

    /**
     * Whether this invocation is preflight asking for a version rather than an
     * attempt.
     *
     * The spellings a {@see VersionRequirement} would use, and nothing wider: a
     * worker that treated any unrecognised argument as a version query would
     * answer an attempt with a version string and report no result at all.
     *
     * @param list<string> $arguments
     */
    private static function isVersionQuery(array $arguments): bool
    {
        foreach ([VersionRequirement::DEFAULT_ARGUMENT, '-v', 'version'] as $spelling) {
            if (in_array($spelling, $arguments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything standard input holds, which is the briefing when the adapter
     * sends one there and nothing when it does not.
     */
    private static function briefing(): string
    {
        $input = stream_get_contents(STDIN);

        return $input === false ? '' : $input;
    }

    /**
     * Leave the step's edits in the workspace.
     *
     * Paths are relative to the working directory the adapter started the
     * worker in, which is the task's own workspace
     * ({@see \Celyes\RalphPhp\Agent\ProcessAdapter}'s guarantee). Resolved
     * against it explicitly rather than written relative to the process's
     * directory, so the journal and the files agree about where an attempt
     * worked even if a future step changes directory.
     *
     * @throws RuntimeException when an edit cannot be carried out, which is a
     *                          broken test rather than a scripted failure
     */
    private static function apply(FixtureStep $step, string $directory): void
    {
        foreach ($step->edits() as $relative => $contents) {
            $path = $directory . DIRECTORY_SEPARATOR . $relative;

            if ($contents === null) {
                if (!is_file($path)) {
                    throw new RuntimeException('cannot delete ' . $relative . ': it is not a file in the workspace');
                }

                if (!unlink($path)) {
                    throw new RuntimeException('cannot delete ' . $relative);
                }

                continue;
            }

            $parent = dirname($path);

            if (!is_dir($parent) && !mkdir($parent, 0o700, true) && !is_dir($parent)) {
                throw new RuntimeException('cannot create the directory for ' . $relative);
            }

            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('cannot write ' . $relative);
            }
        }
    }

    /**
     * Sit there for the step's seconds, noticing a signal if the step's mode
     * says to.
     *
     * {@see FixtureMode::Slow} does not install handlers at all: it is an
     * ordinary program that is merely slow, and a termination signal kills it
     * where it stands, which is what the attempt timeout is enforced against.
     * {@see FixtureMode::Interruption} records the signal that reached it, and
     * — when the step says so — refuses to act on it, which is what makes the
     * escalation from a signal to a kill observable.
     *
     * Without ext-pcntl there is nothing to install: the mode still waits and
     * still dies when it is stopped, and only the record of having been asked
     * is missing. Tests that assert on that record say so by skipping without
     * the extension, which is why the package does not require it.
     */
    private static function wait(FixtureStep $step, string $journal, int $number): void
    {
        $deadline = microtime(true) + $step->seconds();

        if ($step->mode()->handlesSignals() && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $handler = static function (int $signal) use ($journal, $number, $step): void {
                FixtureRecord::append($journal, FixtureRecord::stoppedBy($signal, $number));

                if ($step->ignoresTermination()) {
                    fwrite(STDOUT, sprintf("ignoring signal %d\n", $signal));

                    return;
                }

                fwrite(STDOUT, sprintf("stopped by signal %d\n", $signal));

                // As a shell reports a signalled process, so that a test
                // reading the status can tell which signal ended it.
                exit(128 + $signal);
            };

            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                pcntl_signal($signal, $handler);
            }
        }

        fwrite(STDOUT, sprintf("waiting %d second%s\n", $step->seconds(), $step->seconds() === 1 ? '' : 's'));

        // Flushed before the wait, so that whatever has been printed is
        // captured even by an attempt that is killed: a timeout's evidence is
        // the output that arrived before it (section E, item 4).
        fflush(STDOUT);

        while (microtime(true) < $deadline) {
            usleep(50_000);
        }
    }

    /**
     * The step's result, as a fenced block.
     *
     * Built from {@see ResultProtocol}'s constants rather than written out, so
     * the fixture reports in the format the package asks for by construction.
     *
     * @throws RuntimeException when the report cannot be encoded
     */
    private static function report(FixtureStep $step): string
    {
        $outcome = $step->mode()->declares();

        $fields = [
            ResultProtocol::OUTCOME => $outcome->value,
            ResultProtocol::SUMMARY => $step->summary(),
        ];

        if ($outcome === AttemptOutcome::Blocked) {
            $fields[ResultProtocol::NEXT_ACTION] = $step->nextAction();
        }

        try {
            $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('cannot encode the step\'s result: ' . $exception->getMessage());
        }

        return sprintf("```%s\n%s\n```\n", ResultProtocol::FENCE, $json);
    }

    private function __construct()
    {
    }
}
