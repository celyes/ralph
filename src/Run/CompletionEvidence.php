<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Support\UnverifiedText;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Verification\VerificationReport;

/**
 * What a ticked box rests on, collected as one thing (PROJECT.md section G,
 * item 7).
 *
 * The item has two halves. The first is a condition — a task is checked off
 * only after the configured verification has succeeded — and {@see self::unmet()}
 * is that condition written down as something a pass asks rather than something
 * its control flow implies. The second is *record completion evidence
 * together*, and that is the reason this class holds facts from three different
 * layers instead of each layer reporting its own.
 *
 * **Why the condition is asked again here.** Every one of these facts is
 * already true by the time {@see TaskPass} reaches the tick: the checks passed,
 * or the pass returned before it got this far. So this re-reads what the
 * sequence has already established — deliberately. A box ticked in a user's own
 * file is the package's single outward claim, and the one thing that must not
 * be able to happen is for a later edit to that sequence to move the tick above
 * its condition and for nothing to notice.
 *
 * **Why the evidence is one record and not four.** The facts behind a
 * completion are scattered by the time the pass ends — the checks are rows in
 * `verification_results`, the agent's claim is on the attempt, the state is a
 * column on the generation, and the tick is a line in a Markdown file the
 * package does not own. Each is reachable, and none of them answers the
 * question a reviewer actually arrives with: *why is this box ticked?* So the
 * answer is assembled at the moment it is true, written beside the attempt's
 * logs, and kept whether or not anyone asks.
 *
 * **What is evidence and what is a claim.** The checks and the checkbox edit
 * are things the package did and observed. What the agent said is kept as
 * {@see UnverifiedText} and reported as its words, never as a reason —
 * section D, item 6 — so a record of a completion cannot be mistaken for the
 * agent having certified it.
 *
 * **What a clean record still is not.** It says the configured checks passed in
 * the user's checkout with this work in it, not that any of them tests what the
 * task asked for, and not that the changes have been reviewed. The work is left
 * uncommitted in the working tree (section K, item 1), so the diff is there to
 * be read; that limit is stated in {@see self::toMarkdown()} where a reader
 * meets it rather than only here.
 */
