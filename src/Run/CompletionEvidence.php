<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Verification\Satisfaction;
use Celyes\RalphPhp\Verification\VerificationReport;

/**
 * What a ticked box rests on, collected as one thing (PROJECT.md section G,
 * item 7).
 *
 * The item has two halves. The first is a condition — a task is checked off
 * only after verification, the Git requirements and any required publication
 * have succeeded — and {@see self::unmet()} is that condition written down as
 * something a pass asks rather than something its control flow implies. The
 * second is *record completion evidence together*, and that is the reason this
 * class holds facts from four different layers instead of each layer reporting
 * its own.
 *
 * **Why the condition is asked again here.** Every one of these facts is
 * already true by the time {@see TaskPass} reaches the tick: the checks passed
 * or the pass returned, the commit was made or the no-change case was accepted,
 * the push succeeded or the pass stopped. So this re-reads what the sequence
 * has already established — deliberately. A box ticked in a user's own file is
 * the package's single outward claim, and the one thing that must not be able
 * to happen is for a later edit to that sequence to move the tick above one of
 * its conditions and for nothing to notice. The store refuses an unearned
 * {@see \Celyes\RalphPhp\State\GenerationStore::complete()} for the same reason
 * and is not enough on its own: it runs *after* the write to the user's file,
 * and it cannot see the verification report at all.
 *
 * **Why the evidence is one record and not five.** The facts behind a
 * completion are scattered by the time the pass ends — the checks are rows in
 * `verification_results`, the commit is a SHA on the generation, the push is
 * three more columns, the agent's claim is on the attempt, and the tick is a
 * line in a Markdown file the package does not own. Each is reachable, and none
 * of them answers the question a reviewer actually arrives with: *why is this
 * box ticked?* So the answer is assembled at the moment it is true, written
 * beside the attempt's logs, and kept whether or not anyone asks.
 *
 * **What is evidence and what is a claim.** The checks, the commit, the push
 * and the checkbox edit are things the package did and observed. What the agent
 * said is kept as {@see UnverifiedText} and reported as its words, never as a
 * reason — section D, item 6 — so a record of a completion cannot be mistaken
 * for the agent having certified it.
 *
 * **What a clean record still is not.** It says the configured checks passed
 * against this diff, not that any of them tests what the task asked for. That
 * limit belongs to {@see Satisfaction} as well, and it is stated in
 * {@see self::toMarkdown()} where a reader meets it rather than only here.
 */
final class CompletionEvidence
{
    /**
     * Staged paths named individually in the record.
     *
     * The commit is the authority for the whole diff and `git show` prints it;
     * this is an orientation, so a long refactor does not turn the record into
     * a file listing.
     */
    public const int PATHS_IN_RECORD = 20;

    /**
     * @param GenerationRecord $record the pass as it stood once the commit and
     *        the publication were settled and before the box was ticked — the
     *        row whose columns this evidence is about
     * @param CheckboxEdit|null $edit the checklist write, once it has landed;
     *        null while the condition is still being asked
     * @param string|null $recordPath where this was written for a person to
     *        read, when it was
     */
    private function __construct(
        private readonly GenerationRecord $record,
        private readonly string $description,
        private readonly int $attempt,
        private readonly BaseCommit $base,
        private readonly VerificationReport $verification,
        private readonly StagedChanges $staged,
        private readonly ?Satisfaction $satisfaction,
        private readonly UnverifiedText $claim,
        private readonly string $checklistPath,
        private readonly ?CheckboxEdit $edit,
        private readonly ?string $recordPath,
    ) {
    }

    /**
     * Collect what one attempt earned, at the point the box is about to be
     * ticked.
     *
     * @param StagedChanges $staged what staging decided the commit would
     *        contain, which for a no-change outcome is nothing
     * @param Satisfaction|null $satisfaction the case for a pass that staged
     *        nothing; required for a no-change outcome and absent otherwise
     * @param string $checklistPath the task file the tick is a write to, as the
     *        user names it
     */
    public static function of(
        GenerationRecord $record,
        string $description,
        int $attempt,
        BaseCommit $base,
        VerificationReport $verification,
        StagedChanges $staged,
        ?Satisfaction $satisfaction,
        UnverifiedText $claim,
        string $checklistPath,
    ): self {
        return new self(
            $record,
            $description,
            $attempt,
            $base,
            $verification,
            $staged,
            $satisfaction,
            $claim,
            $checklistPath,
            null,
            null,
        );
    }

