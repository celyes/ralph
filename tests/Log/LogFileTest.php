<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Log\LogFile;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeping a worker's output on disk while the worker runs.
 *
 * Real files throughout. What is under test is almost entirely filesystem
 * behaviour — that a chunk is on disk before the process exits, that a second
 * open appends, that an unwritable path is a readable error, that a write
 * failing part-way does not throw — and a double over a string would confirm
 * none of it.
 */
#[CoversClass(LogFile::class)]
final class LogFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('ralph-log');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    /**
     * The whole point of the class: a user can read the log of an attempt that
     * is still running, and an attempt killed half-way still leaves everything
     * the agent said.
     */
    #[Test]
    public function output_is_on_disk_before_the_log_is_closed(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, "working on it\n");

        self::assertStringContainsString('working on it', (string) file_get_contents($this->path()));

        $log->close();
    }

    #[Test]
    public function both_streams_are_kept_in_the_order_they_arrived(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, "reading the task\n");
        $log->write(OutputStream::Error, "warning: no model configured\n");
        $log->write(OutputStream::Standard, "giving up\n");
        $log->close();

        self::assertSame(
            [
                '--- stdout',
                'reading the task',
                '--- stderr',
                'warning: no model configured',
                '--- stdout',
                'giving up',
            ],
            $this->lines(),
        );
    }

    /**
     * A marker buried inside the worker's own sentence would be worse than no
     * marker at all, so the one byte this class adds is the newline that gets
     * it onto its own line.
     */
    #[Test]
    public function a_stream_changing_over_mid_line_does_not_bury_the_marker(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, 'progress: 40%');
        $log->write(OutputStream::Error, "failed\n");
        $log->close();

        self::assertSame(
            ['--- stdout', 'progress: 40%', '--- stderr', 'failed'],
            $this->lines(),
        );
    }

    /**
     * A chunk off a pipe begins and ends wherever the worker happened to be, so
     * consecutive chunks of one line have to end up as one line.
     */
    #[Test]
    public function chunks_of_one_line_are_not_broken_apart(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, 'the quick ');
        $log->write(OutputStream::Standard, 'brown ');
        $log->write(OutputStream::Standard, "fox\n");
        $log->close();

        self::assertSame(['--- stdout', 'the quick brown fox'], $this->lines());
    }

    /**
     * A log is a copy of the output, not a rendering of it: a worker may print
     * terminal escapes, carriage returns and bytes that are not valid UTF-8 at
     * all, and a reader looking for what the agent printed must get what the
     * agent printed.
     */
    #[Test]
    public function the_workers_bytes_are_kept_as_they_were(): void
    {
        $bytes = "\x1b[32mok\x1b[0m\r\n\xff\xfe binary\n";
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, $bytes);
        $log->close();

        self::assertSame(LogFile::NOTE_PREFIX . "stdout\n" . $bytes, (string) file_get_contents($this->path()));
    }

    #[Test]
    public function a_note_is_marked_as_the_packages_own(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->note('scripted exited with status 0 after 1.2s');
        $log->close();

        self::assertSame(['--- scripted exited with status 0 after 1.2s'], $this->lines());
    }

    /**
     * Otherwise a footer note would be followed by output that read as a
     * continuation of the stream before it.
     */
    #[Test]
    public function output_after_a_note_is_attributed_again(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, "first\n");
        $log->note('running the checks');
        $log->write(OutputStream::Standard, "second\n");
        $log->close();

        self::assertSame(
            ['--- stdout', 'first', '--- running the checks', '--- stdout', 'second'],
            $this->lines(),
        );
    }

    #[Test]
    public function a_note_interrupting_an_unfinished_line_starts_on_its_own(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, 'thinking...');
        $log->note('scripted was stopped after 60 seconds');
        $log->close();

        self::assertSame(
            ['--- stdout', 'thinking...', '--- scripted was stopped after 60 seconds'],
            $this->lines(),
        );
    }

    #[Test]
    public function a_log_left_mid_line_is_finished_when_it_closes(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, 'no trailing newline');
        $log->close();

        self::assertStringEndsWith("no trailing newline\n", (string) file_get_contents($this->path()));
    }

    /**
     * The attempt's agent output and the verification output that follows it
     * belong in one file, in the order they happened, so the second open of the
     * same name must not throw the first away.
     */
    #[Test]
    public function a_second_open_appends_rather_than_truncating(): void
    {
        $first = LogFile::appendTo($this->path());
        $first->write(OutputStream::Standard, "the agent ran\n");
        $first->close();

        $second = LogFile::appendTo($this->path());
        $second->note('running the checks');
        $second->close();

        self::assertSame(
            ['--- stdout', 'the agent ran', '--- running the checks'],
            $this->lines(),
        );
    }

    #[Test]
    public function it_counts_the_workers_bytes_and_not_its_own(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->note('a note that does not count');
        $log->write(OutputStream::Standard, '12345');
        $log->write(OutputStream::Error, '678');
        $log->close();

        self::assertSame(8, $log->bytes());
    }

    #[Test]
    public function an_empty_chunk_is_not_written_and_marks_nothing(): void
    {
        $log = LogFile::appendTo($this->path());

        $log->write(OutputStream::Standard, '');
        $log->close();

        self::assertSame('', (string) file_get_contents($this->path()));
        self::assertSame(0, $log->bytes());
    }

    #[Test]
    public function the_log_and_its_directory_are_kept_to_their_owner(): void
    {
        $path = $this->directory . '/created/sub/attempt-01-agent.log';

        $log = LogFile::appendTo($path);
        $log->close();

        self::assertSame(0, ((int) fileperms($path)) & 0o077);
        self::assertSame(0, ((int) fileperms(dirname($path))) & 0o077);
    }

    #[Test]
    public function closing_twice_is_harmless(): void
    {
        $log = LogFile::appendTo($this->path());
        $log->write(OutputStream::Standard, "done\n");

        $log->close();
        $log->close();

        self::assertNull($log->failure());
        self::assertSame(['--- stdout', 'done'], $this->lines());
    }

    #[Test]
    public function writing_after_closing_is_ignored_rather_than_fatal(): void
    {
        $log = LogFile::appendTo($this->path());
        $log->close();

        $log->write(OutputStream::Standard, "too late\n");
        $log->note('too late as well');

        self::assertSame('', (string) file_get_contents($this->path()));
        self::assertSame(0, $log->bytes());
    }

    /**
     * An unwritable log is a setup problem, and it is found on the way in —
     * before a worker is started — which is why this throws while a failure
     * during the attempt does not.
     */
    #[Test]
    public function a_log_that_cannot_be_opened_is_reported_before_anything_runs(): void
    {
        $unwritable = $this->directory . '/read-only';
        self::assertTrue(mkdir($unwritable, 0o500));

        try {
            $this->expectException(FilesystemException::class);
            $this->expectExceptionMessageMatches('/Unable to (open the log file|create the log directory)/');

            LogFile::appendTo($unwritable . '/attempt-01-agent.log');
        } finally {
            chmod($unwritable, 0o700);
        }
    }

    #[Test]
    public function a_directory_where_the_log_goes_is_reported_as_such(): void
    {
        $path = $this->directory . '/attempt-01-agent.log';
        self::assertTrue(mkdir($path));

        try {
            LogFile::appendTo($path);
            self::fail('A directory where the log goes should not be opened as a log.');
        } catch (FilesystemException $failure) {
            self::assertStringContainsString('Unable to open the log file', $failure->getMessage());
            self::assertStringContainsString('A directory exists at that path.', implode("\n", $failure->hints()));
        }
    }

    /**
     * The class note's rule, and the reason for it: a full disk half-way
     * through an attempt must not abandon the agent that is running. The
     * failure is remembered for whoever owns the log to report afterwards.
     */
    #[Test]
    public function a_write_that_fails_is_remembered_rather_than_thrown(): void
    {
        $log = LogFile::appendTo($this->path());
        $log->write(OutputStream::Standard, "before the trouble\n");

        $this->makeWritesFail($log);
        $log->write(OutputStream::Standard, "after it\n");

        self::assertTrue($log->hasFailed());
        self::assertStringContainsString($this->path(), (string) $log->failure());
        // The cause, which is the whole value of reporting it at all.
        self::assertStringContainsString('Bad file descriptor', (string) $log->failure());
        self::assertStringContainsString('before the trouble', (string) file_get_contents($this->path()));
        self::assertStringNotContainsString('after it', (string) file_get_contents($this->path()));
    }

    /**
     * A handle that is no longer open without this log having closed it is a
     * different thing from a log a caller closed deliberately: the first is a
     * lost record and the second is nothing at all.
     */
    #[Test]
    public function a_handle_that_went_away_is_reported_rather_than_ignored(): void
    {
        $log = LogFile::appendTo($this->path());

        $this->closeTheHandle($log);
        $log->write(OutputStream::Standard, "nowhere to go\n");

        self::assertTrue($log->hasFailed());
        self::assertStringContainsString('no longer open', (string) $log->failure());
    }

    /**
     * One failed write, not one per chunk for the rest of a twenty-minute
     * attempt, and the first failure is the one that gets reported because it
     * is the one with the cause in it.
     */
    #[Test]
    public function it_stops_trying_after_the_first_failure(): void
    {
        $log = LogFile::appendTo($this->path());
        $this->makeWritesFail($log);

        $log->write(OutputStream::Standard, 'one');
        $first = $log->failure();
        $log->write(OutputStream::Error, 'two');
        $log->note('three');

        self::assertSame($first, $log->failure());
        self::assertSame(0, $log->bytes());
    }

    private function path(): string
    {
        return $this->directory . '/attempt-01-agent.log';
    }

    /**
     * Make every write fail the way a full disk would, with a real `fwrite`
     * failure rather than a closed stream.
     *
     * Reaching into the object is deliberate and is the only honest way to test
     * this: the alternatives are filling the test machine's disk or revoking a
     * permission that an already-open handle does not consult. The log keeps a
     * readable handle to its own file, so the writes fail at the system call
     * and PHP reports why — which is the part worth asserting on.
     */
    private function makeWritesFail(LogFile $log): void
    {
        $readOnly = fopen($this->path(), 'rb');
        self::assertIsResource($readOnly);

        $property = new \ReflectionProperty(LogFile::class, 'handle');
        fclose((new \ReflectionProperty(LogFile::class, 'handle'))->getValue($log));
        $property->setValue($log, $readOnly);
    }

    /**
     * Take the log's stream away without closing the log, as a crash in
     * something else holding the same handle would.
     */
    private function closeTheHandle(LogFile $log): void
    {
        $handle = (new \ReflectionProperty(LogFile::class, 'handle'))->getValue($log);

        self::assertIsResource($handle);
        fclose($handle);
    }

    /**
     * The log's lines, with the trailing blank one dropped.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        $contents = (string) file_get_contents($this->path());

        return $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
    }
}
