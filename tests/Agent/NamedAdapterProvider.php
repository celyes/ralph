<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Config\AgentDefinition;

/**
 * A provider whose adapter answers to the name it registers, and nothing else.
 *
 * What the registry and the registration file tests observe is registration
 * itself — which name became available, which construction was refused — so the
 * adapter behind it does the least an adapter can do. A named class rather than
 * an anonymous one because a project's `.ralph/adapters.php` fixture has to be
 * able to write `new NamedAdapterProvider('acme')` in a file of its own.
 */
final class NamedAdapterProvider implements AdapterProvider
{
    /**
     * The definition the registry passed, kept so a test can assert that the
     * selected agent's own settings reached the provider.
     */
    public ?AgentDefinition $definition = null;

    /**
     * @param string $adapter the name to register, and the adapter's own
     * @param string|null $builds the built adapter's name when it must differ,
     *        for the case of a provider answering to another name
     */
    public function __construct(
        private readonly string $adapter,
        private readonly ?string $builds = null,
    ) {
    }

    public function adapter(): string
    {
        return $this->adapter;
    }

    public function create(AgentDefinition $definition): ExecutionAdapter
    {
        $this->definition = $definition;

        return new class ($this->builds ?? $this->adapter) implements ExecutionAdapter {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return ExecutionResult::candidate('Implemented.');
            }
        };
    }
}
