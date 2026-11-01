<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskId;

/**
 * Loads the project context and the relevant memory for one attempt.
 *
 * Called while a task is being prepared — the `preparing` phase — and called
 * again for every attempt rather than once per run, because the notes an
 * attempt needs most are the ones the previous attempt wrote.
 *
 * **What counts as relevant.** The project's context file, which applies to all
 * work; the notes filed under the task being attempted; and the notes filed
 * project-wide. Nothing else: notes from a *different* task are not loaded,
 * because this release implements tasks independently and another task's
 * attempt history describes a branch this one cannot see.
 *
 * **How the budget is spent.** The task's own notes get first refusal on the
 * shared character allowance, and the project-wide notes take what is left.
 * When a task has failed twice, the evidence from those two attempts is the
 * most valuable text in the prompt, and it must not be squeezed out by a
 * standing note that the agent could also have read in the context file. The
 * per-scope note *count* is not shared, only the characters — a cap on how many
 * notes one scope contributes is about keeping the prompt legible, which is not
 * a quantity two scopes compete for.
 */
final class AttemptContextLoader
{
    public function __construct(private readonly MemoryBudget $budget = new MemoryBudget())
    {
    }

    /**
     * The bounds attempts are loaded under.
     */
    public function budget(): MemoryBudget
    {
        return $this->budget;
    }

    /**
     * Everything an attempt at the given task should be told.
     *
     * @throws FilesystemException when the context path is a directory, or a
     *                             context file or note exists and cannot be read
     */
    public function load(Configuration $configuration, Task $task): AttemptContext
    {
        return $this->loadFor($configuration, $task->id());
    }

    /**
     * The same, for a task identified by its ID.
     *
     * Separate so that `resume` and `retry`, which start from a stored task
     * rather than from a parsed checklist item, do not have to reconstruct a
     * {@see Task} to find out what the attempt was given.
     *
     * @throws FilesystemException
     */
    public function loadFor(Configuration $configuration, TaskId $task): AttemptContext
    {
        $memory = MemoryStore::in($configuration->projectRoot());

        $taskNotes = $memory->notesFor(MemoryScope::forTask($task), $this->budget);
        $projectNotes = $this->projectNotes($memory, $taskNotes->length());

        return new AttemptContext(
            ContextFile::read($configuration->contextFile(), $this->budget),
            $projectNotes,
            $taskNotes,
            $this->budget,
        );
    }

    /**
     * The project-wide notes, against whatever the task's own notes left.
     *
     * @throws FilesystemException
     */
    private function projectNotes(MemoryStore $memory, int $spent): MemorySelection
    {
        $scope = MemoryScope::project();
        $remaining = $this->budget->totalNoteCharacters() - $spent;

        if ($remaining < $this->budget->noteFloor()) {
            // Counted rather than assumed absent, so the rendering can say the
            // notes exist and were left out.
            return new MemorySelection($scope, [], $memory->countFor($scope));
        }

        return $memory->notesFor($scope, $this->budget->withTotalNoteCharacters($remaining));
    }
}
