<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Completion;
use Celyes\RalphPhp\Tasks\GenerationHistory;
use Celyes\RalphPhp\Tasks\GenerationSelection;
use Celyes\RalphPhp\Tasks\GenerationSelector;
use Celyes\RalphPhp\Tasks\GenerationStart;
use Celyes\RalphPhp\Tasks\RecordedGeneration;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tasks\TaskList;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reopening a finished task: the old pass keeps everything it had, and the new
 * one gets a generation and a branch of its own.
 *
 * Written as the sequence a project really goes through — run a task, finish
 * it, untick it weeks later, run it again, have that pass interrupted, resume —
 * because the mistakes this guards against are all sequence mistakes: the
 * second pass landing on the first one's branch, a retry being counted as a
 * reopening, or a completed pass being dropped from the record once a new one
 * starts.
 */
#[CoversClass(GenerationSelector::class)]
#[CoversClass(GenerationSelection::class)]
#[CoversClass(GenerationHistory::class)]
#[CoversClass(GenerationStart::class)]
final class GenerationSelectorTest extends TestCase
{
    private GenerationSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new GenerationSelector();
    }

    #[Test]
    public function a_task_nothing_is_recorded_for_is_a_first_run(): void
    {
        $selection = $this->selector->select(self::id());

        self::assertSame(GenerationStart::FirstRun, $selection->start());
        self::assertSame(1, $selection->number());
        self::assertFalse($selection->isReopening());
        self::assertTrue($selection->startsNewBranch());
        self::assertNull($selection->continues());
        self::assertSame([], $selection->preserved());
        self::assertSame([], $selection->reservedBranches());
        self::assertSame([], $selection->notes());
    }

    /**
     * The whole sequence the task exists for.
     */
    #[Test]
    public function reopening_a_finished_task_starts_the_next_generation(): void
    {
        $history = GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'));

        $selection = $this->selector->select(self::id(), $history);

        self::assertSame(GenerationStart::Reopened, $selection->start());
        self::assertTrue($selection->isReopening());
        self::assertSame(2, $selection->number());
        self::assertSame('TASK-19E0214A-g2', $selection->generation()->slug());
        self::assertTrue($selection->generation()->belongsTo(self::id()));
    }

    /**
     * Preserving the history: the completed pass is carried forward whole, not
     * replaced by the new one.
     */
    #[Test]
    public function the_completed_pass_is_preserved_with_its_branch_and_commit(): void
    {
        $finished = self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d');

        $selection = $this->selector->select(self::id(), GenerationHistory::of($finished));

        self::assertSame([$finished], $selection->preserved());
        self::assertSame([$finished], $selection->completed());
        self::assertSame('a1b2c3d', $selection->previousCommit());
        self::assertSame(['ralph/TASK-19E0214A-add-a-flag'], $selection->reservedBranches());
    }

    /**
     * The new pass may not be handed the branch the verified work lives on.
     */
    #[Test]
    public function the_earlier_branch_is_reserved_against_the_new_generation(): void
    {
        $selection = $this->selector->select(
            self::id(),
            GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d')),
        );

        self::assertTrue($selection->isReserved('ralph/TASK-19E0214A-add-a-flag'));
        self::assertFalse($selection->isReserved('ralph/TASK-19E0214A-g2-add-a-flag'));
        self::assertStringContainsString(
            'ralph/TASK-19E0214A-add-a-flag',
            implode("\n", $selection->notes()),
        );
    }

    /**
     * A task reopened twice reaches generation 3, and both finished passes stay
     * on record.
     */
    #[Test]
    public function reopening_a_task_that_was_already_reopened_keeps_counting(): void
    {
        $history = GenerationHistory::of(
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
            self::finished(2, 'ralph/TASK-19E0214A-g2-add-a-flag', 'e4f5a6b'),
        );

        $selection = $this->selector->select(self::id(), $history);

        self::assertSame(3, $selection->number());
        self::assertSame('TASK-19E0214A-g3', $selection->generation()->slug());
        self::assertCount(2, $selection->preserved());
        self::assertSame('e4f5a6b', $selection->previousCommit());
        self::assertSame(2, $history->completedCountOf(self::id()));
    }

    /**
     * The distinction a retry turns on: an unfinished pass is the same pass
     * continuing, on the branch it already owns.
     */
    #[Test]
    public function an_unfinished_pass_is_continued_rather_than_reopened(): void
    {
        $open = RecordedGeneration::open(
            TaskGeneration::first(self::id()),
            'ralph/TASK-19E0214A-add-a-flag',
        );

        $selection = $this->selector->select(self::id(), GenerationHistory::of($open));

        self::assertSame(GenerationStart::Continued, $selection->start());
        self::assertSame(1, $selection->number());
        self::assertFalse($selection->isReopening());
        self::assertFalse($selection->startsNewBranch());
        self::assertTrue($selection->start()->keepsAttemptCount());
        self::assertSame($open, $selection->continues());
        self::assertSame([], $selection->preserved());
    }

    /**
     * A reopened task whose new pass was then interrupted: resume continues
     * generation 2 without touching generation 1.
     */
    #[Test]
    public function resuming_a_reopened_task_continues_its_own_generation(): void
    {
        $finished = self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d');
        $interrupted = RecordedGeneration::open(
            TaskGeneration::of(self::id(), 2),
            'ralph/TASK-19E0214A-g2-add-a-flag',
        );

        $selection = $this->selector->select(
            self::id(),
            GenerationHistory::of($finished, $interrupted),
        );

        self::assertSame(GenerationStart::Continued, $selection->start());
        self::assertSame(2, $selection->number());
        self::assertSame($interrupted, $selection->continues());
        self::assertSame([$finished], $selection->preserved());
        self::assertSame(['ralph/TASK-19E0214A-add-a-flag'], $selection->reservedBranches());
    }

    /**
     * Attempt counters must not carry over, or a task reopened after its
     * attempt limit was reached could never be run again.
     */
    #[Test]
    public function a_reopening_does_not_inherit_the_earlier_attempt_count(): void
    {
        $selection = $this->selector->select(
            self::id(),
            GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d')),
        );

        self::assertFalse($selection->start()->keepsAttemptCount());
    }

    /**
     * A pass that completed with nothing to change (PROJECT.md section G, item
     * 6) is still a completed pass to reopen from.
     */
    #[Test]
    public function a_pass_completed_without_a_commit_is_still_reopened_from(): void
    {
        $selection = $this->selector->select(
            self::id(),
            GenerationHistory::of(RecordedGeneration::completed(TaskGeneration::first(self::id()))),
        );

        self::assertTrue($selection->isReopening());
        self::assertSame(2, $selection->number());
        self::assertNull($selection->previousCommit());
        self::assertSame([], $selection->reservedBranches());
    }

    /**
     * The commit a reviewer is shown is the most recent one, not the first.
     */
    #[Test]
    public function the_previous_commit_is_the_latest_one_that_exists(): void
    {
        $selection = $this->selector->select(
            self::id(),
            GenerationHistory::of(
                self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
                RecordedGeneration::completed(TaskGeneration::of(self::id(), 2)),
            ),
        );

        self::assertSame(3, $selection->number());
        self::assertSame('a1b2c3d', $selection->previousCommit());
    }

    /**
     * Identity survives reopening, which is what makes the history findable at
     * all: the unticked item is the same task it was when it was ticked.
     */
    #[Test]
    public function a_reopened_task_keeps_the_identifier_it_had(): void
    {
        $before = self::parse("- [x] Add a --dry-run option\n");
        $after = self::parse("- [ ] Add a --dry-run option\n");

        $id = $before->all()[0]->id();

        self::assertTrue($after->all()[0]->id()->equals($id));
        self::assertSame(
            Completion::Pending,
            $after->completionOf($id),
            'the file is the authority on what is being asked for',
        );
    }

    /**
     * The two inputs stay separate: the file decides whether to run a task, the
     * history decides which pass it belongs to.
     */
    #[Test]
    public function the_checkbox_does_not_decide_the_generation(): void
    {
        $history = GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'));

        $ticked = self::task('Add a --dry-run option', true);
        $unticked = self::task('Add a --dry-run option', false);

        self::assertSame(
            $this->selector->selectFor($ticked, $history)->number(),
            $this->selector->selectFor($unticked, $history)->number(),
        );
        self::assertSame(2, $this->selector->selectFor($ticked, $history)->number());
    }

    /**
     * A completed pass does not retract the fact that it was verified, which is
     * what {@see TaskList} needs to label an imported tick.
     */
    #[Test]
    public function the_history_can_say_which_tasks_the_runner_verified(): void
    {
        $other = TaskId::fromString('TASK-ABCDEF12');

        $history = GenerationHistory::of(
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
            RecordedGeneration::open(TaskGeneration::first($other)),
        );

        $verified = $history->verified();

        self::assertTrue($verified->contains(self::id()));
        self::assertFalse($verified->contains($other));
        self::assertSame(1, $verified->count());
    }

    #[Test]
    public function an_empty_history_knows_nothing(): void
    {
        $history = GenerationHistory::none();

        self::assertTrue($history->isEmpty());
        self::assertFalse($history->knows(self::id()));
        self::assertNull($history->latestOf(self::id()));
        self::assertSame(0, $history->countOf(self::id()));
        self::assertFalse($history->hasOpen(self::id()));
        self::assertFalse($history->isComplete(self::id()));
        self::assertSame([], $history->generationsOf(self::id()));
        self::assertSame([], $history->branchesOf(self::id()));
        self::assertSame([], $history->records());
        self::assertSame([], $history->tasks());
        self::assertTrue($history->verified()->isEmpty());
    }

    /**
     * Recording a pass does not change the history the caller is holding.
     */
    #[Test]
    public function a_history_is_not_changed_by_recording_a_pass(): void
    {
        $history = GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'));

        $later = $history->with(RecordedGeneration::completed(
            TaskGeneration::of(self::id(), 2),
            'ralph/TASK-19E0214A-g2-add-a-flag',
            'e4f5a6b',
        ));

        self::assertSame(1, $history->countOf(self::id()));
        self::assertSame(2, $later->countOf(self::id()));
        self::assertTrue($history->knows(self::id()));
        self::assertSame(
            ['ralph/TASK-19E0214A-add-a-flag', 'ralph/TASK-19E0214A-g2-add-a-flag'],
            $later->branchesOf(self::id()),
        );
    }

    /**
     * Records arrive from a database in whatever order the query returned them.
     */
    /**
     * A pass that recorded no branch — one that stopped before it got that far,
     * or completed with nothing to change — reserves nothing, and must not
     * leave an empty name among the branches a new pass has to avoid.
     */
    #[Test]
    public function passes_with_no_branch_are_left_out_of_the_reserved_branches(): void
    {
        $history = GenerationHistory::of(
            RecordedGeneration::completed(TaskGeneration::first(self::id())),
            self::finished(2, 'ralph/TASK-19E0214A-g2-add-a-flag', 'e4f5a6b'),
        );

        self::assertSame(['ralph/TASK-19E0214A-g2-add-a-flag'], $history->branchesOf(self::id()));
        self::assertSame(
            ['ralph/TASK-19E0214A-g2-add-a-flag'],
            $this->selector->select(self::id(), $history)->reservedBranches(),
        );
    }

    #[Test]
    public function passes_are_ordered_by_generation_however_they_arrive(): void
    {
        $history = GenerationHistory::of(
            self::finished(2, 'ralph/TASK-19E0214A-g2-add-a-flag', 'e4f5a6b'),
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
        );

        self::assertSame([1, 2], array_map(
            static fn (RecordedGeneration $pass): int => $pass->number(),
            $history->generationsOf(self::id()),
        ));
        self::assertSame(2, $history->latestOf(self::id())?->number());
    }

    #[Test]
    public function one_generation_recorded_twice_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is recorded twice');

        GenerationHistory::of(
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
            RecordedGeneration::open(TaskGeneration::first(self::id())),
        );
    }

    /**
     * Two unfinished passes would leave it undecidable which branch the run
     * owns, and guessing is how unrelated work gets reset.
     */
    #[Test]
    public function an_unfinished_pass_that_is_not_the_latest_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not its latest generation');

        GenerationHistory::of(
            RecordedGeneration::open(TaskGeneration::first(self::id())),
            self::finished(2, 'ralph/TASK-19E0214A-g2-add-a-flag', 'e4f5a6b'),
        );
    }

    /**
     * Tasks do not interfere: one task's passes say nothing about another's.
     */
    #[Test]
    public function generations_are_counted_per_task(): void
    {
        $other = TaskId::fromString('TASK-ABCDEF12');

        $history = GenerationHistory::of(
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
            self::finished(2, 'ralph/TASK-19E0214A-g2-add-a-flag', 'e4f5a6b'),
        );

        self::assertSame(3, $this->selector->select(self::id(), $history)->number());
        self::assertSame(1, $this->selector->select($other, $history)->number());
        self::assertSame([self::id()->value()], $history->tasks());
    }

    #[Test]
    public function a_first_generation_cannot_be_a_reopening(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a reopening');

        GenerationSelection::reopened(TaskGeneration::first(self::id()), []);
    }

    #[Test]
    public function a_completed_pass_cannot_be_continued(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reopened rather than continued');

        GenerationSelection::continuing(
            self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'),
        );
    }

    /**
     * Preserving another task's pass as this one's history would misreport
     * whose branch is reserved.
     */
    #[Test]
    public function a_reopening_refuses_history_belonging_to_another_task(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a pass at');

        GenerationSelection::reopened(
            TaskGeneration::of(self::id(), 2),
            [RecordedGeneration::completed(TaskGeneration::first(TaskId::fromString('TASK-ABCDEF12')))],
        );
    }

    #[Test]
    public function the_reason_names_the_task_and_the_pass(): void
    {
        $history = GenerationHistory::of(self::finished(1, 'ralph/TASK-19E0214A-add-a-flag', 'a1b2c3d'));

        self::assertSame(
            'TASK-19E0214A has not been run before.',
            $this->selector->select(self::id())->reason(),
        );
        self::assertSame(
            'TASK-19E0214A was completed and has been reopened, so this run is generation 2 of it.',
            $this->selector->select(self::id(), $history)->reason(),
        );
        self::assertSame(
            'Generation 1 of TASK-19E0214A did not finish, so this run continues it.',
            $this->selector->select(
                self::id(),
                GenerationHistory::of(RecordedGeneration::open(TaskGeneration::first(self::id()))),
            )->reason(),
        );
    }

    #[Test]
    public function the_labels_say_which_pass_a_status_table_is_showing(): void
    {
        self::assertSame('first run', GenerationStart::FirstRun->label());
        self::assertSame('continued', GenerationStart::Continued->label());
        self::assertSame('reopened', GenerationStart::Reopened->label());
    }

    private static function id(): TaskId
    {
        return TaskId::fromString('TASK-19E0214A');
    }

    private static function finished(int $number, string $branch, string $commit): RecordedGeneration
    {
        return RecordedGeneration::completed(
            TaskGeneration::of(self::id(), $number),
            $branch,
            $commit,
        );
    }

    private static function task(string $description, bool $checked): Task
    {
        return self::parse(sprintf("- [%s] %s\n", $checked ? 'x' : ' ', $description))->all()[0];
    }

    private static function parse(string $markdown): TaskList
    {
        return TaskList::fromChecklist(
            (new ChecklistParser())->parse($markdown),
        );
    }
}
