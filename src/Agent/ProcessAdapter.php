<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Exception\ProcessException;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\FailureEvidence;

/**
 * An adapter that runs a coding agent as a separate process.
 *
 * Both built-in agents are command-line programs, and a registered third-party
 * one almost certainly will be too, so the parts that are the same for all of
 * them live here once: find the configured executable, start it with an
 * argument array in the task's own workspace, hold it to the attempt's time
 * budget, and turn a timeout into the result the loop expects. What is left for
 * a subclass is the two agent-specific halves — which arguments that CLI takes
 * ({@see self::invocationFor()}) and what its output means
 * ({@see self::interpret()}).
 *
 * This is PROJECT.md section E, item 2, and the two guarantees in its wording
 * are both structural rather than conventional:
 *
 * **Argument arrays.** A subclass returns an {@see AgentInvocation}, which has
 * no shell-string form at all, and {@see self::execute()} passes its array
 * straight to {@see ProcessRunner}, which starts it with no shell. A task
 * description containing `rm -rf /; echo` is one argument that an agent reads
 * as text; there is no point in this path where it is parsed as a command. The
 * briefing may also go to standard input, which keeps even a megabyte of
 * context away from the argument list.
 *
 * **An explicit working directory.** The process is started in
 * {@see ExecutionRequest::directory()} — the isolated worktree the runner
 * prepared — and never in the PHP process's own directory, which is the
 * controlling checkout the package has promised not to touch (section F, item
 * 1). The subclass is not asked for it and cannot change it.
 *
 * {@see self::execute()} is final for that reason. A subclass that could
 * override it could lose either guarantee, and an adapter is precisely the
 * place in this package where neither is checkable afterwards: once a shell has
 * run a composed string, nothing downstream can tell that it did.
 *
 * Streaming the agent's output to the terminal and to its log while it runs is
 * section E, item 4; this collects it and hands it over at the end. Telling a
 * candidate implementation from a request for human input is item 5, and lives
 * in {@see self::interpret()} because the answer is written in each agent's own
 * output format.
 */
abstract class ProcessAdapter implements ExecutionAdapter
{
    public function __construct(
        private readonly ProcessRunner $processes = new ProcessRunner(),
        private readonly ExecutableFinder $executables = new ExecutableFinder(),
    ) {
    }

    /**
     * Run the agent once, inside the workspace and inside the budget.
     *
     * @throws ProcessException when the configured executable cannot be found
     *         or the process cannot be started — a setup problem that a retry
     *         would hit identically, which is why the interface has it throw
     *         rather than return. No other agent is tried (section E, item 9).
     */
    final public function execute(ExecutionRequest $request): ExecutionResult
    {
        $invocation = $this->invocationFor($request);

        $result = $this->processes->run(
            $invocation->at($this->resolve($request, $invocation))->command(),
            // The one working directory this process may have. Not a default,
            // not the runner's own: see the class description.
            $request->directory(),
            // The lesser of the task timeout and the run's remaining time,
            // computed by ExecutionBudget so that no adapter decides it.
            $request->seconds(),
            [],
            $invocation->input(),
        );

        // Normalised here rather than in each adapter: a killed process has no
        // output to interpret and reported no exit status, so there is nothing
        // agent-specific left to say about it, and an adapter that forgot the
        // case would report a timeout as an ordinary failure and have the
        // user raise the wrong limit.
        if ($result->timedOut()) {
            return ExecutionResult::timedOut(
                $this->evidenceOf(
                    sprintf(
                        '%s was stopped after %d second%s (%s)',
                        $request->agent()->name(),
                        $request->seconds(),
                        $request->seconds() === 1 ? '' : 's',
                        $request->budget()->isLimitedByRun()
                            ? 'the run\'s remaining time'
                            : 'the task timeout',
                    ),
                    $result,
                ),
                seconds: $result->seconds(),
            );
        }

        return $this->interpret($request, $result);
    }

