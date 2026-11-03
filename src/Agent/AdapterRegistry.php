<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Support\Suggestions;

/**
 * The adapters a run can actually execute, and where another one is added.
 *
 * PROJECT.md section E, item 3. Two jobs, and they are deliberately the same
 * object: it answers the {@see AdapterCatalog} question agent selection asks
 * before a run starts — is there an implementation behind this adapter name? —
 * and it is what builds that implementation when the run needs it. One place
 * decides both, so there is no state in which selection accepts an agent the
 * runner then cannot construct.
 *
 * **Nothing here knows an adapter class.** The built-in adapters arrive as
 * {@see BuiltInAdapters::providers()} and a project's own arrive as
 * {@see AdapterProvider} instances from {@see AdapterRegistrationFile}; both
 * travel the same path. That is what "without editing the runner" means in the
 * item's wording, and it is checked by a test that configures an agent for an
 * adapter this package has never heard of and runs it.
 *
 * ```php
 * $registry = AdapterRegistry::default();
 * AdapterRegistrationFile::in($projectRoot)->registerInto($registry);
 *
 * $selection = (new AgentSelector($registry))->select($configuration, $requested);
 * $adapter = $registry->create($selection);
 * ```
 *
 * Those four lines are the whole composition, and the sequential loop of
 * section G performs them once per run — before the first task, so that a
 * misconfigured agent is reported with nothing created and nothing attempted.
 *
 * **A registration is never replaced.** Registering a name twice, or a name the
 * package ships, is refused rather than taken as an override. An adapter name
 * is what attempts, logs and commits are attributed to; a project that could
 * quietly put its own code behind `codex` would produce a run history that says
 * Codex did work Codex never did. Section E, item 9's rule that no agent is
 * silently substituted for another is the same rule read from the other end.
 *
 * A default registry holds the adapters the package ships: Codex (section E,
 * item 6) and Claude Code (item 7). A registry's answers are its registrations
 * and nothing else — it does not claim an implementation exists before one
 * does — so {@see self::create()} stays the place where a name that is declared
 * but registered nowhere is reported, which is what a project's own adapter
 * reaches when its `.ralph/adapters.php` was never read.
 */
final class AdapterRegistry implements AdapterCatalog
{
    /**
     * Registered providers, keyed by adapter name in registration order.
     *
     * @var array<string, AdapterProvider>
     */
    private array $providers = [];

    /**
     * A registry holding the adapters the package ships.
     *
     * The starting point for a run. A project's own adapters are added to it
     * afterwards, which is also why they cannot shadow a built-in one: that
     * name is taken by the time the project's file is read.
     */
    public static function default(): self
    {
        $registry = new self();

        foreach (BuiltInAdapters::providers() as $provider) {
            $registry->add($provider);
        }

        return $registry;
    }

    /**
     * Add a project's adapter.
     *
     * @throws ConfigurationException when the provider's name is unusable, is
     *         already registered, or is one the package ships
     */
    public function register(AdapterProvider $provider): void
    {
        $name = $provider->adapter();

        if (preg_match(AgentDefinition::NAME_PATTERN, $name) !== 1) {
            throw new ConfigurationException(
                sprintf(
                    '%s registers the unusable adapter name %s.',
                    $this->describe($provider),
                    $name === '' ? 'an empty string' : sprintf('"%s"', $name),
                ),
                [
                    'An adapter name is written in "agents.*.adapter" and shown in status output: '
                        . 'use letters, digits, ".", "_" or "-", starting with a letter or a digit.',
                    sprintf('%s::adapter() must return such a name.', $this->className($provider)),
                ],
            );
        }

        // Before the already-registered check below, so that a name the
        // package ships is refused as reserved whether or not its provider has
        // been added yet. The alternative is a message that depends on how far
        // the package has got through section E — "already registered" for an
        // adapter that has landed, "reserved" for one only declared — for two
        // attempts at the same mistake, and only the reserved message explains
        // the attribution problem that is the actual reason for the refusal.
        if ((new BuiltInAdapters())->has($name)) {
            throw new ConfigurationException(
                sprintf('The adapter name "%s" is reserved for the adapter Ralph PHP ships.', $name),
                [
                    sprintf('%s registers it.', $this->describe($provider)),
                    sprintf('Reserved names: %s.', implode(', ', (new BuiltInAdapters())->names())),
                    'A run whose history said "' . $name . '" but ran other code would attribute work to '
                        . 'the wrong tool.',
                    'Give the adapter a name of its own; an agent may then select it through '
                        . '"agents.*.adapter".',
                ],
            );
        }

        if (isset($this->providers[$name])) {
            throw new ConfigurationException(
                sprintf('The adapter "%s" is already registered.', $name),
                [
                    sprintf('It is provided by %s.', $this->describe($this->providers[$name])),
                    sprintf('%s registers the same name.', $this->describe($provider)),
                    'Ralph PHP does not replace a registered adapter: attempts, logs and commits are '
                        . 'attributed to the adapter name, so two implementations may not share one.',
                    'Give the new adapter a name of its own, and point "agents.*.adapter" at that name.',
                ],
            );
        }

        $this->add($provider);
    }

