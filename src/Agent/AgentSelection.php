<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;

/**
 * The agent one run uses, together with how it was chosen.
 *
 * A run resolves this once, before the first task, and keeps it: PROJECT.md
 * requires that one run uses one agent, that the resolved agent and its
 * non-secret settings are persisted with the run and its attempts, and that
 * resume continues with the recorded agent instead of re-reading a
 * configuration file that may have changed in the meantime.
 *
 * Carries no credentials: the definition lists only the *names* of the
 * environment variables the agent authenticates with, so {@see self::toArray()}
 * is safe to write to the state store and to print.
 */
final class AgentSelection
{
    public function __construct(
        private readonly AgentDefinition $definition,
        private readonly AgentSource $source,
    ) {
    }

    /**
     * The configured agent name, which is also what `--agent` takes.
     */
    public function name(): string
    {
        return $this->definition->name();
    }

    /**
     * The adapter that performs the invocation.
     */
    public function adapter(): string
    {
        return $this->definition->adapter();
    }

    /**
     * How to invoke the agent: executable, optional model, extra arguments, and
     * the names of its authentication variables.
     */
    public function definition(): AgentDefinition
    {
        return $this->definition;
    }

    public function source(): AgentSource
    {
        return $this->source;
    }

    /**
     * Whether this run overrode the project's default agent.
     */
    public function isOverride(): bool
    {
        return $this->source === AgentSource::CommandLine;
    }

    /**
     * One line naming the agent, its adapter and where it was chosen, for
     * `status` and for the run's log header.
     */
    public function describe(): string
    {
        return sprintf(
            '%s (adapter "%s", selected by %s)',
            $this->name(),
            $this->adapter(),
            $this->source->describe(),
        );
    }

    /**
     * The selection as plain data, for persisting with the run.
     *
     * Contains no secrets: see the class description.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent' => $this->name(),
            'source' => $this->source->value,
            'settings' => $this->definition->toArray(),
        ];
    }
}
