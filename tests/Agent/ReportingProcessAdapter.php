<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentInvocation;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\ProcessAdapter;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * An adapter that reads its agent's output and nothing else.
 *
 * The least an adapter can be once {@see ProcessAdapter::reported()} exists,
 * and that is the point: what the tests using it observe is the common reading
 * of the result block, with no agent-specific interpretation in front of it to
 * account for the answer.
 */
final class ReportingProcessAdapter extends ProcessAdapter
{
    /**
     * @param list<string> $arguments what the agent is run with
     */
    public function __construct(private readonly array $arguments)
    {
        parent::__construct();
    }

    public function name(): string
    {
        return 'reporting';
    }

    protected function invocationFor(ExecutionRequest $request): AgentInvocation
    {
        return AgentInvocation::forAgent($request->agent(), $this->arguments);
    }

    protected function interpret(ExecutionRequest $request, ProcessResult $result): ExecutionResult
    {
        return $this->reported($request, $result);
    }
}
