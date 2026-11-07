<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\ProcessTree;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a process and everything it started.
 *
 * Two halves, tested two ways. The walk itself — parents, children,
 * grandchildren, an executable with parentheses in its name, a table that
 * contradicts itself — is read from a written-out process filesystem, because
 * those are shapes a test cannot reliably arrange out of real processes. That
 * the reading matches reality is a real process tree: a child that starts a
 * child of its own, found from the pid `proc_open` reported.
 */
#[CoversClass(ProcessTree::class)]
final class ProcessTreeTest extends TestCase
{
    /**
     * A grandchild of this process: the shape an agent's CLI makes when it
     * starts a test run or a language server.
     */
    private const string SPAWNS_A_CHILD = <<<'CODE'
        $quiet = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], $quiet, $pipes);
        fwrite(STDOUT, proc_get_status($child)['pid'] . "\n");
        fflush(STDOUT);
        sleep(60);
        CODE;

    private string $procfs;

    /**
     * @var list<resource>
     */
    private array $started = [];

    /**
     * Grandchildren, which nothing else will reap: a test that leaked a
     * sleeping process would leave it holding whatever descriptors it
     * inherited for as long as it lived.
     *
     * @var list<int>
     */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->procfs = TemporaryDirectory::create('ralph-procfs');
    }

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
        TemporaryDirectory::remove($this->procfs);
    }

    #[Test]
    public function it_reads_children_grandchildren_and_their_order(): void
    {
        $this->write(100, parent: 1);
        $this->write(200, parent: 100);
        $this->write(300, parent: 100);
        $this->write(400, parent: 200);

        $tree = ProcessTree::rootedAt(100, $this->procfs);

        self::assertSame(100, $tree->root());
        self::assertSame([200, 300, 400], $tree->descendants());
        self::assertSame(4, $tree->count());
        self::assertFalse($tree->isOnlyRoot());
    }

    /**
     * The order termination uses: a child before the parent that is waiting on
     * it, so nothing is orphaned and left running.
     */
    #[Test]
    public function the_deepest_processes_come_first(): void
    {
        $this->write(100, parent: 1);
        $this->write(200, parent: 100);
        $this->write(300, parent: 200);

        self::assertSame([300, 200, 100], ProcessTree::rootedAt(100, $this->procfs)->deepestFirst());
    }

    #[Test]
    public function a_process_with_nothing_below_it_is_only_itself(): void
    {
        $this->write(100, parent: 1);

        $tree = ProcessTree::rootedAt(100, $this->procfs);

        self::assertTrue($tree->isOnlyRoot());
        self::assertSame([], $tree->descendants());
        self::assertSame([100], $tree->deepestFirst());
        self::assertSame('process 100', $tree->describe());
    }

    /**
     * Unrelated work is not in the tree, which is the whole point of reading
     * one: a termination that signalled every process on the machine would be
     * a great deal worse than one that missed a child.
     */
    #[Test]
    public function processes_that_are_not_descendants_are_left_out(): void
    {
        $this->write(100, parent: 1);
        $this->write(200, parent: 100);
        $this->write(900, parent: 1);
        $this->write(901, parent: 900);

        self::assertSame([200], ProcessTree::rootedAt(100, $this->procfs)->descendants());
    }

    /**
     * `/proc/PID/stat` puts the command in parentheses as its second field, and
     * the command may hold spaces and parentheses of its own. Splitting on
     * whitespace would read the state character as a parent process id, and
     * every process would appear to be a child of something it is not.
     */
    #[Test]
    public function a_command_name_with_spaces_and_brackets_does_not_shift_the_fields(): void
    {
        $this->write(100, parent: 1, command: 'my (test) runner');
        $this->write(200, parent: 100, command: 'php (worker)');

        self::assertSame([200], ProcessTree::rootedAt(100, $this->procfs)->descendants());
    }

    #[Test]
    public function a_table_that_claims_a_process_is_its_own_ancestor_does_not_hang(): void
    {
        $this->write(100, parent: 200);
        $this->write(200, parent: 100);

        self::assertSame([200], ProcessTree::rootedAt(100, $this->procfs)->descendants());
    }

    #[Test]
    public function the_walk_is_bounded(): void
    {
        $this->write(1000, parent: 1);

        for ($pid = 1001; $pid <= 1000 + ProcessTree::MAXIMUM + 20; $pid++) {
            $this->write($pid, parent: 1000);
        }

        self::assertCount(ProcessTree::MAXIMUM, ProcessTree::rootedAt(1000, $this->procfs)->descendants());
    }

    #[Test]
    public function a_root_whose_own_entry_has_gone_still_reports_what_it_started(): void
    {
        $this->write(200, parent: 100);

        $tree = ProcessTree::rootedAt(100, $this->procfs);

        self::assertSame([200], $tree->descendants());
        self::assertStringContainsString('process 100 and 1 it started (200)', $tree->describe());
    }

    #[Test]
    public function without_a_process_filesystem_no_relationships_can_be_read(): void
    {
        $tree = ProcessTree::rootedAt(100, $this->procfs . '/missing');

        self::assertFalse(ProcessTree::isReadable($this->procfs . '/missing'));
        self::assertTrue($tree->isOnlyRoot(), 'A tree that cannot be read is the root alone, not a guess.');
    }

    /**
     * Liveness has a fallback where relationships do not: an unsignalled
     * `posix_kill` answers the narrower question of whether an id exists, which
     * is what confirming a termination needs. Null is reserved for a build with
     * neither route, and is a real answer rather than a no.
     */
    #[Test]
    public function liveness_falls_back_to_a_signal_where_there_is_no_process_filesystem(): void
    {
        $tree = ProcessTree::rootedAt(100, $this->procfs . '/missing');
        $self = (int) getmypid();

        if (function_exists('posix_kill')) {
            self::assertTrue($tree->isAlive($self));
            self::assertFalse($tree->isAlive(100), 'Nothing holds that id.');

            return;
        }

        self::assertNull($tree->isAlive($self));
    }

    /**
     * A zombie has stopped. {@see \Celyes\RalphPhp\Process\ProcessProbe} is
     * asked whether an id is *in use* and a zombie's is; termination asks
     * whether anything can still write, and it cannot.
     */
    #[Test]
    public function a_process_waiting_to_be_reaped_counts_as_stopped(): void
    {
        $this->write(100, parent: 1, state: 'R');
        $this->write(200, parent: 1, state: 'S');
        $this->write(300, parent: 1, state: 'Z');
        $this->write(400, parent: 1, state: 'X');

        $tree = ProcessTree::rootedAt(100, $this->procfs);

        self::assertTrue($tree->isAlive(100));
        self::assertTrue($tree->isAlive(200));
        self::assertFalse($tree->isAlive(300));
        self::assertFalse($tree->isAlive(400));
        self::assertFalse($tree->isAlive(500), 'A process with no entry has gone.');
        self::assertFalse($tree->isAlive(0), 'No process has an id below one.');
    }

    /**
     * The same reading, against the operating system rather than a fixture.
     */
    #[Test]
    public function it_finds_a_real_grandchild(): void
    {
        if (!ProcessTree::isReadable()) {
            self::markTestSkipped('This system has no process filesystem to read.');
        }

        [$process, $child, $grandchild] = $this->aRealTree();

        $tree = ProcessTree::rootedAt($child);

        self::assertSame($child, $tree->root());
        self::assertContains($grandchild, $tree->descendants(), $tree->describe());
        self::assertTrue($tree->isAlive($grandchild));
        self::assertSame([$grandchild, $child], $tree->deepestFirst());

        self::assertTrue(is_resource($process));
    }

    /**
     * A running child that started a child of its own.
     *
     * @return array{resource, int, int}
     */
    private function aRealTree(): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', self::SPAWNS_A_CHILD],
            [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'wb'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
        );

        self::assertIsResource($process, 'The child process could not be started.');
        $this->started[] = $process;

        $child = (int) proc_get_status($process)['pid'];
        $line = fgets($pipes[1]);

        self::assertIsString($line, 'The child never reported the process it started.');
        fclose($pipes[1]);

        $grandchild = (int) trim($line);
        $this->spawned[] = $grandchild;

        return [$process, $child, $grandchild];
    }

    /**
     * One entry in the written-out process filesystem.
     */
    private function write(int $pid, int $parent, string $command = 'php', string $state = 'S'): void
    {
        $directory = $this->procfs . '/' . $pid;

        self::assertTrue(mkdir($directory, 0o700, true), 'The fixture entry could not be created.');
        file_put_contents(
            $directory . '/stat',
            sprintf('%d (%s) %s %d 0 0 0 -1 4194304 0 0', $pid, $command, $state, $parent),
        );
    }
}
