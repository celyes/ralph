<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Verification;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\ExcludedChange;
use Celyes\RalphPhp\Git\ExclusionReason;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\WorkingTreeChange;
use Celyes\RalphPhp\Git\WorkspaceState;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\Satisfaction;
use Celyes\RalphPhp\Verification\SatisfactionAuditor;
use Celyes\RalphPhp\Verification\VerificationReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The case for completing a task that changed nothing (PROJECT.md section G,
 * item 6).
 *
 * The item has two halves and only one of them is easy. Completing a task
 * without a commit is three lines; the evidence is the rest, and what these
 * cases are about is that the evidence is *read* rather than collected and
 * filed. So they come in pairs: the facts that support a ticked box, and each
 * fact replaced by the thing that contradicts it.
 *
 * The one case worth naming on its own is the agent that ticks the checklist
 * inside its own workspace and changes nothing else. Everything a green run
 * can say is true of it — the checks passed, the index is empty — and
 * completing it would make a model-written checkbox into verified execution
 * state, which section D, item 6 forbids in as many words.
 */
#[CoversClass(Satisfaction::class)]
#[CoversClass(SatisfactionAuditor::class)]
final class SatisfactionTest extends TestCase
{
    private const string BASE = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private const string OTHER = '0f1e2d3c4b5a69788796a5b4c3d2e1f098765432';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('ralph-satisfaction');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    /**
     * The whole positive case: an empty index, a clean workspace at the base
     * commit, and checks that passed.
     */
    #[Test]
    public function an_empty_index_at_the_base_commit_with_passing_checks_is_justified(): void
    {
        $satisfaction = $this->satisfaction();

        self::assertTrue($satisfaction->isJustified());
        self::assertFalse($satisfaction->isBlocking());
        self::assertSame([], $satisfaction->objections());
        self::assertSame([], $satisfaction->hints());
        self::assertTrue($satisfaction->isAtBase());
        self::assertStringContainsString('the task was already satisfied', $satisfaction->summary());
        self::assertStringContainsString('2 check(s) passed', $satisfaction->summary());
        self::assertStringContainsString('already satisfied', $satisfaction->describe());
    }

    /**
     * A justified outcome records no failure evidence: it is not a failure,
     * and the column it would go in is read as one.
     */
    #[Test]
    public function a_justified_outcome_records_no_failure_evidence(): void
    {
        self::assertTrue($this->satisfaction()->evidence()->isEmpty());
    }

    /**
     * The agent that ticked its own box. Nothing to commit, checks green, and
     * not done.
     */
    #[Test]
    public function a_workspace_whose_only_change_was_to_ralphs_own_files_is_refused(): void
    {
        $satisfaction = $this->satisfaction(staged: StagedChanges::nothingStaged($this->directory, [
            ExcludedChange::of(WorkingTreeChange::of(' ', 'M', '.ralph/tasks.md'), ExclusionReason::Runtime),
        ]));

        self::assertFalse($satisfaction->isJustified());
        self::assertTrue($satisfaction->isBlocking());
        self::assertCount(1, $satisfaction->objections());
        self::assertStringContainsString('.ralph/tasks.md', $satisfaction->objections()[0]);
        self::assertStringContainsString('progress report', $satisfaction->objections()[0]);
        self::assertStringContainsString('not supported', $satisfaction->summary());

        // And the refusal is stored as what the package observed.
        $evidence = $satisfaction->evidence();
        self::assertFalse($evidence->isEmpty());
        self::assertStringContainsString('.ralph/tasks.md', $evidence->toMarkdown());
    }

    /**
     * The other half of that rule, and the one that keeps it usable: a `.env`
     * preparation copied in is excluded on every run of every project that
     * configures one, and is nobody's finding.
     */
    #[Test]
    public function a_local_file_copied_into_the_workspace_is_recorded_and_not_held_against_it(): void
    {
        $satisfaction = $this->satisfaction(staged: StagedChanges::nothingStaged($this->directory, [
            ExcludedChange::of(WorkingTreeChange::of('?', '?', '.env'), ExclusionReason::Local),
        ]));

        self::assertTrue($satisfaction->isJustified());
        self::assertCount(1, $satisfaction->localExclusions());
        self::assertSame([], $satisfaction->runtimeExclusions());
        self::assertContains(
            'excluded: untracked file: .env — a local file copied into the workspace',
            $satisfaction->details(),
        );
    }

