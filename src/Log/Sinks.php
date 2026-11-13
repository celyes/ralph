<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Throwable;

/**
 * Several sinks written to as though they were one.
 *
 * The composite exists so that "to the terminal and to the task's log" — the
 * two destinations PROJECT.md section E, item 4 asks for — is a fact about how
 * a run was assembled rather than a branch inside
 * {@see \Celyes\RalphPhp\Process\ProcessRunner}. The runner forwards a chunk to
 * one sink; whether that reaches a terminal, a file, both, or nothing is
 * decided where the attempt is set up.
 *
 * The fan-out is what makes one rendering serve both destinations: a chunk and
 * what it is ({@see Emphasis}) go to every sink, and each decides what to do
 * with the tag — the terminal colours by it, the log file ignores it. So the
 * weighting a reader sees costs nothing in the file they grep.
 *
 * {@see self::none()} is the null case, and it is a real sink rather than a
 * null the runner has to test for: a `ralph status` that runs a Git command
 * wants no streaming at all, and that should be the same code path as an
 * attempt that streams to two places.
 *
 * **One broken sink does not take the others down.** A sink is not allowed to
 * throw ({@see OutputSink}), but if one does, the chunk still reaches the
 * remaining sinks and the offender is dropped for the rest of the run. The
 * alternative — a terminal write failing on a closed pipe and taking the
 * attempt's log file with it — would lose the copy that was worth keeping.
 */
final class Sinks implements OutputSink
{
    /**
     * @var list<OutputSink> the sinks still being written to
     */
    private array $sinks;

    /**
     * @var list<OutputSink> sinks dropped for throwing, kept only to be closed
     */
    private array $broken = [];

    /**
     * @param list<OutputSink> $sinks
     */
    private function __construct(array $sinks)
    {
        $this->sinks = $sinks;
    }

    /**
     * Write to each of these, in the order given.
     *
     * Nested composites are flattened, so that adding a sink to an existing fan
     * -out does not build a tree whose depth decides the order chunks arrive
     * in.
     */
    public static function of(OutputSink ...$sinks): self
    {
        $flattened = [];

        foreach ($sinks as $sink) {
            if ($sink instanceof self) {
                foreach ($sink->sinks as $nested) {
                    $flattened[] = $nested;
                }

                continue;
            }

            $flattened[] = $sink;
        }

        return new self($flattened);
    }

    /**
     * Write nowhere.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Whether anything is actually being written to.
     *
     * Worth asking before composing a note nobody will read, and the honest
     * answer to "is this attempt being logged".
     */
    public function isEmpty(): bool
    {
        return $this->sinks === [];
    }

    /**
     * How many sinks are still accepting output.
     */
    public function count(): int
    {
        return count($this->sinks);
    }

    public function write(OutputStream $stream, string $chunk, Emphasis $emphasis = Emphasis::Plain): void
    {
        $this->each(static function (OutputSink $sink) use ($stream, $chunk, $emphasis): void {
            $sink->write($stream, $chunk, $emphasis);
        });
    }

    public function note(string $line): void
    {
        $this->each(static function (OutputSink $sink) use ($line): void {
            $sink->note($line);
        });
    }

    public function close(): void
    {
        // Every sink is closed, including ones dropped earlier for throwing:
        // a sink that failed on a write may still be holding a file handle,
        // and the file is the thing worth saving.
        $sinks = [...$this->sinks, ...$this->broken];
        $this->sinks = [];
        $this->broken = [];

        foreach ($sinks as $sink) {
            try {
                $sink->close();
            } catch (Throwable) {
                // Closing is the last thing that happens to a sink; there is
                // nowhere left to report this to, and the others still have to
                // be closed.
            }
        }
    }

    /**
     * Do something to each sink, dropping any that throws.
     *
     * @param callable(OutputSink): void $action
     */
    private function each(callable $action): void
    {
        $surviving = [];

        foreach ($this->sinks as $sink) {
            try {
                $action($sink);
                $surviving[] = $sink;
            } catch (Throwable) {
                // A sink that throws has broken its contract and cannot be
                // trusted with the rest of the output. Dropped rather than
                // retried, so one broken sink costs one failed write and not
                // one per chunk for the next twenty minutes. It is still
                // closed, since it may be holding a file open.
                $this->broken[] = $sink;
            }
        }

        $this->sinks = $surviving;
    }
}
