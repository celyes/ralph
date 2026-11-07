<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\KnownProcesses;
use Celyes\RalphPhp\Process\SystemProbe;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a process id is in use.
 *
 * Asked by {@see \Celyes\RalphPhp\Run\RepositoryLock} about a process it did
 * not start, to decide whether a lock left behind may be taken over. The
 * answers it has to get right are "this process", which certainly exists, and
 * an id no process can have — and the third answer, "this environment cannot
 * tell", which is what keeps a system without a `/proc` from being reported as
 * a system where nothing is running.
 */
#[CoversClass(SystemProbe::class)]
#[CoversClass(KnownProcesses::class)]
final class SystemProbeTest extends TestCase
{
    #[Test]
    public function it_finds_the_process_it_is_running_in(): void
    {
        $pid = getmypid();
        self::assertNotFalse($pid, 'This platform cannot name its own process.');

        self::assertTrue((new SystemProbe())->isRunning($pid));
    }

    #[Test]
    public function no_process_has_an_id_below_one(): void
    {
        $probe = new SystemProbe();

        self::assertFalse($probe->isRunning(0));
        self::assertFalse($probe->isRunning(-1));
    }

    #[Test]
    public function it_reports_an_unused_id_as_gone(): void
    {
        if (!is_dir('/proc')) {
            self::markTestSkipped('There is no /proc to ask, and a free id cannot be chosen reliably.');
        }

        self::assertFalse((new SystemProbe())->isRunning($this->anIdNothingIsUsing()));
    }

    /**
     * A system with neither `/proc` nor `posix_kill` answers nothing.
     *
     * Reached here by pointing the probe at a directory that is not a process
     * filesystem; on a build with the POSIX extension the fallback answers
     * instead, and both are real answers rather than guesses.
     */
    #[Test]
    public function it_admits_when_it_cannot_tell(): void
    {
        $elsewhere = TemporaryDirectory::create('ralph-not-procfs');

        try {
            $probe = new SystemProbe($elsewhere . '/missing');
            $pid = getmypid();
            self::assertNotFalse($pid);

            $answer = $probe->isRunning($pid);

            if (function_exists('posix_kill')) {
                self::assertTrue($answer, 'The POSIX fallback can see this process.');
            } else {
                self::assertNull($answer);
            }
        } finally {
            TemporaryDirectory::remove($elsewhere);
        }
    }

    #[Test]
    public function the_test_probe_answers_from_what_it_was_given(): void
    {
        self::assertTrue(KnownProcesses::running(7, 9)->isRunning(7));
        self::assertFalse(KnownProcesses::running(7, 9)->isRunning(8));
        self::assertFalse(KnownProcesses::none()->isRunning(7));
        self::assertNull(KnownProcesses::unknowable()->isRunning(7));
    }

    /**
     * An id in range that nothing holds.
     */
    private function anIdNothingIsUsing(): int
    {
        for ($pid = 30000; $pid > 1; $pid--) {
            if (!is_dir('/proc/' . $pid)) {
                return $pid;
            }
        }

        self::markTestSkipped('Every process id on this machine is in use.');
    }
}
