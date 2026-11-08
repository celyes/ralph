<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Agent\AdapterCatalog;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\Support\Suggestions;

/**
 * Which agent a resume continues with — the recorded one, or none.
 *
 * {@see \Celyes\RalphPhp\Agent\AgentSelector}'s counterpart for `ralph resume`,
 * and deliberately a different class rather than a flag on it: the two answer
 * opposite questions. A new run asks the configuration what to use. A resume
 * asks the run it is continuing, and the configuration gets no vote
 * (PROJECT.md section H, item 6).
 *
 * **The recorded settings are the invocation.** The executable, the model and
 * the argument array come off the run's row through
 * {@see AgentRecord::definition()}, not out of `.ralph/config.json`. A task
 * interrupted part-way through must be finished by the agent that started it,
 * invoked the way it was invoked; an attempt half carried out by one binary and
 * half by another is evidence of nothing, and its commit would be attributed to
 * whichever of them the row happened to name.
 *
 * **An implicit change of agent is refused, and so is an explicit one.** The
 * implicit case is the one the configuration can cause on its own: the project
 * default moved on, or the `agents` entry was edited. Those do not change this
 * resume — they are reported as drift and the recorded values are used. The
 * explicit case is `resume --agent NAME` naming something else, and it is
 * raised: a resume cannot honour it, because the only thing it could mean is a
 * different run.
 *
 * **How to begin a new run with a different agent**, which the refusals above
 * name and this paragraph is the long form of:
 *
 *  1. Reconcile the interrupted work. `ralph resume` finishes it with the agent
 *     that produced it; `ralph status` shows what is outstanding. A task left
 *     blocked or failed stays unchecked and stays pending, so it can simply be
 *     left to the new run.
 *  2. Start a new run with the agent you want: `ralph run --agent NAME` for one
 *     run, or change the `agent` setting in `.ralph/config.json` to make it the
 *     project's default.
 *
 * Nothing is lost by doing that. A new run begins with the configured limits
 * whole (section H, item 3), tasks earlier runs completed stay completed and
 * are not taken on again, and every verified commit and its attempt history
 * stay where they are, attributed to the agent that earned them.
 *
 * **No fallback, ever.** An adapter the build cannot execute is a refusal and
 * not a reason to try another one, exactly as at selection time: a resume that
 * substituted an adapter would hand half-finished work to a tool that did not
 * write it and go on recording the result under the other's name.
 */
final class AgentContinuity
{
    /**
     * The settings compared against the configuration, in the order a reader
     * wants them: what performs the invocation, then what it invokes, then the
     * details of the command line.
     */
    private const array COMPARED = ['adapter', 'executable', 'model', 'arguments', 'environment'];

    public function __construct(private readonly AdapterCatalog $adapters = new BuiltInAdapters())
    {
    }

    /**
     * Resolve the agent a resume of this run uses.
     *
     * @param RunRecord   $previous the run being continued
     * @param string|null $requested the value of `--agent`, which a resume may
     *        only use to confirm the agent already recorded
     *
     * @throws UsageException         when `--agent` was given without a usable name
     * @throws ConfigurationException when `--agent` names a different agent, or
     *         the recorded adapter is not available
     * @throws StateException         when the recorded settings do not describe
     *         an invocation
     */
    public function continuation(
        RunRecord $previous,
        Configuration $configuration,
        ?string $requested = null,
    ): AgentContinuation {
        $recorded = $previous->agent();

        $confirmed = $this->confirm($previous, $recorded, $requested);

        if (!$this->adapters->has($recorded->adapter())) {
            throw $this->unavailableAdapter($previous, $recorded, $configuration);
        }

        // Off the row, not out of the file: see the class description.
        $definition = $recorded->definition();

        return AgentContinuation::of(
            $previous,
            new AgentSelection($definition, AgentSource::ResumedRun),
            $confirmed,
            $this->drift($previous, $recorded, $configuration),
        );
    }

    /**
     * What `--agent` meant, when it was given.
     *
     * Returns the name when it named the recorded agent — a confirmation, which
     * the continuation prints — and null when the option was absent. Anything
     * else is refused.
     *
     * @throws UsageException         when the option has no usable value
     * @throws ConfigurationException when it names a different agent
     */
    private function confirm(RunRecord $previous, AgentRecord $recorded, ?string $requested): ?string
    {
        if ($requested === null) {
            return null;
        }

        $name = trim($requested);

        if ($name === '') {
            throw new UsageException(
                'Option "--agent" requires an agent name.',
                [
                    sprintf('Run %s was carried out with "%s".', $previous->runId(), $recorded->name()),
                    'Omit "--agent" to continue with the agent the run recorded, which is all a resume can do.',
                ],
            );
        }

        if (!$recorded->is($name)) {
            throw $this->agentChange($previous, $recorded, $name);
        }

        return $name;
    }

