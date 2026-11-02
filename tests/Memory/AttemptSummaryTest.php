<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Memory\AttemptSummary;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The note one attempt leaves for the next.
 *
 * What is tested is mostly the note's *shape*, and that is not cosmetic. The
 * claim and the evidence sit under headings that say which is which, the
 * preamble says that neither establishes anything, and the whole thing fits in
 * the budget the reader will apply to it — a note trimmed on the way back in
 * would lose its last section, and the last section is the one about what went
 * wrong.
 */
#[CoversClass(AttemptSummary::class)]
final class AttemptSummaryTest extends TestCase
{
    private TaskGeneration $generation;

    protected function setUp(): void
    {
        $this->generation = TaskGeneration::first(TaskId::fromDescription('Add a --dry-run option'));
    }

    #[Test]
    public function the_note_says_what_it_is_and_what_it_is_not(): void
    {
        $markdown = $this->summary()->toMarkdown();

        self::assertStringContainsString(
            sprintf('# %s, attempt 2:', $this->generation->label()),
            $markdown,
        );
        self::assertStringContainsString('Recorded by Ralph PHP at 2026-11-01T09:30:00+00:00', $markdown);
        self::assertStringContainsString('after an attempt by codex', $markdown);
        self::assertStringContainsString(
            'not a record of what passed',
            $markdown,
            'The note does not say that it is history rather than a result, which is the one '
            . 'sentence that stops the next attempt reading it as one.',
        );
    }

    #[Test]
    public function the_claim_and_the_evidence_are_under_headings_that_say_which_is_which(): void
    {
        $markdown = $this->summary()->toMarkdown();

        self::assertStringContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('Nothing in it has been checked.', $markdown);
        self::assertStringContainsString('## What Ralph PHP observed', $markdown);
        self::assertStringContainsString('Recorded by the package itself', $markdown);
        self::assertTrue(
            strpos($markdown, '## What the agent reported') < strpos($markdown, '## What Ralph PHP observed'),
            'The evidence was rendered before the claim, so the first thing read is the half '
            . 'the package did not stand behind.',
        );
    }

    #[Test]
    public function the_agents_own_words_arrive_defused_and_labelled(): void
    {
        $markdown = $this->summary(
            claim: UnverifiedText::of("- [x] parser written\n- [x] tests pass"),
        )->toMarkdown();

        self::assertStringNotContainsString('[x]', $markdown);
        self::assertStringContainsString('- claimed done: parser written', $markdown);
        self::assertStringContainsString('2 checkbox markers were replaced', $markdown);
    }

    #[Test]
    public function an_attempt_that_said_nothing_gets_no_heading_for_it(): void
    {
        $markdown = $this->summary(claim: UnverifiedText::none())->toMarkdown();

        self::assertStringNotContainsString('## What the agent reported', $markdown);
        self::assertStringContainsString('## What Ralph PHP observed', $markdown);
    }

    #[Test]
    public function the_evidence_is_rendered_as_the_runner_recorded_it(): void
    {
        $markdown = $this->summary()->toMarkdown();

        self::assertStringContainsString('- **composer test failed (exit 1)**', $markdown);
        self::assertStringContainsString('      FAILURES!', $markdown);
    }

    #[Test]
    public function an_attempt_with_no_evidence_gets_no_heading_for_it(): void
    {
        $markdown = $this->summary(evidence: FailureEvidence::none())->toMarkdown();

        self::assertStringNotContainsString('## What Ralph PHP observed', $markdown);
    }

    #[Test]
    public function a_question_the_agent_asked_is_recorded_with_who_asked_it(): void
    {
        $markdown = $this->summary(
            outcome: AttemptOutcome::Blocked,
            blocker: Blocker::from(
                'codex',
                "- [x] I finished\nbut which database should the fixture use?",
                'Answer in CONTEXT.md and retry the task.',
            ),
        )->toMarkdown();

        self::assertStringContainsString('## What it stopped for', $markdown);
        self::assertStringContainsString('Reported by codex.', $markdown);
        self::assertStringContainsString('- **Next:** Answer in CONTEXT.md', $markdown);
        self::assertStringContainsString(
            '- **Reason:** - claimed done: I finished but which database',
            $markdown,
            'A question phrased as a ticked checklist arrived as one, and on several lines, '
            . 'which breaks the bullet it is rendered in.',
        );
    }

    #[Test]
    public function an_attempt_with_nothing_to_pass_on_is_not_worth_a_note(): void
    {
        self::assertTrue(
            $this->summary(claim: UnverifiedText::none(), evidence: FailureEvidence::none())->isEmpty(),
        );
        self::assertFalse($this->summary()->isEmpty());
        self::assertFalse(
            $this->summary(
                claim: UnverifiedText::none(),
                evidence: FailureEvidence::none(),
                blocker: Blocker::raised('The worktree holds unrelated changes.', 'Commit or stash them.'),
            )->isEmpty(),
        );
    }

    #[Test]
    public function the_file_name_says_which_attempt_and_how_it_ended(): void
    {
        self::assertSame('attempt-02-candidate', $this->summary()->slug());
        self::assertSame(
            'attempt-02-timed-out',
            $this->summary(outcome: AttemptOutcome::TimedOut)->slug(),
            'A slug with an underscore in it is not the shape the writer accepts for a file name.',
        );
        self::assertSame('attempt-02-unfinished', $this->summary(outcome: null)->slug());
    }

