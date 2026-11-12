<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Exception\BranchException;
use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\Run\RunBudget;
use Celyes\RalphPhp\Run\RunStop;
use Celyes\RalphPhp\Run\TaskResult;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\State\StopKind;
use Celyes\RalphPhp\Tasks\ChecklistItem;
use Celyes\RalphPhp\Tasks\Task;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Why a run ended, and what the user is told to do about it.
 *
 * PROJECT.md section H, item 9 wants two things from every stop: a reason that
 * reads, and a next action. What is asserted here is that both are there for
 * each of the six endings, that neither can be left out, and — the part that is
 * not cosmetic — that the action named is one that would actually get the work
 * moving again. A next action a user can follow to no effect is worse than
 * none, because they will believe they have tried.
 *
 * That the loop reaches these endings at all is {@see RunnerTest}; this is the
 * wording and the arithmetic.
 */
#[CoversClass(RunStop::class)]
final class RunStopTest extends TestCase
{
    #[Test]
    public function a_stop_states_a_reason_and_an_action(): void
    {
        $stop = RunStop::of(StopKind::Finished, 'the checklist ran out', 'Add the next task.');

        self::assertSame(StopKind::Finished, $stop->kind());
        self::assertSame('the checklist ran out', $stop->reason());
        self::assertSame('Add the next task.', $stop->nextAction());
        self::assertNull($stop->task());
        self::assertFalse($stop->needsAttention());
        self::assertSame('the checklist ran out', $stop->describe());
        self::assertSame('the checklist ran out — next: Add the next task.', $stop->describeFully());
    }

    #[Test]
    public function a_stop_without_a_reason_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A run that stopped must record its reason.');

