<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\ExcludedChange;
use Celyes\RalphPhp\Git\ExclusionReason;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkspaceState;
use Celyes\RalphPhp\Run\CompletionEvidence;
use Celyes\RalphPhp\Run\CompletionRecorder;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\Satisfaction;
use Celyes\RalphPhp\Verification\VerificationReport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a ticked box rests on (PROJECT.md section G, item 7).
 *
 * The item is a condition and a record, and these cases take them in that
 * order. The condition is tested the way the no-change case is: the complete
 * set of facts that earns a tick, and then each one replaced by the thing that
 * withdraws it — checks that failed, no implementation recorded, a no-change
 * result nobody collected evidence for, a push still owed. The record is tested
 * for holding all five layers at once, because "together" is the whole point of
 * it: a reviewer with a ticked checklist in front of them should get the checks,
 * the commit, the publication, the checklist line and the agent's claim out of
 * one file.
 */
#[CoversClass(CompletionEvidence::class)]
#[CoversClass(CompletionRecorder::class)]
final class CompletionEvidenceTest extends TestCase
{
    private const string BASE = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private const string COMMIT = '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('ralph-completion');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    /**
     * The whole positive case: passing checks, a commit, and nothing owed to a
     * remote.
     */
    #[Test]
    public function passing_checks_a_commit_and_settled_publication_earn_the_tick(): void
    {
        $evidence = $this->evidence();

        self::assertTrue($evidence->isEarned());
        self::assertFalse($evidence->isRefused());
        self::assertSame([], $evidence->unmet());
        self::assertSame([], $evidence->hints());
        self::assertStringContainsString('2 of 2 checks passed', $evidence->summary());
        self::assertStringContainsString('committed as 9f8e7d6c5b4a', $evidence->summary());
        self::assertStringContainsString('completed: verified and committed', $evidence->describe());
    }

    /**
     * An earned completion is not a failure, so nothing goes in the column
     * that is read as one.
     */
    #[Test]
    public function an_earned_completion_records_no_failure_evidence(): void
    {
        self::assertTrue($this->evidence()->evidence()->isEmpty());
    }

    /**
     * The first condition. A commit the remote took and a green-looking row
     * still does not tick a box if the checks said no.
     */
    #[Test]
    public function checks_that_did_not_pass_withdraw_the_tick(): void
    {
        $evidence = $this->evidence(verification: VerificationReport::of(
            $this->check('tests', CheckOutcome::Failed, 1),
        ));

        self::assertTrue($evidence->isRefused());
        self::assertCount(1, $evidence->unmet());
        self::assertStringContainsString('the configured checks did not pass', $evidence->unmet()[0]);
        self::assertStringContainsString('is not checked off', $evidence->summary());
        self::assertStringContainsString('1 unmet condition', $evidence->describe());
    }

    /**
     * An empty *sequence* of checks is a defect — `passed()` would be vacuously
     * true for it — so it cannot be built at all, and the conditions never have
     * to test for one. The deliberate "this project configured none" report is
     * a different constructor and is the case below.
     */
    #[Test]
    public function a_report_with_no_checks_in_it_cannot_be_built_at_all(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain at least one check');

        VerificationReport::of();
    }

    /**
     * The completion a project with no `checks` reaches. It is earned — there
     * is nothing for it to fail, and refusing would leave such a project unable
     * to finish any task — but the record must never call it verification, so
     * that a reader can tell it from a tick a suite stood behind.
     */
    #[Test]
    public function a_completion_under_no_configured_checks_is_earned_without_claiming_verification(): void
    {
        $evidence = $this->evidence(verification: VerificationReport::none());

        self::assertTrue($evidence->isEarned());
        self::assertSame([], $evidence->unmet());

        $markdown = $evidence
            ->ticked(CheckboxEdit::applied('- [x] do it', TaskId::fromString('TASK-1A2B3C4D'), 12, true))
            ->toMarkdown();

        // The condition is not worded as a pass, because nothing passed.
        self::assertStringContainsString('- [x] **No configured check decided against the work', $markdown);
        self::assertStringNotContainsString('**Verification passed', $markdown);

        // And the checks section says what the tick actually rests on.
        self::assertStringContainsString('None. This run froze an empty `checks` set', $markdown);
        self::assertStringContainsString('the agent\'s own result and the recorded commit', $markdown);
        self::assertStringContainsString('review the diff before merging it', $markdown);

        // The commit is still named: it is half of what the tick rests on.
        self::assertStringContainsString(self::COMMIT, $markdown);
    }