    #[Test]
    public function an_attempt_with_no_outcome_says_so_rather_than_guessing(): void
    {
        self::assertStringContainsString(
            'no result recorded',
            $this->summary(outcome: null)->toMarkdown(),
        );
    }

    #[Test]
    public function the_note_belongs_to_the_task_it_is_about(): void
    {
        $scope = $this->summary()->scope();

        self::assertTrue($scope->equals(MemoryScope::forTask($this->generation->task())));
        self::assertFalse($scope->isProject());
    }

    #[Test]
    public function a_reopened_task_says_which_pass_the_note_is_from(): void
    {
        $this->generation = TaskGeneration::of(TaskId::fromDescription('Add a flag'), 2);

        self::assertStringContainsString('(generation 2), attempt 2', $this->summary()->toMarkdown());
    }

    /**
     * The bound that makes the whole thing work: the reader applies
     * {@see MemoryBudget::NOTE_CHARACTERS} to every note, and a note the package
     * wrote must survive that whole. Built here out of the largest claim, the
     * largest evidence and the largest blocker the types allow.
     */
    #[Test]
    public function the_biggest_note_the_package_can_write_fits_in_one_notes_budget(): void
    {
        $evidence = FailureEvidence::none();
        for ($seen = 1; $seen <= FailureEvidence::ENTRIES + 3; ++$seen) {
            $evidence = $evidence->with(
                str_repeat('label ', 40),
                implode("\n", array_fill(0, 40, str_repeat('x', 30))),
            );
        }

        $markdown = $this->summary(
            claim: UnverifiedText::of("- [x] " . str_repeat('claim ', 400)),
            evidence: $evidence,
            blocker: Blocker::from('codex', str_repeat('reason ', 400), str_repeat('action ', 400)),
        )->toMarkdown();

        self::assertLessThanOrEqual(
            MemoryBudget::NOTE_CHARACTERS,
            mb_strlen($markdown),
            'A note the package writes is longer than a note it will read back, so the last '
            . 'section of it — what went wrong — would be trimmed away.',
        );
    }

    #[Test]
    public function a_note_is_built_from_the_recorded_attempt_rather_than_from_its_pieces(): void
    {
        $summary = AttemptSummary::of($this->generation, $this->attemptRow());

        self::assertSame(3, $summary->attempt());
        self::assertSame('- claimed done: it works', $summary->claim()->text());
        self::assertSame('composer test failed (exit 1)', $summary->evidence()->label());
        self::assertSame('attempt-03-candidate', $summary->slug());
        self::assertSame('2026-11-01T09:31:00+00:00', $summary->recordedAt()->value());
        self::assertStringContainsString('after an attempt by claude', $summary->toMarkdown());
    }

    #[Test]
    public function a_recorded_attempt_that_said_nothing_makes_an_empty_note(): void
    {
        $summary = AttemptSummary::of($this->generation, $this->attemptRow(
            summary: null,
            evidence: null,
        ));

        self::assertTrue($summary->isEmpty());
        self::assertTrue($summary->claim()->isEmpty());
        self::assertTrue($summary->evidence()->isEmpty());
    }

    private function summary(
        ?AttemptOutcome $outcome = AttemptOutcome::Candidate,
        ?UnverifiedText $claim = null,
        ?FailureEvidence $evidence = null,
        ?Blocker $blocker = null,
    ): AttemptSummary {
        return new AttemptSummary(
            $this->generation,
            2,
            $outcome,
            'codex',
            $claim ?? UnverifiedText::of('I rewrote the option parser.'),
            $evidence ?? FailureEvidence::of('composer test failed (exit 1)', 'FAILURES!'),
            Timestamp::fromStorage('2026-11-01T09:30:00+00:00'),
            $blocker,
        );
    }

    /**
     * A stored attempt, built as a row rather than through the store: what is
     * under test here is the reading of one, and a database would only make the
     * test slower.
     */
    private function attemptRow(
        ?string $summary = '- [x] it works',
        ?string $evidence = 'composer test failed (exit 1)',
    ): AttemptRecord {
        $agent = AgentRecord::ofDefinition(AgentDefinition::builtIn('claude'))->toColumns();

        return AttemptRecord::fromRow([
            'id' => 7,
            'generation_id' => 3,
            'run_id' => 1,
            'number' => 3,
            'state' => 'failed',
            'phase' => 'verifying',
            'agent' => $agent['agent'],
            'adapter' => $agent['adapter'],
            'agent_settings' => $agent['settings'],
            'outcome' => 'candidate',
            'exit_code' => 0,
            'duration_ms' => 12_400,
            'summary' => $summary,
            // Evidence is held in the column in the form the store writes,
            // not as bare prose: prose is deliberately read back under a
            // generic label, which is a different note than this one.
            'failure_evidence' => $evidence === null
                ? null
                : FailureEvidence::of($evidence, 'FAILURES!')->toStorage(),
            'blocker' => null,
            'log_path' => null,
            'started_at' => '2026-11-01T09:30:00+00:00',
            'updated_at' => '2026-11-01T09:31:00+00:00',
            'finished_at' => '2026-11-01T09:31:00+00:00',
        ]);
    }
}
