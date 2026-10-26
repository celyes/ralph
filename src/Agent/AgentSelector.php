<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Support\Suggestions;

/**
 * Decides which agent a run uses.
 *
 * The project's `agent` setting is the default and `run --agent NAME` overrides
 * it for that run; one run uses exactly one agent. The result is an
 * {@see AgentSelection}, which the run persists so that resume and `status`
 * report the agent that was actually used.
 *
 * Both failures this can produce — a name no `agents` entry matches, and an
 * entry naming an adapter with no implementation behind it — are raised here,
 * before any task is selected or any workspace is created. Neither falls back
 * to another agent: silently running a different agent than the one asked for
 * would attribute its commits and its cost to the wrong tool.
 */
final class AgentSelector
{
    public function __construct(private readonly AdapterCatalog $adapters = new BuiltInAdapters())
    {
    }

    /**
     * Resolve the agent for a run.
     *
     * @param string|null $requested the value of `--agent`, or null to use the project default
     *
     * @throws UsageException when `--agent` was given without a usable name
     * @throws ConfigurationException when no such agent is configured, or its
     *                                adapter is not available
     */
    public function select(Configuration $configuration, ?string $requested = null): AgentSelection
    {
        if ($requested !== null && trim($requested) === '') {
            throw new UsageException(
                'Option "--agent" requires an agent name.',
                [
                    sprintf('Configured agents: %s.', $this->listNames($configuration->agentNames())),
                    'Omit "--agent" to use the project\'s default agent.',
                ],
            );
        }

        $source = $requested === null ? AgentSource::ProjectDefault : AgentSource::CommandLine;
        $name = $requested ?? $configuration->defaultAgent();

        $definition = $configuration->agent($name);
        if ($definition === null) {
            throw $this->unknownAgent($name, $source, $configuration);
        }

        if (!$this->adapters->has($definition->adapter())) {
            throw $this->unsupportedAdapter($definition, $configuration);
        }

        return new AgentSelection($definition, $source);
    }

    /**
     * The name does not match any `agents` entry.
     *
     * Reported the same way whichever source named it, with a hint pointing at
     * the place that has to change: the command line, or the `agent` setting.
     */
    private function unknownAgent(string $name, AgentSource $source, Configuration $configuration): ConfigurationException
    {
        $configured = $configuration->agentNames();
        $hints = [];

        $suggestion = Suggestions::sentence($name, $configured);
        if ($suggestion !== null) {
            $hints[] = $suggestion;
        }

        $hints[] = sprintf('Configured agents: %s.', $this->listNames($configured));
        $hints[] = $source === AgentSource::CommandLine
            ? '"--agent NAME" must name one of them.'
            : sprintf('The "agent" setting in %s must name one of them.', $this->configurationFile($configuration));
        $hints[] = sprintf(
            'Add an entry under "agents" in %s to configure another agent.',
            $this->configurationFile($configuration),
        );

        return new ConfigurationException(
            sprintf('No agent named "%s" is configured.', $name),
            $hints,
        );
    }

    /**
     * The agent exists but names an adapter the package cannot execute.
     */
    private function unsupportedAdapter(AgentDefinition $definition, Configuration $configuration): ConfigurationException
    {
        $available = $this->adapters->names();
        $hints = [];

        $suggestion = Suggestions::sentence($definition->adapter(), $available);
        if ($suggestion !== null) {
            $hints[] = $suggestion;
        }

        $hints[] = sprintf('Available adapters: %s.', $this->listNames($available));
        $hints[] = sprintf(
            'Set "agents.%s.adapter" in %s to an available adapter, or register the adapter under that name.',
            $definition->name(),
            $this->configurationFile($configuration),
        );

        return new ConfigurationException(
            sprintf(
                'The agent "%s" names the unsupported adapter "%s".',
                $definition->name(),
                $definition->adapter(),
            ),
            $hints,
        );
    }

    /**
     * The file the user has to edit. A configuration built from the defaults has
     * no source file, so the path `ralph init` would create is named instead.
     */
    private function configurationFile(Configuration $configuration): string
    {
        return $configuration->source() ?? ConfigurationLoader::fileIn($configuration->projectRoot());
    }

    /**
     * @param list<string> $names
     */
    private function listNames(array $names): string
    {
        if ($names === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $names));
    }
}
