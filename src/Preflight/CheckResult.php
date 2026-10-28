<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

/**
 * One preflight check's conclusion, as data.
 *
 * Three parts, each with a job: a one-line `summary` that says what was found,
 * `details` that show the evidence behind it, and `hints` that say what the
 * user has to do about a problem. Keeping them apart is what lets the same
 * result be printed as a compact table row, expanded for a failure, and later
 * serialised into the structured events of PROJECT.md section I without
 * re-deriving any of it from a sentence.
 */
final class CheckResult
{
    /**
     * @param string $summary one line, stating what was found
     * @param list<string> $details supporting evidence, one line each
     * @param list<string> $hints what to do about a problem
     */
    private function __construct(
        private readonly CheckId $id,
        private readonly CheckOutcome $outcome,
        private readonly string $summary,
        private readonly array $details = [],
        private readonly array $hints = [],
    ) {
    }

    /**
     * The requirement is satisfied.
     *
     * @param list<string> $details
     */
    public static function passed(CheckId $id, string $summary, array $details = []): self
    {
        return new self($id, CheckOutcome::Passed, $summary, $details);
    }

    /**
     * Something could not be established, but nothing is known to be wrong.
     *
     * @param list<string> $details
     * @param list<string> $hints
     */
    public static function warning(CheckId $id, string $summary, array $details = [], array $hints = []): self
    {
        return new self($id, CheckOutcome::Warning, $summary, $details, $hints);
    }

    /**
     * The requirement is not satisfied, and a run must not start.
     *
     * @param list<string> $details
     * @param list<string> $hints
     */
    public static function failed(CheckId $id, string $summary, array $details = [], array $hints = []): self
    {
        return new self($id, CheckOutcome::Failed, $summary, $details, $hints);
    }

    /**
     * The check depends on one that failed, so there was nothing to measure.
     */
    public static function skipped(CheckId $id, CheckId $blockedBy): self
    {
        return new self(
            $id,
            CheckOutcome::Skipped,
            sprintf('not checked, because the "%s" check failed', $blockedBy->label()),
        );
    }

    public function id(): CheckId
    {
        return $this->id;
    }

    public function outcome(): CheckOutcome
    {
        return $this->outcome;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<string>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * The result as plain data, for the structured output of PROJECT.md
     * section I and for persisting with a run.
     *
     * @return array{check: string, outcome: string, summary: string, details: list<string>, hints: list<string>}
     */
    public function toArray(): array
    {
        return [
            'check' => $this->id->value,
            'outcome' => $this->outcome->value,
            'summary' => $this->summary,
            'details' => $this->details,
            'hints' => $this->hints,
        ];
    }
}
