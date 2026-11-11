<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Fixture;

use Celyes\RalphPhp\Process\SystemProbe;
use Celyes\RalphPhp\Tests\Support\Paths;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * A coding agent a test writes in advance: installs the fixture worker and
 * reads back what the package did to it.
 *
 * PROJECT.md section E, item 12. {@see FixtureWorkerProgram} is the program;
 * this is the handle a test holds — it writes the plan, installs an executable
 * the configured agent can point at, and afterwards answers the questions a
 * test has about the run: how many attempts there were, which workspace each
 * one got, what briefing it was given, and whether it was asked to stop before
 * it was killed.
 *
 * **How a test uses it.** Install it in a directory of its own, point an agent
 * definition's `executable` at {@see self::path()}, and run the adapter or (from
 * section G) the runner:
 *
 * ```php
 * $worker = FixtureWorker::install($tools, [
 *     FixtureStep::failure('the test suite was red'),
 *     FixtureStep::success(['src/Import.php' => "<?php …\n"]),
 * ]);
 *
 * $agent = new AgentDefinition('fixture', BuiltInAdapters::CODEX, $worker->path());
 * …
 * self::assertCount(2, $worker->attempts());
 * ```
 *
 * **Not in the workspace.** The installed program, its plan and its journal go
 * in a directory the test owns and which is *not* a task worktree: anything
 * written inside one would appear in the diff the runner stages (section F,
 * item 6), so a fixture living there would corrupt what it exists to test. The
 * same reason {@see \Celyes\RalphPhp\Process\ProcessRunner} writes a
 * briefing to the command's standard input rather than to a file.
 *
 * **It is a real executable.** A shebang line and the execute bit, so an
 * adapter starts it exactly as it would start `codex` or `claude`, through
 * {@see \Celyes\RalphPhp\Process\ExecutableFinder} and an argument array with
 * no shell. It is PHP rather than a shell script so that the plan, the journal and
 * the result protocol are the package's own classes instead of a second
 * implementation in another language.
 */
final class FixtureWorker
{
    /**
     * What the worker calls itself, in its output and in the version it
     * answers with.
     */
    public const string NAME = 'ralph-fixture-worker';

    /**
     * The version a query is answered with unless the test says otherwise.
     *
     * Deliberately higher than any adapter's floor: a fixture agent exists to
     * test the loop, and a version that failed every adapter's requirement
     * would make preflight the subject of every test that used one. A test
     * about an unsupported CLI version installs a worker that answers an old
     * one.
     */
    public const string DEFAULT_VERSION = '99.0.0';

    private const string PLAN_FILE = self::NAME . '.plan.json';
    private const string JOURNAL_FILE = self::NAME . '.journal.jsonl';

    private function __construct(private readonly string $directory)
    {
    }

    /**
     * Install a worker that follows these steps.
     *
     * @param string $directory an existing absolute directory the test owns,
     *        and not a task workspace — see the class description
     * @param list<FixtureStep> $steps what it does on each successive run, the
     *        last one repeating; one plain success by default
     * @param string $version what it answers a version query with
     *
     * @throws InvalidArgumentException when the directory is unusable
     * @throws RuntimeException when the worker cannot be written
     */
    public static function install(
        string $directory,
        array $steps = [],
        string $version = self::DEFAULT_VERSION,
    ): self {
        if (!str_starts_with($directory, '/')) {
            throw new InvalidArgumentException('A fixture worker is installed at an absolute path, not ' . $directory . '.');
        }

        if (!is_dir($directory)) {
            throw new InvalidArgumentException('The directory ' . $directory . ' does not exist.');
        }

        $worker = new self(rtrim($directory, '/'));
        $worker->writePlan($steps === [] ? [FixtureStep::success()] : $steps, $version);
        $worker->writeProgram();

        return $worker;
    }

    /**
     * The executable an agent definition points at.
     */
    public function path(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::NAME;
    }

