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
            'Usage: ralph run [--agent NAME] [--bail|--no-bail]',
            '',
            'Takes the checklist\'s pending tasks in file order, one at a time. For each:',
            'prepares the task\'s context, invokes the selected agent in this directory,',
            'runs the configured verification commands independently of it, and only then',
            'checks the item off.',
            '',
            'A task whose checks fail is attempted again while its attempt limit holds, and',
            'is left unchecked; the run carries on to the next task, which is independent',
            'work — unless bailing is on, which stops the run there. A task that stops to ask',
            'a question ends the run either way, because the answer is a person\'s to give.',
            '',
            'Options:',
            '  --agent NAME  Use this agent for the run instead of the configured default.',
            '  --bail        Stop the run at the first task that does not pass, leaving the',
            '                tasks after it untouched and still pending.',
            '  --no-bail     Carry on past a task that does not pass, even when',
            '                "limits.bail_on_failure" is on in the project configuration.',
            '',
            'Neither given, "limits.bail_on_failure" decides; it is off by default. A bailed',
            'run exits 1 and names the task it stopped on, and the task is left exactly as a',
            'failure the run walked past would be: unchecked, retriable, with its changes',
            'and logs kept.',
            '',
            'The agent works in this directory, on whatever branch is checked out. Nothing',
            'is branched, committed, stashed or pushed: what a run leaves behind is changes',
            'in the working tree, and the only file this package writes itself is the',
            'checkbox of a task that passed. Read the result with "git diff" and commit it',
            'yourself.',
            '',
            'What is printed is the run\'s own account of itself: the run, a line for each',
            'task taken on, what happened to it, and why the run ended. Add "--verbose" to',
            'watch the agent work — its output as it arrives, and a note for every step the',
            'package takes. Nothing is lost by leaving it off: a quiet run writes the same',
            'logs, which "ralph logs TASK-ID" prints afterwards.',
            '',
            'Ctrl+C stops the agent, records the interruption and leaves whatever it had',
            'written in place; "ralph resume" then continues from the phase it stopped in.',
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
        return ['agent', 'bail', 'no-bail'];
    }

    protected function intent(Input $input, Configuration $configuration): RunIntent
    {
        return RunIntent::fresh($this->requestedAgent($input), $this->requestedBail($input));
    }
}
