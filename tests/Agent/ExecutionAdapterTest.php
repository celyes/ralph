<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Exception\CommandUnavailableException;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Memory\MemorySelection;
use Celyes\RalphPhp\Memory\ProjectContext;
use Celyes\RalphPhp\State\Blocker;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the loop and one coding agent.
 *
 * What is under test is that the contract is usable and agent-independent: two
 * adapters with nothing in common are consumed by one caller that never asks
 * which agent ran. The built-in Codex and Claude Code adapters arrive with
 * PROJECT.md section E, items 6 and 7, and the deterministic fixture worker
 * with item 12; the adapters here exist only to exercise the interface.
 */
#[CoversClass(ExecutionRequest::class)]
#[CoversClass(ExecutionResult::class)]
final class ExecutionAdapterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('adapter');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function an_adapter_names_the_registration_it_answers_to(): void
    {
        self::assertSame('fixture', $this->adapter(ExecutionResult::candidate())->name());
    }

    #[Test]
    public function an_adapter_is_given_the_request_and_nothing_about_the_loop(): void
    {
        $adapter = new class () implements ExecutionAdapter {
            public ?ExecutionRequest $request = null;

            public function name(): string
            {
                return 'recording';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                $this->request = $request;

                return ExecutionResult::candidate('Edited two files.');
            }
        };

        $adapter->execute($this->request());

        self::assertNotNull($adapter->request);
        self::assertSame($this->directory, $adapter->request->directory());
        self::assertSame(600, $adapter->request->seconds());
    }

    #[Test]
    public function the_loop_acts_on_two_unrelated_adapters_without_asking_which_agent_ran(): void
    {
        $outcomes = [];

        foreach ([
            'first' => ExecutionResult::candidate('Implemented.'),
            'second' => ExecutionResult::blocked(
                Blocker::from('second', 'Which queue driver?', 'Say so in CONTEXT.md and retry.'),
            ),
            'third' => ExecutionResult::error(FailureEvidence::of('the agent exited with status 2')),
        ] as $name => $result) {
            $adapter = $this->adapter($result, $name);
            $returned = $adapter->execute($this->request());

            // The whole of what the loop needs: whether there is something to
            // verify, and what the result decides on its own.
            $outcomes[$name] = [$returned->hasCandidate(), $returned->settles()];
        }

        self::assertSame([
            'first' => [true, null],
            'second' => [false, LifecycleState::Blocked],
            'third' => [false, LifecycleState::Failed],
        ], $outcomes);
    }

    #[Test]
    public function a_setup_failure_is_raised_rather_than_returned_as_an_attempt(): void
    {
        $adapter = new class () implements ExecutionAdapter {
            public function name(): string
            {
                return 'missing';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                throw new CommandUnavailableException('The executable "codex" was not found.');
            }
        };

        // Retrying would fail the same way, and the run stops on it — unlike a
        // failed attempt, which leaves a recorded row behind and may be retried.
        $this->expectException(RalphException::class);
        $this->expectExceptionMessage('The executable "codex" was not found.');

        $adapter->execute($this->request());
    }

    private function adapter(ExecutionResult $result, string $name = 'fixture'): ExecutionAdapter
    {
        return new class ($result, $name) implements ExecutionAdapter {
            public function __construct(
                private readonly ExecutionResult $result,
                private readonly string $registeredAs,
            ) {
            }

            public function name(): string
            {
                return $this->registeredAs;
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return $this->result;
            }
        };
    }

    private function request(): ExecutionRequest
    {
        $task = Task::fromItem(
            (new ChecklistParser())->parse("- [ ] Add a --dry-run option\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            new AgentSelection(AgentDefinition::builtIn('codex'), AgentSource::ProjectDefault),
            TaskGeneration::first($task->id()),
            1,
            $task,
            Workspace::at($this->directory),
            new AttemptContext(
                ProjectContext::absent('.ralph/CONTEXT.md'),
                MemorySelection::empty(MemoryScope::project()),
                MemorySelection::empty(MemoryScope::forTask($task->id())),
                new MemoryBudget(),
            ),
            ExecutionBudget::of(new Limits(taskTimeout: 600)),
        );
    }
}
