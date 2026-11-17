<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\LogSettings;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Log\LogRetentionReason;
use Celyes\RalphPhp\Log\LogWipe;
use Celyes\RalphPhp\Log\LogWiper;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a successful task's own logs, against a real directory and a real
 * state store (PROJECT.md section K, item 11).
 *
 * Every case here is one of three assertions, and the first two are the item:
 * that the bytes of a **successful** task's agent and verification logs are
 * gone, that everything else in the same directory and every column on the row
 * is **still there**, and that a wipe an interruption cut short leaves a pass
 * the next one finishes rather than a pass nobody looks at again.
 *
 * The files are written as real files rather than mocked away, because what is
 * under test is which paths a deletion reaches: a wipe that deleted by
 * extension, or that followed a link, or that emptied a directory a person had
 * put something in, would pass every assertion made against a stub.
 */
#[CoversClass(LogWiper::class)]
#[CoversClass(LogWipe::class)]
#[CoversClass(LogRetentionReason::class)]
final class LogWiperTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    private string $project;

    private Database $database;

    private FrozenClock $clock;

    private TaskStore $tasks;

    private GenerationStore $generations;

    private LogDirectory $logs;

    protected function setUp(): void
    {
        $this->project = TemporaryDirectory::create('ralph-wipe');
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->clock = FrozenClock::at('2026-11-17T09:30:00+00:00');
        $this->tasks = new TaskStore($this->database, $this->clock);
        $this->generations = new GenerationStore($this->database, $this->clock);
        $this->logs = LogDirectory::in($this->project);
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->project);
    }

    /**
     * The item itself: the agent's output and the checks' output go, and the
     * row records when.
     */
    #[Test]
    public function a_successful_tasks_logs_are_deleted_when_the_project_asks_for_it(): void
    {
        $record = $this->completed();
        $agent = $this->writeLog($record, 'attempt-01-agent.log');
        $checks = $this->writeLog($record, 'attempt-01-check-tests.log');

        $wipe = $this->wiper()->wipe($record);

        self::assertTrue($wipe->isComplete());
        self::assertTrue($wipe->removedAnything());
        self::assertSame(2, $wipe->count());
        self::assertFileDoesNotExist($agent);
        self::assertFileDoesNotExist($checks);

        $written = $this->generations->get($record->generation());

        self::assertTrue($written->logsWereWiped());
        self::assertSame('2026-11-17T09:30:00+00:00', $written->logsWipedAt()?->value());
    }

    /**
     * What the setting trades is output for disk. The commit, the push result,
     * the state and the evidence the completion rests on are not output.
     */
    #[Test]
    public function the_completion_evidence_and_the_commit_outlive_the_logs(): void
    {
        $record = $this->completed();
        $this->writeLog($record, 'attempt-01-agent.log');
        $evidence = $this->writeLog($record, 'attempt-01-completed.md');
        $satisfied = $this->writeLog($record, 'attempt-01-satisfied.md');
        $diff = $this->writeLog($record, 'attempt-01-staged.diff');

        $this->wiper()->wipe($record);

        self::assertFileExists($evidence);
        self::assertFileExists($satisfied);
        self::assertFileExists($diff);

        $written = $this->generations->get($record->generation());

        self::assertSame('f1e2d3c4b5a6', $written->commitSha());
        self::assertSame(LifecycleState::Completed, $written->state());
        self::assertSame(1, $written->attempts());
    }

    /**
     * A directory still holding the completion record is a directory with
     * something in it, and `rmdir` is what enforces that rather than a reading
     * that could go stale.
     */
    #[Test]
    public function the_log_directory_stays_while_anything_is_left_in_it(): void
    {
        $record = $this->completed();
        $this->writeLog($record, 'attempt-01-agent.log');
        $this->writeLog($record, 'attempt-01-completed.md');

        $wipe = $this->wiper()->wipe($record);

        self::assertFalse($wipe->removedDirectory());
        self::assertDirectoryExists($this->logs->forGeneration($record->generation()));
    }

    #[Test]
    public function an_emptied_log_directory_goes_with_the_last_log(): void
    {
        $record = $this->completed();
        $this->writeLog($record, 'attempt-01-agent.log');

        $wipe = $this->wiper()->wipe($record);

        self::assertTrue($wipe->removedDirectory());
        self::assertDirectoryDoesNotExist($this->logs->forGeneration($record->generation()));
    }

    /**
     * The default, and the one most projects stay on: nothing is a candidate,
     * so nothing is read off disk and nothing is written to the row.
     */
    #[Test]
    public function a_project_that_keeps_every_log_deletes_nothing(): void
    {
        $record = $this->completed();
        $agent = $this->writeLog($record, 'attempt-01-agent.log');

        $wipe = $this->wiper(new LogSettings())->wipe($record);

        self::assertSame(LogRetentionReason::Configured, $wipe->reason());
        self::assertFalse($wipe->removedAnything());
        self::assertFileExists($agent);
        self::assertFalse($this->generations->get($record->generation())->logsWereWiped());
    }

    /**
     * Section K, item 12's promise, enforced here rather than at the call site:
     * the logs of a pass that did not succeed are the ones somebody is going to
     * read, and no setting deletes them.
     */
    #[Test]
    #[DataProvider('passesThatStopped')]
    public function a_pass_that_did_not_succeed_keeps_every_byte_it_wrote(LifecycleState $state): void
    {
        $record = $this->stopped($state);
        $agent = $this->writeLog($record, 'attempt-01-agent.log');

        $wipe = $this->wiper()->wipe($record);

        self::assertSame(LogRetentionReason::Unsuccessful, $wipe->reason());
        self::assertFileExists($agent);
    }

    /**
     * @return iterable<string, array{LifecycleState}>
     */
    public static function passesThatStopped(): iterable
    {
        yield 'failed' => [LifecycleState::Failed];
        yield 'blocked' => [LifecycleState::Blocked];
        yield 'interrupted' => [LifecycleState::Interrupted];
    }

    /**
     * An attempt in progress owns an open handle on its log, and the row says
     * `running` throughout. Deleting that file would discard the explanation of
     * whatever happens next.
     */
    #[Test]
    public function a_pass_still_being_worked_on_is_not_wiped(): void
    {
        $record = $this->generations->beginAttempt($this->start());
        $agent = $this->writeLog($record, 'attempt-01-agent.log');

        $wipe = $this->wiper()->wipe($record);

        self::assertSame(LogRetentionReason::Unsuccessful, $wipe->reason());
        self::assertFileExists($agent);
    }

    /**
     * The precondition the item states: a completion is durable once the
     * required push has settled, and a row that says otherwise is refused
     * whatever else it says. The store will not write such a row — `complete()`
     * refuses an outstanding push — so the guard is asked of a row built by
     * hand, which is the only way the condition it defends against could arise.
     */
    #[Test]
    public function a_completion_whose_push_is_outstanding_is_not_wiped(): void
    {
        $generation = TaskGeneration::first(Task::fromItem($this->item())->id());
        $record = GenerationRecord::fromRow([
            'id' => 1,
            'slug' => $generation->slug(),
            'run_id' => 1,
            'state' => 'completed',
            'phase' => null,
            'attempts' => 1,
            'content_hash' => str_repeat('c', 64),
            'agent' => 'codex',
            'adapter' => 'codex',
            'agent_settings' => '{"executable":"codex"}',
            'base_reference' => 'main',
            'base_sha' => str_repeat('a', 40),
            'branch' => null,
            'commit_sha' => str_repeat('f', 40),
            'no_change' => 0,
            'push_state' => 'pending',
            'push_remote' => 'origin',
            'push_error' => null,
            'worktree_path' => null,
            'owns_worktree' => 0,
            'started_at' => '2026-11-17T09:00:00+00:00',
            'updated_at' => '2026-11-17T09:10:00+00:00',
            'finished_at' => null,
        ]);

        $agent = $this->writeLog($record, 'attempt-01-agent.log');

        $wipe = $this->wiper()->wipe($record);

        self::assertSame(LogRetentionReason::Unsettled, $wipe->reason());
        self::assertFileExists($agent);
    }

    /**
     * A directory of generated data is still somewhere a person may have put
     * something. Only the names this package composes are eligible.
     */
    #[Test]
    public function nothing_this_package_did_not_write_is_deleted(): void
    {
        $record = $this->completed();
        $log = $this->writeLog($record, 'attempt-01-agent.log');
        $strangers = [
            $this->writeLog($record, 'notes.txt'),
            $this->writeLog($record, 'attempt-01-agent.log.bak'),
            $this->writeLog($record, 'agent.log'),
            $this->writeLog($record, 'attempt-01-AGENT.log'),
        ];

        $wipe = $this->wiper()->wipe($record);

        self::assertSame(1, $wipe->count());
        self::assertFileDoesNotExist($log);

        foreach ($strangers as $stranger) {
            self::assertFileExists($stranger, $stranger);
        }

        // Something is still in it, so the directory stays.
        self::assertDirectoryExists($this->logs->forGeneration($record->generation()));
    }

    /**
     * A link in here is not something this package wrote, and following one
     * would put the deletion outside the runtime directory altogether.
     */
    #[Test]
    public function a_symbolic_link_is_not_a_log_this_wiper_deletes(): void
    {
        $record = $this->completed();
        $target = $this->project . '/outside.log';
        file_put_contents($target, 'not ours');

        $link = $this->logs->forGeneration($record->generation()) . '/attempt-02-agent.log';
        $this->makeDirectory(dirname($link));

        if (!@symlink($target, $link)) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }

        $wipe = $this->wiper()->wipe($record);

        self::assertSame(0, $wipe->count());
        self::assertFileExists($target);
        self::assertTrue(is_link($link));
    }

    /**
     * Each pass is wiped alone. A reopened task's earlier generation is its
     * history (section C, item 8), and another task's logs are nothing to do
     * with this one.
     */
    #[Test]
    public function only_this_passs_own_logs_are_deleted(): void
    {
        $record = $this->completed();
        $mine = $this->writeLog($record, 'attempt-01-agent.log');

        $other = TaskGeneration::of($record->task(), 2);
        $theirs = $this->logs->forGeneration($other) . '/attempt-01-agent.log';
        $this->makeDirectory(dirname($theirs));
        file_put_contents($theirs, 'the second pass');

        $this->wiper()->wipe($record);

        self::assertFileDoesNotExist($mine);
        self::assertFileExists($theirs);
    }

    /**
     * The retry the item asks for. A wipe that could not finish records
     * nothing, so the pass still reads as owing one; the next look finishes it
     * and only then is the row written.
     */
    #[Test]
    public function a_wipe_that_could_not_finish_is_left_for_the_next_one_to_complete(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root, which a read-only directory does not stop.');
        }

        $record = $this->completed();
        $agent = $this->writeLog($record, 'attempt-01-agent.log');
        $directory = $this->logs->forGeneration($record->generation());

        chmod($directory, 0o500);

        try {
            $refused = $this->wiper()->wipe($record);
        } finally {
            chmod($directory, 0o700);
        }

        self::assertFalse($refused->isComplete());
        self::assertSame(LogRetentionReason::Undeletable, $refused->reason());
        self::assertSame([$agent], $refused->left());
        self::assertFileExists($agent);
        self::assertFalse(
            $this->generations->get($record->generation())->logsWereWiped(),
            'A wipe that left a file behind must not be recorded as finished.',
        );

        $finished = $this->wiper()->wipe($this->generations->get($record->generation()));

        self::assertTrue($finished->isComplete());
        self::assertSame(1, $finished->count());
        self::assertFileDoesNotExist($agent);
        self::assertTrue($this->generations->get($record->generation())->logsWereWiped());
    }

    /**
     * Calling it again is the ordinary case — every resumption of a finished
     * pass reaches it — and it must cost nothing and change nothing, including
     * the timestamp, which dates the removal that actually happened.
     */
    #[Test]
    public function wiping_a_pass_whose_logs_have_already_gone_changes_nothing(): void
    {
        $record = $this->completed();
        $this->writeLog($record, 'attempt-01-agent.log');

        $this->wiper()->wipe($record);
        $first = $this->generations->get($record->generation());

        $this->clock->advance(3600);
        $again = $this->wiper()->wipe($first);

        self::assertTrue($again->isComplete());
        self::assertFalse($again->removedAnything());
        self::assertSame(
            $first->logsWipedAt()?->value(),
            $this->generations->get($record->generation())->logsWipedAt()?->value(),
        );
    }

    /**
     * A pass that never wrote a log is finished rather than outstanding:
     * recording it as outstanding would have every later run read the same
     * empty directory again.
     */
    #[Test]
    public function a_pass_with_no_logs_at_all_is_a_finished_wipe(): void
    {
        $record = $this->completed();

        $wipe = $this->wiper()->wipe($record);

        self::assertTrue($wipe->isComplete());
        self::assertFalse($wipe->removedAnything());
        self::assertTrue($this->generations->get($record->generation())->logsWereWiped());
    }

    /**
     * The run's output says what went, and says nothing at all in the case that
     * happens on every task of every project that keeps its logs.
     */
    #[Test]
    public function what_was_deleted_is_noted_and_what_was_never_a_candidate_is_not(): void
    {
        $record = $this->completed();
        $this->writeLog($record, 'attempt-01-agent.log');

        $quiet = new RecordingSink();
        $this->wiper(new LogSettings())->wipe($record, $quiet);

        self::assertSame([], $quiet->notes);

        $noisy = new RecordingSink();
        $this->wiper()->wipe($record, $noisy);

        self::assertCount(1, $noisy->notes);
        self::assertStringContainsString('1 log file wiped', $noisy->notes[0]);
    }

    /**
     * A part-way wipe that left nothing behind would be retried for ever
     * against a directory with nothing in it.
     */
    #[Test]
    public function a_part_way_wipe_must_name_what_it_could_not_delete(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LogWipe::partial(['attempt-01-agent.log'], []);
    }

    private function wiper(?LogSettings $settings = null): LogWiper
    {
        return new LogWiper(
            $this->logs,
            $settings ?? new LogSettings(true),
            $this->generations,
        );
    }

    /**
     * A pass carried all the way through, as the runner leaves one.
     */
    private function completed(): GenerationRecord
    {
        return $this->generations->complete(
            $this->generations->recordCommit(
                $this->generations->beginAttempt($this->start()),
                'f1e2d3c4b5a6',
            ),
        );
    }

    /**
     * A pass that stopped, in the state it stopped in.
     */
    private function stopped(LifecycleState $state): GenerationRecord
    {
        $generation = $this->generations->beginAttempt($this->start());

        return match ($state) {
            LifecycleState::Failed => $this->generations->fail($generation, $this->blocker()),
            LifecycleState::Blocked => $this->generations->block($generation, $this->blocker()),
            default => $this->generations->interrupt($generation),
        };
    }

    private function blocker(): Blocker
    {
        return Blocker::raised('the checks did not pass', 'read the log and try again');
    }

    private function start(): GenerationRecord
    {
        $snapshot = TaskSnapshot::of(Task::fromItem($this->item()));
        $this->tasks->record($snapshot);

        return $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'main',
            'a1b2c3d4e5f6',
        );
    }

    private function item(): ChecklistItem
    {
        return new ChecklistItem(self::DESCRIPTION, false, 1, '- [ ] ' . self::DESCRIPTION, []);
    }

    /**
     * Write a file into a pass's log directory, and say where it went.
     */
    private function writeLog(GenerationRecord $record, string $name): string
    {
        $directory = $this->logs->forGeneration($record->generation());
        $this->makeDirectory($directory);

        $path = $directory . '/' . $name;
        file_put_contents($path, 'the agent said something, at length');

        return $path;
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            self::fail(sprintf('Unable to create %s.', $directory));
        }
    }
}
