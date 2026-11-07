<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\ExcludedChange;
use Celyes\RalphPhp\Git\ExclusionReason;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\WorkspaceState;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * The case for completing a task that changed nothing, and whether it holds
 * (PROJECT.md section G, item 6).
 *
 * A task can be asked for and already be done. The agent reads the project,
 * finds the behaviour there, edits nothing, and returns. The item says that is
 * a verified outcome rather than a failure, and that it must not be dressed up
 * as an empty commit — a commit with no diff in it is a reference a reviewer
 * cannot read anything out of, and `git log` would show the task as
 * implemented by it.
 *
 * What the item also says is *with supporting evidence*, and that half is what
 * this class is. "Nothing was staged" is not evidence of anything on its own:
 * it is equally what an agent that misread the task, edited the wrong
 * directory, or did nothing at all leaves behind. So the facts the decision
 * rests on are collected at the moment it is made, written down beside the
 * attempt's logs, and — this is the part that matters — read for the things
 * that contradict them. Either every one of them holds and the box may be
 * ticked, or {@see self::objections()} says what does not and the pass stops
 * for a person.
 *
 * The facts:
 *
 *  - **The configured checks passed**, all of them, run by the package against
 *    the workspace. This is the reason a no-change result can be called
 *    verified at all.
 *  - **The index is empty** — what {@see StagedChanges} decided the commit
 *    would contain, which is the question staging exists to answer.
 *  - **The workspace is still at the base commit**, read from Git after the
 *    agent returned and after the checks ran, so a commit either of them made
 *    is not mistaken for a clean workspace.
 *  - **Nothing was excluded that the agent is answerable for.** An index can
 *    be empty because there was nothing to stage, or because everything there
 *    was got kept out of the commit, and those are different outcomes
 *    ({@see StagedChanges::nothingStaged()} carries both).
 *  - **What the agent said**, kept as a claim and never read as a reason
 *    (section D, item 6).
 *
 * **Why Ralph PHP's own files are an objection and local ones are not.** A
 * workspace whose only change is to `.ralph/tasks.md` is an agent that ticked
 * its own box instead of doing the work, and completing that as "already
 * satisfied" would make a model-written checkbox into verified execution
 * state, which is the one thing section D, item 6 names. A `.env` or a local
 * `phpunit.xml`, on the other hand, is untracked in the workspace because
 * {@see \Celyes\RalphPhp\Git\WorkspacePreparer} put it there so the project
 * could be built at all; objecting to it would stop every genuine no-change
 * task on every project that configures `workspace.copy`. Those are recorded
 * and not held against the outcome.
 *
 * **What a clean set of facts is still not.** The checks passing at the base
 * commit says the project is green there. It does not say that any configured
 * check tests what this task asked for, and no reading of an empty diff could
 * — so a task whose requirement nothing covers reads as already satisfied
 * whenever an agent changes nothing. That is why the record is written for a
 * person rather than only counted, and it is stated in {@see self::hints()}
 * and in {@see self::toMarkdown()} where a user meets it rather than only
 * here.
 */
final class Satisfaction
{
    /**
     * Excluded changes named individually in a message.
     *
     * The record file lists all of them; a terminal line and a stored blocker
     * get the first few and an exact count.
     */
    public const int CHANGES_IN_MESSAGE = 5;

