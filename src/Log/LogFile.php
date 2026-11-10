<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Support\Secrets;

/**
 * A worker's output on disk, written while the worker runs.
 *
 * The keeping half of PROJECT.md section E, item 4, and the file
 * `ralph logs TASK-ID` will read. Written as it arrives rather than at the end
 * for two reasons: an attempt killed by Ctrl+C or by its timeout still leaves
 * everything the agent said up to that moment — which is usually the
 * explanation — and a user can `tail -f` the log of a run they are not watching
 * the terminal of.
 *
 * **Both streams in one file, in arrival order.** The alternative, a file each,
 * loses the thing a reader actually needs: that the error appeared *after* the
 * line about rewriting the test. Which stream a line came from is recorded with
 * a marker written whenever the active stream changes, so the ordering and the
 * attribution both survive.
 *
 * **The one place the bytes are not exact.** If a stream changes over mid-line
 * — the worker was half-way through a line on standard output when something
 * arrived on standard error — a newline is inserted so the marker begins on its
 * own line. Without it the marker would be buried inside the worker's sentence
 * and the log would be less readable than no marker at all. Nothing else is
 * added, removed or re-encoded: a worker's output may be any bytes at all,
 * including invalid UTF-8 and terminal escapes, and this is a copy of it rather
 * than a rendering.
 *
 * **Notes are marked as ours, and are redacted.** Lines Ralph PHP writes —
 * which invocation this is, what it exited with — are prefixed with
 * {@see self::NOTE_PREFIX}, and the secrets the project declared are kept out
 * of them ({@see Secrets}). A log
 * where the package's observations and the agent's output look the same is a
 * log in which a model can write "--- exited with status 0" and have a reader
 * believe it. The prefix does not make forgery impossible, and it is not
 * treated as evidence anywhere: the exit code the runner acts on comes from the
 * process, and the state store is what later commands read.
 *
 * **The worker's own bytes are not redacted, and that is the file's purpose.**
 * This is where PROJECT.md section I, item 6 draws its line: a note is a
 * diagnostic the package generated and goes through redaction, while the output
 * between the notes is a verbatim copy of what another process printed. A copy
 * that rewrote parts of itself would lose the thing it exists for — a chunk off
 * a pipe can end in the middle of a value, so a replacement would catch some
 * occurrences and not others, and the byte count the attempt recorded would
 * stop describing the file. So an agent that prints its own key prints it here,
 * which is why the file is created 0600 inside the Git-ignored runtime
 * directory, and why `ralph logs --help` documents what these files hold and
 * for how long.
 *
 * **A write that fails does not end the run.** The file is opened before the
 * worker starts, so an unwritable log is a readable error up front; a failure
 * afterwards — a full disk, a removed directory — is remembered
 * ({@see self::failure()}) and reported by whoever owns the log, while the
 * attempt continues. Losing the copy is a smaller harm than abandoning a
 * running agent, which is what throwing from inside the runner's read loop
 * would do.
 */
final class LogFile implements OutputSink
{
    /**
     * What marks a line as Ralph PHP's own rather than the worker's.
     */
    public const string NOTE_PREFIX = '--- ';

    /**
     * Mode for a created log directory, and for the log itself.
     *
     * Kept to the owner, as the rest of the runtime directory is: a log holds
     * whatever the agent printed, which can include source code and anything
     * the agent's own output happens to quote.
     */
    private const int DIRECTORY_MODE = 0o700;

    private const int FILE_MODE = 0o600;

    /**
     * The open file, or null once closed.
     *
     * @var resource|null
     */
    private mixed $handle;

    /**
     * Which stream the last worker bytes came off, or null before any have.
     */
    private ?OutputStream $active = null;

    /**
     * Whether the file currently ends with a line ending.
     */
    private bool $atLineStart = true;

    /**
     * Bytes of worker output written, notes and markers excluded.
     */
    private int $bytes = 0;

    /**
     * What went wrong with the first failed write, if one failed.
     */
    private ?string $failure = null;

    /**
     * @param resource $handle an open, writable stream
     */
    private function __construct(
        private readonly string $path,
        mixed $handle,
        private readonly Secrets $secrets,
    ) {
        $this->handle = $handle;
    }

