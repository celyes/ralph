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
 * The implementations are the providers {@see self::providers()} returns —
 * PROJECT.md section E, items 6 and 7. Holding the names and the providers in
 * one class is what keeps the two from drifting apart: agent selection rejects
 * an unsupported adapter from this list before a run starts rather than failing
 * at execution time, and a name that named nothing buildable would be a run
 * accepted and then abandoned.
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
     * Both shipped adapters are here: Codex (item 6) and Claude Code (item 7),
     * in the order the names above declare them, which is the order a default
     * registry reports and `status` shows. A test holds every provider listed
     * here to one of those declared names, so a third implementation cannot
     * arrive under a name selection does not know about.
     *
     * @return list<AdapterProvider>
     */
    public static function providers(): array
    {
        return [new CodexAdapterProvider(), new ClaudeAdapterProvider()];
    }
}
