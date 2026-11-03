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
 * **Streamed and captured both.** The agent's output is forwarded to
 * {@see ExecutionRequest::output()} as it is produced — the terminal and the
 * attempt's log — and also collected into the {@see ProcessResult} the
 * subclass interprets (section E, item 4). The subclass does nothing to get
 * this, which is deliberate: an adapter that had to remember to stream would be
 * an adapter whose agent silently went dark for twenty minutes the first time
 * someone forgot.
 *
 * **The log says what the package observed, not what the agent claimed.** Three
 * notes are written around every invocation: which attempt this is, the command
 * that was started, and how it ended — the exit status and the elapsed time, or
 * the timeout that stopped it. They are marked as Ralph PHP's own, because the
 * output between them is an agent's and may say anything about itself.
 *
 * **Per-agent settings are honoured here where they are agent-independent.**
 * The configured executable is what gets resolved and run
 * ({@see self::resolve()}), and the declared authentication variables are
 * resolved and passed to the child ({@see self::authenticate()}), because
 * neither depends on which CLI this is. The optional model and the extra
 * argument array are left to the subclass, since where a `--model` goes in a
 * command line is something only that agent's CLI knows. Together that is
 * PROJECT.md section E, item 8 — and the credential half of it is structural:
 * values live in {@see AgentEnvironment} and reach `proc_open`, while
 * everything this class writes to a log or hands upward is variable names.
 *
 * **Telling the three outcomes apart is offered, not left to each adapter.**
 * Item 5 — a candidate implementation, a request for human input, an execution
 * error, and a refusal of output that is absent or malformed — is
 * {@see self::reported()}, reading the {@see ResultProtocol} block every
 * briefing asks for. {@see self::interpret()} stays abstract because an agent
 * whose CLI says some of this natively should use that first, but an adapter
 * that has nothing else to go on has one line to write.
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
     * @throws ProcessException when the configured executable cannot be found,
     *         a declared authentication variable is not set, or the process
     *         cannot be started — setup problems that a retry would hit
     *         identically, which is why the interface has it throw rather than
     *         return. No other agent is tried (section E, item 9).
     */
    final public function execute(ExecutionRequest $request): ExecutionResult
    {
        $invocation = $this->invocationFor($request);
        $output = $request->output();

        // Written before the executable is resolved, so that a run whose agent
        // cannot be found has a log saying which attempt it was looking for.
        $output->note($request->summary());

        $invocation = $invocation->at($this->resolve($request, $invocation));
        $environment = $this->authenticate($request);
        $output->note('running ' . $invocation->describe());

        $result = $this->processes->run(
            $invocation->command(),
            // The one working directory this process may have. Not a default,
            // not the runner's own: see the class description.
            $request->directory(),
            // The lesser of the task timeout and the run's remaining time,
            // computed by ExecutionBudget so that no adapter decides it.
            $request->seconds(),
            // The declared authentication variables, resolved above. Values
            // reach the child here and nowhere else (section E, item 8).
            $environment->forProcess(),
            $invocation->input(),
            // The terminal and the attempt's log. Not closed here: the
            // verification commands of section G write into the same log after
            // this returns, and whoever opened it closes it.
            $output,
        );

        // Normalised here rather than in each adapter: a killed process has no
        // output to interpret and reported no exit status, so there is nothing
        // agent-specific left to say about it, and an adapter that forgot the
        // case would report a timeout as an ordinary failure and have the
        // user raise the wrong limit.
        if ($result->timedOut()) {
            $stopped = sprintf(
                '%s was stopped after %d second%s (%s)',
                $request->agent()->name(),
                $request->seconds(),
                $request->seconds() === 1 ? '' : 's',
                $request->budget()->isLimitedByRun()
                    ? 'the run\'s remaining time'
                    : 'the task timeout',
            );

            $output->note($stopped);

            return ExecutionResult::timedOut(
                $this->evidenceOf($stopped, $result),
                seconds: $result->seconds(),
            );
        }

        $output->note(sprintf(
            '%s exited with status %d after %s',
            $request->agent()->name(),
            $result->exitCode() ?? -1,
            self::elapsed($result->seconds()),
        ));

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
     *
     * {@see self::reported()} is the common reading of the result block the
     * briefing asks for, and is what an adapter returns when its CLI reports
     * nothing more than the agent's own output.
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
     * The attempt as the agent reported it, measured against what the package
     * observed.
     *
     * PROJECT.md section E, item 5, in the one place both built-in adapters and
     * any registered one can share it: the output is read for a
     * {@see ResultProtocol} block, the three declarable cases become the three
     * normalised outcomes, and output that is absent or malformed becomes an
     * execution error rather than a candidate nobody checked.
     *
     * **The observation outranks the declaration, except for a question.** A
     * declared candidate from a process that exited non-zero is an error: the
     * exit status is something the package watched happen and the declaration
     * is an agent's account of itself, so where they disagree about whether the
     * run went well, the status wins — and the claim is kept, so a user can see
     * what was claimed. A declared blocker is honoured whatever the status,
     * because a question does not stop being a question when the process that
     * asked it then failed, and retrying an attempt that needs a decision
     * spends an attempt to be asked the same thing again.
     *
     * **Silence is not success.** A zero exit with no block is an error, which
     * is section 4's rule in the one place it can be enforced: an agent that
     * printed nothing has not said it changed anything, and the alternative —
     * verifying and committing on the strength of an exit status — is how work
     * nobody claimed gets committed. The evidence says so and says what to
     * print, because this is the one failure an agent cannot diagnose from its
     * own output.
     *
     * An adapter whose CLI reports some of this natively — a known status for
     * "no changes", its own structured transcript — reads that first and falls
     * back to this, in the same way {@see self::failure()} is the ordinary
     * reading of a process that failed.
     */
    protected function reported(ExecutionRequest $request, ProcessResult $result): ExecutionResult
    {
        $reading = ReportReader::read($result);
        $agent = $request->agent()->name();

        // The package's own reading of the output, marked as its own like the
        // notes execute() writes around the invocation. What the agent said
        // about itself is in the output above it.
        $request->output()->note(sprintf('%s %s', $agent, $reading->describe()));

        $report = $reading->report();

        if ($report === null) {
            return ExecutionResult::error(
                $this->unreadable($request, $result, $reading),
                '',
                $result->seconds(),
                $result->exitCode(),
            );
        }

        if ($report->requiresHumanInput()) {
            $blocker = $report->blocker($agent);

            // Unreachable while a blocked report is required to carry both
            // halves of its question, which ReportReader checks before this is
            // ever called. Kept because ExecutionResult::blocked() would throw
            // rather than return, and an adapter must not turn an agent's
            // output into an exception that stops the run.
            if ($blocker === null) {
                return ExecutionResult::error(
                    $this->evidenceOf(sprintf('%s asked for human input without a question', $agent), $result),
                    $report->summary()->text(),
                    $result->seconds(),
                    $result->exitCode(),
                );
            }

            return ExecutionResult::blocked(
                $blocker,
                $report->summary()->text(),
                $result->seconds(),
                $result->exitCode(),
            );
        }

        if ($report->isError()) {
            return ExecutionResult::error(
                $this->evidenceOf(sprintf('%s reported that it could not do the work', $agent), $result),
                $report->summary()->text(),
                $result->seconds(),
                $result->exitCode(),
            );
        }

        if (!$result->succeeded()) {
            return $this->failure($request, $result, $report->summary()->text());
        }

        return ExecutionResult::candidate($report->summary()->text(), $result->seconds(), $result->exitCode());
    }

    /**
     * What to record when there was no report to read.
     *
     * Two observations rather than one, because they answer different
     * questions: what the package saw, and what the agent has to do instead.
     * The second is addressed to the next attempt, which is handed this
     * evidence in its briefing (section D, item 5) — and a retry that is told
     * only "no result" will produce the same no result.
     *
     * The quoted block comes before the explanation on purpose:
     * {@see FailureEvidence} keeps the end of a detail, so the sentence saying
     * what was wrong is the part that survives a long one.
     */
    private function unreadable(
        ExecutionRequest $request,
        ProcessResult $result,
        ReportReading $reading,
    ): FailureEvidence {
        $agent = $request->agent()->name();

        $evidence = $reading->isMalformed()
            ? FailureEvidence::of(
                sprintf('%s printed a result block Ralph PHP could not read', $agent),
                trim(sprintf(
                    "%s\n\nNot read because %s.",
                    $reading->block(),
                    rtrim((string) $reading->problem(), '.'),
                )),
            )
            : $this->evidenceOf(
                $result->succeeded()
                    ? sprintf('%s exited with status 0 without reporting a result', $agent)
                    : sprintf('%s exited with status %d without reporting a result', $agent, $result->exitCode() ?? -1),
                $result,
            );

        return $evidence->with(
            'Ralph PHP has no result to act on',
            sprintf(
                'An attempt is finished by printing one fenced `%s` block holding a JSON object'
                    . ' with an "%s" of %s, as "Reporting back" in the briefing describes.'
                    . ' Without one the attempt is an execution error whatever its exit status.',
                ResultProtocol::FENCE,
                ResultProtocol::OUTCOME,
                '"' . implode('", "', ResultProtocol::outcomes()) . '"',
            ),
        );
    }

    /**
     * A wall-clock duration for a log note.
     *
     * Seconds for anything short and minutes once a duration is long enough
     * that counting seconds is work, because an agent's attempt is routinely
     * several minutes and "after 743.2s" is a number a reader has to divide.
     */
    private static function elapsed(float $seconds): string
    {
        if ($seconds < 60.0) {
            return sprintf('%.1fs', $seconds);
        }

        return sprintf('%dm %ds', (int) ($seconds / 60), (int) fmod($seconds, 60.0));
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
     * The authentication this agent is started with.
     *
     * PROJECT.md section E, item 8's environment-based authentication, resolved
     * here rather than in each adapter because an agent's credentials have
     * nothing to do with which flags its CLI takes, and because there must be
     * exactly one place in this package that holds a credential value.
     * {@see AgentEnvironment} is that place; what comes back here is names.
     *
     * **A declared variable that is not set refuses the attempt.** Preflight
     * reports the same condition as a failed check before a run starts
     * ({@see \Celyes\RalphPhp\Preflight\Check\AgentCheck}), and this is the
     * same question asked at the moment it matters — a run may reach an adapter
     * without preflight having been asked, and an agent started without its key
     * spends the attempt's whole budget to fail with "invalid API key" in prose
     * that only {@see self::reported()} would then have to interpret. Thrown
     * rather than returned, like a missing executable: it is a setup problem a
     * retry would hit identically, and no other agent is tried in its place.
     *
     * **The message names variables, never values.** A hint telling the user to
     * export `ANTHROPIC_API_KEY` is actionable; one echoing what the variable
     * currently holds is a credential in a terminal, a log file and whatever
     * issue tracker the output is pasted into.
     *
     * @throws ProcessException when a declared variable is unset or empty
     */
    private function authenticate(ExecutionRequest $request): AgentEnvironment
    {
        $agent = $request->agent();
        $environment = AgentEnvironment::of($agent->definition());

        if (!$environment->isComplete()) {
            $missing = $environment->missing();

            throw new ProcessException(
                sprintf(
                    'The agent "%s" declares %s for authentication, which %s not set.',
                    $agent->name(),
                    implode(', ', $missing),
                    count($missing) === 1 ? 'is' : 'are',
                ),
                [
                    sprintf(
                        'Export %s before running ralph. Ralph PHP reads %s from the environment when it '
                            . 'starts the agent, so no credential is written into the configuration or the '
                            . 'state store.',
                        count($missing) === 1 ? 'it' : 'them',
                        count($missing) === 1 ? 'it' : 'them',
                    ),
                    sprintf(
                        'A variable exported as the empty string counts as not set; "agents.%s.environment" '
                            . 'lists the names this agent needs.',
                        $agent->name(),
                    ),
                    'Ralph PHP does not run a different agent in its place.',
                ],
            );
        }

        // Only when the agent authenticates this way, so that the common case —
        // both built-in CLIs can be logged in interactively — adds no noise to
        // every attempt's log.
        if ($environment->isDeclared()) {
            $request->output()->note(sprintf('%s %s', $agent->name(), $environment->describe()));
        }

        return $environment;
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