    /**
     * `--agent` named an agent other than the recorded one.
     *
     * The refusal PROJECT.md section H, item 6 asks for, and the place the
     * documented alternative has to be: a user typing this has a different
     * agent in mind, and the message is their only chance of learning what to
     * type instead.
     */
    private function agentChange(RunRecord $previous, AgentRecord $recorded, string $requested): ConfigurationException
    {
        return new ConfigurationException(
            sprintf(
                'Run %s was carried out with the agent "%s"; resuming it with "%s" would change agents part-way through its work.',
                $previous->runId(),
                $recorded->name(),
                $requested,
            ),
            [
                sprintf(
                    'A resume continues interrupted work with the agent that produced it, so no attempt is half "%s" and half "%s".',
                    $recorded->name(),
                    $requested,
                ),
                sprintf('Omit "--agent" to continue run %s with "%s".', $previous->runId(), $recorded->name()),
                sprintf(
                    'To use "%s", reconcile this run\'s interrupted work first — `ralph status` shows what is outstanding — and then start a new run: `ralph run --agent %s`.',
                    $requested,
                    $requested,
                ),
                'A new run begins with the configured limits whole; tasks earlier runs completed stay completed, and their commits and attempt history stay attributed to the agent that earned them.',
            ],
        );
    }

    /**
     * The recorded adapter has no implementation in this build.
     *
     * A registration file that is no longer loaded, a project-registered
     * adapter removed, or a state file carried to a machine without it. Refused
     * rather than resolved to something else, for the reason given in the class
     * description.
     */
    private function unavailableAdapter(
        RunRecord $previous,
        AgentRecord $recorded,
        Configuration $configuration,
    ): ConfigurationException {
        $available = $this->adapters->names();
        $hints = [];

        $suggestion = Suggestions::sentence($recorded->adapter(), $available);
        if ($suggestion !== null) {
            $hints[] = $suggestion;
        }

        $hints[] = sprintf('Available adapters: %s.', $this->listNames($available));
        $hints[] = sprintf(
            'Register the adapter under that name again to resume this run, or start a new run with an available agent: `ralph run --agent NAME`, with the agents configured in %s.',
            $this->configurationFile($configuration),
        );
        $hints[] = 'Nothing is resumed with a different adapter: the work this run left behind was produced by that one.';

        return new ConfigurationException(
            sprintf(
                'Run %s was carried out by the agent "%s" through the adapter "%s", which is not available.',
                $previous->runId(),
                $recorded->name(),
                $recorded->adapter(),
            ),
            $hints,
        );
    }

    /**
     * Where today's configuration disagrees with what the run recorded.
     *
     * Reported and not acted on. Each line names the recorded value as the one
     * being used, because that is the part a user cannot see: they are reading
     * the file, and the resume is reading the row.
     *
     * @return list<string>
     */
    private function drift(RunRecord $previous, AgentRecord $recorded, Configuration $configuration): array
    {
        $drift = [];
        $name = $recorded->name();

        if ($configuration->defaultAgent() !== $name) {
            $drift[] = sprintf(
                'the project\'s default agent is now "%s"; run %s recorded "%s", which is what this resume uses',
                $configuration->defaultAgent(),
                $previous->runId(),
                $name,
            );
        }

        $configured = $configuration->agent($name);

        if ($configured === null) {
            $drift[] = sprintf(
                'no agent named "%s" is configured any more; the settings recorded with run %s are used',
                $name,
                $previous->runId(),
            );

            return $drift;
        }

        return [...$drift, ...$this->settingDrift($previous, $recorded, $configured)];
    }

    /**
     * The settings of the same agent, recorded against configured.
     *
     * @return list<string>
     */
    private function settingDrift(
        RunRecord $previous,
        AgentRecord $recorded,
        AgentDefinition $configured,
    ): array {
        $now = $configured->toArray();
        $drift = [];

        foreach ($this->keys($recorded) as $key) {
            $before = $recorded->setting($key);
            $after = $now[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $drift[] = sprintf(
                '"%s" is now %s for agent "%s"; run %s recorded %s, which is what this resume uses',
                $key,
                $this->value($after),
                $recorded->name(),
                $previous->runId(),
                $this->value($before),
            );
        }

        return $drift;
    }

    /**
     * The settings to compare, in a stable order.
     *
     * The five an invocation is built from, then anything else a row written by
     * a later version of the package happens to hold: an unknown setting that
     * changed is still worth saying, and listing it is cheaper than being
     * silent about the one setting that mattered.
     *
     * @return list<string>
     */
    private function keys(AgentRecord $recorded): array
    {
        $extra = array_diff(
            array_keys($recorded->settings()),
            self::COMPARED,
        );

        return [...self::COMPARED, ...array_values(array_filter($extra, 'is_string'))];
    }

    /**
     * A setting's value, as a message says it.
     */
    private function value(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }

        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'empty';
            }

            return '[' . implode(', ', array_map(
                fn (mixed $item): string => $this->value($item),
                $value,
            )) . ']';
        }

        return 'an unreadable value';
    }

    /**
     * The file a user would edit to configure an agent for a new run. A
     * configuration built from the defaults has no source file, so the path
     * `ralph init` would create is named instead.
     */
    private function configurationFile(Configuration $configuration): string
    {
        return $configuration->source() ?? ConfigurationLoader::fileIn($configuration->projectRoot());
    }

    /**
     * @param list<string> $names
     */
    private function listNames(array $names): string
    {
        if ($names === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $names));
    }
}