    /**
     * The command this agent is run with for this attempt.
     *
     * Where an adapter places the briefing — an argument, standard input, a
     * file in the workspace it points the agent at — and which flags make its
     * CLI non-interactive are its own business (section E, items 6 and 7). What
     * it may not do is compose a command line: {@see AgentInvocation} offers no
     * way to.
     */
    abstract protected function invocationFor(ExecutionRequest $request): AgentInvocation;

    /**
     * What the agent's output means, as one of the normalised outcomes.
     *
     * Called only for a process that ran to completion, successfully or not.
     * Both built-in adapters answer onto the same few cases so that
     * verification and Git behaviour stay agent-independent (section E, item
     * 10), and an adapter that cannot read its agent's output says so with
     * {@see ExecutionResult::error()} instead of claiming a candidate.
     */
    abstract protected function interpret(ExecutionRequest $request, ProcessResult $result): ExecutionResult;

    /**
     * The ordinary reading of a process that failed.
     *
     * Offered because every adapter needs it and they should agree: a non-zero
     * exit with nothing else to go on is an execution error carrying the
     * command's own last words as evidence. An adapter whose agent reports
     * failures more precisely — a parsed result file, a known status for "no
     * changes made" — interprets that first and falls back to this.
     */
    protected function failure(ExecutionRequest $request, ProcessResult $result, string $claim = ''): ExecutionResult
    {
        return ExecutionResult::error(
            $this->evidenceOf(
                sprintf(
                    '%s exited with status %d',
                    $request->agent()->name(),
                    $result->exitCode() ?? -1,
                ),
                $result,
            ),
            $claim,
            $result->seconds(),
            $result->exitCode(),
        );
    }

    /**
     * An observation about an invocation, with the output as its detail.
     *
     * Error output first, because a program that failed explains itself there;
     * ordinary output is the fallback for one that does not. The detail is
     * bounded by {@see FailureEvidence} itself, which keeps the *end* of the
     * output — where a stack trace's message and a test run's summary are.
     */
    protected function evidenceOf(string $label, ProcessResult $result): FailureEvidence
    {
        foreach ([$result->errorOutput(), $result->standardOutput()] as $output) {
            if (trim($output) !== '') {
                return FailureEvidence::of($label, $output);
            }
        }

        return FailureEvidence::of($label, 'The agent printed nothing.');
    }

    /**
     * The program the configured executable names.
     *
     * Resolved before starting, so that a missing CLI is a readable error
     * naming the agent rather than a failed `proc_open`, and so that the
     * command recorded in the log is the binary that actually ran. A bare name
     * is searched for in PATH exactly as a shell would, without a shell being
     * involved.
     *
     * @throws ProcessException when the executable is relative or not found
     */
    private function resolve(ExecutionRequest $request, AgentInvocation $invocation): string
    {
        $configured = $invocation->program();
        $agent = $request->agent()->name();

        // A relative path would be resolved against the workspace, which is a
        // different directory for every task and not the project root that
        // preflight checked the same path against. Two meanings, so neither is
        // guessed.
        if (ExecutableFinder::isRelativePath($configured)) {
            throw new ProcessException(
                sprintf('The executable "%s" configured for the agent "%s" is a relative path.', $configured, $agent),
                [
                    sprintf(
                        'Each task runs in its own worktree, so a relative path would name a different program '
                            . 'per task; %s would be looked for inside %s.',
                        $configured,
                        $request->directory(),
                    ),
                    sprintf('Set "agents.%s.executable" to an absolute path, or to a name on PATH.', $agent),
                ],
            );
        }

        $found = $this->executables->find($configured, $request->directory());

        if ($found !== null) {
            return $found;
        }

        throw new ProcessException(
            sprintf('The executable "%s" configured for the agent "%s" was not found.', $configured, $agent),
            [
                ExecutableFinder::isSearched($configured)
                    ? sprintf('It is in none of the directories in %s.', $this->executables->describeSearchPath())
                    : sprintf('Nothing executable exists at %s.', $configured),
                sprintf('Set "agents.%s.executable" in .ralph/config.json to its absolute path.', $agent),
                'Ralph PHP does not run a different agent in its place.',
            ],
        );
    }
}
