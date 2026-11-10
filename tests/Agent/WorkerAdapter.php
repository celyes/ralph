<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentInvocation;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\ProcessAdapter;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * The adapter {@see WorkerAdapterProvider} builds.
 *
 * The other half of one example: a provider that answers to a name, and the
 * adapter behind it. A project writing its own would keep the two wherever its
 * autoloader expects them, which is why this one is an ordinary class rather
 * than something the provider closes over.
 */
final class WorkerAdapter extends ProcessAdapter
{
    public function __construct(private readonly string $name)
    {
        parent::__construct();
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The executable, the project's own extra arguments, and the briefing on
     * standard input.
     */
    protected function invocationFor(ExecutionRequest $request): AgentInvocation
    {
        return AgentInvocation::forAgent($request->agent(), $request->agent()->definition()->arguments())
            ->withInput($request->toMarkdown());
    }

    /**
     * The report the briefing asked for, read by the base class.
     */
    protected function interpret(ExecutionRequest $request, ProcessResult $result): ExecutionResult
    {
        return $this->reported($request, $result);
    }
}