        RunStop::of(StopKind::Finished, '   ', 'Add the next task.');
    }

    #[Test]
    public function a_stop_without_a_next_action_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A run that stopped must record its next action.');

        RunStop::of(StopKind::Finished, 'the checklist ran out', '');
    }

    #[Test]
    public function a_long_half_is_shortened_rather_than_refused(): void
    {
        $stop = RunStop::of(StopKind::Failed, str_repeat('a', RunStop::LIMIT + 50), 'Look at it.');

        self::assertSame(RunStop::LIMIT + 1, mb_strlen($stop->reason()));
        self::assertStringEndsWith('…', $stop->reason());
    }

    /**
     * The one ending where the loop stopped rather than ran out, and the pass's
     * own blocker is what is quoted: the judgement was made there, and a
     * paraphrase here is one more thing that can disagree with the row.
     */
    #[Test]
    public function a_blocked_pass_is_quoted_rather_than_described_again(): void
    {
        $result = $this->pass(
            attempts: 1,
            blocker: Blocker::from('codex', 'Which importer should this wrap?', 'Name the importer in the task.'),
        );

        $stop = RunStop::decision($result);

        self::assertSame(StopKind::Decision, $stop->kind());
        self::assertStringContainsString($result->taskId()->value(), $stop->reason());
        self::assertStringContainsString('needs human input', $stop->reason());
        self::assertStringContainsString('Which importer should this wrap?', $stop->reason());
        self::assertSame('Name the importer in the task.', $stop->nextAction());
        self::assertSame($result->taskId(), $stop->task());
        self::assertTrue($stop->needsAttention());
    }

    #[Test]
    public function a_blocked_pass_that_recorded_nothing_still_gets_an_action(): void
    {
        $stop = RunStop::decision($this->pass(attempts: 1, blocker: null));

        self::assertStringContainsString('recorded no reason', $stop->reason());
        self::assertStringContainsString('ralph logs', $stop->nextAction());
        self::assertTrue($stop->needsAttention());
    }

    /**
     * Section H, item 9's "exhausted attempts": the run is left naming the task
     * it gave up on, and the action it reports is the pass's own.
     *
     * The pass is where the giving up happened and where the reason for it is
     * recorded — a spent attempt limit wants the limit raised or the task
     * restated, a branch the runner would not touch wants the branch dealt with
     * — so one sentence written here for all of them would be advice for a
     * situation the run was not in.
     */
    #[Test]
    public function a_task_that_ran_out_of_attempts_is_named_and_quotes_its_own_pass(): void
    {
        $spent = $this->pass(
            attempts: 3,
            blocker: Blocker::raised(
                'verification failed: tests exited 1',
                'The attempt limit of 3 per task is spent: raise `limits.attempts_per_task`.',
            ),
        );

        $stop = RunStop::unfinished([$spent], 4, 3);

        self::assertSame(StopKind::Attempts, $stop->kind());
        self::assertTrue($stop->needsAttention());
        self::assertStringContainsString('1 of 4 did not pass', $stop->reason());
        self::assertStringContainsString($spent->taskId()->value() . ' ran out of attempts', $stop->reason());

        self::assertSame($spent->blocker()?->nextAction(), $stop->nextAction());
        self::assertStringNotContainsString('ralph retry', $stop->nextAction());
        self::assertSame($spent->taskId(), $stop->task());
    }

    /**
     * A bail: the run was asked to stop at the first task that did not pass,
     * and what it reports is that task's own next action plus the standing of
     * the tasks it never started.
     *
     * The distinction that matters is in the second half. A bailed run leaves
     * two different kinds of unfinished work behind — one task that was
     * attempted and failed, and however many that were never touched — and a
     * user who is told only about the first will not know whether `ralph run`
     * has anything left to do.
     */
    #[Test]
    public function a_bail_names_the_failed_task_and_the_tasks_it_never_started(): void
    {
        $failed = $this->pass(
            attempts: 2,
            blocker: Blocker::raised(
                'verification failed: tests exited 1',
                'Read `ralph logs` for the failing check, then run `ralph retry`.',
            ),
        );

        $stop = RunStop::bailed($failed, 3);

        // Not `Attempts`: a bail can happen on a first attempt, and reporting
        // spent attempts that were never spent would send the user to raise a
        // limit that is not the problem.
        self::assertSame(StopKind::Failed, $stop->kind());
        self::assertTrue($stop->needsAttention());
        self::assertSame($failed->taskId(), $stop->task());

        self::assertStringContainsString($failed->taskId()->value() . ' did not pass', $stop->reason());
        self::assertStringContainsString('asked to stop at the first failure', $stop->reason());
        self::assertStringContainsString('3 tasks untouched', $stop->reason());

        // The pass's own action first, then what is true of the rest of the
        // checklist and how to stop bailing.
        self::assertStringContainsString('Read `ralph logs`', $stop->nextAction());
        self::assertStringContainsString('still pending', $stop->nextAction());
        self::assertStringContainsString('--no-bail', $stop->nextAction());
    }

    /**
     * A bail on the last pending task: there is nothing untouched to report, so
     * nothing is said about any.
     *
     * "with 0 tasks untouched" is the kind of line that makes a user re-read a
     * sentence to learn nothing, and offering `ralph run` to carry on with
     * tasks that do not exist would be worse than saying nothing.
     */
    #[Test]
    public function a_bail_with_nothing_left_says_nothing_about_untouched_tasks(): void
    {
        $stop = RunStop::bailed($this->pass(attempts: 1), 0);

        self::assertSame(StopKind::Failed, $stop->kind());
        self::assertStringNotContainsString('untouched', $stop->reason());
        self::assertStringNotContainsString('still pending', $stop->nextAction());
        self::assertStringContainsString('ralph logs', $stop->nextAction());
    }

    /**
     * Ambiguous ownership found while a task was being worked on: the pass
     * failed rather than blocked, so the loop carried on, and what the run
     * reports at the end is the refusal's own next action rather than a retry
     * that would meet the same branch again.
     */
    #[Test]
    public function a_refusal_the_pass_recorded_is_what_the_run_passes_on(): void
    {
        $refused = $this->pass(
            attempts: 0,
            blocker: Blocker::raised(
                'ralph/task-1a2b3c4d is owned by unrelated work',
                'Rename the branch, or delete it if the work on it is not wanted.',
            ),
        );

        $stop = RunStop::unfinished([$refused], 1, 3);

        self::assertSame(StopKind::Finished, $stop->kind());
        self::assertSame('Rename the branch, or delete it if the work on it is not wanted.', $stop->nextAction());
    }

    #[Test]
    public function a_pass_that_recorded_no_action_falls_back_to_the_logs(): void
    {
        $stop = RunStop::unfinished([$this->pass(attempts: 1)], 3, 3);

        self::assertStringContainsString('ralph logs', $stop->nextAction());
        self::assertStringContainsString('ralph retry', $stop->nextAction());
    }

    /**
     * With more than one task unfinished, the action says which of them it is
     * about: the user is reading one line about a run that worked through
     * several tasks.
     */
    #[Test]
    public function several_spent_tasks_are_counted_and_the_action_names_its_task(): void
    {
        $first = $this->pass(attempts: 2, blocker: Blocker::raised('the checks failed', 'Raise the limit.'));
        $second = $this->pass(attempts: 2, what: 'the export command');

        $stop = RunStop::unfinished([$first, $second], 2, 2);

        self::assertStringContainsString('2 of 2 did not pass and 2 of them ran out of attempts', $stop->reason());
        self::assertSame($first->taskId()->value() . ': Raise the limit.', $stop->nextAction());
    }

    #[Test]
    public function a_run_with_nothing_unfinished_is_not_a_stop_short_of_anything(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RunStop::unfinished([], 2, 3);
    }

    #[Test]
    public function a_spent_iteration_limit_names_the_setting_that_would_raise_it(): void
    {
        $stop = RunStop::limitReached(RunBudget::of(new Limits(1, 3, 600, 7200), 1, 10), 2);

        self::assertSame(StopKind::Limit, $stop->kind());
        self::assertFalse($stop->needsAttention(), 'A run that did what it was configured to do is not a problem.');
        self::assertStringContainsString('iteration limit of 1 was reached', $stop->reason());
        self::assertStringContainsString('2 tasks still pending', $stop->reason());
        self::assertStringContainsString('still pending', $stop->nextAction());
        self::assertStringContainsString('limits.iterations', $stop->nextAction());
    }

    #[Test]
    public function a_spent_time_budget_names_its_own_setting(): void
    {
        $stop = RunStop::limitReached(RunBudget::of(new Limits(10, 3, 600, 90), 1, 90), 1);

        self::assertStringContainsString('time budget of 90 seconds was spent', $stop->reason());
        self::assertStringContainsString('1 task still pending', $stop->reason());
        self::assertStringContainsString('limits.run_timeout', $stop->nextAction());
    }

    /**
     * The checklist and the limit running out together leaves nothing to do,
     * and saying "run it again" would send a user to a run with no work in it.
     */
    #[Test]
    public function a_limit_that_left_nothing_undone_says_so(): void
    {
        $stop = RunStop::limitReached(RunBudget::of(new Limits(2, 3, 600, 7200), 2, 10), 0);

        self::assertStringContainsString('Nothing was left undone', $stop->nextAction());
        self::assertStringNotContainsString('limits.iterations', $stop->nextAction());
    }

    #[Test]
    public function an_interruption_sends_the_user_to_resume(): void
    {
        $stop = RunStop::interrupted(Interruption::fromSignal(Interruption::SIGINT), 2);

        self::assertSame(StopKind::Interrupted, $stop->kind());
        self::assertStringContainsString('SIGINT', $stop->reason());
        self::assertStringContainsString('2 tasks still pending', $stop->reason());
        self::assertStringContainsString('ralph resume', $stop->nextAction());
    }

    /**
     * A stop that surfaced where the loop was not counting tasks says nothing
     * about how many are left, rather than claiming none are.
     */
    #[Test]
    public function an_interruption_with_nothing_counted_claims_no_count(): void
    {
        $stop = RunStop::interrupted(null);

        self::assertSame('the run was stopped', $stop->reason());
        self::assertStringContainsString('ralph resume', $stop->nextAction());
    }

    /**
     * Ambiguous ownership found before a task was taken on arrives as a
     * failure, and the failure's own hints are the documented next action.
     */
    #[Test]
    public function a_failed_run_carries_the_failures_own_hints_as_its_action(): void
    {
        $refused = new BranchException('ralph/task-1a2b3c4d holds a commit no pass recorded', [
            'Rename the branch, or delete it if the work on it is not wanted.',
            'Nothing was reset.',
        ]);

        $stop = RunStop::failure('BranchException: ' . $refused->getMessage(), $refused->hints());

        self::assertSame(StopKind::Failed, $stop->kind());
        self::assertTrue($stop->needsAttention());
        self::assertStringContainsString('holds a commit no pass recorded', $stop->reason());
        self::assertSame(
            'Rename the branch, or delete it if the work on it is not wanted. Nothing was reset.',
            $stop->nextAction(),
        );
    }

    #[Test]
    public function a_failure_with_no_hints_still_documents_something(): void
    {
        $stop = RunStop::failure('StateException: the row could not be written', ['  ', '']);

        self::assertStringContainsString('logs', $stop->nextAction());
    }

    #[Test]
    public function the_ordinary_endings_have_actions_too(): void
    {
        $empty = RunStop::noTasks('.ralph/tasks.md');
        self::assertStringContainsString('.ralph/tasks.md states no tasks', $empty->reason());
        self::assertStringContainsString('`- [ ]`', $empty->nextAction());

        $checked = RunStop::nothingPending('.ralph/tasks.md');
        self::assertStringContainsString('nothing pending', $checked->reason());
        self::assertStringContainsString('Add the next task to .ralph/tasks.md', $checked->nextAction());

        $done = RunStop::everythingCompleted(3, '.ralph/tasks.md');
        self::assertSame(StopKind::Finished, $done->kind());
        self::assertStringContainsString('every pending task was completed (3 tasks)', $done->reason());
        self::assertStringContainsString('Review the task branches', $done->nextAction());

        self::assertFalse($empty->needsAttention());
        self::assertFalse($checked->needsAttention());
        self::assertFalse($done->needsAttention());
    }

    #[Test]
    public function a_stop_is_readable_as_plain_data(): void
    {
        $result = $this->pass(attempts: 2, blocker: Blocker::raised('the push was refused', 'Fix the remote.'));

        self::assertSame(
            [
                'kind' => 'decision',
                'reason' => sprintf('%s needs human input: the push was refused', $result->taskId()->value()),
                'next_action' => 'Fix the remote.',
                'task' => $result->taskId()->value(),
                'needs_attention' => true,
            ],
            RunStop::decision($result)->toArray(),
        );
    }

    /**
     * A task result as the loop hands it over: the row is the authority for the
     * state, the attempts and the blocker, so a fixture row is all this needs.
     */
    private function pass(int $attempts, ?Blocker $blocker = null, string $what = 'the import command'): TaskResult
    {
        $task = Task::fromItem(new ChecklistItem(
            'Add a --dry-run option to ' . $what,
            false,
            7,
            '- [ ] Add a --dry-run option to ' . $what,
        ));

        $record = GenerationRecord::fromRow([
            'id' => 9,
            'slug' => $task->id()->value() . '-g1',
            'run_id' => 1,
            'state' => $blocker === null ? 'failed' : 'blocked',
            'phase' => null,
            'attempts' => $attempts,
            'content_hash' => str_repeat('c', 64),
            'agent' => 'codex',
            'adapter' => 'codex',
            'agent_settings' => '{"executable":"codex"}',
            'base_reference' => 'main',
            'base_sha' => str_repeat('a', 40),
            'branch' => 'ralph/' . strtolower($task->id()->value()),
            'commit_sha' => null,
            'no_change' => 0,
            'push_state' => PushState::NotRequired->value,
            'push_remote' => null,
            'push_error' => null,
            'worktree_path' => '/tmp/ralph-worktree',
            'owns_worktree' => 1,
            'blocker' => $blocker?->toStorage(),
            'started_at' => '2026-11-08T09:00:00+00:00',
            'updated_at' => '2026-11-08T09:04:00+00:00',
            'finished_at' => '2026-11-08T09:04:00+00:00',
        ]);

        return TaskResult::of($task, $record);
    }
}