    /**
     * Open a log, creating it and its directory if they do not exist.
     *
     * Appends rather than truncates. A log is named after the attempt it
     * belongs to ({@see LogDirectory}), so a second open of the same name means
     * the same attempt is being resumed or its verification commands are
     * running after its agent did — and in both cases what is already in the
     * file is part of the record.
     *
     * @param Secrets|null $secrets what must not appear in the notes this log
     *        writes; the worker's own output is kept as it arrived either way
     *
     * @throws FilesystemException when the log cannot be opened for writing
     */
    public static function appendTo(string $path, ?Secrets $secrets = null): self
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            throw new FilesystemException(
                sprintf('Unable to create the log directory %s.', $directory),
                ['Worker output is written there while a task runs.'],
            );
        }

        $existed = file_exists($path);
        $handle = @fopen($path, 'ab');

        if (!is_resource($handle)) {
            throw new FilesystemException(
                sprintf('Unable to open the log file %s for writing.', $path),
                [
                    is_dir($path)
                        ? 'A directory exists at that path.'
                        : sprintf('Check the permissions of %s.', $directory),
                ],
            );
        }

        if (!$existed) {
            @chmod($path, self::FILE_MODE);
        }

        return new self($path, $handle, $secrets ?? Secrets::none());
    }

    /**
     * Absolute path to the log.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Bytes of worker output this log has taken.
     *
     * The honest answer to "did the agent print anything", which an adapter
     * reporting on an attempt that produced no result wants to be able to say.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Why this log stopped being written to, or null while it is intact.
     *
     * Checked after an attempt rather than during it: see the class note.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    public function write(OutputStream $stream, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if ($stream !== $this->active) {
            $this->startLine();
            $this->put(self::NOTE_PREFIX . $stream->label() . "\n");
            $this->active = $stream;
        }

        if ($this->put($chunk)) {
            $this->bytes += strlen($chunk);
            $this->atLineStart = str_ends_with($chunk, "\n");
        }
    }

    public function note(string $line): void
    {
        $this->startLine();
        $this->put(self::NOTE_PREFIX . $this->secrets->redact($line) . "\n");

        // A note interrupts the worker's output, so the next chunk needs its
        // marker again even if it comes off the same stream as the last one.
        // Without this, a footer note would be followed by output that looked
        // like a continuation of the stream before it.
        $this->active = null;
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        if (is_resource($handle)) {
            $this->startLine($handle);
            fclose($handle);
        }
    }

    /**
     * End an unterminated line, so that what follows starts on its own.
     *
     * @param resource|null $handle the stream to write to, for the one caller
     *        that has already taken the handle off the object: {@see
     *        self::close()}, which must flush before it lets go
     */
    private function startLine(mixed $handle = null): void
    {
        if ($this->atLineStart) {
            return;
        }

        if ($this->put("\n", $handle)) {
            $this->atLineStart = true;
        }
    }

    /**
     * Write bytes, remembering the first failure and then giving up.
     *
     * Returns whether the write happened, so that the byte count and the
     * line-ending state describe the file rather than what was attempted.
     *
     * @param resource|null $handle the stream to write to, defaulting to this
     *        log's own
     */
    private function put(string $text, mixed $handle = null): bool
    {
        $handle ??= $this->handle;

        // A closed log is silent: whoever closed it is done with the attempt,
        // and a late write is not a failure worth reporting. A handle that is
        // no longer a resource without having been closed here is a different
        // thing entirely, and is reported like any other failed write.
        if ($this->failure !== null || $handle === null) {
            return false;
        }

        if (!is_resource($handle)) {
            $this->failure = sprintf('Writing to %s failed: the log file is no longer open.', $this->path);

            return false;
        }

        error_clear_last();
        $written = @fwrite($handle, $text);

        if ($written === false || $written < strlen($text)) {
            $reason = error_get_last()['message'] ?? null;

            $this->failure = sprintf(
                'Writing to %s failed%s.',
                $this->path,
                is_string($reason) && $reason !== '' ? ': ' . trim($reason) : '',
            );

            return false;
        }

        return true;
    }
}
