<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

/**
 * One observation on disk, bounded and ready to put in front of an attempt.
 *
 * A note is a Markdown file and nothing more: the package reads its bytes and
 * passes them on. It carries no verdict — a note saying the work is done is a
 * sentence an agent wrote, and what makes a task complete is a verification
 * result and a commit, which live in the state store. That separation is why
 * notes are files the user can read and delete while the record of what
 * actually ran is a database.
 *
 * The file's name is kept because it is how a note is identified in output and
 * in the rendered prompt, and because it is what notes are *ordered* by: the
 * writer names them so they sort oldest-first, and a note a user drops in by
 * hand simply sorts where its name puts it.
 */
final class MemoryNote
{
    public function __construct(
        private readonly MemoryScope $scope,
        private readonly string $path,
        private readonly BoundedText $text,
    ) {
    }

    /**
     * Which task, or which project, the note belongs to.
     */
    public function scope(): MemoryScope
    {
        return $this->scope;
    }

    /**
     * Absolute path to the note's file.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The note's file name, which is what output and prompts call it.
     */
    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * The note's text, held to the budget.
     */
    public function text(): BoundedText
    {
        return $this->text;
    }

    /**
     * Characters of the note an attempt is shown.
     */
    public function length(): int
    {
        return $this->text->length();
    }

    /**
     * How the note is labelled where it is quoted: scope, then file name.
     */
    public function label(): string
    {
        return $this->scope->label() . '/' . $this->name();
    }
}
