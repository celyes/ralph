<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Memory;

/**
 * The project's instructions file, as an attempt receives it.
 *
 * Three states, not two, and the third is why this is a type rather than a
 * string: present, present but empty, and absent. They need different words in
 * output — a project that has no context file is a project the user has not
 * written one for, while a file that is there and says nothing is usually a
 * template nobody filled in, and both are worth saying out loud before an agent
 * is sent off without any conventions to follow.
 */
final class ProjectContext
{
    private function __construct(
        private readonly string $path,
        private readonly ?BoundedText $text,
    ) {
    }

    /**
     * No context file at the configured path.
     */
    public static function absent(string $path): self
    {
        return new self($path, null);
    }

    /**
     * A context file that was read.
     */
    public static function of(string $path, BoundedText $text): self
    {
        return new self($path, $text);
    }

    /**
     * Where the file was read from, or expected.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function isPresent(): bool
    {
        return $this->text !== null;
    }

    /**
     * Whether there are no instructions to pass on, whether because the file is
     * missing or because it says nothing.
     */
    public function isEmpty(): bool
    {
        return $this->text === null || $this->text->isEmpty();
    }

    /**
     * The instructions, or an empty string when there are none.
     */
    public function text(): string
    {
        return $this->text?->text() ?? '';
    }

    /**
     * Characters of context an attempt is shown.
     */
    public function length(): int
    {
        return $this->text?->length() ?? 0;
    }

    public function wasTrimmed(): bool
    {
        return $this->text?->wasTrimmed() ?? false;
    }

    /**
     * A sentence saying what the budget left out, or null when nothing was.
     */
    public function notice(): ?string
    {
        return $this->text?->notice();
    }
}
