<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Run\CompletionEvidence;
use Celyes\RalphPhp\Run\CompletionRecorder;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Verification\CheckOutcome;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\VerificationReport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a ticked box rests on (PROJECT.md section G, item 7).
 *
 * The item is a condition and a record, and these cases take them in that
 * order. The condition is the configured checks and, since section K, item 1
 * removed the package's Git work, only the configured checks: it is tested with
 * the facts that earn a tick and then with the one thing that withdraws it. The
 * record is tested for holding every layer at once, because "together" is the
 * whole point of it — a reviewer with a ticked checklist in front of them
 * should get the checks, where the changes are, the checklist line and the
 * agent's claim out of one file.
 */
#[CoversClass(CompletionEvidence::class)]
#[CoversClass(CompletionRecorder::class)]
final class CompletionEvidenceTest extends TestCase
{
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
     * The whole positive case: the configured checks passed against the work in
     * the user's own checkout.
     */
    #[Test]
    public function passing_checks_earn_the_tick(): void
    {
        $evidence = $this->evidence();

        self::assertTrue($evidence->isEarned());
        self::assertFalse($evidence->isRefused());
        self::assertSame([], $evidence->unmet());
        self::assertSame([], $evidence->hints());
        self::assertStringContainsString('2 of 2 checks passed', $evidence->summary());
        self::assertStringContainsString('in the working tree for review', $evidence->summary());
        self::assertStringContainsString('completed: verified in the working tree', $evidence->describe());
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
     * The condition, withdrawn. A worker that reported a candidate
     * implementation still does not tick a box if the checks said no.
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
     * true for it — so it cannot be built at all, and the condition never has
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

        $markdown = $evidence->ticked($this->edit())->toMarkdown();

        // The condition is not worded as a pass, because nothing passed.
        self::assertStringContainsString('- [x] **No configured check decided against the work', $markdown);
        self::assertStringNotContainsString('**Verification passed', $markdown);

        // And the checks section says what the tick actually rests on.
        self::assertStringContainsString('None. This run froze an empty `checks` set', $markdown);
        self::assertStringContainsString('the agent\'s own result', $markdown);
        self::assertStringContainsString('read the diff before you commit it', $markdown);
    }

    /**
     * The write is a separate step from the condition, and the record says
     * which of the two it is describing.
     */
    #[Test]
    public function the_evidence_reports_the_checklist_write_only_once_it_has_landed(): void
    {
        $evidence = $this->evidence();

        self::assertFalse($evidence->wasChecked());
        self::assertNull($evidence->edit());
        self::assertStringContainsString('not written yet', implode("\n", $evidence->details()));
        self::assertStringContainsString('was not written', $evidence->toMarkdown());

        $ticked = $evidence->ticked($this->edit());

        self::assertTrue($ticked->wasChecked());
        self::assertSame(12, $ticked->edit()?->line());
        self::assertStringContainsString('line 12, now checked', implode("\n", $ticked->details()));
        self::assertStringContainsString('line 12 now reads `- [x]`', $ticked->toMarkdown());
    }

    /**
     * "Record completion evidence together": one document with the checks, the
     * state of the working tree, the checklist line and the agent's claim in
     * it.
     */
    #[Test]
    public function the_record_holds_the_checks_the_changes_the_checklist_and_the_claim(): void
    {
        $markdown = $this->evidence()->ticked($this->edit())->toMarkdown();

        self::assertStringContainsString('# TASK-1A2B3C4D, attempt 2: checked off', $markdown);
        self::assertStringContainsString('> Add a --dry-run option', $markdown);
        self::assertStringContainsString('Carried out by codex', $markdown);

        self::assertStringContainsString('## The conditions', $markdown);
        self::assertStringContainsString('- [x] **Verification passed', $markdown);

        self::assertStringContainsString('## The checks', $markdown);
        self::assertStringContainsString('**tests** — `composer test`', $markdown);
        self::assertStringContainsString('**analyse** — `composer analyse`', $markdown);

        self::assertStringContainsString('## The changes', $markdown);
        self::assertStringContainsString('Nothing was branched, committed or pushed.', $markdown);
        self::assertStringContainsString('`git diff`', $markdown);

        self::assertStringContainsString('## The checklist', $markdown);
        self::assertStringContainsString('.ralph/tasks.md', $markdown);

        self::assertStringContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('added the option and a test for it', $markdown);

        // And the limit a clean record still has.
        self::assertStringContainsString('## What this is not', $markdown);
        self::assertStringContainsString('does not say', $markdown);
    }