    /**
     * The second condition: the Git half. Green checks over a workspace with
     * neither a commit nor a recorded no-change result tick nothing.
     */
    #[Test]
    public function a_pass_with_no_implementation_recorded_withdraws_the_tick(): void
    {
        $evidence = $this->evidence(commit: null);

        self::assertTrue($evidence->isRefused());
        self::assertStringContainsString('neither a commit nor a verified no-change result', $evidence->unmet()[0]);
    }

    /**
     * The third: a no-change outcome is the one completion with no commit to
     * read, so its evidence is part of what the tick rests on rather than a
     * note beside it.
     */
    #[Test]
    public function a_no_change_result_with_no_evidence_collected_withdraws_the_tick(): void
    {
        $evidence = $this->evidence(
            commit: null,
            noChange: true,
            staged: StagedChanges::nothingStaged($this->directory),
            satisfaction: null,
        );

        self::assertTrue($evidence->isRefused());
        self::assertStringContainsString('no evidence collected for it', $evidence->unmet()[0]);
    }

    /**
     * And a no-change case the facts contradict is refused here too, with the
     * contradiction quoted rather than restated.
     */
    #[Test]
    public function a_contradicted_no_change_result_withdraws_the_tick(): void
    {
        $evidence = $this->evidence(
            commit: null,
            noChange: true,
            staged: StagedChanges::nothingStaged($this->directory),
            satisfaction: $this->satisfaction(StagedChanges::nothingStaged($this->directory, [
                ExcludedChange::of(WorkingTreeChange::of(' ', 'M', '.ralph/tasks.md'), ExclusionReason::Runtime),
            ])),
        );

        self::assertTrue($evidence->isRefused());
        self::assertStringContainsString('contradicted', $evidence->unmet()[0]);
        self::assertStringContainsString('.ralph/tasks.md', $evidence->unmet()[0]);
    }

    /**
     * A justified no-change case earns the tick, and says so without claiming
     * a commit.
     */
    #[Test]
    public function a_justified_no_change_result_earns_the_tick(): void
    {
        $evidence = $this->evidence(
            commit: null,
            noChange: true,
            staged: StagedChanges::nothingStaged($this->directory),
            satisfaction: $this->satisfaction(),
        );

        self::assertTrue($evidence->isEarned());
        self::assertStringContainsString('nothing to commit', $evidence->summary());
        self::assertStringContainsString('nothing to commit, and the checks agree', $evidence->describe());
    }

    /**
     * The fourth condition. A push the configuration requires and the remote
     * has not taken leaves the task unchecked, however good the checks were.
     */
    #[Test]
    public function a_push_still_owed_withdraws_the_tick(): void
    {
        foreach ([PushState::Pending, PushState::Failed] as $state) {
            $evidence = $this->evidence(push: $state);

            self::assertTrue($evidence->isRefused(), $state->value);
            self::assertStringContainsString('publication is required', $evidence->unmet()[0]);
        }
    }

    /**
     * A push that succeeded settles the condition, as does a project that never
     * asked for one.
     */
    #[Test]
    public function a_published_or_unrequired_push_settles_the_condition(): void
    {
        foreach ([PushState::Published, PushState::NotRequired] as $state) {
            self::assertTrue($this->evidence(push: $state)->isEarned(), $state->value);
        }
    }

    /**
     * Every condition failing at once is reported in the order a reader needs
     * them: unverified work makes the rest moot, and an unrecorded
     * implementation makes the push irrelevant.
     */
    #[Test]
    public function the_unmet_conditions_are_ordered_worst_first(): void
    {
        $evidence = $this->evidence(
            verification: VerificationReport::of($this->check('tests', CheckOutcome::Failed, 1)),
            commit: null,
            noChange: true,
            staged: StagedChanges::nothingStaged($this->directory),
            satisfaction: null,
            push: PushState::Failed,
        );

        $unmet = $evidence->unmet();

        self::assertCount(3, $unmet);
        self::assertStringContainsString('checks did not pass', $unmet[0]);
        self::assertStringContainsString('no evidence collected', $unmet[1]);
        self::assertStringContainsString('publication is required', $unmet[2]);

        // And each is stored as its own finding, not as a count of findings.
        $stored = $evidence->evidence();
        self::assertFalse($stored->isEmpty());
        self::assertStringContainsString('checks did not pass', $stored->toMarkdown());
        self::assertStringContainsString('publication is required', $stored->toMarkdown());
    }

