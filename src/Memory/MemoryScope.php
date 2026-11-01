<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

use Celyes\RalphPhp\Tasks\TaskId;

/**
 * Which notes an attempt is entitled to read.
 *
 * Memory is filed by scope rather than in one flat directory, because the two
 * kinds of note answer different questions. A task's own notes say what was
 * already tried on *this* work and why it did not pass, and they are only
 * relevant while that task is open. Project-wide notes say what an attempt at
 * anything in this repository should know — that the test suite needs a
 * database, that a generated file must not be edited — and they outlive every
 * task.
 *
 * A scope is a directory name under `.ralph/runtime/memory/`, which is also
 * what makes the layout legible to a person: `ls` on the memory directory shows
 * one directory per task plus `project`, and a task's notes can be thrown away
 * by deleting the directory named after it. Task directories are named by
 * {@see TaskId}, which is `TASK-` and eight hex digits and so is always a safe
 * directory name and can never collide with `project`.
 */
final class MemoryScope
{
    /**
     * Directory holding notes that apply to every task.
     */
    public const string PROJECT = 'project';

    private function __construct(
        private readonly string $directory,
        private readonly ?TaskId $task,
    ) {
    }

    /**
     * Notes that apply to work on this repository, whatever the task.
     */
    public static function project(): self
    {
        return new self(self::PROJECT, null);
    }

    /**
     * One task's own notes.
     */
    public static function forTask(TaskId $task): self
    {
        return new self($task->value(), $task);
    }

    /**
     * The scope's directory name, relative to the memory directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The scope's directory under a given memory directory.
     */
    public function directoryIn(string $memoryDirectory): string
    {
        return rtrim($memoryDirectory, '/') . '/' . $this->directory;
    }

    /**
     * What the scope is called in output and in a rendered prompt.
     */
    public function label(): string
    {
        return $this->directory;
    }

    public function isProject(): bool
    {
        return $this->task === null;
    }

    /**
     * The task the notes belong to, or null for project-wide notes.
     */
    public function taskId(): ?TaskId
    {
        return $this->task;
    }

    public function equals(self $other): bool
    {
        return $this->directory === $other->directory;
    }
}
