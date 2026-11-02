<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

/**
 * The adapters the package ships.
 *
 * Named independently of the agents configured under them: `agents` entries are
 * user-chosen labels and several may share one adapter (two Codex entries on
 * different models, say), while these names identify the invocation code.
 *
 * The implementations themselves arrive with PROJECT.md section E, items 6 and
 * 7, as the providers {@see self::providers()} returns. Listing the names here
 * now is what lets agent selection reject an unsupported adapter before a run
 * starts instead of failing at execution time, and holding both the names and
 * the providers in one class is what keeps the two from drifting apart.
 */
final class BuiltInAdapters implements AdapterCatalog
{
    /**
     * The Codex CLI adapter.
     */
    public const string CODEX = 'codex';

    /**
     * The Claude Code CLI adapter.
     */
    public const string CLAUDE = 'claude';

    public function has(string $adapter): bool
    {
        return in_array($adapter, $this->names(), true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [self::CODEX, self::CLAUDE];
    }

    /**
     * The providers that build the shipped adapters.
     *
     * The one place the package wires an adapter name to an implementation, and
     * the same {@see AdapterProvider} interface a project's own adapter uses:
     * a built-in adapter has no privileged path into
     * {@see AdapterRegistry}, which is what makes the extension point of
     * PROJECT.md section E, item 3 the real one rather than a second-class
     * route around the built-ins.
     *
     * Empty until items 6 and 7 implement the Codex and Claude Code adapters.
     * A registry does not claim an implementation exists before one does, so
     * until then a default registry provides nothing and agent selection keeps
     * using the declared names above. A test holds every provider listed here
     * to one of those names.
     *
     * @return list<AdapterProvider>
     */
    public static function providers(): array
    {
        return [];
    }
}
