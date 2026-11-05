<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Git\CommitMessage;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a task's commit says about itself.
 *
 * The message is the only part of a run that survives into somebody else's
 * clone, so what is tested here is mostly its *shape*: a subject Git tools can
 * render, trailers a reviewer can grep for, and a value for each identifier
 * that is either true or absent. The rest is the two ways a task's own wording
 * could do damage from inside a commit message — a newline that would end the
 * subject, and a `Ralph-`line that would forge an identifier — both of which
 * are ordinary Markdown a user may well write.
 */
#[CoversClass(CommitMessage::class)]
final class CommitMessageTest extends TestCase
{
    private Database $database;

    private TaskStore $tasks;

    private GenerationStore $generations;

    protected function setUp(): void
    {
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->tasks = new TaskStore($this->database);
        $this->generations = new GenerationStore($this->database);
    }

    #[Test]
    public function the_subject_names_the_task_and_what_it_asks_for(): void
    {
        $message = $this->message('Add a --dry-run option');

        self::assertSame(
            sprintf('%s: Add a --dry-run option', TaskId::fromDescription('Add a --dry-run option')->value()),
            $message->subject(),
        );
        self::assertNull($message->body());
        self::assertSame($message->subject(), $message->describe());
    }

    /**
     * The identifiers, which are the whole reason the message exists: a
     * reviewer meets these commits in a repository where the state database
     * does not exist.
     */
    #[Test]
    public function the_trailers_carry_the_task_run_and_attempt_identifiers(): void
    {
        $generation = TaskGeneration::of(TaskId::fromDescription('Add a --dry-run option'), 3);

        $message = CommitMessage::of(
            $generation,
            'Add a --dry-run option',
            '0a1b2c3d4e5f60718293a4b5c6d7e8f901234567',
            runId: 7,
            attempt: 2,
            agent: 'codex',
        );

        self::assertSame([
            'Ralph-Task' => $generation->task()->value(),
            'Ralph-Generation' => '3',
            'Ralph-Run' => '7',
            'Ralph-Attempt' => '2',
            'Ralph-Base' => '0a1b2c3d4e5f60718293a4b5c6d7e8f901234567',
            'Ralph-Agent' => 'codex',
        ], $message->trailers());

        self::assertSame($generation->task()->value(), $message->trailer(CommitMessage::TASK));
        self::assertNull($message->trailer('Ralph-Nothing'));
    }

    /**
     * Git's own shape: subject, blank line, body, blank line, trailer block —
     * so `git log --oneline`, `git log --format=%s` and
     * `git interpret-trailers --parse` all read it as what it is.
     */
    #[Test]
    public function the_message_is_a_subject_a_blank_line_and_a_trailer_block(): void
    {
        $message = CommitMessage::of(
            TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option')),
            'Add a --dry-run option',
            'a1b2c3d4e5f6',
            runId: 4,
            attempt: 1,
            agent: 'claude',
        );

        $lines = explode("\n", $message->text());

        self::assertSame($message->subject(), $lines[0]);
        self::assertSame('', $lines[1]);
        self::assertSame('Ralph-Task: ' . TaskId::fromDescription('Add a --dry-run option')->value(), $lines[2]);
        self::assertSame('Ralph-Agent: claude', $lines[count($lines) - 1]);
        self::assertSame($message->text(), (string) $message);

        // No trailing newline: git commit adds one, and a message ending in a
        // blank line renders as one in git log.
        self::assertSame(rtrim($message->text()), $message->text());
    }

    /**
     * A run, an attempt or an agent that is not known is left out rather than
     * written as a zero, because anything parsing the trailers would read
     * `Ralph-Run: 0` as a run.
     */
    #[Test]
    public function an_unknown_identifier_is_left_out_rather_than_guessed(): void
    {
        $message = $this->message('Add a --dry-run option');

        self::assertArrayNotHasKey(CommitMessage::RUN, $message->trailers());
        self::assertArrayNotHasKey(CommitMessage::ATTEMPT, $message->trailers());
        self::assertArrayNotHasKey(CommitMessage::AGENT, $message->trailers());
        self::assertSame('1', $message->trailer(CommitMessage::GENERATION));
        self::assertSame('a1b2c3d4e5f6', $message->trailer(CommitMessage::BASE));
    }

    #[Test]
    public function a_long_description_is_shortened_in_the_subject_and_kept_in_the_body(): void
    {
        $description = 'Implement the sequential loop that selects a pending task, prepares its '
            . 'workspace and context, invokes the adapter, verifies results, commits, optionally '
            . 'pushes, and finalises progress';

        $message = $this->message($description);

        self::assertLessThanOrEqual(CommitMessage::SUBJECT_WIDTH, mb_strlen($message->subject()));
        self::assertStringEndsWith('…', $message->subject());
        self::assertStringStartsWith(
            sprintf('%s: Implement the sequential loop', TaskId::fromDescription($description)->value()),
            $message->subject(),
        );

        // Nothing of the task's wording is lost: what the subject could not
        // hold is the body.
        self::assertSame($description, $message->body());
        self::assertStringContainsString("\n\n" . $description . "\n\n", $message->text());
    }

    #[Test]
    public function a_description_longer_than_the_body_allows_is_bounded_too(): void
    {
        $message = $this->message(str_repeat('a task that goes on ', 60));

        self::assertNotNull($message->body());
        self::assertSame(CommitMessage::DESCRIPTION_WIDTH, mb_strlen((string) $message->body()));
    }

