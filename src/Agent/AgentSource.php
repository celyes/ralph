<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

/**
 * Where the agent name used by a run came from.
 *
 * Recorded with the run because resume must reuse the agent that was actually
 * selected, and a reader of `status` needs to know whether that was the
 * project's default or a one-off override.
 */
enum AgentSource: string
{
    /**
     * The `agent` setting in the project configuration.
     */
    case ProjectDefault = 'project_default';

    /**
     * The `run --agent NAME` option, which overrides the default for one run.
     */
    case CommandLine = 'command_line';

    /**
     * The run being continued, read back from its row.
     *
     * What a resume's agent comes from, and the reason this is a third case
     * rather than the recorded source copied forward: the agent a resumed run
     * uses was not chosen by today's configuration or today's command line, and
     * a row saying it was would be read as a fresh decision taken under
     * settings that may since have changed (PROJECT.md section H, item 6).
     */
    case ResumedRun = 'resumed_run';

    /**
     * The source phrased for an error message or a status line.
     */
    public function describe(): string
    {
        return match ($this) {
            self::ProjectDefault => 'the project configuration',
            self::CommandLine => 'the --agent option',
            self::ResumedRun => 'the run being continued',
        };
    }
}
