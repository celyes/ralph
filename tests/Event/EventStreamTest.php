<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Event;

use Celyes\RalphPhp\Event\Event;
use Celyes\RalphPhp\Event\EventId;
use Celyes\RalphPhp\Event\EventKind;
use Celyes\RalphPhp\Event\EventStream;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The structured event stream: one JSON object per line, for a program.
 *
 * PROJECT.md section I, item 3. The stream is an interface with a version
 * number on it, so what is under test is the envelope every line carries and
 * the two properties a consumer depends on: that the sequence orders a run's
 * events without gaps, and that a line is on disk, whole, while the run is
 * still going.
 *
 * Real files throughout, like {@see \Celyes\RalphPhp\Tests\Log\LogFileTest}:
 * the flushing and the appending are the behaviour, and a double over a string
 * would confirm neither. The clock is frozen so that a timestamp can be
 * asserted rather than merely matched.
 */
#[CoversClass(EventStream::class)]
#[CoversClass(Event::class)]
#[CoversClass(EventKind::class)]
final class EventStreamTest extends TestCase
{
    private string $directory;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('ralph-events');
        $this->clock = FrozenClock::at('2026-11-09 10:30:00');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    /**
     * The envelope the item asks for: a schema version, a sequence, a
     * timestamp, the run, the task and the outcome, on every line.
     */
    #[Test]
    public function every_line_carries_the_whole_envelope(): void
    {
        $stream = $this->stream();

        $stream->runStarted(['agent' => 'codex']);
        $stream->taskFinished(TaskId::fromString('TASK-1A2B3C4D'), 'completed', ['commit' => 'abc']);
        $stream->close();

        [$opened, $finished] = $this->lines();

        self::assertSame([
            'schema' => 1,
            // Derived from the occurrence rather than from the line it landed
            // on, which is what lets a consumer recognise an event it has
            // already acted on ({@see EventId}, section I, item 4).
            'id' => EventId::of('run-1', EventKind::RunStarted)->value(),
            'seq' => 1,
            'at' => '2026-11-09T10:30:00+00:00',
            'run' => 'run-1',
            // Present and null, not left out: a consumer switching on the
            // field should not have to handle its absence.
            'task' => null,
            'event' => 'run.started',
            'outcome' => 'started',
            'data' => ['agent' => 'codex'],
        ], $opened->all());

        self::assertSame(2, $finished->integer('seq'));
        self::assertSame(
            EventId::of('run-1', EventKind::TaskFinished, TaskId::fromString('TASK-1A2B3C4D'))->value(),
            $finished->string('id'),
        );
        self::assertSame('TASK-1A2B3C4D', $finished->string('task'));
        self::assertSame('task.finished', $finished->string('event'));
        self::assertSame('completed', $finished->string('outcome'));
        self::assertSame('abc', $finished->string('data.commit'));
    }

    /**
     * The sequence orders a run's events, and the timestamp does not: several
     * events of one run routinely share one second.
     */
    #[Test]
    public function the_sequence_is_gapless_where_the_timestamp_repeats(): void
    {
        $stream = $this->stream();
        $task = TaskId::fromString('TASK-1A2B3C4D');

        $stream->runStarted();
        $stream->taskStarted($task);
        $stream->taskFinished($task, 'failed');
        $stream->runFinished('unfinished');
        $stream->close();

        $lines = $this->lines();

        self::assertSame([1, 2, 3, 4], self::integers($lines, 'seq'));
        self::assertSame(
            ['run.started', 'task.started', 'task.finished', 'run.finished'],
            self::strings($lines, 'event'),
        );

        // One second for all four, which is exactly why the order is not read
        // off them.
        self::assertSame(
            array_fill(0, 4, '2026-11-09T10:30:00+00:00'),
            self::strings($lines, 'at'),
        );
        self::assertSame(4, $stream->written());
    }

    /**
     * A consumer following the file sees an event when it happened, not when a
     * buffer filled up — for a run whose tasks take minutes, the difference
     * between a usable stream and none.
     */
    #[Test]
    public function a_line_is_on_disk_before_the_stream_is_closed(): void
    {
        $stream = $this->stream();

        $stream->taskStarted(TaskId::fromString('TASK-1A2B3C4D'));

        $contents = (string) file_get_contents($stream->path());

        self::assertStringContainsString('task.started', $contents);
        self::assertStringEndsWith("\n", $contents, 'a half-written line is a line a reader cannot parse');

        $stream->close();
    }

