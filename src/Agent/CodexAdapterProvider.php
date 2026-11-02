<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;

/**
 * Registers the Codex adapter under the name the package declares for it.
 *
 * A shipped adapter reaches {@see AdapterRegistry} the same way a project's own
 * does — through {@see AdapterProvider} and nothing else — which is what makes
 * the extension point of PROJECT.md section E, item 3 the real route rather
 * than a second-class one around the built-ins.
 *
 * Nothing is settled at construction. {@see CodexAdapter} reads the model and
 * the extra arguments out of {@see ExecutionRequest} on every attempt, so one
 * instance serves a whole run and the definition is accepted here only because
 * an adapter that needed to check something once would need it.
 */
final class CodexAdapterProvider implements AdapterProvider
{
    public function adapter(): string
    {
        return BuiltInAdapters::CODEX;
    }

    public function create(AgentDefinition $definition): ExecutionAdapter
    {
        return new CodexAdapter();
    }
}
