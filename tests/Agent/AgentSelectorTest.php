<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterCatalog;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Resolving which agent a run uses.
 *
 * The behaviour that matters here is what happens when the answer is wrong: an
 * unconfigured name and an unavailable adapter must both stop the run with an
 * explanation, and neither may quietly resolve to a different agent. The rest
 * asserts that the default, the override, and the settings carried into the run
 * are the ones the project configured — and that no credential value travels
 * with them.
 */
#[CoversClass(AgentSelector::class)]
#[CoversClass(AgentSelection::class)]
#[CoversClass(AgentSource::class)]
#[CoversClass(BuiltInAdapters::class)]
final class AgentSelectorTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_package_default_agent_is_selected_when_nothing_overrides_it(): void
    {
        $selection = (new AgentSelector())->select($this->configuration([]));

        self::assertSame(Defaults::AGENT, $selection->name());
        self::assertSame('codex', $selection->adapter());
        self::assertSame(AgentSource::ProjectDefault, $selection->source());
        self::assertFalse($selection->isOverride());
    }

    #[Test]
    public function the_projects_default_agent_is_selected_when_nothing_overrides_it(): void
    {
        $selection = (new AgentSelector())->select($this->configuration(['agent' => 'claude']));

        self::assertSame('claude', $selection->name());
        self::assertSame('claude', $selection->adapter());
        self::assertSame(AgentSource::ProjectDefault, $selection->source());
    }

    #[Test]
    public function the_agent_option_overrides_the_projects_default(): void
    {
        $selection = (new AgentSelector())->select($this->configuration(['agent' => 'codex']), 'claude');

        self::assertSame('claude', $selection->name());
        self::assertSame(AgentSource::CommandLine, $selection->source());
        self::assertTrue($selection->isOverride());
    }

    #[Test]
    public function a_selected_agent_carries_the_settings_configured_for_it(): void
    {
        $configuration = $this->configuration([
            'agents' => [
                'codex-fast' => [
                    'adapter' => 'codex',
                    'executable' => '/opt/codex/bin/codex',
                    'model' => 'gpt-5-codex',
                    'arguments' => ['--full-auto'],
                ],
            ],
        ]);

        $definition = (new AgentSelector())->select($configuration, 'codex-fast')->definition();

        self::assertSame('codex', $definition->adapter());
        self::assertSame('/opt/codex/bin/codex', $definition->executable());
        self::assertSame('gpt-5-codex', $definition->model());
        self::assertSame(['--full-auto'], $definition->arguments());
    }

    #[Test]
    public function an_agent_entry_overriding_a_built_in_keeps_that_built_ins_adapter(): void
    {
        $configuration = $this->configuration([
            'agents' => ['claude' => ['executable' => '/usr/local/bin/claude']],
        ]);

        $selection = (new AgentSelector())->select($configuration, 'claude');

        self::assertSame('claude', $selection->adapter());
        self::assertSame('/usr/local/bin/claude', $selection->definition()->executable());
    }

    #[Test]
    public function the_persisted_selection_records_the_agent_its_source_and_its_settings(): void
    {
        $configuration = $this->configuration([
            'agents' => ['claude' => ['model' => 'claude-opus-5']],
        ]);

        $selection = (new AgentSelector())->select($configuration, 'claude');

        self::assertSame([
            'agent' => 'claude',
            'source' => 'command_line',
            'settings' => [
                'adapter' => 'claude',
                'executable' => 'claude',
                'model' => 'claude-opus-5',
                'arguments' => [],
                'environment' => [],
            ],
        ], $selection->toArray());

        self::assertSame(
            'claude (adapter "claude", selected by the --agent option)',
            $selection->describe(),
        );
    }

    #[Test]
    public function the_persisted_selection_carries_variable_names_and_not_their_values(): void
    {
        $variable = 'RALPH_TEST_AGENT_TOKEN';
        putenv($variable . '=super-secret-value');

        try {
            $configuration = $this->configuration([
                'agents' => ['codex' => ['environment' => [$variable]]],
            ]);

            $selection = (new AgentSelector())->select($configuration, 'codex');
            $persisted = json_encode($selection->toArray(), JSON_THROW_ON_ERROR);

            self::assertStringContainsString($variable, $persisted);
            self::assertStringNotContainsString('super-secret-value', $persisted);
            self::assertSame([$variable], $selection->definition()->environment());
        } finally {
            putenv($variable);
        }
    }

    #[Test]
    public function an_agent_name_no_entry_matches_is_rejected_with_a_suggestion(): void
    {
        $exception = $this->selectionFailure($this->configuration([]), 'codx');

        self::assertSame('No agent named "codx" is configured.', $exception->getMessage());
        self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        self::assertContains('Did you mean "codex"?', $exception->hints());
        self::assertContains('Configured agents: "codex", "claude".', $exception->hints());
        self::assertContains('"--agent NAME" must name one of them.', $exception->hints());
    }

    #[Test]
    public function an_unconfigured_default_agent_points_at_the_agent_setting(): void
    {
        $configuration = $this->configuration(['agent' => 'gemini']);

        $exception = $this->selectionFailure($configuration);

        self::assertSame('No agent named "gemini" is configured.', $exception->getMessage());
        self::assertContains(
            sprintf('The "agent" setting in %s must name one of them.', $this->project->path('.ralph/config.json')),
            $exception->hints(),
        );
        self::assertContains(
            sprintf('Add an entry under "agents" in %s to configure another agent.', $this->project->path('.ralph/config.json')),
            $exception->hints(),
        );
    }

    #[Test]
    public function an_unconfigured_agent_names_the_file_init_would_create_when_there_is_none(): void
    {
        $configuration = (new ConfigurationLoader())->loadOrDefaults($this->project->root());

        $exception = $this->selectionFailure($configuration, 'gemini');

        self::assertNull($configuration->source());
        self::assertContains(
            sprintf('Add an entry under "agents" in %s to configure another agent.', $this->project->path('.ralph/config.json')),
            $exception->hints(),
        );
    }

    #[Test]
    public function an_agent_naming_an_unavailable_adapter_is_rejected_without_falling_back(): void
    {
        $configuration = $this->configuration([
            'agent' => 'house',
            'agents' => ['house' => ['adapter' => 'codexx', 'executable' => './bin/worker']],
        ]);

        $exception = $this->selectionFailure($configuration);

        self::assertSame('The agent "house" names the unsupported adapter "codexx".', $exception->getMessage());
        self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        self::assertContains('Did you mean "codex"?', $exception->hints());
        self::assertContains('Available adapters: "codex", "claude".', $exception->hints());
        self::assertContains(
            sprintf(
                'Set "agents.house.adapter" in %s to an available adapter, or register the adapter under that name.',
                $this->project->path('.ralph/config.json'),
            ),
            $exception->hints(),
        );
    }

    #[Test]
    public function an_adapter_registered_by_the_project_is_accepted(): void
    {
        $configuration = $this->configuration([
            'agents' => ['house' => ['adapter' => 'house-agent', 'executable' => './bin/worker']],
        ]);

        $selector = new AgentSelector(new class () implements AdapterCatalog {
            public function has(string $adapter): bool
            {
                return in_array($adapter, $this->names(), true);
            }

            /**
             * @return list<string>
             */
            public function names(): array
            {
                return ['house-agent'];
            }
        });

        $selection = $selector->select($configuration, 'house');

        self::assertSame('house', $selection->name());
        self::assertSame('house-agent', $selection->adapter());
    }

    #[Test]
    public function a_built_in_agent_is_rejected_when_its_adapter_is_not_in_the_catalog(): void
    {
        $selector = new AgentSelector(new class () implements AdapterCatalog {
            public function has(string $adapter): bool
            {
                return false;
            }

            /**
             * @return list<string>
             */
            public function names(): array
            {
                return [];
            }
        });

        try {
            $selector->select($this->configuration([]), 'codex');
            self::fail('Expected the selection to fail.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('unsupported adapter "codex"', $exception->getMessage());
            self::assertContains('Available adapters: none.', $exception->hints());
        }
    }

    #[Test]
    public function an_empty_agent_option_is_a_usage_error(): void
    {
        try {
            (new AgentSelector())->select($this->configuration([]), '   ');
            self::fail('Expected the selection to fail.');
        } catch (UsageException $exception) {
            self::assertSame('Option "--agent" requires an agent name.', $exception->getMessage());
            self::assertSame(ExitCode::USAGE, $exception->exitCode());
            self::assertContains('Configured agents: "codex", "claude".', $exception->hints());
        }
    }

    #[Test]
    public function every_built_in_agent_names_an_available_adapter(): void
    {
        $adapters = new BuiltInAdapters();
        $configuration = $this->configuration([]);

        foreach ($configuration->agentNames() as $name) {
            $selection = (new AgentSelector($adapters))->select($configuration, $name);

            self::assertTrue(
                $adapters->has($selection->adapter()),
                sprintf('The built-in agent "%s" names an unavailable adapter.', $name),
            );
        }

        self::assertSame(['codex', 'claude'], $adapters->names());
        self::assertFalse($adapters->has('CODEX'));
    }

    /**
     * A configuration loaded from a real file, so that the paths quoted in the
     * error hints are the ones the user would have to edit.
     *
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings): Configuration
    {
        $this->project->writeConfigArray($settings === [] ? ['agent' => Defaults::AGENT] : $settings);

        return (new ConfigurationLoader())->load($this->project->root());
    }

    private function selectionFailure(Configuration $configuration, ?string $requested = null): ConfigurationException
    {
        try {
            (new AgentSelector())->select($configuration, $requested);
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('Expected the selection to fail.');
    }
}
