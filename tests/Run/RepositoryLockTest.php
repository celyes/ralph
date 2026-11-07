<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\LockException;
use Celyes\RalphPhp\Process\KnownProcesses;
use Celyes\RalphPhp\Run\HeldLock;
use Celyes\RalphPhp\Run\LockHolder;
use Celyes\RalphPhp\Run\RepositoryLock;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One runner per repository, and the question a resume has to answer first.
 *
 * PROJECT.md section H, item 1 is two promises. The first is that a second
 * runner cannot start beside a live one, and it is tested against a real
 * `flock` on a real file: two handles opened on one file conflict whether or
 * not they belong to the same process, so the overlap can be created here
 * exactly as it would occur between two terminals.
 *
 * The second is that a lock left behind is taken over only once its runner is
 * confirmed stopped. The three readings that confirmation can produce —
 * stopped, still running, and not answerable from this machine — are driven
 * through {@see KnownProcesses} and a hand-written lock record, because the
 * alternative is abandoning real processes and hoping their ids survive to the
 * assertion.
 *
 * The lock file is read back as JSON throughout rather than through the class
 * that wrote it, since it is the one piece of state a *different* process has
 * to understand.
 */
#[CoversClass(RepositoryLock::class)]
#[CoversClass(HeldLock::class)]
#[CoversClass(LockHolder::class)]
final class RepositoryLockTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function it_takes_the_lock_and_records_who_holds_it(): void
    {
        $lock = $this->lockOf();

        $held = $lock->acquire('run');

        try {
            self::assertTrue($held->isHeld());
            self::assertFalse($held->wasReclaimed());
            self::assertNull($held->reclaimed());
            self::assertFileExists($lock->path());

            $record = $this->recordOn($lock);
            self::assertSame('run', $record['purpose']);
            self::assertSame(getmypid(), $record['pid']);
            self::assertSame(gethostname(), $record['host']);
            self::assertNull($record['run_id']);
            self::assertNull($record['released_at']);
            self::assertSame('2026-11-02T09:00:00+00:00', $record['acquired_at']);
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function it_creates_the_runtime_directory_when_it_is_missing(): void
    {
        self::assertDirectoryDoesNotExist($this->project->path('.ralph/runtime'));

        $held = $this->lockOf()->acquire('run');
        $held->release();

        self::assertDirectoryExists($this->project->path('.ralph/runtime'));
    }

    #[Test]
    public function the_lock_file_lives_in_the_projects_runtime_directory(): void
    {
        self::assertSame(
            $this->project->path('.ralph/runtime/run.lock'),
            RepositoryLock::fileIn($this->project->root()),
        );

        self::assertSame(
            RepositoryLock::fileIn($this->project->root()),
            RepositoryLock::forProject(
                (new ConfigurationLoader())->fromArray([], $this->project->root()),
            )->path(),
        );
    }

    /**
     * The promise itself: a second runner does not start.
     */
    #[Test]
    public function it_refuses_a_second_runner_while_the_lock_is_held(): void
    {
        $held = $this->lockOf()->acquire('run');
        $held->record('run-20261102-090000-abcdef');

        try {
            $this->lockOf()->acquire('resume');
            self::fail('A second runner took a lock that was held.');
        } catch (LockException $refusal) {
            self::assertStringContainsString('Another Ralph PHP runner is working', $refusal->getMessage());
            self::assertStringContainsString('"resume" will not start beside it', $refusal->getMessage());

            // The refusal has to name what is already going on, or there is
            // nothing for the reader to go and look at.
            $hints = implode("\n", $refusal->hints());
            self::assertStringContainsString('run-20261102-090000-abcdef', $hints);
            self::assertStringContainsString((string) getmypid(), $hints);
            self::assertStringContainsString('ralph status', $hints);
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function it_takes_the_lock_again_once_it_has_been_released(): void
    {
        $first = $this->lockOf()->acquire('run');
        $first->release();

        self::assertFalse($first->isHeld());
        self::assertNotNull($this->recordOn($this->lockOf())['released_at']);

        // A release is a record, not a deleted file: the inode the lock lives
        // on has to stay the same one.
        self::assertFileExists($this->lockOf()->path());

        $second = $this->lockOf()->acquire('run');

        try {
            // Nothing was taken over, because nothing had been left held.
            self::assertFalse($second->wasReclaimed());
        } finally {
            $second->release();
        }
    }

    #[Test]
    public function releasing_twice_does_nothing_the_second_time(): void
    {
        $held = $this->lockOf()->acquire('run');
        $held->release();
        $released = $this->recordOn($this->lockOf())['released_at'];

        $held->release();

        self::assertFalse($held->isHeld());
        self::assertSame($released, $this->recordOn($this->lockOf())['released_at']);
    }

    #[Test]
    public function naming_the_run_keeps_the_moment_the_lock_was_taken(): void
    {
        $held = $this->lockOf()->acquire('run');

        try {
            $holder = $held->record('run-20261102-090000-abcdef');

            self::assertSame('run-20261102-090000-abcdef', $holder->runId());
            self::assertSame('2026-11-02T09:00:00+00:00', $holder->acquiredAt()->value());

            $record = $this->recordOn($this->lockOf());
            self::assertSame('run-20261102-090000-abcdef', $record['run_id']);
            self::assertSame('2026-11-02T09:00:00+00:00', $record['acquired_at']);
        } finally {
            $held->release();
        }
    }

    /**
     * The state a resume exists for: a runner that stopped without releasing.
     *
     * The lock is free — the process holding it is gone, which is the kernel's
     * own doing — and the record still claims to be held. Nothing else produces
     * that combination, so it is taken over, and the record of what was
     * displaced is kept for the runner to print.
     */
    #[Test]
    public function it_takes_over_a_lock_left_by_a_runner_that_has_stopped(): void
    {
        $this->givenAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa');

        $held = $this->lockOf(KnownProcesses::none())->acquire('resume');

        try {
            self::assertTrue($held->wasReclaimed());
            $reclaimed = $held->reclaimed();
            self::assertNotNull($reclaimed);
            self::assertSame(4242, $reclaimed->pid());
            self::assertSame('run-20261101-120000-aaaaaa', $reclaimed->runId());
            self::assertStringContainsString('stopped without releasing it', $held->describe());
            self::assertStringContainsString('run-20261101-120000-aaaaaa', $held->describe());

            // The file now records this process, not the one it displaced.
            self::assertSame(getmypid(), $this->recordOn($this->lockOf())['pid']);
            self::assertSame('resume', $this->recordOn($this->lockOf())['purpose']);
        } finally {
            $held->release();
        }
    }

    /**
     * Confirmation, not assumption: the recorded process is still there.
     *
     * A free lock and a live holder disagree, and the two explanations — a
     * reused process id, a filesystem whose locks do nothing — cannot be told
     * apart from here. Section H, item 9: stop with the reason and the next
     * action.
     */
    #[Test]
    public function it_refuses_to_take_over_while_the_recorded_process_is_still_running(): void
    {
        $this->givenAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa');

        try {
            $this->lockOf(KnownProcesses::running(4242))->acquire('resume');
            self::fail('A resume started over a process that is still running.');
        } catch (LockException $refusal) {
            self::assertStringContainsString('process 4242, which is still running', $refusal->getMessage());

            $hints = implode("\n", $refusal->hints());
            self::assertStringContainsString('run-20261101-120000-aaaaaa', $hints);
            self::assertStringContainsString('process id has been reused', $hints);
            self::assertStringContainsString($this->lockOf()->path(), $hints);
        }
    }

    #[Test]
    public function it_refuses_to_take_over_a_lock_recorded_on_another_machine(): void
    {
        $this->givenAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa', host: 'another-machine');

        try {
            $this->lockOf(KnownProcesses::none())->acquire('resume');
            self::fail('A resume took over a lock from a machine it cannot see.');
        } catch (LockException $refusal) {
            self::assertStringContainsString('another machine (another-machine)', $refusal->getMessage());

            $hints = implode("\n", $refusal->hints());
            self::assertStringContainsString('cannot be seen from this machine', $hints);
            self::assertStringContainsString('another-machine', $hints);
        }
    }

    /**
     * A record this process wrote itself is not an overlap.
     *
     * The only runner this process could overlap with is itself, so a lock it
     * apparently failed to release is taken back rather than refused — a
     * refusal here would wedge a long-lived process out of its own repository.
     */
    #[Test]
    public function it_takes_back_a_lock_its_own_process_left_held(): void
    {
        $pid = getmypid();
        self::assertNotFalse($pid);
        $this->givenAbandonedLock(pid: $pid, runId: 'run-20261101-120000-aaaaaa');

        $held = $this->lockOf(KnownProcesses::running($pid))->acquire('run');

        try {
            self::assertTrue($held->wasReclaimed());
        } finally {
            $held->release();
        }
    }

    /**
     * A system that cannot look a process up is not a reason to refuse.
     *
     * The free `flock` is the proof that no live process on this machine holds
     * the lock; the probe only adds the contradictory case. Where it cannot
     * answer, there is no contradiction to act on.
     */
    #[Test]
    public function it_takes_over_when_the_system_cannot_say_whether_a_process_exists(): void
    {
        $this->givenAbandonedLock(pid: 4242);

        $held = $this->lockOf(KnownProcesses::unknowable())->acquire('resume');

        try {
            self::assertTrue($held->wasReclaimed());
        } finally {
            $held->release();
        }
    }

    /**
     * An unreadable record is treated as no record.
     *
     * The contents are generated and exist only to explain a refusal. A project
     * that could not be run again because a full disk truncated its lock file
     * would be a worse outcome than a takeover that says less about what it
     * displaced.
     */
    #[Test]
    public function it_takes_over_a_lock_whose_record_cannot_be_read(): void
    {
        $this->project->write('.ralph/runtime/run.lock', "{not json at all\n");

        $held = $this->lockOf(KnownProcesses::none())->acquire('run');

        try {
            self::assertFalse($held->wasReclaimed());
            self::assertSame(getmypid(), $this->recordOn($this->lockOf())['pid']);
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function it_reports_what_the_lock_file_says_without_taking_it(): void
    {
        self::assertNull($this->lockOf()->holder(), 'A project that never ran has no holder.');

        $this->givenAbandonedLock(pid: 4242, runId: 'run-20261101-120000-aaaaaa');

        $holder = $this->lockOf()->holder();
        self::assertNotNull($holder);
        self::assertTrue($holder->isHeld());
        self::assertSame(4242, $holder->pid());
        self::assertSame('run-20261101-120000-aaaaaa', $holder->runId());

        // Reading it did not take it: `status` must never be able to block a
        // run.
        $held = $this->lockOf(KnownProcesses::none())->acquire('run');
        $held->release();
    }

    #[Test]
    public function a_record_describes_itself_for_a_person_to_act_on(): void
    {
        $holder = LockHolder::fromStorage([
            'purpose' => 'run',
            'host' => 'workstation',
            'pid' => 4242,
            'run_id' => 'run-20261101-120000-aaaaaa',
            'acquired_at' => '2026-11-01T12:00:00+00:00',
            'released_at' => null,
        ]);

        self::assertNotNull($holder);
        self::assertSame(
            'run-20261101-120000-aaaaaa, taken by process 4242 on workstation '
                . 'at 2026-11-01T12:00:00+00:00',
            $holder->describe(),
        );

        $released = $holder->releasedAt(FrozenClock::at('2026-11-01T12:30:00+00:00'));
        self::assertTrue($released->isReleased());
        self::assertStringContainsString('and released at 2026-11-01T12:30:00+00:00', $released->describe());
        self::assertSame('2026-11-01T12:00:00+00:00', $released->acquiredAt()->value());

        // A record with no run yet names the command instead, because "a lock
        // is held" with nothing attached is not something anyone can act on.
        $anonymous = LockHolder::here('retry', FrozenClock::at('2026-11-01T12:00:00+00:00'));
        self::assertStringContainsString('a "retry" command', $anonymous->describe());
    }

    #[Test]
    public function a_record_that_says_nothing_usable_is_no_record(): void
    {
        self::assertNull(LockHolder::fromStorage([]));
        self::assertNull(LockHolder::fromStorage(['purpose' => 'run']));
        self::assertNull(LockHolder::fromStorage(['purpose' => '  ', 'acquired_at' => '2026-11-01T12:00:00+00:00']));
        self::assertNull(LockHolder::fromStorage(['purpose' => 'run', 'acquired_at' => 'the other day']));

        // What it does read: a record missing everything optional.
        $sparse = LockHolder::fromStorage([
            'purpose' => 'run',
            'acquired_at' => '2026-11-01T12:00:00+00:00',
            'host' => '',
            'pid' => 0,
            'run_id' => null,
        ]);
        self::assertNotNull($sparse);
        self::assertNull($sparse->host());
        self::assertNull($sparse->pid());
        self::assertNull($sparse->runId());
        self::assertNull($sparse->isOn('workstation'), 'An unnamed host cannot be compared.');
        self::assertFalse($sparse->isProcess(null));
        self::assertStringContainsString('an unrecorded process', $sparse->describe());
    }

    #[Test]
    public function it_refuses_a_lock_path_it_cannot_create(): void
    {
        $this->project->write('.ralph/runtime', "not a directory\n");

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('where the runtime directory goes');

        $this->lockOf()->acquire('run');
    }

    /**
     * A lock file holding a record that claims to be held, over a free lock.
     */
    private function givenAbandonedLock(int $pid, ?string $runId = null, ?string $host = null): void
    {
        $this->project->write('.ralph/runtime/run.lock', (string) json_encode([
            'purpose' => 'run',
            'host' => $host ?? gethostname(),
            'pid' => $pid,
            'run_id' => $runId,
            'acquired_at' => Timestamp::of(FrozenClock::at('2026-11-01T12:00:00+00:00')->now())->value(),
            'released_at' => null,
        ]));
    }

    private function lockOf(?KnownProcesses $processes = null): RepositoryLock
    {
        return RepositoryLock::in(
            $this->project->root(),
            FrozenClock::at('2026-11-02T09:00:00+00:00'),
            $processes ?? KnownProcesses::none(),
        );
    }

    /**
     * The lock file as another process would read it.
     *
     * @return array{purpose: string|null, host: string|null, pid: int|null, run_id: string|null, acquired_at: string|null, released_at: string|null}
     */
    private function recordOn(RepositoryLock $lock): array
    {
        $contents = file_get_contents($lock->path());
        self::assertIsString($contents);

        $data = json_decode($contents, true);
        self::assertIsArray($data);

        /** @var array{purpose: string|null, host: string|null, pid: int|null, run_id: string|null, acquired_at: string|null, released_at: string|null} $data */
        return $data;
    }
}