    /**
     * The same evidence, with the checklist write that landed.
     *
     * Separate from collection because the two happen in that order and must:
     * the conditions are read first and decide whether there is a write at all.
     */
    public function ticked(CheckboxEdit $edit): self
    {
        return new self(
            $this->record,
            $this->description,
            $this->attempt,
            $this->base,
            $this->verification,
            $this->staged,
            $this->satisfaction,
            $this->claim,
            $this->checklistPath,
            $edit,
            $this->recordPath,
        );
    }

    /**
     * The same evidence, noted as written at this path.
     */
    public function keptAt(string $recordPath): self
    {
        return new self(
            $this->record,
            $this->description,
            $this->attempt,
            $this->base,
            $this->verification,
            $this->staged,
            $this->satisfaction,
            $this->claim,
            $this->checklistPath,
            $this->edit,
            $recordPath,
        );
    }

    /**
     * The pass this completion belongs to.
     */
    public function record(): GenerationRecord
    {
        return $this->record;
    }

    public function generation(): TaskGeneration
    {
        return $this->record->generation();
    }

    /**
     * The task as the checklist words it.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Which attempt within the pass earned it.
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * The agent that was used, as the run recorded it — no credentials.
     */
    public function agent(): AgentRecord
    {
        return $this->record->agent();
    }

    public function base(): BaseCommit
    {
        return $this->base;
    }

    /**
     * The checks, as they decided this work.
     */
    public function verification(): VerificationReport
    {
        return $this->verification;
    }

    /**
     * What the commit contains, or nothing for a no-change outcome.
     */
    public function staged(): StagedChanges
    {
        return $this->staged;
    }

    /**
     * The case for a pass that staged nothing, where one was made.
     */
    public function satisfaction(): ?Satisfaction
    {
        return $this->satisfaction;
    }

    /**
     * What the agent said about the attempt. A claim, and kept as one.
     */
    public function claim(): UnverifiedText
    {
        return $this->claim;
    }

    public function commitSha(): ?string
    {
        return $this->record->commitSha();
    }

    /**
     * Whether the task was already satisfied, so there is nothing to commit.
     */
    public function isNoChange(): bool
    {
        return $this->record->isNoChange();
    }

    /**
     * Where publication stands.
     */
    public function push(): PushResult
    {
        return $this->record->push();
    }

    /**
     * The task file the tick is a write to.
     */
    public function checklistPath(): string
    {
        return $this->checklistPath;
    }

    /**
     * The checklist write, once it has landed.
     */
    public function edit(): ?CheckboxEdit
    {
        return $this->edit;
    }

    /**
     * Whether the box has been ticked.
     */
    public function wasChecked(): bool
    {
        return $this->edit !== null;
    }

    /**
     * Where this was written for a person to read, or null when it was not.
     */
    public function recordPath(): ?string
    {
        return $this->recordPath;
    }