    /**
     * An implementation recorded as both a commit and a no-change result is
     * incoherent, and the no-change half is what decides: without a collected
     * case there is nothing saying the task was already done.
     */
    #[Test]
    public function a_commit_recorded_alongside_a_no_change_result_is_refused(): void
    {
        $evidence = $this->evidence(noChange: true, satisfaction: null);

        self::assertTrue($evidence->isRefused());
        self::assertStringContainsString('no evidence collected for it', $evidence->unmet()[0]);
    }

    /**
     * The tick is not assumed from the conditions holding: it is a separate
     * write, and the evidence says so only once it has landed.
     */
    #[Test]
    public function the_evidence_reports_the_checklist_write_only_once_it_has_landed(): void
    {
        $evidence = $this->evidence();

        self::assertFalse($evidence->wasChecked());
        self::assertNull($evidence->edit());
        self::assertStringContainsString('not written yet', implode("\n", $evidence->details()));
        self::assertStringContainsString('was not written', $evidence->toMarkdown());

        $ticked = $evidence->ticked(
            CheckboxEdit::applied('- [x] do the thing', TaskId::fromString('TASK-1A2B3C4D'), 7, true),
        );

        self::assertTrue($ticked->wasChecked());
        self::assertSame(7, $ticked->edit()?->line());
        self::assertStringContainsString('line 7, now checked', implode("\n", $ticked->details()));

        // And the original is untouched: this is a value, not a mutable row.
        self::assertFalse($evidence->wasChecked());
    }

    /**
     * The record holds all five layers at once, which is what "record
     * completion evidence together" asks for.
     */
    #[Test]
    public function the_record_holds_the_checks_the_commit_the_push_the_checklist_and_the_claim(): void
    {
        $markdown = $this->evidence()
            ->ticked(CheckboxEdit::applied('- [x] do it', TaskId::fromString('TASK-1A2B3C4D'), 12, true))
            ->toMarkdown();

        // The conditions, each with its answer.
        self::assertStringContainsString('## The conditions', $markdown);
        self::assertStringContainsString('- [x] **Verification passed', $markdown);
        self::assertStringContainsString('- [x] **An implementation is recorded', $markdown);
        self::assertStringContainsString('- [x] **Publication is settled', $markdown);

        // The checks, named and with their commands.
        self::assertStringContainsString('## The checks', $markdown);
        self::assertStringContainsString('`composer test`', $markdown);
        self::assertStringContainsString('`composer analyse`', $markdown);

        // Git: the base, the branch, the commit and what is in it.
        self::assertStringContainsString('## Git', $markdown);
        self::assertStringContainsString('main (' . substr(self::BASE, 0, 12) . ')', $markdown);
        self::assertStringContainsString('ralph/task-1a2b3c4d', $markdown);
        self::assertStringContainsString(self::COMMIT, $markdown);
        self::assertStringContainsString('`src/Thing.php`', $markdown);
        self::assertStringContainsString('git show 9f8e7d6c5b4a', $markdown);

        // The checklist line.
        self::assertStringContainsString('## The checklist', $markdown);
        self::assertStringContainsString('line 12 now reads `- [x]`', $markdown);

        // The agent, by name, without a credential anywhere near it.
        self::assertStringContainsString('codex', $markdown);

        // The claim, marked as one.
        self::assertStringContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('Nothing in them has been checked', $markdown);
        self::assertStringContainsString('added the option', $markdown);

        // And the limit, where a reader meets it.
        self::assertStringContainsString('## What this is not', $markdown);
        self::assertStringContainsString('tests what this task asked for', $markdown);
    }

    /**
     * A refusal's record reads as the same document with different answers in
     * it, and names what to do next.
     */
    #[Test]
    public function a_refused_completion_records_the_conditions_it_failed(): void
    {
        $markdown = $this->evidence(push: PushState::Failed)->toMarkdown();

        self::assertStringContainsString('not checked off', $markdown);
        self::assertStringContainsString('- [x] **Verification passed', $markdown);
        self::assertStringContainsString('- [ ] **Publication is settled', $markdown);
        self::assertStringContainsString('left as you wrote it', $markdown);
        self::assertStringContainsString('ralph retry TASK-1A2B3C4D', $markdown);
    }

