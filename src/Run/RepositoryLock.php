<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Exception\LockException;
use Celyes\RalphPhp\Process\ProcessProbe;
use Celyes\RalphPhp\Process\SystemProbe;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;

/**
 * One runner per repository, and a resume that will not start on top of a
 * worker that has not stopped.
 *
 * PROJECT.md section H, item 1. Two runners in one repository would be sharing
 * a state database, a set of task branches, a worktree directory and the user's
 * one checklist file: they would hand the same pending task to two agents,
 * claim the same branch, and tick a box each for work only one of them did.
 * None of that is detectable after the fact, so it is prevented before anything
 * is read.
 *
 * **The lock is an `flock`, and that choice is the design.** The kernel
 * releases it when the holding process exits, however it exits — a clean
 * finish, a `kill -9`, a crashed PHP, a closed terminal. So the state "nobody
 * is running" needs no cleanup step that a dying process might not reach, and
 * there is no timeout to tune and no heartbeat to miss. A lock that is free is
 * a runner that has stopped.
 *
 * **The file it locks is never deleted.** {@see LockHolder} explains why: an
 * unlinked inode would let two processes hold two different locks at one path.
 * A released lock is a file whose record says it was released.
 *
 * **Taking it over is the confirmation the item asks for.** A lock file holding
 * a record that claims to be held, over a lock that is free, describes exactly
 * one thing: a runner that stopped without releasing. That is the state a
 * resume has to recognise, and recognising it is what lets resume continue the
 * interrupted run's work rather than refuse forever or — worse — start while
 * the old worker is still writing. Two cases are refused instead of taken over:
 *
 * - **Another machine.** A state directory on a network share can hold a record
 *   written on a host whose processes this one cannot see, and a free `flock`
 *   over such a filesystem proves nothing.
 * - **A process that is still there.** The record names a process the probe
 *   finds running, which contradicts the free lock. The honest readings are a
 *   recycled process id and a filesystem whose locks do not work, and the two
 *   are indistinguishable from here — so the run stops with the reason and the
 *   next action (section H, item 9) rather than guessing.
 * - **A worker that outlived its runner.** A run stopped with Ctrl+C terminates
 *   the agent's process tree and confirms it; where it cannot, it leaves the
 *   record claiming the repository and naming the survivors (section H, item
 *   4). Those are a live agent writing in a task worktree, not an ambiguity, so
 *   the refusal names the process to stop. One that has since exited is not
 *   found and the lock is taken over as usual.
 *
 * The same gate serves `run` and `resume`: the question "has the previous
 * runner stopped" has one answer and one safe way to get it, and a fresh run
 * started over a live runner's worktrees would do the same damage as a resume.
 * What differs is only the recorded purpose, so a refusal can say what is
 * already going on.
 */
final class RepositoryLock
{
    /**
     * The locked file, relative to the repository root.
     *
     * In the runtime directory because it is generated, ignored and meaningless
     * to copy; not configurable, for the same reason the state database is not
     * ({@see Defaults::STATE_FILE}) — a project able to point two runners at
     * two lock files would be a project with no lock.
     */
    public const string LOCK_FILE = Defaults::RUNTIME_DIRECTORY . '/run.lock';

    /**
     * Mode for a runtime directory this has to create.
     *
     * The same as `ralph init` and the state bootstrap use: its neighbours are
     * worker logs, which can hold anything an agent printed.
     */
    private const int RUNTIME_MODE = 0o700;

    public function __construct(
        private readonly string $file,
        private readonly Clock $clock = new SystemClock(),
        private readonly ProcessProbe $processes = new SystemProbe(),
    ) {
    }

    /**
     * The lock of a loaded project.
     */
    public static function forProject(
        Configuration $configuration,
        ?Clock $clock = null,
        ?ProcessProbe $processes = null,
    ): self {
        return self::in($configuration->projectRoot(), $clock, $processes);
    }

    /**
     * The lock of the project at a path.
     */
    public static function in(
        string $projectRoot,
        ?Clock $clock = null,
        ?ProcessProbe $processes = null,
    ): self {
        return new self(
            self::fileIn($projectRoot),
            $clock ?? new SystemClock(),
            $processes ?? new SystemProbe(),
        );
    }

