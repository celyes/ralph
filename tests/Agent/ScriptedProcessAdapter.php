<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentInvocation;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\ProcessAdapter;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * A {@see ProcessAdapter} whose agent is whatever the test says.
 *
 * Here to exercise the base class and nothing else: the two abstract halves do
 * the least an adapter can do, so that what a test observes belongs to
 * {@see ProcessAdapter} — the working directory the process got, the timeout it
 * was held to, the arguments it was started with.
 *
 * Not the deterministic fixture worker of PROJECT.md section E, item 12 —
 * that one is {@see \Celyes\RalphPhp\Tests\Fixture\FixtureWorker}, a
 * *program* the tests run, with modes for success, failure, blocking, slow
 * execution and interruption. This is a subclass, in a test: a test about a
 * command line asks this one what was run, and a test about the loop asks the
 * worker what was done to it.
 */
final class ScriptedProcessAdapter extends ProcessAdapter
{
    /**
     * The process result the base class handed over, if it handed one over.
     */
    public ?ProcessResult $interpreted = null;

    /**
     * How many times the base class asked for a reading.
     */
    public int $interpretations = 0;

    /**
     * @param list<string> $arguments what the agent is run with
     * @param bool $briefingOnInput whether the briefing goes to standard input
     * @param bool $descriptionAsArgument whether the task description is
     *        appended as one argument
     */
    public function __construct(
        private readonly array $arguments,
        private readonly bool $briefingOnInput = false,
        private readonly bool $descriptionAsArgument = false,
    ) {
        parent::__construct();
    }

    public function name(): string
    {
        return 'scripted';
    }

    protected function invocationFor(ExecutionRequest $request): AgentInvocation
    {
        $invocation = AgentInvocation::forAgent($request->agent(), $this->arguments);

        if ($this->descriptionAsArgument) {
            $invocation = $invocation->with($request->description());
        }

        return $this->briefingOnInput ? $invocation->withInput($request->toMarkdown()) : $invocation;
    }

    protected function interpret(ExecutionRequest $request, ProcessResult $result): ExecutionResult
    {
        $this->interpreted = $result;
        $this->interpretations++;

        return $result->succeeded()
            ? ExecutionResult::candidate($result->trimmedOutput(), $result->seconds(), $result->exitCode())
            : $this->failure($request, $result);
    }
}