    /**
     * A refusal's hints keep the commit visible: the implementation is durable
     * and must not be repeated.
     */
    #[Test]
    public function a_refused_completion_names_the_commit_it_keeps(): void
    {
        $hints = $this->evidence(push: PushState::Failed)->hints();

        self::assertStringContainsString('left as you wrote it', $hints[0]);
        self::assertStringContainsString('9f8e7d6c5b4a', $hints[1]);
        self::assertStringContainsString('not repeated', $hints[1]);
    }

    /**
     * A diff longer than the record names is counted rather than listed: the
     * commit is the authority for the whole of it.
     */
    #[Test]
    public function a_long_diff_is_counted_rather_than_listed_in_full(): void
    {
        $paths = [];

        for ($index = 1; $index <= CompletionEvidence::PATHS_IN_RECORD + 3; $index++) {
            $paths[] = sprintf('src/File%02d.php', $index);
        }

        $markdown = $this->evidence(staged: $this->staged($paths))->toMarkdown();

        self::assertStringContainsString('`src/File01.php`', $markdown);
        self::assertStringContainsString('`src/File20.php`', $markdown);
        self::assertStringNotContainsString('`src/File21.php`', $markdown);
        self::assertStringContainsString('and 3 more', $markdown);
    }

    /**
     * The structured form carries every layer too, for `status --json` and the
     * events of section I.
     */
    #[Test]
    public function the_structured_form_carries_each_layer(): void
    {
        $array = $this->evidence()
            ->ticked(CheckboxEdit::applied('- [x] do it', TaskId::fromString('TASK-1A2B3C4D'), 4, true))
            ->toArray();

        self::assertTrue($array['earned']);
        self::assertSame([], $array['unmet']);
        self::assertTrue($array['checked']);
        self::assertSame('TASK-1A2B3C4D', $array['task']);
        self::assertSame('TASK-1A2B3C4D', $array['generation']);
        self::assertSame('codex', $array['agent']);
        self::assertSame(self::COMMIT, $array['commit']);
        self::assertSame('ralph/task-1a2b3c4d', $array['branch']);
        self::assertFalse($array['no_change']);
        self::assertSame(4, $array['checklist']['line']);
        self::assertSame('.ralph/tasks.md', $array['checklist']['path']);
        self::assertIsArray($array['verification']);
        self::assertIsArray($array['staged']);
        self::assertNull($array['record']);
    }

    /**
     * The recorder writes the record beside the attempt's logs, notes where it
     * went, and hands back evidence that knows its own path.
     */
    #[Test]
    public function the_recorder_keeps_the_record_and_notes_where_it_went(): void
    {
        $path = $this->directory . '/logs/TASK-1A2B3C4D/attempt-02-completed.md';
        $sink = new RecordingSink();

        $kept = (new CompletionRecorder())->record($this->evidence(), $path, $sink);

        self::assertSame($path, $kept->recordPath());
        self::assertFileExists($path);
        self::assertStringContainsString('## The conditions', (string) file_get_contents($path));
        self::assertStringContainsString('completion evidence kept at ' . $path, implode("\n", $sink->notes));

        // The verdict and the facts reach the log as well as the file.
        self::assertStringContainsString('completed: verified and committed', implode("\n", $sink->notes));
        self::assertStringContainsString('commit: 9f8e7d6c5b4a', implode("\n", $sink->notes));
        self::assertStringContainsString('publication: ', implode("\n", $sink->notes));
    }

    /**
     * A record that cannot be written does not un-complete a task: the facts
     * are durable in the state store, and this is a copy for a reader.
     */
    #[Test]
    public function a_record_that_cannot_be_written_leaves_the_outcome_alone(): void
    {
        $blocker = $this->directory . '/not-a-directory';
        file_put_contents($blocker, 'in the way');

        $sink = new RecordingSink();
        $kept = (new CompletionRecorder())->record($this->evidence(), $blocker . '/attempt-01-completed.md', $sink);

        self::assertNull($kept->recordPath());
        self::assertTrue($kept->isEarned());
        self::assertStringContainsString('could not be kept', implode("\n", $sink->notes));
    }

    /**
     * A caller with nowhere to write still gets the verdict on the log.
     */
    #[Test]
    public function the_recorder_notes_the_verdict_with_no_path_to_write_to(): void
    {
        $sink = new RecordingSink();
        $kept = (new CompletionRecorder())->record($this->evidence(), null, $sink);

        self::assertNull($kept->recordPath());
        self::assertStringContainsString('completed: verified and committed', implode("\n", $sink->notes));
        self::assertStringNotContainsString('kept at', implode("\n", $sink->notes));
    }

