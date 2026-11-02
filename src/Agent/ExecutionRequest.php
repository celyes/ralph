<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\Sinks;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;

/**
 * Everything one invocation of an agent is given.
 *
 * The request half of the contract PROJECT.md section 4 requires be common to
 * every adapter, and the list is section E, item 1's: the task, its optional
 * criteria, the workspace, the project context, the previous attempt's failure
 * evidence, and what is left of the execution limits. Passing them as one
 * object rather than seven parameters is what lets a third-party adapter
 * registered through section E, item 3 keep working when the runner learns to
 * pass something more.
 *
 * **What it deliberately does not carry.** The verification commands are not
 * here. They are snapshotted at run start precisely so that nothing during an
 * attempt can replace them (section G, item 3), and an agent told the exact
 * commands its work will be judged by is an agent that can satisfy the commands
 * instead of the task. The adapter also gets no run identifier, no database
 * handle and no checklist: an adapter implements one invocation, and the loop's
 * bookkeeping stays with the loop.
 *
 * **The output sink is here, and is not part of the briefing.** An adapter has
 * to be handed somewhere to stream its worker's output to (section E, item 4),
 * and it cannot hold one itself: adapters are stateless across attempts, while
 * a log belongs to one attempt. So it arrives with the attempt, like everything
 * else here, and is the one member of this object the agent is never told
 * about. Nothing is read back from it; it is a destination.
 *
 * **It is not a prompt.** {@see self::toMarkdown()} renders what is *known*
 * about the attempt, in one place, for the same reason
 * {@see AttemptContext::toMarkdown()} exists: two adapters composing the same
 * briefing separately would answer "what was the agent actually told" two
 * slightly different ways, and the first time that matters is when one agent
 * succeeds at a task the other cannot. How the briefing reaches the agent — an
 * argument, standard input, a file it is pointed at — and whatever instruction
 * preamble that agent's CLI expects are the adapter's own business (section E,
 * items 6 and 7).
 */
final class ExecutionRequest
{
    /**
     * What the runner observed on the attempt before this one.
     */
    private readonly FailureEvidence $evidence;

    /**
     * Where this attempt's output is streamed as it arrives.
     */
    private readonly OutputSink $output;

    /**
     * @param int $attempt 1-based attempt number within this generation
     * @param FailureEvidence|null $evidence what the previous attempt ran into,
     *        or null on the first attempt at a generation
     * @param OutputSink|null $output where the worker's output goes while it
     *        runs, or null to keep and show nothing — which is what a test of
     *        something else wants, and never what a run wants
     *
     * @throws InvalidArgumentException when the attempt number is not positive,
     *                                 the task is not the one the generation is
     *                                 for, or the budget has nothing left
     */
    public function __construct(
        private readonly AgentSelection $agent,
        private readonly TaskGeneration $generation,
        private readonly int $attempt,
        private readonly Task $task,
        private readonly Workspace $workspace,
        private readonly AttemptContext $context,
        private readonly ExecutionBudget $budget,
        ?FailureEvidence $evidence = null,
        ?OutputSink $output = null,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('An attempt number starts at 1.');
        }

        // The generation names the task it is a pass at, so a mismatch means
        // the caller has paired a task with another task's history — and the
        // memory notes and the branch would both belong to the other one.
        if (!$generation->belongsTo($task->id())) {
            throw new InvalidArgumentException(sprintf(
                'The generation %s is not a pass at %s.',
                $generation->slug(),
                $task->id()->value(),
            ));
        }

        // Refused here rather than in the adapter: a spent budget means the
        // task stops, which is the runner's decision (PROJECT.md section H),
        // and an adapter should never have to recognise a zero-second timeout
        // as one.
        if ($budget->isExhausted()) {
            throw new InvalidArgumentException(sprintf(
                'No attempt can be prepared for %s: %s.',
                $generation->label(),
                $budget->describe(),
            ));
        }