    /**
     * A workspace that is no longer at the commit it was handed: the work may
     * be in a commit nothing recorded, and an empty diff says nothing about
     * it.
     */
    #[Test]
    public function a_workspace_that_moved_off_the_base_commit_is_refused(): void
    {
        $satisfaction = $this->satisfaction(state: WorkspaceState::of(self::OTHER));

        self::assertFalse($satisfaction->isJustified());
        self::assertFalse($satisfaction->isAtBase());
        self::assertStringContainsString('0f1e2d3c4b5a', $satisfaction->objections()[0]);
        self::assertStringContainsString('a1b2c3d4e5f6', $satisfaction->objections()[0]);
        self::assertStringContainsString('not the commit it was handed', implode("\n", $satisfaction->details()));
    }

    #[Test]
    public function a_half_finished_git_operation_is_refused(): void
    {
        $satisfaction = $this->satisfaction(
            state: WorkspaceState::of(self::BASE, operation: 'a rebase'),
        );

        self::assertFalse($satisfaction->isJustified());
        self::assertStringContainsString('a rebase is half-finished', $satisfaction->objections()[0]);
    }

    /**
     * Checks that did not pass, which cannot reach this in the loop and must
     * not be completed if they ever do: a no-change outcome is called verified
     * because of them.
     */
    #[Test]
    public function checks_that_did_not_pass_are_refused_before_anything_else(): void
    {
        $satisfaction = $this->satisfaction(verification: VerificationReport::of(
            $this->check('tests', CheckOutcome::Failed, 1),
        ));

        self::assertFalse($satisfaction->isJustified());
        self::assertStringContainsString('did not pass', $satisfaction->objections()[0]);
    }

    /**
     * An index with something in it is a commit, not a no-change outcome, and
     * a caller that asks the question anyway gets an answer rather than a
     * ticked box.
     */
    #[Test]
    public function an_index_with_something_in_it_is_refused(): void
    {
        $satisfaction = $this->satisfaction(staged: StagedChanges::parse(
            $this->directory,
            "M\0src/Import.php\0",
            "2\t1\tsrc/Import.php\0",
        ));

        self::assertFalse($satisfaction->isJustified());
        self::assertStringContainsString('something to commit after all', $satisfaction->objections()[0]);
    }

    /**
     * Objections are ordered by what makes the ones below it irrelevant, and
     * every one of them is kept.
     */
    #[Test]
    public function every_objection_is_reported_worst_first(): void
    {
        $satisfaction = $this->satisfaction(
            state: WorkspaceState::of(self::OTHER, operation: 'a merge'),
            staged: StagedChanges::nothingStaged($this->directory, [
                ExcludedChange::of(WorkingTreeChange::of(' ', 'M', '.ralph/config.json'), ExclusionReason::Runtime),
            ]),
            verification: VerificationReport::of($this->check('tests', CheckOutcome::Failed, 1)),
        );

        $objections = $satisfaction->objections();

        self::assertCount(4, $objections);
        self::assertStringContainsString('did not pass', $objections[0]);
        self::assertStringContainsString('Ralph PHP\'s own files', $objections[1]);
        self::assertStringContainsString('base commit', $objections[2]);
        self::assertStringContainsString('half-finished', $objections[3]);
    }

    /**
     * The findings are bounded for a message and the count stays exact, so a
     * blocker's reason cannot become a directory listing.
     */
    #[Test]
    public function many_excluded_changes_are_named_in_part_and_counted_in_full(): void
    {
        $excluded = [];

        for ($number = 1; $number <= Satisfaction::CHANGES_IN_MESSAGE + 3; $number++) {
            $excluded[] = ExcludedChange::of(
                WorkingTreeChange::of(' ', 'M', sprintf('.ralph/runtime/memory/note-%02d.md', $number)),
                ExclusionReason::Runtime,
            );
        }

        $satisfaction = $this->satisfaction(
            staged: StagedChanges::nothingStaged($this->directory, $excluded),
        );

        self::assertCount(Satisfaction::CHANGES_IN_MESSAGE + 3, $satisfaction->runtimeExclusions());
        self::assertStringContainsString('and 3 more', $satisfaction->objections()[0]);
        self::assertStringNotContainsString('note-07.md', $satisfaction->objections()[0]);
        self::assertContains('excluded: and 3 more', $satisfaction->details());
    }

