<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\CommitIdentity;
use Celyes\RalphPhp\Git\CommitMessage;
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
 * Reading a commit back: what it says it is, and what it must not be talked
 * into saying.
 *
 * The reading half of {@see CommitMessage}, and the only thing standing between
 * a crashed run and a duplicated implementation (PROJECT.md section H, item 5).
 * Two properties are what matter here and both are asserted against messages
 * this package really writes rather than against hand-built strings alone: a
 * commit Ralph PHP made is recognised as the pass's, and a commit whose *task
 * description* is written to look like a trailer block is not.
 */
#[CoversClass(CommitIdentity::class)]
final class CommitIdentityTest extends TestCase
{
    private const string SHA = '7c1e9b2a5f4d3c8e9a0b1d2e3f4a5b6c7d8e9f01';

    #[Test]
    public function a_commit_this_package_wrote_names_the_pass_that_made_it(): void
    {
        $generation = $this->generation();
        $message = CommitMessage::of(
            $generation,
            'Add a greeting command',
            'fa1e0b2c3d4e5f60718293a4b5c6d7e8f9012345',
            7,
            3,
            'codex',
        );

        $identity = CommitIdentity::of(self::SHA, $message->text());

        self::assertTrue($identity->isRalphCommit());
        self::assertTrue($identity->names($generation));
        self::assertTrue($identity->namesTask($generation->task()));
        self::assertSame($generation->task()->value(), $identity->task());
        self::assertSame(1, $identity->generation());
        self::assertSame(7, $identity->run());
        self::assertSame(3, $identity->attempt());
        self::assertSame('fa1e0b2c3d4e5f60718293a4b5c6d7e8f9012345', $identity->base());
        self::assertSame('codex', $identity->agent());
        self::assertSame($message->subject(), $identity->subject());
        self::assertSame(self::SHA, $identity->sha());
        self::assertSame(substr(self::SHA, 0, CommitIdentity::SHORT_LENGTH), $identity->shortSha());
    }

    /**
     * The forgery this guards against: a task whose wording is a trailer block.
     *
     * A description reaches a commit as the message's body, and a body is never
     * the last paragraph of a message that carries trailers — so the only
     * trailers read are the ones this package wrote underneath it.
     */
    #[Test]
    public function a_task_description_shaped_like_a_trailer_block_does_not_become_one(): void
    {
        // Long enough to be carried as the message's body rather than fitting
        // in the subject, which is the placement the forgery would need.
        $forgery = 'Ralph-Task: TASK-FAKE0001 and Ralph-Generation: 9, with enough '
            . 'further wording to push it past the width of a subject line';
        $message = CommitMessage::of(
            $this->generation(),
            $forgery,
            'fa1e0b2c3d4e5f60718293a4b5c6d7e8f9012345',
            1,
            1,
            'codex',
        );

        $identity = CommitIdentity::of(self::SHA, $message->text());

        self::assertSame($this->generation()->task()->value(), $identity->task());
        self::assertSame(1, $identity->generation());
        self::assertTrue($identity->names($this->generation()));
    }

    /**
     * A message that is nothing but trailers is somebody else's: this package
     * writes a subject first, always.
     */
    #[Test]
    public function a_message_of_only_trailers_is_read_as_having_none(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Ralph-Task: TASK-19E0214A\nRalph-Generation: 1\n",
        );

