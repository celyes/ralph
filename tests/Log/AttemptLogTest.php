<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Log\AttemptLog;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\CapturingOutput;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One attempt's output going to the terminal and to its own log at once.
 *
 * Both destinations of PROJECT.md section E, item 4, which is why these tests
 * assert on a real file and a real pair of streams together: the failure worth
 * catching is output reaching one of them and not the other.
 */
#[CoversClass(AttemptLog::class)]
final class AttemptLogTest extends TestCase
{
    private string $project;

    private LogDirectory $logs;

    private TaskGeneration $generation;

    private CapturingOutput $terminal;

    protected function setUp(): void
    {
        $this->project = TemporaryDirectory::create('ralph-attempt-log');
        $this->logs = LogDirectory::in($this->project);
        $this->generation = TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option'));
        $this->terminal = new CapturingOutput();
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->project);
    }

    #[Test]
    public function output_reaches_the_terminal_and_the_file(): void
    {
        $log = AttemptLog::open($this->logs, $this->generation, 1, $this->terminal->output());

        $log->write(OutputStream::Standard, "reading the task\n");
        $log->write(OutputStream::Error, "warning: slow\n");
        $log->note('scripted exited with status 0 after 1.2s');
        $log->close();

        self::assertStringContainsString("reading the task\n", $this->terminal->standardOutput());
        self::assertSame("warning: slow\n", $this->terminal->errorOutput());

        $kept = (string) file_get_contents((string) $log->path());
        self::assertStringContainsString("--- stdout\nreading the task\n", $kept);
        self::assertStringContainsString("--- stderr\nwarning: slow\n", $kept);
        self::assertStringContainsString('--- scripted exited with status 0 after 1.2s', $kept);
    }

    #[Test]
    public function the_log_is_the_file_the_attempt_is_named_after(): void
    {
        $log = AttemptLog::open($this->logs, $this->generation, 2);
        $log->close();

        self::assertSame($this->logs->agentLog($this->generation, 2), $log->path());
    }

    /**
     * A run whose output nobody is watching still keeps the record.
     */
    #[Test]
    public function the_file_is_kept_even_with_no_terminal_to_show_it_on(): void
    {
        $log = AttemptLog::open($this->logs, $this->generation, 1);

        $log->write(OutputStream::Standard, "worked on it\n");
        $log->close();

        self::assertStringContainsString('worked on it', (string) file_get_contents((string) $log->path()));
    }

    #[Test]
    public function it_counts_what_the_worker_printed(): void
    {
        $log = AttemptLog::open($this->logs, $this->generation, 1, $this->terminal->output());

        $log->note('a note that does not count');
        $log->write(OutputStream::Standard, '12345');
        $log->close();

        self::assertSame(5, $log->bytes());
    }

    #[Test]
    public function showing_output_without_keeping_it_has_no_file(): void
    {
        $log = AttemptLog::toTerminal($this->terminal->output());

        $log->write(OutputStream::Standard, "shown only\n");
        $log->close();

        self::assertNull($log->path());
        self::assertNull($log->failure());
        self::assertSame(0, $log->bytes());
        self::assertSame("shown only\n", $this->terminal->standardOutput());
    }

    #[Test]
    public function keeping_and_showing_nothing_is_still_a_log(): void
    {
        $log = AttemptLog::none();

        $log->write(OutputStream::Standard, 'nobody is reading this');
        $log->note('nor this');
        $log->close();

        self::assertNull($log->path());
        self::assertFalse($log->hasFailed());
    }

    /**
     * An unwritable log is a setup problem with a readable cause, found before
     * an agent is started rather than twenty minutes into an attempt.
     */
    #[Test]
    public function a_log_that_cannot_be_opened_is_reported_before_the_attempt(): void
    {
        chmod($this->project, 0o500);

        try {
            $this->expectException(FilesystemException::class);

            AttemptLog::open($this->logs, $this->generation, 1, $this->terminal->output());
        } finally {
            chmod($this->project, 0o700);
        }
    }

    /**
     * The asymmetry the class exists for: a lost log is reported, while a
     * terminal nobody is looking at is not — there is nothing a user can do
     * about output they redirected, and the place it would be reported to is
     * that terminal.
     */
    #[Test]
    public function only_the_files_failures_are_reported(): void
    {
        $log = AttemptLog::open($this->logs, $this->generation, 1, $this->terminal->output());
        self::assertFalse($log->hasFailed());

        self::assertTrue(unlink((string) $log->path()));
        self::assertTrue(rmdir(dirname((string) $log->path())));

        // The handle outlives the file on a POSIX filesystem, so the write
        // still succeeds and nothing is reported: a removed log directory is
        // not detectable until the next open. What this asserts is the shape of
        // the reporting, which the LogFile tests exercise against a real
        // failure.
        $log->write(OutputStream::Standard, "into a file nobody can find\n");

        self::assertSame("into a file nobody can find\n", $this->terminal->standardOutput());
        $log->close();
    }
}