    /**
     * What the record says, which is the half of the item that is not the
     * decision: every fact the box rests on, the agent's words marked as a
     * claim, and the limit of the whole reading.
     */
    #[Test]
    public function the_record_states_the_facts_the_claim_and_the_limits(): void
    {
        $markdown = $this->satisfaction()->toMarkdown();

        self::assertStringContainsString('# TASK-', $markdown);
        self::assertStringContainsString('attempt 2: already satisfied', $markdown);

        // The facts.
        self::assertStringContainsString('## What the package observed', $markdown);
        self::assertStringContainsString('a1b2c3d4e5f6', $markdown);
        self::assertStringContainsString('has nothing staged', $markdown);

        // The checks, by name and by command.
        self::assertStringContainsString('## The checks it rests on', $markdown);
        self::assertStringContainsString('**tests** — `composer test`', $markdown);
        self::assertStringContainsString('**analyse** — `composer analyse`', $markdown);

        // The claim, as a claim.
        self::assertStringContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('Nothing in them has been checked', $markdown);
        self::assertStringContainsString('the option is already there', $markdown);

        // And what a clean reading is still not evidence of.
        self::assertStringContainsString('## What this is not', $markdown);
        self::assertStringContainsString('does not say that any configured check tests', $markdown);
        self::assertStringContainsString('ignore rules', $markdown);
    }

    /**
     * A refused outcome's record says why, and says where the work is.
     */
    #[Test]
    public function a_refused_record_states_the_objections_and_the_next_action(): void
    {
        $satisfaction = $this->satisfaction(staged: StagedChanges::nothingStaged($this->directory, [
            ExcludedChange::of(WorkingTreeChange::of(' ', 'M', '.ralph/tasks.md'), ExclusionReason::Runtime),
        ]))->keptAt($this->directory . '/attempt-02-satisfied.md');

        $markdown = $satisfaction->toMarkdown();

        self::assertStringContainsString('nothing staged, and not satisfied', $markdown);
        self::assertStringContainsString('## Why it was refused', $markdown);
        self::assertStringContainsString('## Changes kept out of the commit', $markdown);
        self::assertStringContainsString(
            '- modified: .ralph/tasks.md — Ralph PHP\'s own files',
            $markdown,
        );
        self::assertStringContainsString('attempt-02-satisfied.md', $markdown);

        $hints = $satisfaction->hints();
        self::assertStringContainsString('kept at', $hints[0]);
        self::assertStringContainsString($this->directory, $hints[1]);
        self::assertStringContainsString('a ticked box in a task workspace is a claim', $hints[2]);
        self::assertStringContainsString('ralph retry TASK-', $hints[3]);
    }

    /**
     * An agent that said nothing leaves the section out rather than writing an
     * empty quotation.
     */
    #[Test]
    public function a_silent_agent_contributes_no_claim_section(): void
    {
        $markdown = $this->satisfaction(claim: UnverifiedText::none())->toMarkdown();

        self::assertStringNotContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('## The checks it rests on', $markdown);
    }

