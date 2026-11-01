<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

/**
 * Everything an attempt is told about the project before it starts.
 *
 * Assembled once per attempt by {@see AttemptContextLoader} and passed through
 * to the adapter, which is what keeps the question "what does the agent get to
 * read" answerable in one place instead of being spread across two adapters
 * that would answer it slightly differently.
 *
 * It holds the project's instructions and the notes from earlier work, in the
 * order they are meant to be read: the standing conventions first, then what
 * has already been tried. The notes stay grouped by scope rather than being
 * merged into one list, because a prompt has to be able to say which task a
 * note was written about — a note from another attempt at *this* task is
 * history, and a project-wide note is a rule.
 *
 * {@see self::toMarkdown()} is the rendered form. Markdown, with the user's own
 * text placed under headings of its own: a context file has its own `#`
 * headings, which end up nested under these, and that is the accepted cost of
 * passing the file through unaltered rather than rewriting a user's document to
 * fit a prompt.
 */
final class AttemptContext
{
    public function __construct(
        private readonly ProjectContext $project,
        private readonly MemorySelection $projectNotes,
        private readonly MemorySelection $taskNotes,
        private readonly MemoryBudget $budget,
    ) {
    }

    /**
     * The project's instructions.
     */
    public function project(): ProjectContext
    {
        return $this->project;
    }

    /**
     * Notes that apply to any task in this repository.
     */
    public function projectNotes(): MemorySelection
    {
        return $this->projectNotes;
    }

    /**
     * Notes from earlier attempts at the task being prepared.
     */
    public function taskNotes(): MemorySelection
    {
        return $this->taskNotes;
    }

    /**
     * Both groups of notes, in the order they are rendered.
     *
     * @return list<MemorySelection>
     */
    public function selections(): array
    {
        return [$this->projectNotes, $this->taskNotes];
    }

    /**
     * Every selected note, project-wide first.
     *
     * @return list<MemoryNote>
     */
    public function notes(): array
    {
        return [...$this->projectNotes->notes(), ...$this->taskNotes->notes()];
    }

    /**
     * The bounds this was assembled under.
     */
    public function budget(): MemoryBudget
    {
        return $this->budget;
    }

    /**
     * Whether there is nothing to tell the agent.
     *
     * True for a project with no context file and no recorded notes — the first
     * attempt of a freshly initialised project. The adapter leaves the section
     * out rather than sending an empty heading.
     */
    public function isEmpty(): bool
    {
        return $this->project->isEmpty()
            && $this->projectNotes->isEmpty()
            && $this->taskNotes->isEmpty();
    }

    /**
     * Characters of project text and note text being passed on.
     */
    public function length(): int
    {
        return $this->project->length()
            + $this->projectNotes->length()
            + $this->taskNotes->length();
    }

    /**
     * One line describing what was loaded, for a run's output and logs.
     */
    public function summary(): string
    {
        $parts = [];

        $parts[] = $this->project->isEmpty()
            ? 'no project context'
            : sprintf('context %d chars', $this->project->length());

        $notes = count($this->notes());
        $omitted = $this->projectNotes->omitted() + $this->taskNotes->omitted();

        $parts[] = $notes === 1 ? '1 note' : sprintf('%d notes', $notes);

        if ($omitted > 0) {
            $parts[] = sprintf('%d omitted', $omitted);
        }

        return implode(', ', $parts);
    }

    /**
     * The whole briefing as Markdown, or an empty string when there is none.
     */
    public function toMarkdown(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $sections = [];

        if (!$this->project->isEmpty()) {
            $sections[] = $this->projectSection();
        }

        if (!$this->projectNotes->isEmpty() || !$this->taskNotes->isEmpty()) {
            $sections[] = $this->notesSection();
        }

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * The context file, under a heading saying where it came from.
     */
    private function projectSection(): string
    {
        $lines = [
            '# Project context',
            '',
            sprintf('The project\'s own instructions, from `%s`.', $this->project->path()),
        ];

        $notice = $this->project->notice();
        if ($notice !== null) {
            $lines[] = $notice;
        }

        $lines[] = '';
        $lines[] = trim($this->project->text());

        return implode("\n", $lines);
    }

    /**
     * The notes, each under its own heading, with what was left out stated.
     *
     * The preamble is not decoration. These notes are an agent's own words from
     * an earlier attempt, and the one failure mode worth spending three lines
     * on is an attempt reading "implemented and tests pass" in a note and
     * concluding there is nothing to do.
     */
    private function notesSection(): string
    {
        $lines = [
            '# Notes from earlier work',
            '',
            'Recorded during earlier attempts. They are observations, not results:',
            'nothing here establishes that a verification command passed or that a',
            'change was committed. Treat them as leads, and check the repository.',
        ];

        foreach ($this->selections() as $selection) {
            foreach ($selection->notes() as $note) {
                $lines[] = '';
                $lines[] = sprintf('## %s', $note->label());

                $notice = $note->text()->notice();
                if ($notice !== null) {
                    $lines[] = '';
                    $lines[] = $notice;
                }

                $lines[] = '';
                $lines[] = trim($note->text()->text());
            }

            $omitted = $selection->omitted();
            if ($omitted > 0) {
                $lines[] = '';
                $lines[] = sprintf(
                    '%d older %s for %s %s not shown; %s on disk under the memory directory.',
                    $omitted,
                    $omitted === 1 ? 'note' : 'notes',
                    $selection->scope()->label(),
                    $omitted === 1 ? 'is' : 'are',
                    $omitted === 1 ? 'it is' : 'they are',
                );
            }
        }

        return implode("\n", $lines);
    }
}
