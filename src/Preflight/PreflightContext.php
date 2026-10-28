<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Config\Configuration;

/**
 * What a preflight check is given to work with.
 *
 * The settings and the resolved agent, plus the results of the checks that
 * already ran — which a check reads to answer questions the earlier ones have
 * already paid for, such as where `git` was found, rather than looking it up
 * again.
 *
 * Collecting results is {@see Preflight}'s job; a check only ever reads them.
 */
final class PreflightContext
{
    /**
     * @var array<string, CheckResult>
     */
    private array $results = [];

    /**
     * @var array<string, string>
     */
    private array $facts = [];

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AgentSelection $agent,
    ) {
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }

    public function agent(): AgentSelection
    {
        return $this->agent;
    }

    /**
     * The directory the checks are about, and the working directory every
     * command they run is given.
     */
    public function projectRoot(): string
    {
        return $this->configuration->projectRoot();
    }

    /**
     * Record a result. Called by {@see Preflight} only.
     */
    public function record(CheckResult $result): void
    {
        $this->results[$result->id()->value] = $result;
    }

    /**
     * Whether checks depending on this one can be answered: it passed, or it
     * warned without finding anything wrong.
     */
    public function allowsDependents(CheckId $id): bool
    {
        $result = $this->results[$id->value] ?? null;

        return $result !== null && $result->outcome()->allowsDependents();
    }

    /**
     * @return list<CheckResult>
     */
    public function results(): array
    {
        return array_values($this->results);
    }

    /**
     * Hand a later check something this one established.
     *
     * Only for facts a check genuinely needs from another — the path `git` was
     * found at, which the repository check would otherwise have to search for
     * again. Kept to strings so nothing structural can travel between checks
     * behind the report's back.
     */
    public function remember(string $fact, string $value): void
    {
        $this->facts[$fact] = $value;
    }

    public function recall(string $fact): ?string
    {
        return $this->facts[$fact] ?? null;
    }
}