    /**
     * The auditor writes the record beside the attempt's logs, tells the log
     * what it found, and hands back a value that knows where its own evidence
     * went.
     */
    #[Test]
    public function the_auditor_keeps_the_record_and_notes_what_it_found(): void
    {
        $path = $this->directory . '/logs/TASK-1a2b3c4d-1/attempt-02-satisfied.md';
        $sink = new RecordingSink();

        $satisfaction = (new SatisfactionAuditor())->audit(
            $this->worktree(),
            2,
            BaseCommit::of('main', self::BASE),
            WorkspaceState::of(self::BASE),
            StagedChanges::nothingStaged($this->directory),
            $this->passingChecks(),
            UnverifiedText::of('nothing to do'),
            $path,
            $sink,
        );

        self::assertTrue($satisfaction->isJustified());
        self::assertSame($path, $satisfaction->recordPath());
        self::assertFileExists($path);
        self::assertStringContainsString(
            '## The checks it rests on',
            (string) file_get_contents($path),
        );

        $noted = implode("\n", $sink->notes);
        self::assertStringContainsString('no-change evidence kept at ' . $path, $noted);
        self::assertStringContainsString('the task was already satisfied', $noted);
        self::assertStringContainsString('check: tests', $noted);
    }

    /**
     * A record that could not be written does not turn a verified task into a
     * failed one — the durable evidence is the verification rows, and this is a
     * copy of it.
     */
    #[Test]
    public function a_record_that_cannot_be_written_is_noted_and_the_outcome_stands(): void
    {
        $blocked = $this->directory . '/not-a-directory';
        file_put_contents($blocked, 'in the way');

        $sink = new RecordingSink();

        $satisfaction = (new SatisfactionAuditor())->audit(
            $this->worktree(),
            1,
            BaseCommit::of('main', self::BASE),
            WorkspaceState::of(self::BASE),
            StagedChanges::nothingStaged($this->directory),
            $this->passingChecks(),
            UnverifiedText::none(),
            $blocked . '/attempt-01-satisfied.md',
            $sink,
        );

        self::assertTrue($satisfaction->isJustified());
        self::assertNull($satisfaction->recordPath());
        self::assertStringContainsString(
            'the no-change evidence could not be kept',
            implode("\n", $sink->notes),
        );
    }

    /**
     * Asked for nowhere to keep it, the auditor decides without writing
     * anything.
     */
    #[Test]
    public function the_auditor_can_decide_without_keeping_a_record(): void
    {
        $satisfaction = (new SatisfactionAuditor())->audit(
            $this->worktree(),
            1,
            BaseCommit::of('main', self::BASE),
            WorkspaceState::of(self::OTHER),
            StagedChanges::nothingStaged($this->directory),
            $this->passingChecks(),
            UnverifiedText::none(),
        );

        self::assertTrue($satisfaction->isBlocking());
        self::assertNull($satisfaction->recordPath());
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    /**
     * Everything a status command or an event would need, including the
     * verdict and what it was made on.
     */
    #[Test]
    public function the_whole_reading_is_available_as_data(): void
    {
        $data = $this->satisfaction()->toArray();

        self::assertSame('TASK-1A2B3C4D', $data['generation']);
        self::assertSame(2, $data['attempt']);
        self::assertTrue($data['justified']);
        self::assertSame([], $data['objections']);
        self::assertTrue($data['at_base']);
        self::assertSame(self::BASE, $data['head']);
        self::assertSame(['reference' => 'main', 'sha' => self::BASE], $data['base']);
        self::assertNull($data['record']);
        self::assertTrue($data['verification']['passed']);
        self::assertStringContainsString('already there', (string) $data['claim']);
    }

    /**
     * One case, with any of its facts replaced.
     */
    private function satisfaction(
        ?WorkspaceState $state = null,
        ?StagedChanges $staged = null,
        ?VerificationReport $verification = null,
        ?UnverifiedText $claim = null,
    ): Satisfaction {
        return Satisfaction::of(
            TaskGeneration::first(TaskId::fromString('TASK-1a2b3c4d')),
            2,
            $this->directory,
            BaseCommit::of('main', self::BASE),
            $state ?? WorkspaceState::of(self::BASE),
            $staged ?? StagedChanges::nothingStaged($this->directory),
            $verification ?? $this->passingChecks(),
            $claim ?? UnverifiedText::of('the option is already there, so I changed nothing'),
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

    private function worktree(): TaskWorktree
    {
        return TaskWorktree::created(
            TaskGeneration::first(TaskId::fromString('TASK-1a2b3c4d')),
            $this->directory,
            BaseCommit::of('main', self::BASE),
        );
    }
}
