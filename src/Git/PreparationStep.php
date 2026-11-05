<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\PreparationCommand;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * One `workspace.prepare` command that was run in a task workspace, and what it
 * produced.
 *
 * Kept even though preparation stops at the first failure, because the
 * successful steps are the evidence for what the workspace now contains: a task
 * that later fails verification is read differently depending on whether
 * `composer install` ran in this workspace two minutes ago or was skipped
 * because the workspace was adopted from an interrupted run.
 *
 * The command is carried as the {@see ProcessResult}'s argument array rather
 * than reconstructed from the setting, so a log line states what actually ran.
 */
final class PreparationStep
{
    public function __construct(
        private readonly string $name,
        private readonly ProcessResult $result,
    ) {
    }

    public static function of(PreparationCommand $command, ProcessResult $result): self
    {
        return new self($command->name(), $result);
    }

    /**
     * The configured label, which also names this step in logs and status.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function command(): array
    {
        return $this->result->command();
    }

    public function result(): ProcessResult
    {
        return $this->result;
    }

    public function succeeded(): bool
    {
        return $this->result->succeeded();
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %s (%s)',
            $this->name,
            implode(' ', $this->command()),
            $this->result->succeeded()
                ? sprintf('%.1fs', $this->result->seconds())
                : $this->result->failureReason(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command(),
            'exit_code' => $this->result->exitCode(),
            'timed_out' => $this->result->timedOut(),
            'seconds' => round($this->result->seconds(), 3),
        ];
    }
}
