<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Support\Excerpt;

/**
 * Gives every item in a checklist its identity, and refuses a checklist whose
 * items cannot be told apart.
 *
 * Identity comes from the task's normalised description and nothing else
 * ({@see TaskBody}), which is what the state store, the task branch and
 * `ralph logs TASK-ID` rely on: the same task keeps the same name across runs,
 * through reordering, and after being reopened.
 *
 * That has a price the user has to be told about rather than discover. Two
 * items that read the same are one task, so a checklist that states one twice
 * is refused here. Accepting it would mean one of the two silently became the
 * other: a single row in the database, one branch, one set of attempts, one
 * checkbox ticked — and the user watching the second item never get done would
 * have no way to see why. A refusal naming both lines is one edit away from a
 * checklist that works.
 *
 * Refused while reading, before anything runs, for the same reason the parser
 * refuses a checkbox with no task: nothing has been executed yet, the remedy is
 * in the file the user is already editing, and a run that starts against a
 * checklist the runner reads differently than the user does is worse than a run
 * that does not start.
 */
final class TaskIdentifier
{
    /**
     * Identify every item in a checklist, in file order.
     *
     * Checked items are identified too, and by the same rule: they are the
     * history of the project, `status` has to be able to list them, and a task
     * that is reopened has to come back with the identifier it had before.
     *
     * @return list<Task>
     *
     * @throws ChecklistException when two items state the same task, or when
     *                            two different tasks produce one identifier
     */
    public function identify(Checklist $checklist): array
    {
        $tasks = [];

        /** @var array<string, Task> $byBody */
        $byBody = [];

        /** @var array<string, Task> $byId */
        $byId = [];

        foreach ($checklist->items() as $item) {
            $task = Task::fromItem($item);

            $body = $task->body()->normalized();
            if (isset($byBody[$body])) {
                throw self::duplicate($byBody[$body], $task, $checklist);
            }

            $id = $task->id()->value();
            if (isset($byId[$id])) {
                throw self::collision($byId[$id], $task, $checklist);
            }

            $byBody[$body] = $task;
            $byId[$id] = $task;
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * Refuse a checklist that states the same task twice.
     *
     * The message names both lines, because the second one on its own is not
     * the mistake — which of the two to reword or delete is the user's call and
     * they need to see both to make it.
     */
    private static function duplicate(Task $first, Task $repeat, Checklist $checklist): ChecklistException
    {
        $hints = [
            'A task is identified by its description, so two items that read the same are the same task.',
            'Reword one of them to say what is different about it, or delete the repeated line.',
        ];

        // When the two lines do not look alike, say why they are nonetheless
        // one task: the user is staring at two different strings and the
        // message has to account for that before its advice makes sense.
        if (trim($first->description()) !== trim($repeat->description())) {
            $hints[] = sprintf(
                'The two differ only in capitalisation or spacing, which task identity ignores: "%s" and "%s".',
                Excerpt::of($first->description()),
                Excerpt::of($repeat->description()),
            );
        }

        if ($first->item()->hasDetails() || $repeat->item()->hasDetails()) {
            $hints[] = 'Indented details are not part of a task\'s identity, so two items that differ only in what is written under them still read as one task.';
        }

        return new ChecklistException(
            sprintf(
                '%s states the same task on lines %d and %d: "%s".',
                $checklist->subject(),
                $first->line(),
                $repeat->line(),
                Excerpt::of($repeat->description()),
            ),
            $hints,
        );
    }

    /**
     * Refuse two different tasks that happen to hash to one identifier.
     *
     * Rare enough that the message says so — a user who meets this has not done
     * anything wrong and should not be left thinking they wrote the same task
     * twice. Refusing is still the only safe answer: the alternative is two
     * tasks sharing a branch, a log directory and a row of state.
     */
    private static function collision(Task $first, Task $second, Checklist $checklist): ChecklistException
    {
        return new ChecklistException(
            sprintf(
                '%s has two different tasks with the same generated identifier %s, on lines %d and %d: "%s" and "%s".',
                $checklist->subject(),
                $second->id()->value(),
                $first->line(),
                $second->line(),
                Excerpt::of($first->description()),
                Excerpt::of($second->description()),
            ),
            [
                'Reword either description. The identifier is derived from the description, so different wording produces a different identifier.',
                'This is a hash collision between two unrelated tasks, which is rare enough to be worth reporting at https://github.com/celyes/ralph-php/issues.',
            ],
        );
    }
}
