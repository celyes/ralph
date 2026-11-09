<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Event;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\TaskId;

/**
 * What a run did, as JSON lines another program can follow.
 *
 * PROJECT.md section I, item 3. The terminal output is written for a person
 * reading it as it happens; this is the same run written for something that has
 * to act on it — a wrapper script, a CI job, an editor extension — and the two
 * are separate because the first is prose and may be reworded in any release,
 * while the second is an interface with a version number on it ({@see Event}).
 *
 * **One file per project, appended to across runs.** Every line carries the run
 * it belongs to, so a consumer can follow one stable path with `tail -f`
 * instead of discovering a new file per run and racing the runner to open it.
 * It lives in the runtime directory, which is Git-ignored and is what cleanup
 * owns.
 *
 * **One line, one `fwrite`, flushed.** A line is written whole and the handle is
 * flushed after it, so a reader following the file sees complete events rather
 * than half of one, and sees them while the run is still going. The file is
 * opened for appending, which is also what keeps a second writer — a `resume`
 * in another terminal, say — from writing over this one's lines rather than
 * after them.
 *
 * **A stream that cannot be written does not stop the run.** The same decision
 * {@see \Celyes\RalphPhp\Log\LogFile} makes, for a stronger reason: an event
 * stream is a report about the work, and abandoning a running agent because a
 * report could not be filed would be the tool doing real harm over a courtesy.
 * The first failure is remembered ({@see self::failure()}) for whoever owns the
 * stream to mention, and nothing is written afterwards — a stream with a hole in
 * the middle would be worse than one that stops, because a consumer cannot see
 * the hole.
 *
 * **The run's events are numbered here.** The sequence is this object's, which
 * is what makes it gapless: it counts the events of one run in the order they
 * were emitted, rather than lines of a file that other runs are also appending
 * to. See {@see Event} for why that, and not the timestamp, is the order.
 */
final class EventStream
{
    /**
     * Mode for the created runtime directory and the stream itself.
     *
     * Kept to the owner, like the logs: the payloads quote what an agent said
     * and what a failing check printed.
     */
    private const int DIRECTORY_MODE = 0o700;

    private const int FILE_MODE = 0o600;

    /**
     * The open file, or null before it is opened and once it is closed.
     *
     * @var resource|null
     */
    private mixed $handle = null;

    /**
     * Events of this run written so far, which is the last sequence used.
     */
    private int $sequence = 0;

    /**
     * What went wrong the first time something did, if anything has.
     */
    private ?string $failure = null;

    private function __construct(
        private readonly string $path,
        private readonly string $runId,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Where a project's event stream lives.
     */
    public static function fileIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . Defaults::EVENTS_FILE);
    }

    /**
     * The stream a run writes its events to.
     *
     * Opened here rather than at the first write, so that an unwritable stream
     * is known about while the run is still being announced rather than
     * discovered in the middle of a task.
     */
    public static function forRun(string $projectRoot, string $runId, ?Clock $clock = null): self
    {
        return self::appendingTo(self::fileIn($projectRoot), $runId, $clock);
    }

    /**
     * The stream at a given path, opened for appending.
     */
    public static function appendingTo(string $path, string $runId, ?Clock $clock = null): self
    {
        $stream = new self($path, $runId, $clock ?? new SystemClock());
        $stream->open();

        return $stream;
    }

    /**
     * Absolute path to the stream.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The run every event of this stream belongs to.
     */
    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * Events written for this run.
     */
    public function written(): int
    {
        return $this->sequence;
    }

    /**
     * Why the stream stopped being written to, or null while it is intact.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    /**
     * The run has been opened and is about to work.
     *
     * @param array<string, mixed> $data
     */
    public function runStarted(array $data = []): ?Event
    {
        return $this->append(EventKind::RunStarted, Event::STARTED, null, $data);
    }

    /**
     * A task has been taken on.
     *
     * @param array<string, mixed> $data
     */
    public function taskStarted(TaskId $task, array $data = []): ?Event
    {
        return $this->append(EventKind::TaskStarted, Event::STARTED, $task, $data);
    }

    /**
     * A task's pass ended, and this is how.
     *
     * @param string $outcome the pass's lifecycle state
     * @param array<string, mixed> $data
     */
    public function taskFinished(TaskId $task, string $outcome, array $data = []): ?Event
    {
        return $this->append(EventKind::TaskFinished, $outcome, $task, $data);
    }

    /**
     * The run ended, and this is why.
     *
     * @param string $outcome the run's stop kind
     * @param array<string, mixed> $data
     */
    public function runFinished(string $outcome, array $data = []): ?Event
    {
        return $this->append(EventKind::RunFinished, $outcome, null, $data);
    }

    /**
     * Write one event, and give back what was written.
     *
     * Null when nothing was: a stream that has already failed, or has been
     * closed, is silent rather than fatal. The caller is the loop, which has a
     * run to get on with.
     *
     * @param array<string, mixed> $data
     */
    public function append(EventKind $kind, string $outcome, ?TaskId $task = null, array $data = []): ?Event
    {
        if ($this->failure !== null || !is_resource($this->handle)) {
            return null;
        }

        $event = Event::of(
            $this->sequence + 1,
            Timestamp::now($this->clock),
            $this->runId,
            $kind,
            $outcome,
            $task,
            $data,
        );

        if (!$this->put($event->encode() . "\n")) {
            return null;
        }

        $this->sequence = $event->sequence();

        return $event;
    }

    /**
     * Close the stream, if it is open.
     *
     * Safe to call more than once, and safe to call on a stream that never
     * opened: the loop closes it in a `finally`, which is reached by the paths
     * where something had already gone wrong.
     */
    public function close(): void
    {
        $handle = $this->handle;
        $this->handle = null;

        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Open the file, remembering a failure rather than raising it.
     */
    private function open(): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            $this->failure = sprintf('the directory %s could not be created', $directory);

            return;
        }

        $existed = file_exists($this->path);
        $handle = @fopen($this->path, 'ab');

        if (!is_resource($handle)) {
            $this->failure = sprintf('%s could not be opened for appending', $this->path);

            return;
        }

        if (!$existed) {
            @chmod($this->path, self::FILE_MODE);
        }

        $this->handle = $handle;
    }

    /**
     * Write bytes, remembering the first failure and then giving up.
     *
     * Flushed per line so that a consumer following the file sees an event when
     * it happened rather than when PHP's buffer filled up — which, for a run
     * whose tasks take minutes, would be long enough to make the stream useless
     * for watching.
     */
    private function put(string $line): bool
    {
        $handle = $this->handle;

        if (!is_resource($handle)) {
            return false;
        }

        $written = @fwrite($handle, $line);

        if ($written !== strlen($line)) {
            $this->failure = sprintf('%s could not be written to', $this->path);

            return false;
        }

        @fflush($handle);

        return true;
    }
}
