<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\ProcessTree;
use Celyes\RalphPhp\Process\Terminator;
use Celyes\RalphPhp\Process\Termination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stopping a worker and everything it started.
 *
 * Real processes, for the same reason {@see ProcessRunnerTest} uses them: what
 * is under test is whether a process is actually gone afterwards, and a double
 * can only be asked whether it was told to go. So each test starts a program
 * that behaves like the awkward part of a coding agent — one that spawns a test
 * runner, one that catches the termination signal and carries on — and then
 * asks the operating system what is left.
 */
#[CoversClass(Terminator::class)]
#[CoversClass(Termination::class)]
final class TerminatorTest extends TestCase
{
    /**
     * A program that sits there until it is stopped.
     *
     * Bounded rather than endless: a test that leaked one of these would leave
     * it holding whatever descriptors it inherited, and a minute is long enough
     * that nothing here can outlast it by finishing early.
     */
    private const string WAITS = 'sleep(60);';

    /**
     * A program that starts one of its own and then waits, printing the process
     * it started.
     */
    private const string SPAWNS_AND_WAITS = <<<'CODE'
        $quiet = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], $quiet, $pipes);
        fwrite(STDOUT, proc_get_status($child)['pid'] . "\n");
        fflush(STDOUT);
        sleep(60);
        CODE;

    /**
     * A program that is asked to stop, says so, and does not.
     */
    private const string REFUSES_TO_STOP = <<<'CODE'
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {});
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
        // Not sleep(): a signal cuts that short, and a program that returned
        // from it would exit by itself and look like one that stopped when
        // asked.
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) { usleep(20000); }
        CODE;

    /**
     * @var list<resource>
     */
    private array $started = [];

    /**
     * @var list<int>
     */
    private array $spawned = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }

        foreach ($this->started as $process) {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
        }

        $this->started = [];
        $this->spawned = [];
    }

    #[Test]
    public function it_stops_a_waiting_process_and_confirms_it(): void
    {
        [$process, $pid] = $this->start(self::WAITS);

        $termination = (new Terminator())->stop($process);

        self::assertTrue($termination->isConfirmed(), $termination->describe());
        self::assertSame([$pid], $termination->signalled());
        self::assertSame([], $termination->survivors());
        self::assertFalse($termination->wasKilled(), 'A process that stops when asked is not killed.');
        self::assertStringContainsString('confirmed stopped', $termination->describe());
    }

    /**
     * The point of the whole class: an agent's children go with it.
     */
    #[Test]
    public function the_children_a_worker_started_are_stopped_too(): void
    {
        if (!ProcessTree::isReadable() || !function_exists('posix_kill')) {
            self::markTestSkipped('Reaching a grandchild needs a process filesystem and ext-posix.');
        }

        [$process, $pid, $grandchild] = $this->startAndSpawn();

        self::assertTrue(ProcessTree::isProcessAlive($grandchild), 'The child was never started.');

        $termination = (new Terminator())->stop($process);

        self::assertTrue($termination->isConfirmed(), $termination->describe());
        self::assertSame([$grandchild, $pid], $termination->signalled(), 'Deepest first.');
        self::assertFalse(
            ProcessTree::isProcessAlive($grandchild),
            'The process the worker started outlived the worker.',
        );
    }

    #[Test]
    public function a_worker_that_ignores_a_termination_signal_is_killed(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('The stubborn worker needs ext-pcntl to ignore a signal.');
        }

        [$process, $pid] = $this->start(self::REFUSES_TO_STOP, awaitLine: true);

        // Short, because the test waits it out: the escalation is what is under
        // test, not how long the package is willing to be patient for.
        $termination = (new Terminator())->stop($process, grace: 0.2);

        self::assertTrue($termination->isConfirmed(), $termination->describe());
        self::assertTrue($termination->wasKilled(), 'A process that refused to stop was not killed.');
        self::assertSame([$pid], $termination->signalled());
        self::assertSame([], $termination->survivors());
    }

    #[Test]
    public function a_process_that_has_already_exited_leaves_nothing_to_stop(): void
    {
        [$process] = $this->start('exit(0);');

        // Read its status until it has gone, as any caller would before
        // deciding to stop it.
        for ($attempt = 0; $attempt < 500 && proc_get_status($process)['running']; $attempt++) {
            usleep(2000);
        }

        $termination = (new Terminator())->stop($process);

        self::assertTrue($termination->isConfirmed());
        self::assertSame([], $termination->signalled());
        self::assertSame('nothing was running to stop', $termination->describe());
    }

    /**
     * The documented boundary of "where supported".
     *
     * Without a process filesystem there are no parent links to read, so the
     * tree is the worker alone: it is still stopped, and still confirmed
     * stopped through its own handle, and anything it started is out of reach.
     * Asserted rather than left implied, because it is the one thing this class
     * cannot do everywhere and a reader should be able to find out from a test
     * that it is a limitation and not an oversight.
     */
    #[Test]
    public function without_a_process_filesystem_only_the_worker_itself_is_reached(): void
    {
        [$process, $pid] = $this->start(self::WAITS);

        self::assertTrue(
            ProcessTree::rootedAt($pid, '/nonexistent-procfs')->isOnlyRoot(),
            'Relationships cannot be read, so nothing below the worker is known.',
        );

        $termination = (new Terminator('/nonexistent-procfs'))->stop($process);

        self::assertSame([$pid], $termination->signalled());
        self::assertTrue(
            $termination->isConfirmed(),
            'The worker itself is confirmed through its handle, with or without a /proc: '
                . $termination->describe(),
        );
    }

    #[Test]
    public function the_three_readings_describe_themselves_apart(): void
    {
        self::assertSame('nothing was running to stop', Termination::nothingToStop()->describe());
        self::assertSame('1 process stopped, confirmed stopped', Termination::confirmed([7])->describe());
        self::assertSame('2 processes killed, confirmed stopped', Termination::confirmed([7, 8], killed: true)->describe());
        self::assertSame(
            '2 processes killed, and one is still running (8)',
            Termination::survived([7, 8], [8])->describe(),
        );

        $survived = Termination::survived([7, 8, 9], [8, 9]);
        self::assertFalse($survived->isConfirmed());
        self::assertTrue($survived->isKnowable());
        self::assertSame([8, 9], $survived->survivors());
        self::assertStringContainsString('2 are still running (8, 9)', $survived->describe());
    }

    /**
     * Start a program and return its handle and process id.
     *
     * @param bool $awaitLine whether to wait for the program's first line,
     *        which is how a test knows it has got as far as installing a signal
     *        handler
     *
     * @return array{resource, int}
     */
    private function start(string $code, bool $awaitLine = false): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'wb'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
        );

        self::assertIsResource($process, 'The process could not be started.');
        $this->started[] = $process;

        if ($awaitLine) {
            self::assertIsString(fgets($pipes[1]), 'The process never reported itself ready.');
        }

        fclose($pipes[1]);

        return [$process, (int) proc_get_status($process)['pid']];
    }

    /**
     * @return array{resource, int, int}
     */
    private function startAndSpawn(): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', self::SPAWNS_AND_WAITS],
            [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'wb'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
        );

        self::assertIsResource($process, 'The process could not be started.');
        $this->started[] = $process;

        $line = fgets($pipes[1]);
        self::assertIsString($line, 'The worker never reported the process it started.');
        fclose($pipes[1]);

        $grandchild = (int) trim($line);
        $this->spawned[] = $grandchild;

        return [$process, (int) proc_get_status($process)['pid'], $grandchild];
    }
}
