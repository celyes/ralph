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
 * The implementations themselves arrive with PROJECT.md section E. Listing the
 * names here now is what lets agent selection reject an unsupported adapter
 * before a run starts instead of failing at execution time.
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
}
