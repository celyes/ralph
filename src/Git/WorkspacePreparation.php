<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * What was done to a task workspace to make the project usable in it.
 *
 * Returned once preparation has succeeded, so every step it names ran and every
 * file it names is in place. A failure is a
 * {@see \Celyes\RalphPhp\Exception\PreparationException} instead: a partially
 * prepared workspace is not a result the runner should be able to carry on with.
 *
 * Two lists of files rather than one, because the difference matters later.
 * {@see self::copied()} is what preparation put in the workspace, and those
 * files are untracked and must stay out of the task's commit — they are the
 * local configuration and the credentials PROJECT.md section F, item 6 excludes
 * from staging by name, and this is the record that says which paths they are.
 * {@see self::alreadyPresent()} is what was found and left alone, which in an
 * adopted workspace may be a file the agent has edited.
 */
final class WorkspacePreparation
{
    /**
     * @param list<string> $copied paths copied in, relative to the workspace root
     * @param list<string> $alreadyPresent paths that were already there and were left untouched
     * @param list<PreparationStep> $steps commands that ran, in order
     */
    public function __construct(
        private readonly string $path,
        private readonly array $copied = [],
        private readonly array $alreadyPresent = [],
        private readonly array $steps = [],
    ) {
    }

    /**
     * A preparation that had nothing to do, for a project that configures
     * neither commands nor copies.
     */
    public static function nothingToDo(string $path): self
    {
        return new self($path);
    }

    /**
     * The workspace this was done to.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Files copied in from the controlling checkout, relative to the workspace
     * root.
     *
     * @return list<string>
     */
    public function copied(): array
    {
        return $this->copied;
    }

    /**
     * Configured files that were already in the workspace and were left as they
     * were, relative to the workspace root.
     *
     * @return list<string>
     */
    public function alreadyPresent(): array
    {
        return $this->alreadyPresent;
    }

    /**
     * The commands that ran, in configured order.
     *
     * @return list<PreparationStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Whether preparation did anything at all.
     */
    public function isEmpty(): bool
    {
        return $this->copied === [] && $this->alreadyPresent === [] && $this->steps === [];
    }

    /**
     * Seconds spent running preparation commands. Not the whole of preparation
     * — copying is not timed — but it is the part that takes minutes, and the
     * part worth charging to the task's own budget rather than to the agent.
     */
    public function seconds(): float
    {
        $seconds = 0.0;

        foreach ($this->steps as $step) {
            $seconds += $step->result()->seconds();
        }

        return $seconds;
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return sprintf('%s needed no preparation', $this->path);
        }

        $parts = [];

        if ($this->steps !== []) {
            $parts[] = sprintf(
                '%d %s in %.1fs',
                count($this->steps),
                count($this->steps) === 1 ? 'command' : 'commands',
                $this->seconds(),
            );
        }

        if ($this->copied !== []) {
            $parts[] = sprintf('%d file(s) copied in: %s', count($this->copied), implode(', ', $this->copied));
        }

        if ($this->alreadyPresent !== []) {
            $parts[] = sprintf('%d already present: %s', count($this->alreadyPresent), implode(', ', $this->alreadyPresent));
        }

        return sprintf('%s prepared: %s', $this->path, implode('; ', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'copied' => $this->copied,
            'already_present' => $this->alreadyPresent,
            'steps' => array_map(
                static fn (PreparationStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