        $this->evidence = $evidence ?? FailureEvidence::none();
        $this->output = $output ?? Sinks::none();
    }

    /**
     * The agent this run selected, with its executable, optional model, extra
     * arguments and the names of its authentication variables.
     */
    public function agent(): AgentSelection
    {
        return $this->agent;
    }

    /**
     * Which pass at the task this attempt belongs to.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * Which attempt at that pass this is, counting from 1.
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * Whether an earlier attempt at this pass has already been made.
     */
    public function isRetry(): bool
    {
        return $this->attempt > 1;
    }

    /**
     * The task being worked on.
     */
    public function task(): Task
    {
        return $this->task;
    }

    public function taskId(): TaskId
    {
        return $this->task->id();
    }

    /**
     * The work to do, as the checklist states it.
     */
    public function description(): string
    {
        return $this->task->description();
    }

    /**
     * The indented notes written under the checklist item, or null when there
     * are none.
     *
     * Optional by design: a one-line task with no criteria under it is the
     * ordinary case, and a heading over nothing would read as though something
     * had been lost.
     */
    public function criteria(): ?string
    {
        $details = trim($this->task->details());

        return $details === '' ? null : $details;
    }

    public function hasCriteria(): bool
    {
        return $this->criteria() !== null;
    }

    /**
     * The directory the agent is to be run in.
     */
    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * The workspace path, for the adapter's explicit working directory.
     */
    public function directory(): string
    {
        return $this->workspace->path();
    }

    /**
     * The project's instructions and the notes from earlier work.
     */
    public function context(): AttemptContext
    {
        return $this->context;
    }

    /**
     * What the runner observed on the previous attempt.
     *
     * Empty on a first attempt, and also on a retry after an attempt that went
     * wrong without the runner seeing anything worth passing on.
     */
    public function evidence(): FailureEvidence
    {
        return $this->evidence;
    }

    public function hasEvidence(): bool
    {
        return !$this->evidence->isEmpty();
    }

    /**
     * Where to stream this attempt's output as it is produced.
     *
     * Handed to the process runner by {@see ProcessAdapter}, so an adapter that
     * extends that base class gets the terminal and the log without doing
     * anything. An adapter that runs its agent some other way is responsible
     * for writing to this itself; an attempt that streams nowhere is an attempt
     * a user can only watch by waiting for it to finish.
     */
    public function output(): OutputSink
    {
        return $this->output;
    }

    /**
     * What is left of the attempt and time limits.
     */
    public function budget(): ExecutionBudget
    {
        return $this->budget;
    }

    /**
     * Seconds this invocation may take: the timeout the adapter gives the
     * process it starts.
     */
    public function seconds(): int
    {
        return $this->budget->seconds();
    }

    /**
     * One line for the terminal and the log header.
     */
    public function summary(): string
    {
        return sprintf(
            '%s, attempt %d, agent %s: %s (%s; %s)',
            $this->generation->label(),
            $this->attempt,
            $this->agent->name(),
            $this->description(),
            $this->budget->describe(),
            $this->context->summary(),
        );
    }

    /**
     * The briefing, as Markdown.
     *
     * In the order it is meant to be read: the work, then what finishing it
     * means and how to report it, then the standing instructions and the
     * history, then what the last attempt ran into. The failure evidence is
     * last on purpose — it is the most specific thing here, and on a retry it
     * is what the attempt should act on first.
     */
    public function toMarkdown(): string
    {
        $sections = [$this->taskSection()];

        $criteria = $this->criteria();
        if ($criteria !== null) {
            $sections[] = implode("\n", [
                '# Acceptance criteria',
                '',
                'Written under the task in the checklist by whoever wrote the task.',
                '',
                $criteria,
            ]);
        }

        // Before the context and the history, because it is part of what
        // finishing the task means, and after the criteria for the same reason:
        // what the work is comes first. Stated in the common briefing rather
        // than in each adapter's preamble because the outcomes it names are
        // agent-independent (section E, item 10) — two adapters wording this
        // their own way would be two agents reporting in two formats the reader
        // of section E, item 5 does not accept.
        $sections[] = ResultProtocol::instructions();

        $context = trim($this->context->toMarkdown());
        if ($context !== '') {
            $sections[] = $context;
        }

        if ($this->hasEvidence()) {
            $sections[] = $this->evidenceSection();
        }

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * The task, the workspace, and the room left.
     *
     * The workspace is stated because it is the one fact an agent cannot
     * discover and must not get wrong: changes made anywhere else are not part
     * of the task and will not be committed. The remaining budget is stated
     * because an attempt that knows it is the last one should spend its time
     * differently.
     */
    private function taskSection(): string
    {
        $lines = [
            sprintf('# %s, attempt %d', $this->generation->label(), $this->attempt),
            '',
            $this->description(),
            '',
            sprintf('- **Work in:** `%s`. Changes outside it are not part of this task.', $this->directory()),
            sprintf('- **Budget:** %s.', $this->budget->describe()),
        ];

        if ($this->generation->isReopening()) {
            $lines[] = '- **Reopened:** this task was finished before and has been reopened,'
                . ' on a new branch of its own. Earlier work is not in this workspace.';
        }

        if ($this->budget->isLastAttempt()) {
            $lines[] = '- **Last attempt:** nothing follows this one. Finish the smallest'
                . ' correct change rather than starting something larger.';
        }

        return implode("\n", $lines);
    }

    /**
     * What went wrong last time, attributed to the runner.
     *
     * Headed as the package's own observations, because the briefing's other
     * half — the notes under "Notes from earlier work" — is the agent's own
     * prose, and an attempt that cannot tell a failing command from a previous
     * attempt's opinion of it will argue with the wrong one.
     */
    private function evidenceSection(): string
    {
        return implode("\n", [
            '# What the previous attempt ran into',
            '',
            'Observed by Ralph PHP itself, from the commands it ran after the last attempt.',
            'Unlike the notes above, this is not an agent\'s account: these commands'
            . ' were run and this is what they printed.',
            '',
            $this->evidence->toMarkdown(),
        ]);
    }
}
