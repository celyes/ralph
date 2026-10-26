<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

/**
 * The set of adapter names the runner can actually execute.
 *
 * Agent selection has to answer one question about an adapter before any work
 * starts — is there an implementation behind this name? — and nothing else. That
 * is the whole interface, so the adapter registry built for PROJECT.md section E
 * can satisfy it without agent selection knowing how adapters are constructed,
 * and so a project that registers its own adapter needs no change here.
 */
interface AdapterCatalog
{
    /**
     * Whether an adapter is available under this exact name.
     */
    public function has(string $adapter): bool;

    /**
     * Every available adapter name, for listing in an error message.
     *
     * @return list<string>
     */
    public function names(): array;
}
