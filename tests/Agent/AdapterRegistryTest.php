<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The registry that says which adapters exist, and builds them.
 *
 * Two things are under test. That an agent the package has never heard of can
 * be selected and constructed through registration alone — PROJECT.md section
 * E, item 3's "without editing the runner", asserted by configuring one and
 * running it. And that a registration is never silently replaced: a name is
 * what a run's attempts, logs and commits are attributed to, so a second claim
 * on one has to fail rather than win.
 */
#[CoversClass(AdapterRegistry::class)]
#[CoversClass(BuiltInAdapters::class)]
final class AdapterRegistryTest extends TestCase
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
    public function a_registered_adapter_is_available_under_the_name_it_registered(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('acme'));

        self::assertTrue($registry->has('acme'));
        self::assertSame(['acme'], $registry->names());
        self::assertSame('acme', $registry->create($this->selection('acme'))->name());
    }

    #[Test]
    public function an_adapter_name_is_matched_exactly(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('acme'));

        // A configuration saying "Acme" is reported as unavailable rather than
        // quietly resolved: the name is persisted with the run as written.
        self::assertFalse($registry->has('Acme'));
        self::assertFalse($registry->has('acme '));
    }

    #[Test]
    public function adapters_are_listed_in_registration_order(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('zeta'));
        $registry->register($this->provider('acme'));

        self::assertSame(['zeta', 'acme'], $registry->names());
    }

    #[Test]
    public function the_provider_is_given_the_selected_agents_own_settings(): void
    {
        $provider = new NamedAdapterProvider('acme');
        $registry = new AdapterRegistry();
        $registry->register($provider);

        $registry->create(new AgentSelection(
            new AgentDefinition('acme-fast', 'acme', '/opt/acme/bin/acme', 'acme-2', ['--full-auto']),
            AgentSource::CommandLine,
        ));

        self::assertNotNull($provider->definition);
        self::assertSame('/opt/acme/bin/acme', $provider->definition->executable());
        self::assertSame('acme-2', $provider->definition->model());
        self::assertSame(['--full-auto'], $provider->definition->arguments());
    }

    #[Test]
    public function a_second_registration_of_one_name_is_refused_rather_than_taken_as_an_override(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('acme'));

        $failure = $this->failure(fn () => $registry->register($this->provider('acme')));

        self::assertSame('The adapter "acme" is already registered.', $failure->getMessage());
        self::assertStringContainsString(
            'Ralph PHP does not replace a registered adapter',
            implode("\n", $failure->hints()),
        );

        // The first registration is the one that stands, and it still works.
        self::assertSame('acme', $registry->create($this->selection('acme'))->name());
    }

    #[Test]
    public function a_name_the_package_ships_cannot_be_registered_by_a_project(): void
    {
        foreach ((new BuiltInAdapters())->names() as $reserved) {
            $failure = $this->failure(fn () => (new AdapterRegistry())->register($this->provider($reserved)));

            self::assertSame(
                sprintf('The adapter name "%s" is reserved for the adapter Ralph PHP ships.', $reserved),
                $failure->getMessage(),
            );
            self::assertStringContainsString(
                'attribute work to the wrong tool',
                implode("\n", $failure->hints()),
            );

            // Said the same way on a registry that already holds the shipped
            // provider, so the reason a project cannot take the name does not
            // change as the built-in adapters land.
            self::assertSame(
                sprintf('The adapter name "%s" is reserved for the adapter Ralph PHP ships.', $reserved),
                $this->failure(
                    fn () => AdapterRegistry::default()->register($this->provider($reserved)),
                )->getMessage(),
            );
        }
    }

    #[Test]
    public function a_name_no_configuration_file_could_contain_is_refused(): void
    {
        // The shape the configuration validator requires of "agents.*.adapter":
        // a provider must not be able to register a name no project could
        // legally point an agent at.
        foreach (['', ' ', 'two words', 'with/slash', '-leading', 'tab' . "\t"] as $name) {
            $failure = $this->failure(fn () => (new AdapterRegistry())->register($this->provider($name)));

            self::assertStringContainsString('unusable adapter name', $failure->getMessage());
            self::assertSame(ExitCode::CONFIGURATION, $failure->exitCode());
        }
    }

    #[Test]
    public function an_empty_name_is_named_as_such_rather_than_quoted(): void
    {
        $failure = $this->failure(fn () => (new AdapterRegistry())->register($this->provider('')));

        self::assertStringContainsString('registers the unusable adapter name an empty string', $failure->getMessage());
    }

    #[Test]
    public function an_unregistered_adapter_stops_the_run_without_substituting_another(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('acme'));

        $failure = $this->failure(fn () => $registry->create($this->selection('acmee', 'mine')));

        self::assertSame('No adapter is registered under the name "acmee".', $failure->getMessage());
        self::assertSame(ExitCode::CONFIGURATION, $failure->exitCode());

        $hints = implode("\n", $failure->hints());
        self::assertStringContainsString('Did you mean "acme"?', $hints);
        self::assertStringContainsString('Registered adapters: acme.', $hints);
        self::assertStringContainsString('The agent "mine" selects it', $hints);
        self::assertStringContainsString(Defaults::ADAPTERS_FILE, $hints);
        self::assertStringContainsString('does not run a different agent in its place', $hints);
    }

    #[Test]
    public function an_empty_registry_says_so_instead_of_listing_nothing(): void
    {
        $failure = $this->failure(fn () => (new AdapterRegistry())->create($this->selection('acme')));

        self::assertStringContainsString('No adapters are registered.', implode("\n", $failure->hints()));
    }

    #[Test]
    public function a_provider_building_an_adapter_with_another_name_is_refused(): void
    {
        $registry = new AdapterRegistry();
        $registry->register(new NamedAdapterProvider('acme', builds: 'something-else'));

        $failure = $this->failure(fn () => $registry->create($this->selection('acme')));

        self::assertStringContainsString(
            'is registered as "acme" but builds an adapter named "something-else"',
            $failure->getMessage(),
        );
        self::assertStringContainsString(
            'Attempts and logs are recorded under the adapter name',
            implode("\n", $failure->hints()),
        );
    }

    #[Test]
    public function the_registry_is_the_catalog_agent_selection_asks(): void
    {
        $registry = new AdapterRegistry();
        $registry->register($this->provider('acme'));

        $this->project->writeConfigArray([
            'agent' => 'mine',
            'agents' => ['mine' => ['adapter' => 'acme', 'executable' => '/usr/bin/acme']],
        ]);
        $configuration = (new ConfigurationLoader())->load($this->project->root());

        // An agent for an adapter this package has never heard of, selected and
        // constructed with nothing in the package naming it: section E, item 3.
        $selection = (new AgentSelector($registry))->select($configuration);

        self::assertSame('mine', $selection->name());
        self::assertSame('acme', $selection->adapter());
        self::assertSame('acme', $registry->create($selection)->name());
    }

    #[Test]
    public function selection_rejects_an_adapter_the_registry_does_not_provide(): void
    {
        $this->project->writeConfigArray([
            'agent' => 'mine',
            'agents' => ['mine' => ['adapter' => 'acme', 'executable' => '/usr/bin/acme']],
        ]);
        $configuration = (new ConfigurationLoader())->load($this->project->root());

        $failure = $this->failure(
            fn () => (new AgentSelector(new AdapterRegistry()))->select($configuration),
        );

        self::assertSame(
            'The agent "mine" names the unsupported adapter "acme".',
            $failure->getMessage(),
        );
    }

    /**
     * A project's registry is the shipped adapters plus its own file's.
     *
     * The composition every command that can start an agent goes through, in
     * one place so that the loop and the preflight checks cannot disagree
     * about which adapters exist: a project with no `.ralph/adapters.php` gets
     * the built-ins and no error, and a project with one gets its adapter
     * alongside them, after them, where it cannot shadow one.
     */
    #[Test]
    public function a_projects_registry_holds_the_shipped_adapters_and_its_own(): void
    {
        self::assertSame(
            AdapterRegistry::default()->names(),
            AdapterRegistry::forProject($this->project->root())->names(),
            'A project with no registration file is not a project with no adapters.',
        );

        $this->project->write(Defaults::ADAPTERS_FILE, sprintf(
            "<?php\n\nreturn [new \\%s('acme')];\n",
            NamedAdapterProvider::class,
        ));

        self::assertSame(
            [...AdapterRegistry::default()->names(), 'acme'],
            AdapterRegistry::forProject($this->project->root())->names(),
        );
    }

    #[Test]
    public function every_shipped_provider_answers_to_a_declared_built_in_name(): void
    {
        $declared = (new BuiltInAdapters())->names();

        foreach (BuiltInAdapters::providers() as $provider) {
            self::assertContains(
                $provider->adapter(),
                $declared,
                sprintf('%s provides an adapter the package does not declare.', $provider::class),
            );
        }

        // A default registry provides exactly what the package ships, in the
        // order it declares them. Codex is there (section E, item 6); Claude
        // Code joins it with item 7.
        self::assertSame(
            array_map(static fn (AdapterProvider $provider): string => $provider->adapter(), BuiltInAdapters::providers()),
            AdapterRegistry::default()->names(),
        );
    }

    /**
     * A provider whose adapter answers to the name it registers: what is under
     * test here is the registry, so the adapter behind it does nothing else.
     */
    private function provider(string $adapter): AdapterProvider
    {
        return new NamedAdapterProvider($adapter);
    }

    private function selection(string $adapter, string $agent = 'acme'): AgentSelection
    {
        return new AgentSelection(
            new AgentDefinition($agent, $adapter, 'acme-cli'),
            AgentSource::ProjectDefault,
        );
    }

    private function failure(callable $call): ConfigurationException
    {
        try {
            $call();
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('Expected a configuration failure.');
    }
}