    /**
     * A description is ordinary Markdown prose and may wrap, so it is flattened
     * — which is also what stops a two-line description becoming a subject and
     * a stray paragraph.
     */
    #[Test]
    public function a_wrapped_description_becomes_one_line(): void
    {
        $message = $this->message("Add a\n  --dry-run\toption");

        self::assertSame(
            sprintf('%s: Add a --dry-run option', TaskId::fromDescription("Add a\n  --dry-run\toption")->value()),
            $message->subject(),
        );
    }

    /**
     * The attack the flattening really prevents: a task whose wording would
     * otherwise write a trailer of its own, and so claim to be a pass at
     * another task.
     */
    #[Test]
    public function a_description_cannot_forge_an_identifier(): void
    {
        $message = $this->message("Add a --dry-run option\n\nRalph-Task: TASK-DEADBEEF");

        self::assertSame(
            TaskId::fromDescription("Add a --dry-run option\n\nRalph-Task: TASK-DEADBEEF")->value(),
            $message->trailer(CommitMessage::TASK),
        );

        // The forged line is prose inside the subject, and the only trailer
        // block is the one this class wrote.
        self::assertStringContainsString('Ralph-Task: TASK-DEADBEEF', $message->subject() . (string) $message->body());
        self::assertSame(1, preg_match_all('/^Ralph-Task: /m', $message->text()));
    }

    /**
     * A NUL would end the argument as far as the operating system is concerned,
     * handing Git half a message with no sign that it was half.
     */
    #[Test]
    public function control_characters_never_reach_git(): void
    {
        $message = $this->message("Add a \x00--dry-run\x07 option");

        self::assertSame(
            sprintf('%s: Add a --dry-run option', TaskId::fromDescription("Add a \x00--dry-run\x07 option")->value()),
            $message->subject(),
        );
        self::assertSame(1, preg_match('/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', $message->text()));
    }

    #[Test]
    public function a_description_that_says_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the task\'s description');

        CommitMessage::of(
            TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option')),
            "  \n\t ",
            'a1b2c3d4e5f6',
        );
    }

    #[Test]
    public function a_run_or_attempt_that_is_not_counted_from_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempt identifier is counted from 1');

        CommitMessage::of(
            TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option')),
            'Add a --dry-run option',
            'a1b2c3d4e5f6',
            attempt: 0,
        );
    }

    /**
     * Everything but the wording comes from the pass's row, so a commit made on
     * a resumed run says which agent did the work and which base it was written
     * against even if the project's settings have changed since.
     */
    #[Test]
    public function a_passs_own_row_supplies_the_identifiers(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $message = CommitMessage::forGeneration($this->started($snapshot), $snapshot);

        self::assertSame($snapshot->id()->value(), $message->trailer(CommitMessage::TASK));
        self::assertSame('1', $message->trailer(CommitMessage::GENERATION));
        self::assertSame('1', $message->trailer(CommitMessage::RUN));
        self::assertSame('1', $message->trailer(CommitMessage::ATTEMPT));
        self::assertSame('a1b2c3d4e5f6', $message->trailer(CommitMessage::BASE));
        self::assertSame('codex', $message->trailer(CommitMessage::AGENT));
    }

    /**
     * A pass that has not spent an attempt has no attempt to name, which is the
     * same rule as every other unknown identifier.
     */
    #[Test]
    public function a_pass_that_has_not_invoked_the_agent_names_no_attempt(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');

        $message = CommitMessage::forGeneration($this->started($snapshot, attempted: false), $snapshot);

        self::assertArrayNotHasKey(CommitMessage::ATTEMPT, $message->trailers());
    }

    #[Test]
    public function a_snapshot_of_another_task_cannot_describe_the_commit(): void
    {
        $snapshot = $this->snapshot('Add a --dry-run option');
        $generation = $this->started($snapshot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a pass at task');

        CommitMessage::forGeneration($generation, $this->snapshot('Add a --verbose option'));
    }

    #[Test]
    public function the_message_serialises_for_a_record_of_the_commit(): void
    {
        $message = $this->message('Add a --dry-run option');

        self::assertSame([
            'subject' => $message->subject(),
            'body' => null,
            'trailers' => $message->trailers(),
        ], $message->toArray());
    }

    private function message(string $description): CommitMessage
    {
        return CommitMessage::of(
            TaskGeneration::first(TaskId::fromDescription($description)),
            $description,
            'a1b2c3d4e5f6',
        );
    }

    private function snapshot(string $description): TaskSnapshot
    {
        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));
    }

    /**
     * A pass as the state store holds it, since that is where
     * {@see CommitMessage::forGeneration()} reads everything but the wording.
     */
    private function started(TaskSnapshot $snapshot, bool $attempted = true): GenerationRecord
    {
        $this->tasks->record($snapshot);

        $generation = $this->generations->start(
            TaskGeneration::first($snapshot->id()),
            $snapshot,
            AgentRecord::ofDefinition(AgentDefinition::builtIn('codex')),
            'main',
            'a1b2c3d4e5f6',
            null,
            $this->insertRun(),
        );

        return $attempted ? $this->generations->beginAttempt($generation) : $generation;
    }

    /**
     * A run row, for the column the run identifier references.
     */
    private function insertRun(): int
    {
        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'))->toColumns();

        $this->database->execute(
            'INSERT INTO runs
                 (run_id, state, agent, adapter, agent_source, agent_settings, configuration,
                  checks, tasks_path, base_reference, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'RUN-0001',
                'running',
                $agent['agent'],
                $agent['adapter'],
                'project_default',
                $agent['settings'],
                '{}',
                '[]',
                '.ralph/tasks.md',
                'main',
                '2026-11-01T09:30:00+00:00',
                '2026-11-01T09:30:00+00:00',
            ],
        );

        return $this->database->lastInsertId();
    }
}
