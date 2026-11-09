<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Run\RunIntent;

/**
 * `ralph run`: process pending tasks within the configured limits.
 *
 * The checklist's pending items in file order, one at a time, each carried
 * through the sequence PROJECT.md section G, item 1 names. {@see LoopCommand}
 * is the wiring; this is the one decision that makes it a run rather than a
 * resume or a retry.
 */
final class RunCommand extends LoopCommand
{
    public function name(): string
    {
        return 'run';
    }

    public function summary(): string
    {
        return 'Process pending tasks within the configured limits.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph run [--agent NAME]',
            '',
            'Takes the checklist\'s pending tasks in file order, one at a time. For each:',
            'prepares an isolated worktree and the task\'s context, invokes the selected',
            'agent, runs the configured verification commands independently of it, commits',
            'the verified changes on a task branch, pushes when pushing is enabled, and only',
            'then checks the item off.',
            '',
            'A task whose checks fail is attempted again while its attempt limit holds, and',
            'is left unchecked; the run carries on to the next task, which is independent',
            'work. A task that stops to ask a question ends the run, because the answer is a',
            'person\'s to give.',
            '',
            'Options:',
            '  --agent NAME  Use this agent for the run instead of the configured default.',
            '',
            'Nothing of yours is touched without being asked for: a checkout holding',
            'unrelated uncommitted changes is refused rather than stashed, task commits are',
            'made on their own branches, and the only write to a file you own is the',
            'checkbox of a task that passed.',
            '',
            'Ctrl+C stops the agent, records the interruption and preserves the workspace;',
            '"ralph resume" then continues from the phase it stopped in.',
            '',
            'Exits 75 when the run stopped with something waiting for a person: a task',
            'blocked on a decision, or a task it gave up on. A run that worked through the',
            'checklist, reached a configured limit, or was stopped with Ctrl+C exits 0 —',
            'each of those did what it was asked. Either way the reason and what to do',
            'about it are the last line, and are kept on the run\'s record for',
            '"ralph status".',
        ];
    }

    /**
     * @return list<string>
     */
    public function valueOptions(): array
    {
        return ['agent'];
    }

    /**
     * @return list<string>
     */
    protected function options(): array
    {
        return ['agent'];
    }

    protected function intent(Input $input, Configuration $configuration): RunIntent
    {
        return RunIntent::fresh($this->requestedAgent($input));
    }
}
