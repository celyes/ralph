<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

/**
 * Decides which pass at a task a run should work in.
 *
 * The rule is short, and it reads from the recorded history alone:
 *
 * - nothing recorded — generation 1, a first run;
 * - the latest pass is unfinished — that same generation, continued, on the
 *   branch it already owns;
 * - the latest pass finished — the next generation, a reopening, with a branch
 *   of its own and every earlier pass left exactly as it is.
 *
 * The checkbox is deliberately not consulted. Whether a task should be worked
 * on at all is the checklist's business ({@see TaskList} and
 * {@see Completion}), and it has one input: the file. What generation the work
 * belongs to is this class's business, and it has one input: what has already
 * been recorded. Mixing them would mean `ralph retry` on a task someone ticked
 * by hand either overwriting a verified commit or refusing to run; keeping them
 * apart means a task is run when the file asks for it and is recorded against a
 * pass that cannot collide with a finished one.
 *
 * Free of I/O. The history comes from the state store of PROJECT.md section D;
 * naming and creating the branch is section F; this only says which pass is
 * being made and what it must not touch.
 */
final class GenerationSelector
{
    /**
     * The pass to make at a task.
     *
     * @param GenerationHistory|null $history what the state store has recorded;
     *                                        null, the default, means nothing
     *                                        has been, so every task is a first
     *                                        run — the reading that cannot
     *                                        claim an earlier pass's branch
     */
    public function select(TaskId $task, ?GenerationHistory $history = null): GenerationSelection
    {
        $history ??= GenerationHistory::none();
        $latest = $history->latestOf($task);

        if ($latest === null) {
            return GenerationSelection::firstRun($task);
        }

        $recorded = $history->generationsOf($task);

        if ($latest->isOpen()) {
            return GenerationSelection::continuing(
                $latest,
                array_slice($recorded, 0, -1),
            );
        }

        return GenerationSelection::reopened($latest->generation()->next(), $recorded);
    }

    /**
     * The pass to make at a task read from the checklist.
     *
     * The form the runner uses, which holds a {@see Task} rather than an
     * identifier.
     */
    public function selectFor(Task $task, ?GenerationHistory $history = null): GenerationSelection
    {
        return $this->select($task->id(), $history);
    }
}
