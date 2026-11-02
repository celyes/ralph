<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\State\AttemptRecord;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * One finished attempt, written up for the next one to read.
 *
 * The note {@see MemoryWriter} puts on disk, and the other half of
 * {@see AttemptContextLoader}: that assembles what an attempt is told, this
 * composes what an attempt leaves behind. Both exist in the `Memory` namespace
 * because both are about the prompt; what is *recorded* about an attempt is the
 * row {@see AttemptRecord} reads, and the two are deliberately not the same
 * thing.
 *
 * **The note is in two sections, and the headings are the point.** "What the
 * agent reported" is a claim, marked as one, with its checkbox markers already
 * defused by {@see UnverifiedText}. "What Ralph PHP observed" is the runner's
 * own evidence. Keeping them apart is what stops the next attempt — and the
 * user reading the directory a week later — from having to guess which half the
 * package stood behind. The preamble says so in as many words, because the one
 * failure mode worth spending three lines on is an attempt reading "implemented,
 * tests pass" and concluding there is nothing to do.
 *
 * **Nothing here is state.** A note is a file in a directory a user can edit
 * and delete; whether the task is done is a verification result and a commit in
 * the state store. The note says what the outcome was recorded as, but it says
 * it as reported history, and no part of the loop reads a note back to decide
 * anything.
 *
 * **It fits in one note's budget.** The claim and the evidence are bounded by
 * their own types, and the sum of those bounds plus these headings is under
 * {@see MemoryBudget::NOTE_CHARACTERS} — so a note the package writes is never
 * trimmed when the next attempt reads it back. A test pins that.
 */
final class AttemptSummary
{
    /**
     * Characters of a blocker's reason, and of its next action, in a note.
     *
     * A stored blocker may be long — {@see Blocker::LIMIT} allows two thousand
     * characters, because the whole of an agent's question belongs in the state
     * store and in `ralph status`. A note is read on every later attempt
     * alongside everything else, so here it is the short form, and the recorded
     * blocker is where the rest is.
     */
    public const int BLOCKER_CHARACTERS = 240;

    public function __construct(
        private readonly TaskGeneration $generation,
        private readonly int $attempt,
        private readonly ?AttemptOutcome $outcome,
        private readonly string $agent,
        private readonly UnverifiedText $claim,
        private readonly FailureEvidence $evidence,
        private readonly Timestamp $recordedAt,
        private readonly ?Blocker $blocker = null,
    ) {
    }

    /**
     * The note for a recorded attempt.
     *
     * Reads the row rather than taking the pieces separately, so that what is
     * written to the memory directory and what is in the database cannot
     * describe the attempt differently. The generation is passed in because the
     * row holds a foreign key and a note heading needs the name a person reads.
     */
    public static function of(
        TaskGeneration $generation,
        AttemptRecord $attempt,
        ?Timestamp $recordedAt = null,
    ): self {
        return new self(
            $generation,
            $attempt->number(),
            $attempt->outcome(),
            $attempt->agent()->name(),
            $attempt->summary() ?? UnverifiedText::none(),
            $attempt->evidence() ?? FailureEvidence::none(),
            $recordedAt ?? $attempt->updatedAt(),
            $attempt->blocker(),
        );
    }

    /**
     * Which pass the attempt belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * Which attempt at that pass this is.
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * The notes directory this belongs in: the task's own.
     */
    public function scope(): MemoryScope
    {
        return MemoryScope::forTask($this->generation->task());
    }

    /**
     * The part of the note's file name that says what it is about.
     */
    public function slug(): string
    {
        // An attempt with no recorded outcome is one that stopped before
        // anything was written down about it — named for what it is, rather
        // than left out of the file name, so the directory still says which
        // attempt the note is about.
        $outcome = $this->outcome === null
            ? 'unfinished'
            : str_replace('_', '-', $this->outcome->value);

        return sprintf('attempt-%02d-%s', $this->attempt, $outcome);
    }

    /**
     * When the note was written up.
     */
    public function recordedAt(): Timestamp
    {
        return $this->recordedAt;
    }

    /**
     * The agent's own account.
     */
    public function claim(): UnverifiedText
    {
        return $this->claim;
    }

    /**
     * What the runner observed.
     */
    public function evidence(): FailureEvidence
    {
        return $this->evidence;
    }

    /**
     * Whether there is nothing here the next attempt could use.
     *
     * An attempt that claimed nothing, showed nothing and blocked on nothing
     * has nothing to pass on, and a note recording only that it happened would
     * spend the next attempt's reading budget saying so.
     */
    public function isEmpty(): bool
    {
        return $this->claim->isEmpty()
            && $this->evidence->isEmpty()
            && $this->blocker === null;
    }

    /**
     * The note, as the Markdown that goes in the file.
     */
    public function toMarkdown(): string
    {
        $sections = [$this->heading()];

        if (!$this->claim->isEmpty()) {
            $sections[] = $this->claimSection();
        }

        if (!$this->evidence->isEmpty()) {
            $sections[] = $this->evidenceSection();
        }

        if ($this->blocker !== null) {
            $sections[] = $this->blockerSection();
        }

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * What the note is, and when it was written.
     */
    private function heading(): string
    {
        return implode("\n", [
            sprintf(
                '# %s, attempt %d: %s',
                $this->generation->label(),
                $this->attempt,
                $this->outcome === null ? 'no result recorded' : $this->outcome->describe(),
            ),
            '',
            sprintf(
                'Recorded by Ralph PHP at %s, after an attempt by %s.',
                $this->recordedAt->value(),
                $this->agent,
            ),
            'It is history for the next attempt to read, not a record of what passed:',
            'the verification results and the commit are in the state database.',
        ]);
    }

    /**
     * The agent's words, marked as the agent's words.
     */
    private function claimSection(): string
    {
        $lines = [
            '## What the agent reported',
            '',
            sprintf(
                '%s wrote the following about this attempt. Nothing in it has been checked.',
                $this->agent,
            ),
        ];

        $notice = $this->claim->notice();
        if ($notice !== null) {
            $lines[] = $notice;
        }

        $lines[] = '';
        $lines[] = $this->claim->text();

        return implode("\n", $lines);
    }

    /**
     * The runner's observations.
     */
    private function evidenceSection(): string
    {
        return implode("\n", [
            '## What Ralph PHP observed',
            '',
            'Recorded by the package itself, from the commands it ran.',
            '',
            $this->evidence->toMarkdown(),
        ]);
    }

    /**
     * What the attempt stopped to ask, where it stopped to ask something.
     */
    private function blockerSection(): string
    {
        $blocker = $this->blocker;

        if ($blocker === null) {
            return '';
        }

        return implode("\n", [
            '## What it stopped for',
            '',
            sprintf('Reported by %s.', $blocker->reportedBy()),
            '',
            sprintf('- **Reason:** %s', self::oneLine($blocker->reason())),
            sprintf('- **Next:** %s', self::oneLine($blocker->nextAction())),
        ]);
    }

    /**
     * A blocker's text, defused, shortened and put on one line.
     *
     * Defused for the same reason a summary is: when an agent asks for human
     * input, the question is the agent's own prose, and a question phrased as a
     * ticked checklist is a question that reads like a result. Put on one line
     * afterwards, because it is rendered as a bullet — and afterwards rather
     * than before, so that a marker at the start of a line is still at the
     * start of a line when it is looked for.
     */
    private static function oneLine(string $text): string
    {
        $defused = UnverifiedText::of($text, self::BLOCKER_CHARACTERS)->text();

        return trim((string) preg_replace('/\s+/u', ' ', $defused));
    }
}