        self::assertSame([], $identity->trailers());
        self::assertFalse($identity->isRalphCommit());
        self::assertNull($identity->task());
        self::assertSame('Ralph-Task: TASK-19E0214A', $identity->subject());
    }

    #[Test]
    public function an_ordinary_commit_is_not_this_packages(): void
    {
        $identity = CommitIdentity::of(self::SHA, "Fix the greeting\n\nIt said hello twice.\n");

        self::assertFalse($identity->isRalphCommit());
        self::assertFalse($identity->names($this->generation()));
        self::assertFalse($identity->namesTask($this->generation()->task()));
        self::assertSame('Fix the greeting', $identity->subject());
        self::assertSame([], $identity->trailers());
        self::assertSame(
            substr(self::SHA, 0, CommitIdentity::SHORT_LENGTH) . ' Fix the greeting',
            $identity->describe(),
        );
    }

    /**
     * One identifier alone is a message with one of our keys in it, which is a
     * different thing from a commit a pass made.
     */
    #[Test]
    public function a_commit_naming_a_task_but_no_generation_is_not_adoptable(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Do the thing\n\nRalph-Task: TASK-19E0214A\nSigned-off-by: Someone <s@example.com>\n",
        );

        self::assertSame('TASK-19E0214A', $identity->task());
        self::assertNull($identity->generation());
        self::assertFalse($identity->isRalphCommit());
        self::assertFalse($identity->namesTask(TaskId::fromString('TASK-19E0214A')));

        // Read, because a reader deciding whose a commit is wants to see what
        // else the message says.
        self::assertSame('Someone <s@example.com>', $identity->trailer('Signed-off-by'));
    }

    #[Test]
    public function a_generation_that_is_not_a_counted_number_is_treated_as_absent(): void
    {
        foreach (['two', '0', '-1', '1.0', ''] as $value) {
            $identity = CommitIdentity::of(
                self::SHA,
                sprintf("Do the thing\n\nRalph-Task: TASK-19E0214A\nRalph-Generation: %s\n", $value),
            );

            self::assertNull($identity->generation(), sprintf('"%s" should not be read as a number', $value));
            self::assertFalse($identity->isRalphCommit());
        }
    }

    #[Test]
    public function a_task_identifier_that_is_not_one_is_refused(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Do the thing\n\nRalph-Task: not-a-task-id\nRalph-Generation: 1\n",
        );

        self::assertSame('not-a-task-id', $identity->task());
        self::assertFalse($identity->isRalphCommit());
    }

    /**
     * A reopened task's second pass finding its first pass's commit is a
     * different problem from finding a stranger's, and needs different words.
     */
    #[Test]
    public function a_commit_of_another_generation_of_the_same_task_is_recognised_as_such(): void
    {
        $first = $this->generation();
        $second = $first->next();

        $identity = CommitIdentity::of(self::SHA, $this->messageFor($first));

        self::assertTrue($identity->names($first));
        self::assertFalse($identity->names($second));
        self::assertTrue($identity->namesTask($second->task()));
        self::assertStringContainsString('generation 1', $identity->describe());
    }

    #[Test]
    public function a_wrapped_trailer_value_does_not_make_the_block_unreadable(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Do the thing\n\nRalph-Task: TASK-19E0214A\nRalph-Generation: 1\n"
                . "Co-authored-by: Someone\n    <someone@example.com>\n",
        );

        self::assertTrue($identity->isRalphCommit());
        self::assertSame('Someone', $identity->trailer('Co-authored-by'));
    }

    #[Test]
    public function carriage_returns_do_not_hide_the_trailer_block(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Do the thing\r\n\r\nRalph-Task: TASK-19E0214A\r\nRalph-Generation: 1\r\n",
        );

        self::assertTrue($identity->isRalphCommit());
        self::assertSame('Do the thing', $identity->subject());
    }

    /**
     * A body paragraph between the subject and the trailers is the ordinary
     * shape of a message this package writes.
     */
    #[Test]
    public function only_the_last_paragraph_is_read_as_trailers(): void
    {
        $identity = CommitIdentity::of(
            self::SHA,
            "Do the thing\n\nRalph-Task: TASK-FAKE0001\n\nRalph-Task: TASK-19E0214A\nRalph-Generation: 1\n",
        );

        self::assertSame('TASK-19E0214A', $identity->task());
    }

    #[Test]
    public function a_commit_identity_needs_a_commit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommitIdentity::of('  ', 'Do the thing');
    }

    #[Test]
    public function it_serialises_for_a_record_of_a_recovery(): void
    {
        $identity = CommitIdentity::of(self::SHA, $this->messageFor($this->generation()));
        $written = $identity->toArray();

        self::assertSame(self::SHA, $written['sha']);
        self::assertTrue($written['ralph']);
        self::assertIsArray($written['trailers']);
        self::assertSame($this->generation()->task()->value(), $written['trailers'][CommitMessage::TASK]);
        self::assertStringContainsString('attempt 1', $identity->describe());
    }

    private function messageFor(TaskGeneration $generation): string
    {
        return CommitMessage::of(
            $generation,
            'Add a greeting command',
            'fa1e0b2c3d4e5f60718293a4b5c6d7e8f9012345',
            1,
            1,
            'codex',
        )->text();
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first($this->snapshot()->id());
    }

    private function snapshot(): TaskSnapshot
    {
        $description = 'Add a greeting command';

        return TaskSnapshot::of(Task::fromItem(new ChecklistItem(
            $description,
            false,
            1,
            sprintf('- [ ] %s', $description),
            [],
        )));
    }
}
