<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\Sinks;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Memory\MemorySelection;
use Celyes\RalphPhp\Memory\ProjectContext;
use Celyes\RalphPhp\State\FailureEvidence;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Everything one invocation of an agent is given.
 */
#[CoversClass(ExecutionRequest::class)]
final class ExecutionRequestTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create('request');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function a_request_carries_the_six_things_an_adapter_is_promised(): void
    {
        $evidence = FailureEvidence::of('composer test failed (exit 1)', '1 failed, 42 passed');
        $request = $this->request(attempt: 2, evidence: $evidence);

        self::assertSame(self::DESCRIPTION, $request->description());
        self::assertSame('The flag must default to off.', $request->criteria());
        self::assertSame($this->directory, $request->directory());
        self::assertFalse($request->context()->isEmpty());
        self::assertSame($evidence, $request->evidence());
        self::assertSame(1800, $request->seconds());
    }

    #[Test]
    public function the_selected_agent_travels_with_the_request_so_resume_can_rebuild_it(): void
    {
        $request = $this->request();

        self::assertSame('codex', $request->agent()->name());
        self::assertSame('codex', $request->agent()->adapter());
    }

    #[Test]
    public function a_task_with_no_notes_under_it_has_no_criteria_rather_than_empty_ones(): void
    {
        $request = $this->request(markdown: '- [ ] ' . self::DESCRIPTION . "\n");

        self::assertNull($request->criteria());
        self::assertFalse($request->hasCriteria());
        self::assertStringNotContainsString('# Acceptance criteria', $request->toMarkdown());
    }

    #[Test]
    public function a_first_attempt_has_no_evidence_and_is_not_a_retry(): void
    {
        $request = $this->request();

        self::assertSame(1, $request->attempt());
        self::assertFalse($request->isRetry());
        self::assertFalse($request->hasEvidence());
        self::assertTrue($request->evidence()->isEmpty());
    }

    #[Test]
    public function the_task_and_its_identity_are_both_reachable(): void
    {
        $request = $this->request();

        self::assertSame(TaskId::fromDescription(self::DESCRIPTION)->value(), $request->taskId()->value());
        self::assertInstanceOf(Task::class, $request->task());
        self::assertTrue($request->generation()->isFirst());
    }

    #[Test]
    public function an_attempt_number_starts_at_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An attempt number starts at 1.');

        $this->request(attempt: 0);
    }

    #[Test]
    public function a_generation_belonging_to_another_task_is_refused(): void
    {
        $other = TaskGeneration::first(TaskId::fromDescription('Rewrite the importer'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a pass at');

        $this->request(generation: $other);
    }

    #[Test]
    public function a_spent_budget_is_refused_here_rather_than_reaching_an_adapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No attempt can be prepared for');

        $this->request(budget: ExecutionBudget::of(new Limits(attemptsPerTask: 2), attemptsUsed: 2));
    }

    #[Test]
    public function the_briefing_states_the_workspace_because_nothing_else_will_be_committed(): void
    {
        $markdown = $this->request()->toMarkdown();

        self::assertStringContainsString(
            sprintf('**Work in:** `%s`. Changes outside it are not part of this task.', $this->directory),
            $markdown,
        );
    }

    #[Test]
    public function the_briefing_opens_with_the_task_and_then_its_criteria(): void
    {
        $markdown = $this->request()->toMarkdown();

        self::assertStringStartsWith(
            '# ' . TaskGeneration::first(TaskId::fromDescription(self::DESCRIPTION))->label() . ', attempt 1',
            $markdown,
        );
        self::assertStringContainsString(self::DESCRIPTION, $markdown);
        self::assertStringContainsString("# Acceptance criteria\n", $markdown);
        self::assertStringContainsString('The flag must default to off.', $markdown);
        self::assertStringEndsWith("\n", $markdown);
    }

    #[Test]
    public function the_briefing_says_how_much_room_is_left(): void
    {
        $markdown = $this->request()->toMarkdown();

        self::assertStringContainsString('**Budget:** 3 of 3 attempts left, up to 1800 seconds.', $markdown);
        self::assertStringNotContainsString('**Last attempt:**', $markdown);
    }

    #[Test]
    public function the_briefing_says_when_nothing_follows_this_attempt(): void
    {
        $request = $this->request(
            attempt: 3,
            budget: ExecutionBudget::of(new Limits(attemptsPerTask: 3), attemptsUsed: 2),
        );

        self::assertStringContainsString('**Last attempt:** nothing follows this one.', $request->toMarkdown());
    }

    #[Test]
    public function the_briefing_says_a_reopened_task_starts_from_a_branch_of_its_own(): void
    {
        $generation = TaskGeneration::of(TaskId::fromDescription(self::DESCRIPTION), 2);
        $request = $this->request(generation: $generation);

        self::assertStringContainsString('**Reopened:**', $request->toMarkdown());
        self::assertStringContainsString('Earlier work is not in this workspace.', $request->toMarkdown());
    }

    #[Test]
    public function the_briefing_carries_the_project_context_as_the_memory_namespace_rendered_it(): void
    {
        $markdown = $this->request()->toMarkdown();

        self::assertStringContainsString('# Project context', $markdown);
        self::assertStringContainsString('PHP 8.3, composer test.', $markdown);
        self::assertStringContainsString('# Notes from earlier work', $markdown);
        self::assertStringContainsString('The flag was added in the wrong file.', $markdown);
    }

    #[Test]
    public function the_briefing_puts_the_failure_evidence_last_and_marks_it_as_the_runners_own(): void
    {
        $request = $this->request(
            attempt: 2,
            evidence: FailureEvidence::of('composer test failed (exit 1)', 'FAILURES! Tests: 42, Failures: 1.'),
        );
        $markdown = $request->toMarkdown();

        self::assertStringContainsString('# What the previous attempt ran into', $markdown);
        self::assertStringContainsString('composer test failed (exit 1)', $markdown);
        self::assertStringContainsString('FAILURES! Tests: 42, Failures: 1.', $markdown);
        self::assertStringContainsString('Observed by Ralph PHP itself', $markdown);

        // The most specific thing in the briefing is the last thing read.
        self::assertGreaterThan(
            strpos($markdown, '# Notes from earlier work'),
            strpos($markdown, '# What the previous attempt ran into'),
        );
    }

    #[Test]
    public function a_first_attempt_leaves_the_evidence_heading_out(): void
    {
        self::assertStringNotContainsString('# What the previous attempt ran into', $this->request()->toMarkdown());
    }

    #[Test]
    public function a_project_with_nothing_written_down_leaves_the_context_sections_out(): void
    {
        $request = $this->request(context: new AttemptContext(
            ProjectContext::absent('.ralph/CONTEXT.md'),
            MemorySelection::empty(MemoryScope::project()),
            MemorySelection::empty(MemoryScope::forTask(TaskId::fromDescription(self::DESCRIPTION))),
            new MemoryBudget(),
        ));
        $markdown = $request->toMarkdown();

        self::assertStringNotContainsString('# Project context', $markdown);
        self::assertStringNotContainsString('# Notes from earlier work', $markdown);
        self::assertStringContainsString(self::DESCRIPTION, $markdown);
    }

    #[Test]
    public function the_summary_is_one_line_naming_the_pass_the_agent_and_the_room_left(): void
    {
        $request = $this->request(attempt: 2);
        $label = TaskGeneration::first(TaskId::fromDescription(self::DESCRIPTION))->label();

        self::assertSame(
            sprintf(
                '%s, attempt 2, agent codex: %s (3 of 3 attempts left, up to 1800 seconds; context 24 chars, 1 note)',
                $label,
                self::DESCRIPTION,
            ),
            $request->summary(),
        );
    }

    /**
     * Where the adapter streams its worker's output (section E, item 4). It
     * arrives with the attempt because an adapter cannot hold one: adapters are
     * stateless across attempts, while a log belongs to one attempt.
     */
    #[Test]
    public function it_carries_the_sink_the_attempts_output_goes_to(): void
    {
        $sink = new RecordingSink();

        $this->request(output: $sink)->output()->write(OutputStream::Standard, 'working');

        self::assertSame('working', $sink->textOf(OutputStream::Standard));
    }

    /**
     * A request built without one writes nowhere rather than holding a null
     * every adapter would have to test for — and nowhere means nowhere, not
     * the process's own streams. Ralph PHP is a library as well as a command,
     * and an attempt prepared by a host application must not print to its
     * standard output because nobody passed a sink.
     */
    #[Test]
    public function an_attempt_with_nowhere_to_stream_to_writes_nowhere(): void
    {
        $output = $this->request()->output();

        $output->write(OutputStream::Standard, 'nobody is reading this');
        $output->note('nor this');

        self::assertInstanceOf(Sinks::class, $output);
        self::assertTrue($output->isEmpty());
    }

    /**
     * The one member of the request the agent is never told about: it is a
     * destination, not part of the briefing.
     */
    #[Test]
    public function the_sink_is_not_mentioned_in_the_briefing(): void
    {
        self::assertSame(
            $this->request()->toMarkdown(),
            $this->request(output: new RecordingSink())->toMarkdown(),
        );
    }

    private function request(
        int $attempt = 1,
        ?TaskGeneration $generation = null,
        ?ExecutionBudget $budget = null,
        ?FailureEvidence $evidence = null,
        ?AttemptContext $context = null,
        string $markdown = '- [ ] ' . self::DESCRIPTION . "\n    The flag must default to off.\n",
        ?OutputSink $output = null,
    ): ExecutionRequest {
        $task = Task::fromItem((new ChecklistParser())->parse($markdown, 'tasks.md')->items()[0]);

        return new ExecutionRequest(
            new AgentSelection(AgentDefinition::builtIn('codex'), AgentSource::ProjectDefault),
            $generation ?? TaskGeneration::first($task->id()),
            $attempt,
            $task,
            Workspace::at($this->directory),
            $context ?? $this->context($task->id()),
            $budget ?? ExecutionBudget::of(new Limits(attemptsPerTask: 3, taskTimeout: 1800, runTimeout: 7200)),
            $evidence,
            $output,
        );
    }

    /**
     * A context loaded from a real project, so that what an adapter is handed
     * is what {@see AttemptContextLoader} actually produces.
     */
    private function context(TaskId $task): AttemptContext
    {
        $project = $this->directory . '/project';
        $notes = $project . '/.ralph/runtime/memory/' . MemoryScope::forTask($task)->directory();

        mkdir($project . '/.ralph', 0o755, true);
        mkdir($notes, 0o755, true);
        file_put_contents($project . '/.ralph/CONTEXT.md', "PHP 8.3, composer test.\n");
        file_put_contents($notes . '/0001-attempt.md', "The flag was added in the wrong file.\n");

        return (new AttemptContextLoader())->loadFor(
            (new ConfigurationLoader())->defaults($project),
            $task,
        );
    }
}
