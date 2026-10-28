<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Agent\AgentSelection;

/**
 * Every preflight check's result, plus what they were run against.
 *
 * The report is the whole answer: it is not a boolean with an error message
 * attached, because a user whose environment is not ready usually has more than
 * one thing to fix, and finding them one run at a time is the experience this
 * replaces. {@see self::isUsable()} is the verdict; the results are the reason.
 */
final class PreflightReport
{
    /**
     * @param string $projectRoot the directory the checks were run against
     * @param string|null $configurationSource the file the settings came from, null for defaults
     * @param list<CheckResult> $results in the order the checks ran
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $configurationSource,
        private readonly AgentSelection $agent,
        private readonly array $results,
    ) {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function configurationSource(): ?string
    {
        return $this->configurationSource;
    }

    /**
     * The agent the checks were run for. One run uses one agent, so preflight
     * answers for that one rather than for every configured agent.
     */
    public function agent(): AgentSelection
    {
        return $this->agent;
    }

    /**
     * @return list<CheckResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * One check's result, or null when it was not part of this report.
     */
    public function result(CheckId $id): ?CheckResult
    {
        foreach ($this->results as $result) {
            if ($result->id() === $id) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Whether a run may start.
     */
    public function isUsable(): bool
    {
        return $this->failures() === [];
    }

    /**
     * The results that stop a run, which is what {@see self::isUsable()} is
     * decided by. Which outcomes those are is {@see CheckOutcome::blocks()}'s
     * to say, so the verdict cannot drift from the meaning of an outcome.
     *
     * @return list<CheckResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (CheckResult $result): bool => $result->outcome()->blocks(),
        ));
    }

    /**
     * @return list<CheckResult>
     */
    public function warnings(): array
    {
        return $this->withOutcome(CheckOutcome::Warning);
    }

    /**
     * The report as plain data, for `status --json` and for the structured
     * events of PROJECT.md section I.
     *
     * @return array{
     *     project: string,
     *     configuration: string|null,
     *     agent: string,
     *     usable: bool,
     *     checks: list<array{check: string, outcome: string, summary: string, details: list<string>, hints: list<string>}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'project' => $this->projectRoot,
            'configuration' => $this->configurationSource,
            'agent' => $this->agent->name(),
            'usable' => $this->isUsable(),
            'checks' => array_map(
                static fn (CheckResult $result): array => $result->toArray(),
                $this->results,
            ),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    private function withOutcome(CheckOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (CheckResult $result): bool => $result->outcome() === $outcome,
        ));
    }
}
