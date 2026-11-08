<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\RunRecord;

/**
 * The agent a resume continues with, and what it is ignoring to do so.
 *
 * The result of {@see AgentContinuity}, and a record rather than a bare
 * {@see AgentSelection} because the interesting part is usually the difference:
 * the project's default moved on, the executable was reinstalled somewhere
 * else, the `agents` entry was renamed away. A resume that quietly used the
 * recorded settings and said nothing would leave a user reading
 * `.ralph/config.json` and watching something else happen.
 *
 * Two kinds of line, and they answer different questions:
 *
 *  - **Drift** is every place today's configuration disagrees with what the
 *    run recorded. The recorded value is the one used, in each case, and the
 *    line says so. Drift is not a failure: a user who moved a binary between
 *    the interruption and the resume made a legitimate edit, and the way to
 *    put it into effect is a new run, not a resume that changes the invocation
 *    half-way through a task.
 *  - **The confirmation** is `--agent` having named the agent the run already
 *    recorded. Harmless, and worth printing: a user who typed it asked a
 *    question, and "yes, that is the one" is the answer.
 *
 * A disagreement that cannot be carried on through — `--agent` naming a
 * *different* agent, or an adapter this build cannot execute — never reaches
 * here. {@see AgentContinuity} raises those, because a resume has no honest way
 * to continue them.
 */
final class AgentContinuation
{
    /**
     * @param list<string> $drift where today's configuration disagrees with
     *        the run's record, with the recorded value named as the one used
     */
    private function __construct(
        private readonly RunRecord $previous,
        private readonly AgentSelection $selection,
        private readonly ?string $requested,
        private readonly array $drift,
    ) {
    }

    /**
     * The agent to continue with, rebuilt from the run being continued.
     *
     * @param string|null  $requested what `--agent` named, when it was given
     *        and named the recorded agent
     * @param list<string> $drift
     */
    public static function of(
        RunRecord $previous,
        AgentSelection $selection,
        ?string $requested = null,
        array $drift = [],
    ): self {
        return new self($previous, $selection, $requested, $drift);
    }

    /**
     * The run being continued.
     */
    public function previous(): RunRecord
    {
        return $this->previous;
    }

    /**
     * The selection to open the resumed run with.
     *
     * Its source is {@see \Celyes\RalphPhp\Agent\AgentSource::ResumedRun}, so
     * the new row says where the agent came from rather than claiming today's
     * configuration chose it.
     */
    public function selection(): AgentSelection
    {
        return $this->selection;
    }

    /**
     * The agent as the earlier run wrote it down.
     */
    public function recorded(): AgentRecord
    {
        return $this->previous->agent();
    }

    /**
     * The configured agent name, which is what `--agent` takes.
     */
    public function name(): string
    {
        return $this->selection->name();
    }

    /**
     * The adapter that will perform the invocation.
     */
    public function adapter(): string
    {
        return $this->selection->adapter();
    }

    /**
     * Whether `--agent` was given and named the recorded agent.
     */
    public function wasConfirmed(): bool
    {
        return $this->requested !== null;
    }

    /**
     * Where today's configuration disagrees with the run's record.
     *
     * @return list<string>
     */
    public function drift(): array
    {
        return $this->drift;
    }

    /**
     * Whether the configuration has moved since the run was opened.
     */
    public function hasDrift(): bool
    {
        return $this->drift !== [];
    }

    /**
     * Every line a resume should print about its agent.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        if ($this->requested !== null) {
            $lines[] = sprintf(
                '"--agent %s" names the agent run %s recorded, so the resume continues with it',
                $this->requested,
                $this->previous->runId(),
            );
        }

        return [...$lines, ...$this->drift];
    }

    /**
     * One line for a log or a terminal.
     */
    public function describe(): string
    {
        return sprintf(
            'continuing run %s with %s, as recorded%s',
            $this->previous->runId(),
            $this->recorded()->describe(),
            $this->drift === []
                ? ''
                : sprintf(' (%d setting%s since changed in the configuration)', count($this->drift), count($this->drift) === 1 ? '' : 's'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resuming' => $this->previous->runId(),
            'agent' => $this->name(),
            'adapter' => $this->adapter(),
            'source' => $this->selection->source()->value,
            'settings' => $this->selection->definition()->toArray(),
            'confirmed_on_command_line' => $this->wasConfirmed(),
            'drift' => $this->drift,
        ];
    }
}
