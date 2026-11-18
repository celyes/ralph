<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Run\RunIntent;

/**
 * `ralph resume`: reconcile an interrupted run and continue it.
 *
 * The agent and its recorded settings come off the interrupted run's row, each
 * pass it left open is put back in step with the repository before anything is
 * worked on, and the iteration and time counters carry over — PROJECT.md
 * section H, items 3, 5 and 6, all of them the runner's doing.
 *
 * `--agent` exists here only to be refused when it names a different agent. A
 * resume cannot change agents part-way through a task's work, so the option's
 * whole value is that a user who types it learns what to type instead rather
 * than quietly getting a resume they did not ask for.
 */
final class ResumeCommand extends LoopCommand
{
    public function name(): string
    {
        return 'resume';
    }

    public function summary(): string
    {
        return 'Continue an interrupted run from its recorded phase.';
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph resume [--agent NAME] [--bail|--no-bail]',
            '',
            'Continues the run that was interrupted, with the agent and the settings',
            'recorded for it. An edit to the agent settings in .ralph/config.json since that',
            'run was opened is reported and not applied: the task is finished as it was',
            'started.',
            '',
            'A task is picked up at the phase it stopped in, and nothing earlier is repeated.',
            'A pass stopped while the agent was working is attempted again; one stopped',
            'during its checks has them run again over the changes that are still there;',
            'one whose box could not be written writes only the box, without invoking the',
            'agent and without spending an attempt.',
            '',
            'The run\'s iteration count and elapsed time carry over, so resuming finishes the',
            'work the interrupted run had left rather than starting its budget again.',
            '',
            'Options:',
            '  --agent NAME  Confirm the agent the interrupted run recorded. A resume cannot',
            '                change agents, so naming a different one is refused.',
            '  --bail        Stop at the first task that does not pass, leaving the tasks',
            '                after it untouched and still pending.',
            '  --no-bail     Carry on past a task that does not pass, even when',
            '                "limits.bail_on_failure" is on in the project configuration.',
            '',
            'Bailing is this resume\'s own decision, unlike the agent: it shapes the tasks',
            'still to come rather than the work already done, which is how the iteration and',
            'time limits are read too. Neither given, "limits.bail_on_failure" decides.',
            '',
            'To use a different agent, deal with the interrupted work first — "ralph status"',
            'shows what is outstanding — and then begin a new run with it:',
            '',
            '  ralph run --agent NAME',
            '',
            'A new run starts with the configured limits whole. Tasks earlier runs completed',
            'stay completed and are not taken on again, and their attempt history stays',
            'attributed to the agent that earned them.',
            '',
            'Exits as "ralph run" does: 0 when the resumed run finished the checklist, 1',
            'when a task was given up on, 75 when it stopped on a decision, 124 when it',
            'reached a configured limit, and 130 when it was stopped again.',
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
        return RunIntent::resumed($this->requestedAgent($input), $this->requestedBail($input));
    }
}
