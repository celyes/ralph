<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Event;

/**
 * The things the event stream reports, and what each one is about.
 *
 * Four, deliberately. A supervising process watching a run wants to know that
 * it started, which task is being worked on, how each task came out and how the
 * run ended; everything finer than that is already in the task's log and the
 * state store, and an event per phase would be a second, less reliable copy of
 * the `generations.phase` column.
 *
 * **The names are `noun.verb` and they are the stream's public vocabulary.**
 * They are what a consumer switches on, so they are written down here rather
 * than composed at the call site, and they are prefixed by what they are about
 * so that filtering a stream for the tasks is a prefix match.
 *
 * **Whether an event is about a task is a property of its kind.** The envelope
 * carries a task identifier, and for a run-level event there is no honest value
 * for it — a run works through many tasks, so naming one of them would be a
 * guess and naming the current one would make `run.finished` look like a task
 * result. {@see Event} holds each kind to its answer, so a consumer can rely on
 * `task` being present exactly when the prefix says it is.
 */
enum EventKind: string
{
    /**
     * A run has been opened, its agent resolved and its checks frozen.
     *
     * Emitted once the run has a row and an identifier, which is what every
     * later event is correlated by.
     */
    case RunStarted = 'run.started';

    /**
     * A task has been taken on, and an iteration spent on it.
     */
    case TaskStarted = 'task.started';

    /**
     * A task's pass ended, however it ended.
     *
     * The outcome carries which of the ways it was, and the data is the pass's
     * own record of it.
     */
    case TaskFinished = 'task.finished';

    /**
     * The run ended, with the reason and the next action it was closed on.
     */
    case RunFinished = 'run.finished';

    /**
     * Whether an event of this kind is about one task.
     */
    public function concernsATask(): bool
    {
        return str_starts_with($this->value, 'task.');
    }
}