    /**
     * Every condition on ticking a box that this pass has not met, worst first.
     *
     * Empty means the box may be ticked. The order is the order the conditions
     * are reached in: unverified work makes everything after it moot, and an
     * unrecorded implementation makes the push irrelevant.
     *
     * @return list<string>
     */
    public function unmet(): array
    {
        $unmet = [];

        // "The checks passed" can mean "no check ran", and only in one case: a
        // run whose project configured no checks at all, which is the default
        // because only a project knows how it is verified. That is not
        // something to refuse here — the run announced it before it started,
        // and refusing would make such a project unable to finish any task —
        // so what this evidence does instead is never call it verification.
        // The condition it is listed under, the record's own checks section and
        // the summary line all say that nothing was verified.
        //
        // The accidental empty report remains impossible: VerificationReport::of()
        // refuses the empty sequence, and only the explicit none() constructor
        // produces one.
        if (!$this->verification->passed()) {
            $unmet[] = sprintf(
                'the configured checks did not pass (%s)',
                $this->verification->describe(),
            );
        }

        if (!$this->record->hasImplementation()) {
            $unmet[] = 'neither a commit nor a verified no-change result is recorded for this pass';
        }

        // A no-change outcome is the one completion with no commit to read, so
        // its case is part of what the tick rests on rather than a note beside
        // it: without the audit there is no evidence the task was already done
        // (section G, item 6).
        if ($this->isNoChange() && ($this->satisfaction === null || !$this->satisfaction->isJustified())) {
            $unmet[] = $this->satisfaction === null
                ? 'the pass recorded a no-change result with no evidence collected for it'
                : 'the no-change result is contradicted: ' . $this->satisfaction->objections()[0];
        }

        if ($this->push()->isOutstanding()) {
            $unmet[] = sprintf('publication is required and %s', $this->push()->describe());
        }

        return $unmet;
    }

    /**
     * Whether every condition on ticking the box has been met.
     */
    public function isEarned(): bool
    {
        return $this->unmet() === [];
    }

    /**
     * Whether the box must stay as the user left it.
     */
    public function isRefused(): bool
    {
        return !$this->isEarned();
    }

    /**
     * One line saying what was earned, or what is missing.
     */
    public function summary(): string
    {
        if ($this->isRefused()) {
            return sprintf(
                'the task is not checked off: %s',
                $this->unmet()[0],
            );
        }

        return sprintf(
            '%s, %s, and %s',
            $this->verification->describe(),
            $this->isNoChange()
                ? 'nothing to commit'
                : sprintf('committed as %s', $this->shortCommit()),
            $this->push()->describe(),
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
            sprintf('agent: %s', $this->agent()->describe()),
            sprintf('base: %s', $this->base->describe()),
        ];

        foreach ($this->verification->details() as $check) {
            $details[] = 'check: ' . $check;
        }

        $details[] = $this->isNoChange()
            ? sprintf('commit: none — %s', $this->satisfaction?->summary() ?? 'the task was already satisfied')
            : sprintf(
                'commit: %s on %s (%s)',
                $this->shortCommit(),
                $this->record->branch() ?? 'no branch recorded',
                $this->staged->summary(),
            );

        $details[] = 'publication: ' . $this->push()->describe();
        $details[] = sprintf(
            'checklist: %s%s',
            $this->checklistPath,
            $this->edit === null ? ' — not written yet' : sprintf(' line %d, now checked', $this->edit->line()),
        );

        return $details;
    }

    /**
     * What a user can do about a completion that was refused.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isEarned()) {
            return [];
        }

        $hints = [sprintf('The box in %s is left as you wrote it.', $this->checklistPath)];

        if ($this->record->hasCommit()) {
            $hints[] = sprintf(
                'The commit %s is kept on %s and the implementation is not repeated.',
                $this->shortCommit(),
                $this->record->branch() ?? 'its branch',
            );
        }

        $hints[] = sprintf(
            'Run `ralph logs %s` for the attempt, then `ralph retry %s`.',
            $this->record->task()->value(),
            $this->record->task()->value(),
        );

        return $hints;
    }

    /**
     * The unmet conditions as evidence for the attempt's row.
     *
     * One entry each, so what was missing is stored rather than a count of
     * things missing. An earned completion has nothing to say here: it is not a
     * failure, and its evidence is the record file, the verification rows and
     * the commit.
     */
    public function evidence(): FailureEvidence
    {
        $evidence = FailureEvidence::none();

        foreach ($this->unmet() as $condition) {
            $evidence = $evidence->with('not checked off', $condition);
        }

        return $evidence;
    }

