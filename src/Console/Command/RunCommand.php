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
            'The exit status says how the run ended, and "ralph help" lists every code:',
            '',
            '    0  the checklist was worked through',
            '    1  a task was given up on after spending its attempts',
            '   75  a task is blocked on a decision, or a refusal stopped the run with its',
            '       work preserved',
            '  124  a configured limit stopped the run with tasks still pending',
            '  130  the run was stopped from outside; "ralph resume" continues it',
            '',
            'Whichever it is, the reason and what to do about it are the last line, and are',
            'kept on the run\'s record for "ralph status".',
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
