<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Run\RunIntent;

/**
 * `ralph retry TASK-ID`: try one task again, keeping what it already has.
 *
 * The pass is continued rather than replaced, so its attempts, logs, branch and
 * blocker details stay where they are and the next attempt is told what the
 * last one failed on. What the retry grants is the configured attempt allowance
 * again: a task whose attempts ran out is the commonest thing there is to
 * retry, and refusing it would make the command useless.
 *
 * One task and no others, which is the difference from `ralph run`. A failed
 * task is usually not the last pending item in the file, and working through
 * everything before it to get back to it is not what a person typing a task's
 * identifier is asking for.
 */
final class RetryCommand extends LoopCommand
{
    public function name(): string
    {
        return 'retry';
    }

    public function summary(): string
    {
        return 'Retry a failed or blocked task while preserving its history.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph retry TASK-ID',
            '',
            'Starts a new attempt at one task, continuing the pass that stopped: its earlier',
            'attempts, logs, branch and blocker details are kept, and the new attempt is',
            'given the recorded failure as evidence. The configured per-task attempt',
            'allowance starts again, so a task that ran out of attempts can be tried.',
            '',
            'Only the named task is taken on, whatever else the checklist has pending.',
            '',
            'Which tasks this applies to:',
            '',
            '  failed       stopped after unsuccessful attempts — this is the command',
            '  blocked      waiting on a decision; resolve what it is waiting for first',
            '  interrupted  use "ralph resume", which finishes the pass from where it',
            '               stopped instead of attempting it again',
            '  never run    use "ralph run", which takes pending tasks in file order',
            '  checked off  untick the item and run "ralph run": the work is taken on as a',
            '               new pass with its own branch, and the finished one keeps its',
            '               commit and its evidence',
            '',
            'Run "ralph status" to list the tasks with their identifiers and states, and',
            '"ralph logs TASK-ID" to read what the earlier attempts did.',
            '',
            'Exits as "ralph run" does: 0 when the task was verified, committed and checked',
            'off, 1 when it spent its attempts again, 75 when it stopped on a decision, and',
            '130 when it was stopped from outside.',
        ];
    }

    protected function maximumArguments(): int
    {
        return 1;
    }

    protected function intent(Input $input, Configuration $configuration): RunIntent
    {
        return RunIntent::retrying($this->taskArgument($input));
    }
}
