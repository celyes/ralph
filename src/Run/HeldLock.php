<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Support\Clock;

/**
 * A repository lock this process is holding.
 *
 * A separate type from {@see RepositoryLock} so that "the lock of this project"
 * and "the lock I am holding" cannot be confused: the first can be asked about
 * at any time by `status`, and only the second can be released or written to.
 * Holding it is holding an open file handle, which is what makes the kernel
 * release the lock if this process dies without reaching
 * {@see self::release()}.
 *
 * {@see self::reclaimed()} is the one piece of history it carries: the record
 * of a runner that stopped without releasing, which this lock displaced. The
 * runner prints it, because a resume quietly taking over an interrupted run's
 * lock would hide the fact that there was something to take over.
 *
 * There are two ways to give it up. {@see self::release()} is the ordinary one
 * and says the repository is free. {@see self::retain()} is for a run that was
 * interrupted and could not confirm that the worker it started has stopped: the
 * handle closes, because the process is ending, but the record keeps claiming
 * the repository and names the processes that are the reason.
 */
final class HeldLock
{
    /**
     * The open, locked file handle, or null once released.
     *
     * @var resource|null
     */
    private $handle;

    /**
     * @param resource        $handle    the locked file handle
     * @param LockHolder|null $reclaimed the unreleased record this displaced
     */
    public function __construct(
        $handle,
        private readonly string $file,
        private LockHolder $holder,
        private readonly ?LockHolder $reclaimed,
        private readonly Clock $clock,
    ) {
        $this->handle = $handle;
    }

    /**
     * What the file says while this process holds it.
     */
    public function holder(): LockHolder
    {
        return $this->holder;
    }

    /**
     * The record of a runner that stopped without releasing, if there was one.
     */
    public function reclaimed(): ?LockHolder
    {
        return $this->reclaimed;
    }

    public function wasReclaimed(): bool
    {
        return $this->reclaimed !== null;
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * Name the run in the lock record, once the run row exists.
     *
     * @throws FilesystemException when the record cannot be written
     */
    public function record(string $runId): LockHolder
    {
        $this->holder = $this->holder->forRun($runId);
        $this->write();

        return $this->holder;
    }

    /**
     * One line for a terminal or a log header.
     */
    public function describe(): string
    {
        return $this->reclaimed === null
            ? sprintf('lock: %s', $this->holder->describe())
            : sprintf(
                'lock: %s, taken over from %s, which stopped without releasing it',
                $this->holder->describe(),
                $this->reclaimed->describe(),
            );
    }

    /**
     * Give up the lock while leaving the record claiming the repository.
     *
     * PROJECT.md section H, item 4's "releasing ownership only after
     * termination is confirmed", for the case where it cannot be. The handle is
     * closed — there is no choice about that, the process is ending and the
     * kernel would drop the `flock` anyway — but the record is written with the
     * surviving workers named and no release time, so the takeover check
     * ({@see RepositoryLock::acquire()}) refuses to start anything here while
     * one of them is alive.
     *
     * Idempotent in the same way {@see self::release()} is, and for the same
     * reason: both are reached from a `finally`.
     *
     * @param list<int> $workers processes asked to stop that could not be
     *        confirmed stopped; an empty list is an ordinary release, because
     *        there is then nothing to hold the repository for
     */
    public function retain(array $workers): void
    {
        if ($workers === []) {
            $this->release();

            return;
        }

        $handle = $this->handle;

        if ($handle === null) {
            return;
        }

        $this->holder = $this->holder->withWorkers($workers);

        try {
            $this->write();
        } catch (FilesystemException) {
            // The claim could not be written, so the next command will read a
            // record it can take over. Nothing better is available from here,
            // and failing the run over it would replace the interruption the
            // user is being told about with a filesystem complaint.
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $this->handle = null;
    }

    /**
     * Release the lock and record that it was released.
     *
     * Idempotent, because it is called from a `finally` that may run after a
     * path that has already released. A failure to write the record is
     * swallowed: the lock is released by closing the handle either way, and a
     * run must not end with a filesystem complaint about a bookkeeping line
     * instead of whatever actually happened to it.
     */
    public function release(): void
    {
        $handle = $this->handle;

        if ($handle === null) {
            return;
        }

        $this->holder = $this->holder->releasedAt($this->clock);

        try {
            $this->write();
        } catch (FilesystemException) {
            // Releasing matters; saying so in the file does not matter enough
            // to fail a command over.
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $this->handle = null;
    }

    /**
     * Write the current record over the file, keeping the lock.
     *
     * @throws FilesystemException when the record cannot be written
     */
    public function write(): void
    {
        $handle = $this->handle;

        if ($handle === null) {
            throw new FilesystemException(
                sprintf('The run lock at %s is no longer held and cannot be written to.', $this->file),
            );
        }

        $json = json_encode($this->holder->toStorage(), JSON_PRETTY_PRINT);

        if ($json === false
            || !ftruncate($handle, 0)
            || rewind($handle) === false
            || fwrite($handle, $json . PHP_EOL) === false
            || !fflush($handle)
        ) {
            throw new FilesystemException(
                sprintf('Unable to write the run lock record to %s.', $this->file),
                ['The lock itself is held; only the record of who holds it could not be written.'],
            );
        }
    }
}
