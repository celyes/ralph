<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Exception\RalphException;

/**
 * One coding agent, as the loop sees it.
 *
 * The seam PROJECT.md section 4 describes: the runner owns task selection,
 * lifecycle state, Git actions, verification and completion, and an adapter
 * owns command construction, prompt handling, output interpretation and
 * cancellation for exactly one agent. Everything agent-specific lives behind
 * this one method, which is what keeps the loop independent of any particular
 * CLI — and what lets another agent be added through section E, item 3's
 * registry without the runner changing.
 *
 * Three obligations, and they are all about the loop being able to trust the
 * result.
 *
 * **Normalise.** Whatever the agent printed, the result is one of the cases
 * {@see ExecutionResult} offers. An adapter that cannot tell what its agent
 * did returns {@see ExecutionResult::error()} with the output as evidence; it
 * does not guess at a candidate. The strongest thing it may claim is that there
 * is something worth verifying.
 *
 * **Stay inside the budget.** The invocation is given
 * {@see ExecutionRequest::seconds()} and must stop by then, reporting
 * {@see ExecutionResult::timedOut()}. An adapter that lets its process outlive
 * the budget breaks the run's own time limit, and a loop that cannot be bounded
 * is the failure this package exists to avoid.
 *
 * **Work only in the workspace.** The process is started with
 * {@see ExecutionRequest::directory()} as its explicit working directory and
 * with an argument array, never a shell string: a task description is user text
 * that may contain quotes, backticks and semicolons, and it must reach the
 * agent as one argument rather than as something the shell reads.
 *
 * Adapters are stateless with respect to a run. One instance may serve several
 * attempts, so nothing about a task may be kept between calls — the request
 * carries everything, which is also what makes resume with the recorded agent
 * (section H, item 6) a matter of rebuilding the request.
 */
interface ExecutionAdapter
{
    /**
     * The name this adapter is registered under.
     *
     * Matches an {@see AdapterCatalog} entry and the `agents.*.adapter` setting
     * that selects it — not the user's chosen agent name, which is a label
     * several agents may share one adapter under.
     */
    public function name(): string;

    /**
     * Run the agent once against one task.
     *
     * Returns rather than throws for anything the agent did: a non-zero exit,
     * unreadable output, a request for human input and a timeout are all
     * results, because the loop has bookkeeping to do for each of them and a
     * task that merely failed must leave a recorded attempt behind.
     *
     * @throws RalphException when the invocation could not be started at all —
     *         a missing executable, an unreadable workspace, a CLI version the
     *         adapter does not support. That is a problem with the setup rather
     *         than with the attempt, retrying it would fail the same way, and
     *         the run stops on it. An adapter must never resolve such a failure
     *         by invoking a different agent (section E, item 9).
     */
    public function execute(ExecutionRequest $request): ExecutionResult;
}
