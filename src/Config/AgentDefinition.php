<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * One entry of the `agents` setting: how to invoke a particular coding agent.
 *
 * Holds no credentials by design. `environment` lists the *names* of the
 * variables the agent's authentication needs, which are read from the process
 * environment when the adapter runs, so the configuration file stays safe to
 * commit and this object stays safe to persist with a run.
 */
final class AgentDefinition
{
    /**
     * The shape of a name in this part of the configuration.
     *
     * Agent names are typed as `run --agent NAME`, shown in status output and
     * persisted with every run, so they stay to an unambiguous character set.
     * Adapter names have the same shape for the same reasons: they are written
     * in `agents.*.adapter`, and {@see \Celyes\RalphPhp\Agent\AdapterRegistry}
     * holds registrations to it too, so that a name a provider registers is a
     * name a configuration file can legally contain.
     */
    public const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * @param string $name key this agent is configured under, and the value of `run --agent`
     * @param string $adapter registered adapter implementing the invocation
     * @param string $executable command or absolute path to run
     * @param string|null $model optional model, when the agent accepts one
     * @param list<string> $arguments extra arguments, passed as an argument array
     * @param list<string> $environment names of environment variables required for authentication
     */
    public function __construct(
        private readonly string $name,
        private readonly string $adapter,
        private readonly string $executable,
        private readonly ?string $model = null,
        private readonly array $arguments = [],
        private readonly array $environment = [],
    ) {
    }

    /**
     * Read one agent definition, falling back to the built-in defaults for the
     * same name when it has them.
     *
     * `adapter` and `executable` have no universal default, so an agent the
     * package does not ship must state them.
     */
    public static function fromRaw(string $name, RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['adapter', 'executable', 'model', 'arguments', 'environment']);

        $builtIn = Defaults::agents()[$name] ?? null;
        $defaultAdapter = is_string($builtIn['adapter'] ?? null) ? $builtIn['adapter'] : null;
        $defaultExecutable = is_string($builtIn['executable'] ?? null) ? $builtIn['executable'] : null;

        return new self(
            $name,
            $raw->requiredString('adapter', $defaultAdapter),
            $raw->requiredString('executable', $defaultExecutable),
            $raw->nullableString('model'),
            $raw->stringList('arguments'),
            $raw->stringList('environment'),
        );
    }

    /**
     * The built-in definition for an agent the package ships.
     */
    public static function builtIn(string $name): self
    {
        return self::fromRaw($name, RawConfiguration::fromArray([], 'the built-in defaults'));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function adapter(): string
    {
        return $this->adapter;
    }

    public function executable(): string
    {
        return $this->executable;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * Names of the environment variables this agent needs for authentication.
     *
     * @return list<string>
     */
    public function environment(): array
    {
        return $this->environment;
    }

    /**
     * The definition as plain data, for persisting with a run and for display.
     *
     * Contains no secrets: see the class description.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'executable' => $this->executable,
            'model' => $this->model,
            'arguments' => $this->arguments,
            'environment' => $this->environment,
        ];
    }
}
