<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * One path `ralph init` is responsible for, and what happened to it.
 */
final class InitializedPath
{
    /**
     * @param string $path absolute path
     * @param string $relativePath path as shown to the user, relative to the project root
     */
    public function __construct(
        private readonly string $path,
        private readonly string $relativePath,
        private readonly InitializationOutcome $outcome,
        private readonly bool $directory,
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

    public function outcome(): InitializationOutcome
    {
        return $this->outcome;
    }

    public function isDirectory(): bool
    {
        return $this->directory;
    }

    public function wasCreated(): bool
    {
        return $this->outcome === InitializationOutcome::Created;
    }

    public function wasKept(): bool
    {
        return $this->outcome === InitializationOutcome::Kept;
    }

    /**
     * How the path is shown in output: directories carry a trailing separator,
     * so an empty runtime directory is not mistaken for a file.
     */
    public function label(): string
    {
        return $this->directory ? $this->relativePath . '/' : $this->relativePath;
    }
}
