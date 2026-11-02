<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * Where one attempt's output goes: the terminal and the attempt's own log.
 *
 * Both destinations PROJECT.md section E, item 4 asks for, composed once. The
 * runner opens this when it starts an attempt and closes it when the attempt is
 * over — after the verification commands of section G, not after the agent — so
 * that one file holds the whole attempt in the order it happened: what the
 * agent printed, then what the checks printed about it.
 *
 * **It is one object rather than a pair** because the two destinations fail
 * differently and the difference matters. A terminal that cannot be written to
 * is nothing: the user redirected output, or closed a pager. A log file that
 * cannot be written to is a lost record, and {@see self::failure()} is how the
 * run gets to say so once the attempt is finished rather than by throwing out
 * of a read loop with an agent still running.
 *
 * **The log file is optional.** `ralph run` always has one; a command that
 * streams a worker without a task to file it under — and the tests of this
 * behaviour — do not, and {@see self::toTerminal()} is that case rather than a
 * null nobody checks for.
 */
final class AttemptLog implements OutputSink
{
    private function __construct(
        private readonly Sinks $sinks,
        private readonly ?LogFile $file,
    ) {
    }

    /**
     * Open the log of one attempt, and show its output as it arrives.
     *
     * @param Output|null $terminal where to echo the output, or null to only
     *        keep the file — a run whose output is not being watched
     *
     * @throws FilesystemException when the log file cannot be opened. Raised
     *         before the worker starts, which is the point of opening it here:
     *         an unwritable log is a setup problem with a readable cause, and
     *         not something to discover twenty minutes into an attempt.
     */
    public static function open(
        LogDirectory $logs,
        TaskGeneration $generation,
        int $attempt,
        ?Output $terminal = null,
    ): self {
        $file = $logs->openAgentLog($generation, $attempt);

        return new self(
            $terminal === null ? Sinks::of($file) : Sinks::of(new TerminalSink($terminal), $file),
            $file,
        );
    }

    /**
     * Show output without keeping it.
     */
    public static function toTerminal(?Output $terminal): self
    {
        return new self($terminal === null ? Sinks::none() : Sinks::of(new TerminalSink($terminal)), null);
    }

    /**
     * Keep nothing and show nothing.
     */
    public static function none(): self
    {
        return new self(Sinks::none(), null);
    }

    /**
     * The file this attempt is logged to, or null when it is not being kept.
     */
    public function path(): ?string
    {
        return $this->file?->path();
    }

    /**
     * Bytes of worker output the log has taken.
     */
    public function bytes(): int
    {
        return $this->file?->bytes() ?? 0;
    }

    /**
     * Why the log stopped being written, or null while it is intact.
     *
     * Only the file's failures. A terminal write that failed is not reported,
     * because there is nothing a user can do about output they chose not to
     * look at, and because the place it would be reported to is the terminal.
     */
    public function failure(): ?string
    {
        return $this->file?->failure();
    }

    public function hasFailed(): bool
    {
        return $this->failure() !== null;
    }

    public function write(OutputStream $stream, string $chunk): void
    {
        $this->sinks->write($stream, $chunk);
    }

    public function note(string $line): void
    {
        $this->sinks->note($line);
    }

    public function close(): void
    {
        $this->sinks->close();
    }
}
