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
     * The source phrased for an error message or a status line.
     */
    public function describe(): string
    {
        return match ($this) {
            self::ProjectDefault => 'the project configuration',
            self::CommandLine => 'the --agent option',
        };
    }
}