    /**
     * @param int $attempt the attempt within the pass whose work this is about
     * @param string $head what Git said HEAD was, read after the checks ran
     * @param string|null $operation a half-finished Git operation, if any
     * @param string|null $recordPath where the evidence was written, when it was
     */
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly int $attempt,
        private readonly string $workspace,
        private readonly BaseCommit $base,
        private readonly string $head,
        private readonly ?string $operation,
        private readonly VerificationReport $verification,
        private readonly StagedChanges $staged,
        private readonly UnverifiedText $claim,
        private readonly ?string $recordPath,
    ) {
    }

    /**
     * Collect the case for one attempt that staged nothing.
     *
     * @param WorkspaceState $state the workspace as Git reported it at the
     *        moment of the decision — not the reading taken before the agent
     *        ran, which could not say whether the checks had committed
     *        anything
     */
    public static function of(
        TaskGeneration $generation,
        int $attempt,
        string $workspace,
        BaseCommit $base,
        WorkspaceState $state,
        StagedChanges $staged,
        VerificationReport $verification,
        UnverifiedText $claim,
    ): self {
        return new self(
            $generation,
            $attempt,
            $workspace,
            $base,
            $state->head(),
            $state->operation(),
            $verification,
            $staged,
            $claim,
            null,
        );
    }

    /**
     * The same case, with the record noted as written at this path.
     *
     * Separate from construction because the two happen in that order: the
     * facts are collected, and only then is a file written from them.
     */
    public function keptAt(string $recordPath): self
    {
        return new self(
            $this->generation,
            $this->attempt,
            $this->workspace,
            $this->base,
            $this->head,
            $this->operation,
            $this->verification,
            $this->staged,
            $this->claim,
            $recordPath,
        );
    }

    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * The workspace the task was carried out in.
     */
    public function workspace(): string
    {
        return $this->workspace;
    }

    public function base(): BaseCommit
    {
        return $this->base;
    }

    /**
     * What HEAD was when the decision was made.
     */
    public function head(): string
    {
        return $this->head;
    }

    /**
     * Whether the workspace is still at the commit it was handed.
     */
    public function isAtBase(): bool
    {
        return $this->head === $this->base->sha();
    }

    /**
     * The checks that were run, and passed.
     */
    public function verification(): VerificationReport
    {
        return $this->verification;
    }

    /**
     * What staging decided the commit would contain, which for a satisfied
     * task is nothing.
     */
    public function staged(): StagedChanges
    {
        return $this->staged;
    }

    /**
     * What the agent said about the attempt. A claim, and stored as one.
     */
    public function claim(): UnverifiedText
    {
        return $this->claim;
    }

    /**
     * Where the evidence was written for a person to read, or null when it
     * could not be.
     */
    public function recordPath(): ?string
    {
        return $this->recordPath;
    }

    /**
     * Changes kept out of the commit, in the order Git reported them.
     *
     * @return list<ExcludedChange>
     */
    public function excluded(): array
    {
        return $this->staged->excluded();
    }

    /**
     * Excluded changes to Ralph PHP's own configuration, checklist or runtime
     * data — the ones the agent is answerable for.
     *
     * @return list<ExcludedChange>
     */
    public function runtimeExclusions(): array
    {
        return $this->exclusionsFor(ExclusionReason::Runtime);
    }

    /**
     * Excluded changes to files `workspace.copy` brought in, which preparation
     * also creates and which are therefore nobody's finding.
     *
     * @return list<ExcludedChange>
     */
    public function localExclusions(): array
    {
        return $this->exclusionsFor(ExclusionReason::Local);
    }

    /**
     * Everything that contradicts the outcome, worst first.
     *
     * Empty means the box may be ticked. The order is what a person needs to
     * read first: a check that did not pass makes everything below it
     * irrelevant, and a workspace that moved makes the diff a statement about
     * the wrong commit.
     *
     * @return list<string>
     */
    public function objections(): array
    {
        $objections = [];

        if (!$this->verification->passed()) {
            $objections[] = sprintf(
                'the configured checks did not pass (%s), so nothing here is verified',
                $this->verification->describe(),
            );
        }

        if (!$this->staged->isEmpty()) {
            $objections[] = sprintf(
                'there is something to commit after all: %s',
                $this->staged->summary(),
            );
        }

        $runtime = $this->runtimeExclusions();

        if ($runtime !== []) {
            $objections[] = sprintf(
                'the only change in the workspace was to Ralph PHP\'s own files (%s), which is a '
                    . 'progress report rather than an implementation',
                $this->listOf($runtime),
            );
        }

        if (!$this->isAtBase()) {
            $objections[] = sprintf(
                'the workspace is at %s rather than at the base commit %s, so the task\'s work may be '
                    . 'in a commit nothing recorded',
                substr($this->head, 0, BaseCommit::SHORT_LENGTH),
                $this->base->shortSha(),
            );
        }

        if ($this->operation !== null) {
            $objections[] = sprintf(
                'a %s is half-finished in the workspace, which is not a state an unchanged checkout '
                    . 'can be read from',
                $this->operation,
            );
        }

        return $objections;
    }

    /**
     * Whether the task may be completed as a verified no-change outcome.
     */
    public function isJustified(): bool
    {
        return $this->objections() === [];
    }

    /**
     * Whether this stops the pass.
     */
    public function isBlocking(): bool
    {
        return !$this->isJustified();
    }

    /**
     * One line saying what was decided and on what.
     */
    public function summary(): string
    {
        if ($this->isJustified()) {
            return sprintf(
                'the task was already satisfied: nothing to commit, and %d check(s) passed against %s '
                    . 'unchanged',
                $this->verification->count(),
                $this->base->shortSha(),
            );
        }

        return sprintf(
            'nothing was staged, but a verified no-change outcome is not supported: %s',
            $this->objections()[0],
        );
    }

    /**
     * The facts, one per line, for a log and for `ralph logs`.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [
            sprintf('base: %s', $this->base->describe()),
            sprintf(
                'workspace: %s, at %s%s',
                $this->workspace,
                substr($this->head, 0, BaseCommit::SHORT_LENGTH),
                $this->isAtBase() ? ' — the commit it was handed' : ' — not the commit it was handed',
            ),
            sprintf('staged: %s', $this->staged->summary()),
        ];

        foreach ($this->verification->details() as $check) {
            $details[] = 'check: ' . $check;
        }

        foreach (array_slice($this->excluded(), 0, self::CHANGES_IN_MESSAGE) as $change) {
            $details[] = 'excluded: ' . $change->describe();
        }

        $remaining = count($this->excluded()) - self::CHANGES_IN_MESSAGE;

        if ($remaining > 0) {
            $details[] = sprintf('excluded: and %d more', $remaining);
        }

        return $details;
    }

    /**
     * What the user can do about an outcome that is not supported.
     *
     * Only on the refusal, and in the order a person needs it: where the
     * workspace is, what the package will not decide, what to do next.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isJustified()) {
            return [];
        }

        $hints = [];

        if ($this->recordPath !== null) {
            $hints[] = sprintf('What was collected is kept at %s.', $this->recordPath);
        }

        $hints[] = sprintf(
            'Nothing was committed and nothing was undone. The workspace is left at %s to be read.',
            $this->workspace,
        );

        if ($this->runtimeExclusions() !== []) {
            $hints[] = 'Ralph PHP never commits its own files or trusts an agent\'s edit to the '
                . 'checklist: a ticked box in a task workspace is a claim, and the box in your '
                . 'checkout is only ticked for work that passed the checks.';
        }

        $hints[] = sprintf(
            'If the task really is done already, tick it off yourself; if it is not, reword it to say '
                . 'what must change and run `ralph retry %s`.',
            $this->generation->task()->value(),
        );

        return $hints;
    }

    /**
     * One phrase for a log header.
     */
    public function describe(): string
    {
        if ($this->isJustified()) {
            return 'already satisfied: nothing to commit, and the checks agree';
        }

        return sprintf(
            'no-change outcome refused: %d objection%s',
            count($this->objections()),
            count($this->objections()) === 1 ? '' : 's',
        );
    }

    /**
     * The objections as evidence for the attempt's row.
     *
     * One entry each, so what was found is stored rather than a count of
     * findings. A justified outcome has nothing to say here: it is not a
     * failure, and its evidence is the record file and the verification rows.
     */
    public function evidence(): FailureEvidence
    {
        $evidence = FailureEvidence::none();

        if ($this->isJustified()) {
            return $evidence;
        }

        foreach ($this->objections() as $objection) {
            $evidence = $evidence->with('no-change outcome refused', $objection);
        }

        return $evidence;
    }

    /**
     * The evidence as a file for a person to read.
     *
     * Markdown, beside the attempt's logs, because this is read by whoever
     * asks why a task was ticked off without a commit — and because the
     * alternative, a row in a database, is not where that question gets asked.
     */
    public function toMarkdown(): string
    {
        $sections = [
            $this->heading(),
            $this->factsSection(),
            $this->checksSection(),
        ];

        if ($this->excluded() !== []) {
            $sections[] = $this->exclusionsSection();
        }

        if (!$this->claim->isEmpty()) {
            $sections[] = $this->claimSection();
        }

        if ($this->isBlocking()) {
            $sections[] = $this->objectionsSection();
        }

        $sections[] = $this->limitsSection();

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation->slug(),
            'attempt' => $this->attempt,
            'workspace' => $this->workspace,
            'base' => ['reference' => $this->base->reference(), 'sha' => $this->base->sha()],
            'head' => $this->head,
            'at_base' => $this->isAtBase(),
            'operation' => $this->operation,
            'justified' => $this->isJustified(),
            'objections' => $this->objections(),
            'record' => $this->recordPath,
            'staged' => $this->staged->toArray(),
            'verification' => $this->verification->toArray(),
            'claim' => $this->claim->toStorage(),
        ];
    }

    /**
     * Excluded changes of one reason, in the order Git reported them.
     *
     * @return list<ExcludedChange>
     */
    private function exclusionsFor(ExclusionReason $reason): array
    {
        return array_values(array_filter(
            $this->excluded(),
            static fn (ExcludedChange $change): bool => $change->reason() === $reason,
        ));
    }

    /**
     * Paths from a bounded set of changes, as one phrase.
     *
     * @param list<ExcludedChange> $changes
     */
    private function listOf(array $changes): string
    {
        $named = array_map(
            static fn (ExcludedChange $change): string => $change->path(),
            array_slice($changes, 0, self::CHANGES_IN_MESSAGE),
        );

        $remaining = count($changes) - count($named);

        if ($remaining > 0) {
            $named[] = sprintf('and %d more', $remaining);
        }

        return implode(', ', $named);
    }

    private function heading(): string
    {
        return implode("\n", [
            sprintf(
                '# %s, attempt %d: %s',
                $this->generation->label(),
                $this->attempt,
                $this->isJustified() ? 'already satisfied' : 'nothing staged, and not satisfied',
            ),
            '',
            $this->isJustified()
                ? 'This task was completed without a commit. Ralph PHP found nothing to commit, the '
                    . 'configured checks passed against the workspace as it stood, and a verified '
                    . 'no-change outcome is what PROJECT.md section G, item 6 asks for instead of an '
                    . 'empty commit. What follows is the evidence that decision rests on.'
                : 'This task staged nothing, and the facts collected do not support completing it. '
                    . 'Nothing was committed and the box was not ticked.',
        ]);
    }

    private function factsSection(): string
    {
        return implode("\n", [
            '## What the package observed',
            '',
            ...array_map(static fn (string $detail): string => '- ' . $detail, $this->details()),
        ]);
    }

    private function checksSection(): string
    {
        $lines = [
            '## The checks it rests on',
            '',
            sprintf(
                '%s, run by the package in the workspace — not by the agent.',
                ucfirst($this->verification->describe()),
            ),
            '',
        ];

        foreach ($this->verification->runs() as $run) {
            $lines[] = sprintf(
                '- **%s** — `%s` (%s)',
                $run->name(),
                implode(' ', $run->command()),
                $run->summary(),
            );
        }

        return implode("\n", $lines);
    }

    private function exclusionsSection(): string
    {
        $lines = [
            '## Changes kept out of the commit',
            '',
            'An empty index can mean there was nothing to stage, or that everything there was got '
                . 'excluded. These are what was excluded.',
            '',
        ];

        foreach ($this->excluded() as $change) {
            $lines[] = '- ' . $change->describe();
        }

        return implode("\n", $lines);
    }

    private function claimSection(): string
    {
        $lines = [
            '## What the agent reported',
            '',
            'The agent\'s own words about the attempt. Nothing in them has been checked, and nothing '
                . 'in them decided this outcome.',
        ];

        $notice = $this->claim->notice();

        if ($notice !== null) {
            $lines[] = $notice;
        }

        $lines[] = '';
        $lines[] = $this->claim->text();

        return implode("\n", $lines);
    }

    private function objectionsSection(): string
    {
        $lines = ['## Why it was refused', ''];

        foreach ($this->objections() as $objection) {
            $lines[] = '- ' . ucfirst($objection);
        }

        $lines[] = '';

        foreach ($this->hints() as $hint) {
            $lines[] = '- ' . $hint;
        }

        return implode("\n", $lines);
    }

    private function limitsSection(): string
    {
        return implode("\n", [
            '## What this is not',
            '',
            'The checks passing says the project is green at the base commit. It does not say that '
                . 'any configured check tests what this task asked for, and reading an empty diff '
                . 'cannot establish that — so a requirement no check covers reads as already '
                . 'satisfied whenever an agent changes nothing.',
            '',
            'Git\'s ignore rules are also not covered: a workspace whose only changes are to ignored '
                . 'paths is reported here as unchanged, because `git status` does not mention them.',
            '',
            'Read the task\'s wording against the checks above before treating this as done.',
        ]);
    }
}