    /**
     * One completion's facts, with any of them replaceable.
     */
    private function evidence(
        ?VerificationReport $verification = null,
        ?string $commit = self::COMMIT,
        bool $noChange = false,
        ?StagedChanges $staged = null,
        ?Satisfaction $satisfaction = null,
        PushState $push = PushState::Published,
        ?UnverifiedText $claim = null,
    ): CompletionEvidence {
        return CompletionEvidence::of(
            $this->record($commit, $noChange, $push),
            'add a --force option to the init command',
            2,
            BaseCommit::of('main', self::BASE),
            $verification ?? $this->passingChecks(),
            $staged ?? $this->staged(['src/Thing.php']),
            $satisfaction,
            $claim ?? UnverifiedText::of('added the option and a test for it'),
            '.ralph/tasks.md',
        );
    }

    /**
     * A generation row as the store would have read it back at the moment the
     * box is about to be ticked.
     */
    private function record(?string $commit, bool $noChange, PushState $push): GenerationRecord
    {
        return GenerationRecord::fromRow([
            'id' => 4,
            'slug' => 'TASK-1A2B3C4D',
            'run_id' => 1,
            'state' => 'running',
            'phase' => 'finalizing',
            'attempts' => 2,
            'content_hash' => str_repeat('c', 64),
            'agent' => 'codex',
            'adapter' => 'codex',
            'agent_settings' => '{"executable":"codex"}',
            'base_reference' => 'main',
            'base_sha' => self::BASE,
            'branch' => 'ralph/task-1a2b3c4d',
            'commit_sha' => $commit,
            'no_change' => $noChange ? 1 : 0,
            'push_state' => $push->value,
            'push_remote' => $push === PushState::NotRequired ? null : 'origin',
            'push_error' => $push === PushState::Failed ? 'non-fast-forward' : null,
            'worktree_path' => $this->directory,
            'owns_worktree' => 1,
            'blocker' => null,
            'started_at' => '2026-11-07T09:00:00+00:00',
            'updated_at' => '2026-11-07T09:04:00+00:00',
            'finished_at' => null,
        ]);
    }

    /**
     * An index with one modified file per path, built from the two outputs Git
     * is actually asked for, so the fixture cannot describe a diff that
     * {@see StagedChanges::parse()} would reject.
     *
     * @param list<string> $paths
     */
    private function staged(array $paths): StagedChanges
    {
        $statuses = [];
        $counts = [];

        foreach ($paths as $path) {
            $statuses[] = 'M';
            $statuses[] = $path;
            $counts[] = "3\t1\t" . $path;
        }

        return StagedChanges::parse(
            $this->directory,
            self::records(...$statuses),
            self::records(...$counts),
        );
    }

    /**
     * Git's `-z` output: every record followed by a NUL.
     */
    private static function records(string ...$records): string
    {
        return implode('', array_map(static fn (string $record): string => $record . "\0", $records));
    }

    /**
     * A no-change case, justified unless something excluded is handed in.
     */
    private function satisfaction(?StagedChanges $staged = null): Satisfaction
    {
        return Satisfaction::of(
            TaskGeneration::first(TaskId::fromString('TASK-1A2B3C4D')),
            2,
            $this->directory,
            BaseCommit::of('main', self::BASE),
            WorkspaceState::of(self::BASE),
            $staged ?? StagedChanges::nothingStaged($this->directory),
            $this->passingChecks(),
            UnverifiedText::of('it was already there'),
        );
    }

    private function passingChecks(): VerificationReport
    {
        return VerificationReport::of(
            $this->check('tests', CheckOutcome::Passed, 0, ['composer', 'test']),
            $this->check('analyse', CheckOutcome::Passed, 0, ['composer', 'analyse']),
        );
    }

    /**
     * @param list<string> $command
     */
    private function check(
        string $name,
        CheckOutcome $outcome,
        int $exitCode,
        array $command = ['composer', 'test'],
    ): CheckRun {
        return CheckRun::of(
            new VerificationCommand($name, $command),
            $outcome,
            $this->directory,
            $exitCode,
            1.5,
            $outcome === CheckOutcome::Passed ? '' : 'Failed asserting that false is true.',
        );
    }
}
