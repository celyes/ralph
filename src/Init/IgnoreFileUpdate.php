<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * What `ralph init` did about Git ignore rules, as data.
 *
 * Carries the rules themselves rather than just the outcome, because the
 * command has to name them: lines appended to a file the user owns are a change
 * to their project, and the user is the one who decides whether to keep them.
 */
final class IgnoreFileUpdate
{
    /**
     * @param string $path absolute path of the ignore file
     * @param string $relativePath path as shown to the user
     * @param list<string> $rules rules written, in file order — or, when
     *        nothing could be written, the rules the project still needs
     * @param string|null $reason why nothing was written, set only when the
     *        outcome is {@see IgnoreFileOutcome::Skipped}
     */
    public function __construct(
        private readonly string $path,
        private readonly string $relativePath,
        private readonly IgnoreFileOutcome $outcome,
        private readonly array $rules = [],
        private readonly ?string $reason = null,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function outcome(): IgnoreFileOutcome
    {
        return $this->outcome;
    }

    /**
     * The rules this update is about: the ones appended, or the ones the user
     * has to add by hand when the file could not be written.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Whether rules were written to the file.
     */
    public function wrote(): bool
    {
        return $this->outcome->wrote();
    }
}
