<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Exception\RalphException;

/**
 * How another coding agent is added to Ralph PHP.
 *
 * PROJECT.md section E, item 3's registration interface, and the whole of it: a
 * provider says which adapter name it answers to, and builds the
 * {@see ExecutionAdapter} for one configured agent. No part of the runner names
 * an adapter class, so a project that registers a provider — through
 * {@see AdapterRegistrationFile}, or directly into {@see AdapterRegistry} when
 * the package is used as a library — gets its own agent without editing the
 * package.
 *
 * **Why construction is a separate step from invocation.** The two fail for
 * different reasons and at different times. Building the adapter happens once
 * per run, before a task is selected, and a problem there is a setup problem:
 * the configured settings cannot produce a usable adapter, retrying would fail
 * identically, and the run must stop with an explanation. Invoking it happens
 * once per attempt, and nearly everything that goes wrong there is an outcome
 * the loop records and may retry. Keeping them apart is also what lets the
 * runner report an unusable agent before it creates a worktree or a branch.
 *
 * A provider holds no state about a run. It may be asked for an adapter more
 * than once — one per run — and each answer must be independently usable.
 *
 * ```php
 * final class AcmeAdapterProvider implements AdapterProvider
 * {
 *     public function adapter(): string
 *     {
 *         return 'acme';
 *     }
 *
 *     public function create(AgentDefinition $definition): ExecutionAdapter
 *     {
 *         return new AcmeAdapter();
 *     }
 * }
 * ```
 *
 * The agent is then an ordinary `agents` entry naming that adapter:
 *
 * ```json
 * {"agent": "acme", "agents": {"acme": {"adapter": "acme", "executable": "acme-cli"}}}
 * ```
 */
interface AdapterProvider
{
    /**
     * The adapter name this provider registers.
     *
     * The value users write in `agents.*.adapter`, and what
     * {@see AdapterRegistry::names()} lists — not an agent name, which is a
     * user-chosen label several agents may share one adapter under. It has the
     * same shape as any other configured name ({@see AgentDefinition::NAME_PATTERN}),
     * because it is typed into a configuration file and shown in status output,
     * and it must equal the {@see ExecutionAdapter::name()} of what
     * {@see self::create()} returns, which is the name attempts and logs are
     * recorded under.
     */
    public function adapter(): string;

    /**
     * Build the adapter for one configured agent.
     *
     * The definition is the run's own agent entry: its executable, optional
     * model, extra argument array, and the names of the environment variables
     * it authenticates with. Most adapters need nothing from it here, because
     * {@see ExecutionRequest} carries the same selection into every attempt;
     * it is passed so that an adapter that must settle something once — a
     * client built from a configured model, a CLI version checked before the
     * first task — can.
     *
     * @throws RalphException when the configured settings cannot produce a
     *         usable adapter. That stops the run before any task is selected,
     *         and no other agent is used in its place (section E, item 9).
     */
    public function create(AgentDefinition $definition): ExecutionAdapter;
}