    /**
     * One phrase for a log header.
     */
    public function describe(): string
    {
        if ($this->isEarned()) {
            return sprintf(
                'completed: %s',
                $this->isNoChange() ? 'nothing to commit, and the checks agree' : 'verified and committed',
            );
        }

        return sprintf(
            'not checked off: %d unmet condition%s',
            count($this->unmet()),
            count($this->unmet()) === 1 ? '' : 's',
        );
    }

    /**
     * The evidence as a file for a person to read.
     *
     * Markdown, beside the attempt's logs, for the reason the no-change record
     * is: this is read by whoever asks why a box is ticked, and a row in a
     * database is not where that question gets asked.
     */
    public function toMarkdown(): string
    {
        $sections = [
            $this->heading(),
            $this->conditionsSection(),
            $this->checksSection(),
            $this->gitSection(),
            $this->checklistSection(),
        ];

        if (!$this->claim->isEmpty()) {
            $sections[] = $this->claimSection();
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
            'generation' => $this->generation()->slug(),
            'task' => $this->record->task()->value(),
            'description' => $this->description,
            'attempt' => $this->attempt,
            'agent' => $this->agent()->name(),
            'base' => ['reference' => $this->base->reference(), 'sha' => $this->base->sha()],
            'earned' => $this->isEarned(),
            'unmet' => $this->unmet(),
            'checked' => $this->wasChecked(),
            'checklist' => ['path' => $this->checklistPath, 'line' => $this->edit?->line()],
            'commit' => $this->commitSha(),
            'branch' => $this->record->branch(),
            'no_change' => $this->isNoChange(),
            'staged' => $this->staged->toArray(),
            'push' => $this->push()->toColumns(),
            'verification' => $this->verification->toArray(),
            'satisfaction' => $this->satisfaction?->toArray(),
            'claim' => $this->claim->toStorage(),
            'record' => $this->recordPath,
        ];
    }

    /**
     * The commit, short, or a word saying there is none.
     */
    private function shortCommit(): string
    {
        $sha = $this->commitSha();

        return $sha === null ? 'no commit' : substr($sha, 0, BaseCommit::SHORT_LENGTH);
    }

    private function heading(): string
    {
        return implode("\n", [
            sprintf(
                '# %s, attempt %d: %s',
                $this->generation()->label(),
                $this->attempt,
                $this->isEarned() ? 'checked off' : 'not checked off',
            ),
            '',
            sprintf('> %s', $this->description),
            '',
            // The agent by name, because "which agent produced this" is the
            // first thing asked of a commit nobody wrote by hand, and the row
            // it comes from holds no credentials to leak here.
            sprintf('Carried out by %s.', $this->agent()->describe()),
            '',
            $this->isEarned()
                ? 'This task is checked off in the checklist. PROJECT.md section G, item 7 allows that '
                    . 'only once the configured checks have passed, a commit or a verified no-change '
                    . 'result has been recorded, and any required publication has succeeded. What '
                    . 'follows is each of those, as the package observed it.'
                : 'This task was carried through to the end of the pass and was not checked off. The '
                    . 'conditions below were read before the checklist would have been written, and '
                    . 'at least one of them did not hold.',
        ]);
    }