final class CompletionEvidence
{
    /**
     * @param GenerationRecord $record the pass as it stood once its checks had
     *        passed and before the box was ticked — the row this evidence is
     *        about
     * @param CheckboxEdit|null $edit the checklist write, once it has landed;
     *        null while the condition is still being asked
     * @param string|null $recordPath where this was written for a person to
     *        read, when it was
     */
    private function __construct(
        private readonly GenerationRecord $record,
        private readonly string $description,
        private readonly int $attempt,
        private readonly VerificationReport $verification,
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
     * @param string $checklistPath the task file the tick is a write to, as the
     *        user names it
     */
    public static function of(
        GenerationRecord $record,
        string $description,
        int $attempt,
        VerificationReport $verification,
        UnverifiedText $claim,
        string $checklistPath,
    ): self {
        return new self($record, $description, $attempt, $verification, $claim, $checklistPath, null, null);
    }

    /**
     * The same evidence, with the checklist write that landed.
     *
     * Separate from collection because the two happen in that order and must:
     * the condition is read first and decides whether there is a write at all.
     */
    public function ticked(CheckboxEdit $edit): self
    {
        return new self(
            $this->record,
            $this->description,
            $this->attempt,
            $this->verification,
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
            $this->verification,
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

    /**
     * The checks, as they decided this work.
     */
    public function verification(): VerificationReport
    {
        return $this->verification;
    }

    /**
     * What the agent said about the attempt. A claim, and kept as one.
     */
    public function claim(): UnverifiedText
    {
        return $this->claim;
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
     * Empty means the box may be ticked.
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
            return sprintf('the task is not checked off: %s', $this->unmet()[0]);
        }

        return sprintf(
            '%s, and the work is in the working tree for review',
            $this->verification->describe(),
        );
    }

    /**
     * The facts, one per line, for a log and for `ralph logs`.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [sprintf('agent: %s', $this->agent()->describe())];

        foreach ($this->verification->details() as $check) {
            $details[] = 'check: ' . $check;
        }

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

        return [
            sprintf('The box in %s is left as you wrote it.', $this->checklistPath),
            'The agent\'s changes are left in the working tree: read them with `git diff`.',
            sprintf(
                'Run `ralph logs %s` for the attempt, then `ralph retry %s`.',
                $this->record->task()->value(),
                $this->record->task()->value(),
            ),
        ];
    }

    /**
     * The unmet conditions as evidence for the attempt's row.
     *
     * One entry each, so what was missing is stored rather than a count of
     * things missing. An earned completion has nothing to say here: it is not a
     * failure, and its evidence is the record file and the verification rows.
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
            return 'completed: verified in the working tree';
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
     * Markdown, beside the attempt's logs: this is read by whoever asks why a
     * box is ticked, and a row in a database is not where that question gets
     * asked.
     */
    public function toMarkdown(): string
    {
        $sections = [
            $this->heading(),
            $this->conditionsSection(),
            $this->checksSection(),
            $this->changesSection(),
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
            'earned' => $this->isEarned(),
            'unmet' => $this->unmet(),
            'checked' => $this->wasChecked(),
            'checklist' => ['path' => $this->checklistPath, 'line' => $this->edit?->line()],
            'verification' => $this->verification->toArray(),
            'claim' => $this->claim->toStorage(),
            'record' => $this->recordPath,
        ];
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
            // first thing asked of a change nobody wrote by hand, and the row
            // it comes from holds no credentials to leak here.
            sprintf('Carried out by %s.', $this->agent()->describe()),
            '',
            $this->isEarned()
                ? 'This task is checked off in the checklist. PROJECT.md section G, item 7 allows that '
                    . 'only once the configured checks have passed against the work. What follows is '
                    . 'that, as the package observed it.'
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
        ];
    }

    private function checksSection(): string
    {
        if ($this->verification->verifiedNothing()) {
            // The one completion no check stands behind, written out as such.
            // A reader of this record has to be able to tell it from one a
            // suite passed, because what it rests on is different: the agent
            // reported a candidate implementation, and the changes in the
            // working tree are what that implementation was.
            return implode("\n", [
                '## The checks',
                '',
                'None. This run froze an empty `checks` set, so no verification command was run and '
                    . 'nothing here was established by one. What the tick rests on is the agent\'s own '
                    . 'result — read the diff before you commit it.',
                '',
                'Listing a command under `checks` in `.ralph/config.json` is what makes a later run '
                    . 'decide this question for itself.',
            ]);
        }

        $lines = [
            '## The checks',
            '',
            sprintf(
                '%s, run by the package in the project itself — not by the agent, and not from the '
                    . 'configuration as it stands now but from the set this run froze.',
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

    /**
     * Where the work is, which since section K, item 1 is one answer for every
     * pass: in the user's checkout, uncommitted, on whatever branch they had
     * checked out.
     */
    private function changesSection(): string
    {
        return implode("\n", [
            '## The changes',
            '',
            'Nothing was branched, committed or pushed. The agent worked in this checkout, on the '
                . 'branch you had checked out, and the checks above passed with its changes in place.',
            '',
            '- read them with `git diff` (and `git status` for files it added)',
            '- commit them yourself when you are satisfied with them',
            '- `git checkout -- .` is what undoes them, and it undoes the work of this task',
        ]);
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
                . 'that any configured check tests what this task asked for — that is what reading '
                . 'the diff is for, and a checked box is not a substitute for it.',
            '',
            'Nor is the agent\'s report evidence. It is kept above because it is often the quickest '
                . 'description of what changed, and it is never read as a reason.',
        ]);
    }
}