    /**
     * A completion that was refused is the record most worth reading, so it is
     * the same document with the conditions answered differently and the next
     * action in it.
     */
    #[Test]
    public function a_refused_completion_records_the_conditions_it_failed(): void
    {
        $evidence = $this->evidence(verification: VerificationReport::of(
            $this->check('tests', CheckOutcome::Failed, 1),
        ));

        $markdown = $evidence->toMarkdown();

        self::assertStringContainsString('attempt 2: not checked off', $markdown);
        self::assertStringContainsString('- [ ] **Verification passed', $markdown);
        self::assertStringContainsString('The box in .ralph/tasks.md is left as you wrote it.', $markdown);
        self::assertStringContainsString('left in the working tree', $markdown);
        self::assertStringContainsString('ralph retry TASK-1A2B3C4D', $markdown);

        // And the attempt's row carries the reason as evidence.
        self::assertStringContainsString(
            'the configured checks did not pass',
            $evidence->evidence()->toMarkdown(),
        );
    }

    /**
     * The structured form, for `ralph logs --json` and whatever else reads the
     * result rather than the file.
     */
    #[Test]
    public function the_structured_form_carries_each_layer(): void
    {
        $data = $this->evidence()->ticked($this->edit())->toArray();

        self::assertSame('TASK-1A2B3C4D', $data['generation']);
        self::assertSame('TASK-1A2B3C4D', $data['task']);
        self::assertSame('Add a --dry-run option', $data['description']);
        self::assertSame(2, $data['attempt']);
        self::assertSame('codex', $data['agent']);
        self::assertTrue($data['earned']);
        self::assertSame([], $data['unmet']);
        self::assertTrue($data['checked']);
        self::assertSame(['path' => '.ralph/tasks.md', 'line' => 12], $data['checklist']);
        self::assertTrue($data['verification']['passed']);
        self::assertStringContainsString('added the option', (string) $data['claim']);
    }

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
        self::assertStringContainsString('completed: verified in the working tree', implode("\n", $sink->notes));
        self::assertStringContainsString('check: tests', implode("\n", $sink->notes));
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
        self::assertStringContainsString('completed: verified in the working tree', implode("\n", $sink->notes));
        self::assertStringNotContainsString('kept at', implode("\n", $sink->notes));
    }

    /**
     * The evidence as the pass collects it, with one fact replaceable per case.
     */
    private function evidence(
        ?VerificationReport $verification = null,
        ?UnverifiedText $claim = null,
    ): CompletionEvidence {
        return CompletionEvidence::of(
            $this->record(),
            'Add a --dry-run option',
            2,
            $verification ?? $this->passingChecks(),
            $claim ?? UnverifiedText::of('added the option and a test for it'),
            '.ralph/tasks.md',
        );
    }

    /**
     * A generation row as the store would have read it back at the moment the
     * box is about to be ticked.
     */
    private function record(): GenerationRecord
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
            'blocker' => null,
            'started_at' => '2026-11-07T09:00:00+00:00',
            'updated_at' => '2026-11-07T09:04:00+00:00',
            'finished_at' => null,
        ]);
    }

    private function edit(): CheckboxEdit
    {
        return CheckboxEdit::applied('- [x] do it', TaskId::fromString('TASK-1A2B3C4D'), 12, true);
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
