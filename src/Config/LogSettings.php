<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `logs` section: how long a task's own output is kept (PROJECT.md section
 * K, item 10).
 *
 * One setting, and it is off:
 *
 * ```json
 * {"logs": {"wipe_on_success": true}}
 * ```
 *
 * **What it is for.** A task writes its agent's output and one file per
 * verification command into `.ralph/runtime/logs/TASK-.../`, and nothing in the
 * package has ever removed them. That is the right default — the logs are the
 * only account of what an agent did that outlives the agent, and a commit that
 * passed the checks and still did the wrong thing gets read backwards from the
 * ticked box to the output behind it. But a long checklist on a project with a
 * loud suite leaves a runtime directory measured in hundreds of megabytes,
 * nearly all of it successful tasks nobody will open again. Turning this on
 * keeps the output of the passes that went wrong and lets the rest go.
 *
 * **What "eligible" is going to mean.** Only the log files of a task that
 * *succeeded*: its agent logs and its verification logs, for that task's
 * generation, after its commit, its required push, its state update and its
 * checklist write are all durable. Not the state, not the commit, not the
 * completion evidence, and not the logs of a failed, blocked or interrupted
 * task — those are preserved whatever this setting says, because they are the
 * ones somebody is going to read. The deletion itself belongs to section K,
 * items 11 and 12; this class is the switch, and reading it is how everything
 * downstream asks.
 *
 * **Why a section rather than a flag on something else.** Retention is not a
 * Git question and not a limit, and the two items after this one add behaviour
 * that reads the same answer in two places — finalisation, which deletes, and
 * `ralph logs`, which has to say that a missing log was wiped on purpose. One
 * named section is where both look.
 */
final class LogSettings
{
    public function __construct(
        private readonly bool $wipeOnSuccess = Defaults::WIPE_LOGS_ON_SUCCESS,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['wipe_on_success']);

        return new self($raw->bool('wipe_on_success', Defaults::WIPE_LOGS_ON_SUCCESS));
    }

    /**
     * Whether a successful task's eligible log files are deleted once its
     * completion is durable.
     */
    public function wipesOnSuccess(): bool
    {
        return $this->wipeOnSuccess;
    }

    /**
     * Whether every log this project writes is kept, which is the default.
     *
     * The same answer as {@see self::wipesOnSuccess()} read the other way
     * round, so that a caller explaining what it did not do — `ralph logs`
     * reporting an absent file, a completion record saying what was retained —
     * says what it means rather than negating a verb.
     */
    public function retainsEverything(): bool
    {
        return !$this->wipeOnSuccess;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wipe_on_success' => $this->wipeOnSuccess,
        ];
    }
}