    /**
     * Where a project's lock file lives.
     */
    public static function fileIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . self::LOCK_FILE);
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * What the lock file says, or null when it says nothing readable.
     *
     * Read without locking, because the callers are diagnostics — `status`
     * reporting what is going on, and a refusal naming who it is refusing for —
     * and taking a lock to read one would mean `status` could block a run.
     *
     * This is a claim and not a verdict: see {@see LockHolder}. "Held" here
     * means the last command to write the file had not released it when it
     * stopped writing.
     */
    public function holder(): ?LockHolder
    {
        if (!is_file($this->file)) {
            return null;
        }

        $contents = @file_get_contents($this->file);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? LockHolder::fromStorage($data) : null;
    }

    /**
     * Take the lock, or refuse to start.
     *
     * @param string      $purpose the command taking it, recorded so a conflict
     *        can name what is already running
     * @param string|null $runId   the run this is for, when it already exists;
     *        a `run` takes the lock before opening its row and writes the
     *        identifier in afterwards with {@see HeldLock::record()}
     *
     * @throws FilesystemException when the lock file cannot be created or
     *                             opened
     * @throws LockException when another runner holds this repository, or the
     *         runner that left the lock behind cannot be confirmed stopped
     */
    public function acquire(string $purpose, ?string $runId = null): HeldLock
    {
        $this->prepareDirectory(dirname($this->file));

        // `c+` creates the file without truncating it: the record of whoever
        // held it last has to survive long enough to be read, and truncating
        // before the lock is taken would destroy a live holder's record.
        $handle = @fopen($this->file, 'c+');

        if ($handle === false) {
            throw new FilesystemException(
                sprintf('Unable to open the run lock at %s.', $this->file),
                ['Ralph PHP will not start a run it cannot take the repository lock for.'],
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw $this->occupied($purpose);
        }

        $previous = $this->read($handle);
        $refusal = $previous === null ? null : $this->whyItCannotBeTakenOver($previous);

        if ($refusal !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);

            throw $refusal;
        }

        $held = new HeldLock(
            $handle,
            $this->file,
            LockHolder::here($purpose, $this->clock, $runId),
            $previous !== null && $previous->isHeld() ? $previous : null,
            $this->clock,
        );

        $held->write();

        return $held;
    }

    /**
     * Why a lock record left behind must not simply be taken over.
     *
     * Reached only while this process holds the `flock`, so the holder named by
     * the record is not holding it. Null means the takeover is safe.
     */
    private function whyItCannotBeTakenOver(LockHolder $previous): ?LockException
    {
        if ($previous->isReleased()) {
            return null;
        }

        if ($previous->isOn(LockHolder::thisHost()) === false) {
            return new LockException(
                sprintf(
                    'The run lock at %s was taken on another machine (%s) and has not been released.',
                    $this->file,
                    $previous->host() ?? 'unnamed',
                ),
                [
                    sprintf('It records %s.', $previous->describe()),
                    'Whether that runner has stopped cannot be seen from this machine, and a file '
                        . 'lock over a shared filesystem is not proof that it has.',
                    sprintf('Run "ralph status" on %s, or delete %s once you know nothing is '
                        . 'running there.', $previous->host() ?? 'that machine', $this->file),
                ],
            );
        }

        $worker = $this->survivingWorker($previous);

        if ($worker !== null) {
            return new LockException(
                sprintf(
                    'The run that held %s was interrupted and its worker process %d is still running.',
                    $this->file,
                    $worker,
                ),
                [
                    sprintf('It records %s.', $previous->describe()),
                    'Ralph PHP stopped that worker and could not confirm it had gone, so it left the '
                        . 'repository claimed rather than release it over an agent that may still be '
                        . 'writing in a task worktree.',
                    sprintf('Stop process %d, then run the command again; the lock is taken over '
                        . 'automatically once nothing it names is running.', $worker),
                ],
            );
        }

        // A record this process wrote itself: whatever went wrong between
        // taking the lock and releasing it, the runner it names is this one,
        // and nothing can be overlapping.
        if ($previous->isProcess(LockHolder::thisPid())) {
            return null;
        }

        $pid = $previous->pid();

        if ($pid !== null && $this->processes->isRunning($pid) === true) {
            return new LockException(
                sprintf(
                    'The run lock at %s is recorded as held by process %d, which is still running.',
                    $this->file,
                    $pid,
                ),
                [
                    sprintf('It records %s.', $previous->describe()),
                    'The lock itself is free, which normally means that process has stopped, so the '
                        . 'two readings disagree: either the process id has been reused by unrelated '
                        . 'work, or this filesystem does not honour file locks.',
                    'Ralph PHP will not guess which, because starting over a runner that is still '
                        . 'writing would corrupt its branches and its state database.',
                    sprintf('Check that process, then delete %s if it is not a Ralph PHP run.', $this->file),
                ],
            );
        }

        return null;
    }

    /**
     * A worker the previous run left behind that is still running, if any.
     *
     * The second half of PROJECT.md section H, item 4. The runner that wrote
     * this record had already stopped — its `flock` is free — but a run
     * interrupted with a worker it could not confirm dead names that worker
     * instead of releasing ({@see LockHolder::withWorkers()}), and starting
     * here while it is alive would put a second agent in a worktree the first
     * is still writing to.
     *
     * Workers that have since gone are simply not found, so the next command
     * takes the lock over in the ordinary way: the record is a reason to wait,
     * not a lock-out that has to be cleared by hand.
     */
    private function survivingWorker(LockHolder $previous): ?int
    {
        foreach ($previous->workers() as $pid) {
            if ($this->processes->isRunning($pid) === true) {
                return $pid;
            }
        }

        return null;
    }

    /**
     * The refusal for a lock a live process is holding.
     *
     * The record is read without the lock — it cannot be read with it, the
     * point being that somebody else has it — so it may be a moment out of
     * date. It is used for the message only.
     */
    private function occupied(string $purpose): LockException
    {
        $holder = $this->holder();

        return new LockException(
            sprintf(
                'Another Ralph PHP runner is working in this repository; "%s" will not start beside it.',
                $purpose,
            ),
            [
                $holder === null
                    ? sprintf('The run lock at %s is held, and its record could not be read.', $this->file)
                    : sprintf('The lock records %s.', $holder->describe()),
                'Two runners in one repository would hand the same task to two agents and tick a '
                    . 'box each for work only one of them did.',
                'Run "ralph status" to see what it is doing, or wait for it to finish.',
            ],
        );
    }

    /**
     * Read the record through the handle this process holds the lock on.
     *
     * @param resource $handle
     */
    private function read($handle): ?LockHolder
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? LockHolder::fromStorage($data) : null;
    }

    /**
     * @throws FilesystemException when the directory is missing and cannot be
     *                             created
     */
    private function prepareDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (is_file($directory)) {
            throw new FilesystemException(
                sprintf('A file exists at %s, where the runtime directory goes.', $directory),
                ['Move the file out of the way, then run the command again.'],
            );
        }

        if (!@mkdir($directory, self::RUNTIME_MODE, true) && !is_dir($directory)) {
            throw new FilesystemException(
                sprintf('Unable to create the runtime directory %s.', $directory),
                ['The repository lock is taken there before a run starts.'],
            );
        }
    }
}