    /**
     * The plan the worker follows.
     */
    public function planPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::PLAN_FILE;
    }

    /**
     * Where the worker records what it did.
     */
    public function journalPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
    }

    /**
     * Everything the worker recorded, in order.
     *
     * @return list<FixtureRecord>
     */
    public function records(): array
    {
        return FixtureRecord::read($this->journalPath());
    }

    /**
     * The runs that were attempts at the task, in order.
     *
     * A version query is not one of them, which is the distinction that keeps
     * a preflight check from shifting every assertion about attempts.
     *
     * @return list<FixtureRecord>
     */
    public function attempts(): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn (FixtureRecord $record): bool => $record->isAttempt(),
        ));
    }

    /**
     * The one attempt the worker made, for the common case of a test that
     * expects exactly one.
     *
     * @throws RuntimeException when there was not exactly one
     */
    public function attempt(): FixtureRecord
    {
        $attempts = $this->attempts();

        if (count($attempts) !== 1) {
            throw new RuntimeException(sprintf(
                'The fixture worker made %d attempts, so there is no single one to ask about.',
                count($attempts),
            ));
        }

        return $attempts[0];
    }

    /**
     * The signals the worker noticed reaching it, in order.
     *
     * Only {@see FixtureMode::Interruption} records these, and only with
     * ext-pcntl available; an empty list from a slow step means the step never
     * looked, not that nothing arrived.
     *
     * @return list<int>
     */
    public function signals(): array
    {
        $signals = [];

        foreach ($this->records() as $record) {
            $signal = $record->signal();

            if ($record->isSignal() && $signal !== null) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * How many times the worker was asked for its version.
     */
    public function versionQueries(): int
    {
        return count(array_filter(
            $this->records(),
            static fn (FixtureRecord $record): bool => $record->isVersionQuery(),
        ));
    }

    /**
     * Whether a process the worker recorded is still running, or null when the
     * platform cannot say.
     *
     * Here rather than in a test because the worker is what reports process
     * identifiers, and because section H's "confirm that an old worker has
     * stopped before allowing resume" is a claim about exactly this. A reaped
     * child is gone from `/proc`, which is what makes the answer meaningful
     * after an adapter has returned.
     */
    public static function isRunning(int $pid): ?bool
    {
        // Asked through the probe the repository lock uses, so that "has the
        // old worker stopped" is answered here the same way the lock answers
        // it. A non-positive identifier is not a process that has stopped; it
        // is a record that never named one.
        return $pid <= 0 ? null : (new SystemProbe())->isRunning($pid);
    }

    /**
     * Write the plan the installed program reads.
     *
     * @param non-empty-list<FixtureStep> $steps
     *
     * @throws RuntimeException
     */
    private function writePlan(array $steps, string $version): void
    {
        try {
            $plan = json_encode(
                [
                    FixtureWorkerProgram::VERSION => $version,
                    FixtureWorkerProgram::STEPS => array_map(
                        static fn (FixtureStep $step): array => $step->toArray(),
                        $steps,
                    ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the fixture worker plan.', 0, $exception);
        }

        if (file_put_contents($this->planPath(), $plan . "\n") === false) {
            throw new RuntimeException('Unable to write the fixture worker plan at ' . $this->planPath() . '.');
        }
    }

    /**
     * Write the executable itself.
     *
     * A shebang naming the running PHP binary, the suite's own autoloader, and
     * one call into {@see FixtureWorkerProgram}: the paths are baked in rather
     * than passed as arguments or environment variables, because the adapter
     * owns the argument list and the environment is where credentials live.
     * Both are properties of this installation, so two workers installed in two
     * directories keep two plans and two journals and cannot read each other's.
     *
     * @throws RuntimeException
     */
    private function writeProgram(): void
    {
        $autoload = Paths::inPackage('vendor/autoload.php');

        if (!is_file($autoload)) {
            throw new RuntimeException('The fixture worker needs the suite\'s autoloader at ' . $autoload . '.');
        }

        // Built as lines rather than as a heredoc: the shebang has to be the
        // first byte of the file, and a nested heredoc's indentation is one
        // more thing that has to come out right for the program to run at all.
        $program = implode("\n", [
            '#!' . PHP_BINARY,
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'require ' . var_export($autoload, true) . ';',
            '',
            sprintf(
                'exit(\\%s::main(%s, %s, array_slice($argv, 1)));',
                FixtureWorkerProgram::class,
                var_export($this->journalPath(), true),
                var_export($this->planPath(), true),
            ),
            '',
        ]);

        if (file_put_contents($this->path(), $program) === false || !chmod($this->path(), 0o700)) {
            throw new RuntimeException('Unable to install the fixture worker at ' . $this->path() . '.');
        }
    }
}
