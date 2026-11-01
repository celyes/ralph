<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Memory;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Memory\AttemptContext;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Memory\MemoryBudget;
use Celyes\RalphPhp\Memory\MemoryNote;
use Celyes\RalphPhp\Memory\MemoryScope;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an attempt is told before it starts.
 */
#[CoversClass(AttemptContextLoader::class)]
#[CoversClass(AttemptContext::class)]
final class AttemptContextLoaderTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    private TemporaryProject $project;

    private TaskId $task;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->task = TaskId::fromDescription(self::DESCRIPTION);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function the_context_file_and_both_scopes_of_notes_are_loaded(): void
    {
        $this->project->write('.ralph/CONTEXT.md', "# Project context\n\nPHP 8.3, composer test.\n");
        $this->note(MemoryScope::project(), '0001-tooling.md', 'The suite needs no database.');
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'The flag was added in the wrong file.');

        $context = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task);

        self::assertFalse($context->isEmpty());
        self::assertSame("# Project context\n\nPHP 8.3, composer test.\n", $context->project()->text());
        self::assertSame(['0001-tooling.md'], $this->names($context->projectNotes()->notes()));
        self::assertSame(['0001-attempt.md'], $this->names($context->taskNotes()->notes()));
        self::assertSame(['0001-tooling.md', '0001-attempt.md'], $this->names($context->notes()));
    }

    #[Test]
    public function a_parsed_checklist_item_can_be_loaded_for_directly(): void
    {
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'Evidence.');

        $context = (new AttemptContextLoader())->load($this->configuration(), $this->parsedTask());

        self::assertSame(['0001-attempt.md'], $this->names($context->taskNotes()->notes()));
    }

    #[Test]
    public function notes_about_other_tasks_are_not_loaded(): void
    {
        $other = MemoryScope::forTask(TaskId::fromDescription('Rewrite the importer'));
        $this->note($other, '0001-attempt.md', 'About a branch this task cannot see.');

        $context = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task);

        self::assertTrue($context->isEmpty());
        self::assertSame('', $context->toMarkdown());
    }

    #[Test]
    public function a_freshly_initialised_project_has_nothing_to_say(): void
    {
        $context = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task);

        self::assertTrue($context->isEmpty());
        self::assertSame('', $context->toMarkdown());
        self::assertSame(0, $context->length());
        self::assertSame('no project context, 0 notes', $context->summary());
    }

    #[Test]
    public function the_tasks_own_notes_get_first_refusal_on_the_shared_allowance(): void
    {
        $this->note(MemoryScope::project(), '0001-tooling.md', str_repeat('p', 400));
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', str_repeat('t', 400));

        $loader = new AttemptContextLoader(new MemoryBudget(noteCharacters: 400, totalNoteCharacters: 450));
        $context = $loader->loadFor($this->configuration(), $this->task);

        self::assertSame(['0001-attempt.md'], $this->names($context->taskNotes()->notes()));
        self::assertTrue($context->projectNotes()->isEmpty());

        // The project note still exists, and is reported as left out rather
        // than as never having been there.
        self::assertSame(1, $context->projectNotes()->available());
        self::assertSame(1, $context->projectNotes()->omitted());
    }

    #[Test]
    public function a_task_history_that_spends_the_whole_allowance_leaves_the_project_notes_counted(): void
    {
        $this->note(MemoryScope::project(), '0001-tooling.md', str_repeat('p', 100));
        $this->note(MemoryScope::project(), '0002-tooling.md', str_repeat('p', 100));
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', str_repeat('t', 300));

        $loader = new AttemptContextLoader(new MemoryBudget(noteCharacters: 300, totalNoteCharacters: 300));
        $context = $loader->loadFor($this->configuration(), $this->task);

        self::assertCount(1, $context->taskNotes()->notes());
        self::assertSame(2, $context->projectNotes()->omitted());
        self::assertSame('no project context, 1 note, 2 omitted', $context->summary());
    }

    #[Test]
    public function the_rendered_briefing_names_its_sources_and_calls_notes_unverified(): void
    {
        $this->project->write('.ralph/CONTEXT.md', "# Project context\n\nPHP 8.3, composer test.\n");
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'Tests pass and the work is done.');

        $markdown = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task)->toMarkdown();

        self::assertStringContainsString('# Project context', $markdown);
        self::assertStringContainsString($this->project->path('.ralph/CONTEXT.md'), $markdown);
        self::assertStringContainsString('PHP 8.3, composer test.', $markdown);
        self::assertStringContainsString('# Notes from earlier work', $markdown);
        self::assertStringContainsString('## ' . $this->task->value() . '/0001-attempt.md', $markdown);
        self::assertStringContainsString('Tests pass and the work is done.', $markdown);
        self::assertStringContainsString('observations, not results', $markdown);
        self::assertStringEndsWith("\n", $markdown);
    }

    #[Test]
    public function the_briefing_renders_project_notes_before_the_tasks_own_history(): void
    {
        $this->note(MemoryScope::project(), '0001-tooling.md', 'A standing rule.');
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'What happened last time.');

        $markdown = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task)->toMarkdown();

        self::assertLessThan(
            strpos($markdown, 'What happened last time.'),
            strpos($markdown, 'A standing rule.'),
        );
    }

    #[Test]
    public function a_missing_context_file_leaves_the_section_out_rather_than_rendering_it_empty(): void
    {
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'Evidence.');

        $markdown = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task)->toMarkdown();

        self::assertStringNotContainsString('# Project context', $markdown);
        self::assertStringStartsWith('# Notes from earlier work', $markdown);
    }

    #[Test]
    public function the_briefing_says_what_the_budget_left_out(): void
    {
        $this->project->write('.ralph/CONTEXT.md', str_repeat("a conventions line\n", 100));
        foreach (range(1, 3) as $number) {
            $this->note(
                MemoryScope::forTask($this->task),
                sprintf('%04d-attempt.md', $number),
                sprintf('Attempt %d.', $number),
            );
        }

        $loader = new AttemptContextLoader(new MemoryBudget(contextCharacters: 100, notes: 1));
        $markdown = $loader->loadFor($this->configuration(), $this->task)->toMarkdown();

        self::assertStringContainsString('Trimmed: ', $markdown);
        self::assertStringContainsString('of 1900 characters shown.', $markdown);
        self::assertStringContainsString(
            sprintf('2 older notes for %s are not shown', $this->task->value()),
            $markdown,
        );
        self::assertStringContainsString('Attempt 3.', $markdown);
        self::assertStringNotContainsString('Attempt 1.', $markdown);
    }

    #[Test]
    public function a_single_omitted_note_is_reported_in_the_singular(): void
    {
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'First.');
        $this->note(MemoryScope::forTask($this->task), '0002-attempt.md', 'Second.');

        $loader = new AttemptContextLoader(new MemoryBudget(notes: 1));
        $markdown = $loader->loadFor($this->configuration(), $this->task)->toMarkdown();

        self::assertStringContainsString(
            sprintf('1 older note for %s is not shown; it is on disk', $this->task->value()),
            $markdown,
        );
    }

    #[Test]
    public function the_summary_reports_what_was_loaded(): void
    {
        $this->project->write('.ralph/CONTEXT.md', str_repeat('x', 120));
        $this->note(MemoryScope::forTask($this->task), '0001-attempt.md', 'A note.');

        $context = (new AttemptContextLoader())->loadFor($this->configuration(), $this->task);

        self::assertSame('context 120 chars, 1 note', $context->summary());
        self::assertSame(127, $context->length());
    }

    #[Test]
    public function the_budget_the_briefing_was_assembled_under_is_reported_with_it(): void
    {
        $budget = new MemoryBudget(notes: 2);
        $loader = new AttemptContextLoader($budget);

        self::assertSame($budget, $loader->budget());
        self::assertSame($budget, $loader->loadFor($this->configuration(), $this->task)->budget());
    }

    #[Test]
    public function a_context_file_configured_elsewhere_is_read_from_there(): void
    {
        $this->project->write('docs/agents.md', 'Conventions live here.');

        $configuration = (new ConfigurationLoader())
            ->fromArray(['context' => 'docs/agents.md'], $this->project->root());

        $context = (new AttemptContextLoader())->loadFor($configuration, $this->task);

        self::assertSame('Conventions live here.', $context->project()->text());
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->defaults($this->project->root());
    }

    private function parsedTask(): Task
    {
        $checklist = (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md');

        return Task::fromItem($checklist->items()[0]);
    }

    private function note(MemoryScope $scope, string $name, string $contents): string
    {
        return $this->project->write(
            Defaults::MEMORY_DIRECTORY . '/' . $scope->directory() . '/' . $name,
            $contents,
        );
    }

    /**
     * @param list<MemoryNote> $notes
     * @return list<string>
     */
    private function names(array $notes): array
    {
        return array_map(static fn (MemoryNote $note): string => $note->name(), $notes);
    }
}