    /**
     * One file per project, appended to across runs, so that a supervising
     * process can follow one stable path instead of racing the runner for a
     * new one.
     */
    #[Test]
    public function a_second_run_appends_to_the_same_file_and_numbers_from_one(): void
    {
        $first = EventStream::appendingTo($this->path(), 'run-1', $this->clock);
        $first->runStarted();
        $first->runFinished('completed');
        $first->close();

        $second = EventStream::appendingTo($this->path(), 'run-2', $this->clock);
        $second->runStarted();
        $second->close();

        $lines = $this->lines();

        self::assertCount(3, $lines, 'the earlier run\'s lines must still be there');
        self::assertSame(['run-1', 'run-1', 'run-2'], self::strings($lines, 'run'));

        // Numbered per run, not per file: numbering from the file would mean
        // reading all of it back before the first line of a run could be
        // written, and `(run, seq)` is what identifies an event.
        self::assertSame([1, 2, 1], self::integers($lines, 'seq'));
    }

    /**
     * A stream that cannot be opened is remembered, not raised: abandoning a
     * run because a report could not be filed would be the tool doing real
     * harm over a courtesy.
     */
    #[Test]
    public function a_stream_that_cannot_be_opened_is_silent_rather_than_fatal(): void
    {
        // A file where the stream expects a directory, so neither the mkdir
        // nor the open can succeed.
        $blocked = $this->directory . '/blocked';
        file_put_contents($blocked, "not a directory\n");

        $stream = EventStream::appendingTo($blocked . '/events.jsonl', 'run-1', $this->clock);

        self::assertTrue($stream->hasFailed());
        self::assertNotNull($stream->failure());

        // Writing to it is a no-op that says so, rather than an exception for
        // the loop to handle.
        self::assertNull($stream->runStarted());
        self::assertSame(0, $stream->written());

        $stream->close();
    }

    /**
     * Where a project's stream lives: inside the Git-ignored runtime directory
     * that cleanup owns, and not under `logs/`, which is other processes' output
     * organised by task.
     */
    #[Test]
    public function the_stream_lives_in_the_projects_runtime_directory(): void
    {
        self::assertSame(
            $this->directory . '/.ralph/runtime/events.jsonl',
            EventStream::fileIn($this->directory),
        );

        $stream = EventStream::forRun($this->directory, 'run-1', $this->clock);

        self::assertFalse($stream->hasFailed(), (string) $stream->failure());
        self::assertSame('run-1', $stream->runId());
        self::assertFileExists($stream->path());

        $stream->close();
    }

    /**
     * An envelope a consumer could not use is a mistake in this package, and
     * noticing it beats writing the line.
     */
    #[Test]
    public function an_envelope_that_could_not_be_used_is_refused(): void
    {
        // A task result naming no task.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is about a task');

        Event::of(1, Timestamp::now($this->clock), 'run-1', EventKind::TaskFinished, 'completed');
    }

    /**
     * An outcome is matched on by other programs, so one that cannot be
     * switched on is refused rather than written.
     */
    #[Test]
    public function an_outcome_that_cannot_be_switched_on_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lowercase words joined by underscores');