    private function conditionsSection(): string
    {
        $lines = ['## The conditions', ''];

        foreach ($this->conditions() as $condition => $met) {
            $lines[] = sprintf('- %s **%s**', $met ? '[x]' : '[ ]', $condition);
        }

        if ($this->isRefused()) {
            $lines[] = '';

            foreach ($this->unmet() as $missing) {
                $lines[] = '- ' . ucfirst($missing) . '.';
            }

            foreach ($this->hints() as $hint) {
                $lines[] = '- ' . $hint;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Each condition on ticking a box, and whether this pass met it.
     *
     * Phrased as the conditions rather than as the findings, so the record of a
     * completion and the record of a refusal read as the same document with
     * different answers in it.
     *
     * @return array<string, bool>
     */
    private function conditions(): array
    {
        return [
            sprintf(
                '%s — %s',
                $this->verification->verifiedNothing()
                    ? 'No configured check decided against the work'
                    : 'Verification passed',
                $this->verification->describe(),
            ) => $this->verification->passed(),
            sprintf(
                'An implementation is recorded — %s',
                $this->isNoChange()
                    ? 'a verified no-change result'
                    : sprintf('commit %s', $this->shortCommit()),
            ) => $this->record->hasImplementation()
                && (!$this->isNoChange() || $this->satisfaction?->isJustified() === true),
            sprintf('Publication is settled — %s', $this->push()->describe())
                => $this->push()->isSatisfied(),
        ];
    }

    private function checksSection(): string
    {
        if ($this->verification->verifiedNothing()) {
            // The one completion no check stands behind, written out as such.
            // A reader of this record has to be able to tell it from one a
            // suite passed, because what it rests on is different: the agent
            // reported a candidate implementation, and the commit below is
            // what that implementation was.
            return implode("\n", [
                '## The checks',
                '',
                'None. This run froze an empty `checks` set, so no verification command was run and '
                    . 'nothing here was established by one. What the tick rests on is the agent\'s own '
                    . 'result and the recorded commit — review the diff before merging it.',
                '',
                'Listing a command under `checks` in `.ralph/config.json` is what makes a later run '
                    . 'decide this question for itself.',
            ]);
        }

        $lines = [
            '## The checks',
            '',
            sprintf(
                '%s, run by the package against the task\'s workspace — not by the agent, and not '
                    . 'from the configuration as it stands now but from the set this run froze.',
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

    private function gitSection(): string
    {
        if ($this->isNoChange()) {
            return implode("\n", [
                '## Git',
                '',
                'Nothing was committed: the task was found already satisfied, which section G, item 6 '
                    . 'accepts as a verified outcome rather than an empty commit.',
                '',
                sprintf('- base: %s', $this->base->describe()),
                sprintf('- publication: %s', $this->push()->describe()),
                sprintf(
                    '- evidence: %s',
                    $this->satisfaction?->recordPath() ?? 'see the attempt\'s log',
                ),
            ]);
        }

        $lines = [
            '## Git',
            '',
            sprintf('- base: %s', $this->base->describe()),
            sprintf('- branch: %s', $this->record->branch() ?? 'none recorded'),
            sprintf('- commit: %s', $this->commitSha() ?? 'none recorded'),
            sprintf('- staged: %s', $this->staged->summary()),
            sprintf('- publication: %s', $this->push()->describe()),
        ];

        $paths = $this->staged->paths();

        if ($paths !== []) {
            $lines[] = '';
            $lines[] = 'What the commit contains:';
            $lines[] = '';

            foreach (array_slice($paths, 0, self::PATHS_IN_RECORD) as $path) {
                $lines[] = '- `' . $path . '`';
            }

            $remaining = count($paths) - self::PATHS_IN_RECORD;

            if ($remaining > 0) {
                $lines[] = sprintf('- and %d more', $remaining);
            }

            $lines[] = '';
            $lines[] = sprintf('Read the whole diff with `git show %s`.', $this->shortCommit());
        }

        return implode("\n", $lines);
    }

    private function checklistSection(): string
    {
        if ($this->edit === null) {
            return implode("\n", [
                '## The checklist',
                '',
                sprintf('The box in %s was not written.', $this->checklistPath),
            ]);
        }

        return implode("\n", [
            '## The checklist',
            '',
            sprintf(
                '`%s` line %d now reads `- [x]`. Only that line\'s marker was changed; every other '
                    . 'byte of the file is as you left it.',
                $this->checklistPath,
                $this->edit->line(),
            ),
        ]);
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

    private function limitsSection(): string
    {
        return implode("\n", [
            '## What this is not',
            '',
            'The checks passing says the project is green with this work applied. It does not say '
                . 'that any configured check tests what this task asked for — that is what reviewing '
                . 'the commit is for, and a checked box is not a substitute for it.',
            '',
            'Nor is the agent\'s report evidence. It is kept above because it is often the quickest '
                . 'description of what changed, and it is never read as a reason.',
        ]);
    }
}