    /**
     * Whether an adapter is available under this exact name.
     *
     * Exact: adapter names are matched as written, so a configuration saying
     * `Codex` is reported as unavailable rather than quietly resolved.
     */
    public function has(string $adapter): bool
    {
        return isset($this->providers[$adapter]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Build the adapter for the agent a run selected.
     *
     * Takes the selection rather than a bare name so that the adapter and the
     * settings it is built from cannot be paired wrongly: one run has one
     * agent, and this is that agent's adapter.
     *
     * @throws ConfigurationException when no adapter is registered under the
     *         selected name, or the provider builds an adapter answering to a
     *         different one
     */
    public function create(AgentSelection $selection): ExecutionAdapter
    {
        $name = $selection->adapter();
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw $this->unregistered($selection);
        }

        $adapter = $provider->create($selection->definition());

        // The registered name is what the run records and what logs are filed
        // under; an adapter answering to another name would attribute its own
        // attempts somewhere else. A provider mistake, caught before the first
        // attempt rather than puzzled over in a log afterwards.
        if ($adapter->name() !== $name) {
            throw new ConfigurationException(
                sprintf(
                    '%s is registered as "%s" but builds an adapter named "%s".',
                    $this->describe($provider),
                    $name,
                    $adapter->name(),
                ),
                [
                    'Attempts and logs are recorded under the adapter name, so the two must agree.',
                    sprintf(
                        '%s::adapter() and %s::name() must return the same name.',
                        $this->className($provider),
                        $adapter::class,
                    ),
                ],
            );
        }

        return $adapter;
    }

    /**
     * The selected agent names an adapter nothing is registered under.
     *
     * Agent selection reports this first, with the configuration file in hand,
     * so a caller usually never reaches it; it exists because the runner must
     * not build an agent on the strength of a check made somewhere else.
     */
    private function unregistered(AgentSelection $selection): ConfigurationException
    {
        $name = $selection->adapter();
        $hints = [];

        $suggestion = Suggestions::sentence($name, $this->names());
        if ($suggestion !== null) {
            $hints[] = $suggestion;
        }

        $hints[] = $this->providers === []
            ? 'No adapters are registered.'
            : sprintf('Registered adapters: %s.', implode(', ', $this->names()));
        $hints[] = sprintf(
            'The agent "%s" selects it through its "adapter" setting.',
            $selection->name(),
        );
        $hints[] = sprintf(
            'Register an implementation for "%s" in %s, or point the agent at a registered adapter.',
            $name,
            Defaults::ADAPTERS_FILE,
        );
        $hints[] = 'Ralph PHP does not run a different agent in its place.';

        return new ConfigurationException(
            sprintf('No adapter is registered under the name "%s".', $name),
            $hints,
        );
    }

    /**
     * Register without the checks {@see self::register()} makes, for the
     * built-in providers: their names are the reserved ones.
     */
    private function add(AdapterProvider $provider): void
    {
        $this->providers[$provider->adapter()] = $provider;
    }

    /**
     * A provider named for an error message, as the subject of a sentence.
     */
    private function describe(AdapterProvider $provider): string
    {
        return sprintf('The provider %s', $this->className($provider));
    }

    private function className(AdapterProvider $provider): string
    {
        return $provider::class;
    }
}