        Event::of(
            1,
            Timestamp::now($this->clock),
            'run-1',
            EventKind::RunFinished,
            'Gave Up',
        );
    }

    /**
     * A run-level event cannot name one task: a run works through many, so
     * naming the current one would make `run.finished` read as a task result.
     */
    #[Test]
    public function a_run_event_cannot_name_a_task(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Event::of(
            1,
            Timestamp::now($this->clock),
            'run-1',
            EventKind::RunFinished,
            'completed',
            TaskId::fromString('TASK-1A2B3C4D'),
        );
    }

    /**
     * Text that came out of another process can hold bytes that are not valid
     * UTF-8, and a stream that broke a run over one would be worse than none.
     */
    #[Test]
    public function output_that_is_not_valid_utf8_still_writes_one_usable_line(): void
    {
        $stream = $this->stream();

        $stream->taskFinished(
            TaskId::fromString('TASK-1A2B3C4D'),
            'failed',
            ['output' => "the check said \xB1\x31 and gave up"],
        );
        $stream->close();

        [$line] = $this->lines();

        self::assertSame('failed', $line->string('outcome'));
        self::assertStringContainsString('the check said', $line->string('data.output'));
    }

    /**
     * One occurrence, one line. A repeat is this package reporting the same
     * thing twice, which would make the stream contradict itself.
     *
     * Raised rather than quietly dropped, for the same reason the other
     * envelope mistakes are: a stream that silently swallowed a second
     * `run.finished` would hide the bug that produced it, and the bug is in
     * this package.
     */
    #[Test]
    public function an_occurrence_reported_twice_is_refused(): void
    {
        $stream = $this->stream();
        $stream->runFinished('finished');

        try {
            $stream->runFinished('finished');
            self::fail('A second ending for one run must not be written.');
        } catch (InvalidArgumentException $refusal) {
            // The sequence the first one went out on, so whoever reads the
            // failure can go and look at the line already in the file.
            self::assertStringContainsString('already written', $refusal->getMessage());
            self::assertStringContainsString('at sequence 1', $refusal->getMessage());
        }

        $stream->close();

        self::assertCount(1, $this->lines(), 'the refusal must not have left a second line');
    }

    /**
     * Two goes at one task are two occurrences, and the discriminator is what
     * says so.
     *
     * Without it the second attempt would carry the identity of the first and
     * be refused as a repeat — so this is the case that keeps the identity
     * naming the occurrence rather than the kind.
     */
    #[Test]
    public function two_goes_at_one_task_are_two_occurrences(): void
    {
        $stream = $this->stream();
        $task = TaskId::fromString('TASK-1A2B3C4D');

        $first = $stream->taskStarted($task, [], 'iteration:1');
        $again = $stream->taskStarted($task, [], 'iteration:2');
        $stream->close();

        self::assertNotNull($first);
        self::assertNotNull($again);
        self::assertNotSame($first->id()->value(), $again->id()->value());
        self::assertCount(2, $this->lines());
    }

    /**
     * The identity is the occurrence's, so it is the same from a second
     * process — which is the whole point of it.
     *
     * This is the case the deduplication is actually for. A run reported again
     * by another process cannot be refused here, because nothing in this
     * object knows what another one wrote; what makes the repeat harmless is
     * that both lines carry one identity, so a consumer that acted on the
     * first drops the second on sight.
     */
    #[Test]
    public function the_same_occurrence_is_named_the_same_from_another_process(): void
    {
        $first = EventStream::appendingTo($this->path(), 'run-1', $this->clock);
        $first->runStarted(['agent' => 'codex']);
        $first->close();

        // A second process, the same run: a different object, and a different
        // payload, since what the identity names is the occurrence and not the
        // line.
        $second = EventStream::appendingTo($this->path(), 'run-1', $this->clock);
        $second->runStarted(['agent' => 'claude']);
        $second->close();

        $lines = $this->lines();

        self::assertCount(2, $lines);
        self::assertSame($lines[0]->string('id'), $lines[1]->string('id'));

        // And the sequence does not identify it: both lines are their own run's
        // first, which is exactly why `(run, seq)` is not the key.
        self::assertSame([1, 1], self::integers($lines, 'seq'));
    }

    private function stream(): EventStream
    {
        return EventStream::appendingTo($this->path(), 'run-1', $this->clock);
    }

    private function path(): string
    {
        return $this->directory . '/events.jsonl';
    }

    /**
     * One field of every line, which is how a stream's order is asserted.
     *
     * @param list<JsonDocument> $lines
     *
     * @return list<string>
     */
    private static function strings(array $lines, string $field): array
    {
        return array_map(static fn (JsonDocument $line): string => $line->string($field), $lines);
    }

    /**
     * @param list<JsonDocument> $lines
     *
     * @return list<int>
     */
    private static function integers(array $lines, string $field): array
    {
        return array_map(static fn (JsonDocument $line): int => $line->integer($field), $lines);
    }

    /**
     * Every line of the stream, decoded.
     *
     * @return list<JsonDocument>
     */
    private function lines(): array
    {
        return JsonDocument::parseLines((string) file_get_contents($this->path()));
    }
}
