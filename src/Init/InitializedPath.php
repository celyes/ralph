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
     * @param string|null $backupPath absolute path of the copy taken before the
     *        file was replaced; null unless the outcome is
     *        {@see InitializationOutcome::Replaced}
     */
    private function __construct(
        private readonly string $path,
        private readonly string $relativePath,
        private readonly InitializationOutcome $outcome,
        private readonly bool $directory,
        private readonly ?string $backupPath,
    ) {
    }

    /**
     * A file initialisation handled.
     *
     * Named constructors rather than a public constructor so the directory flag
     * cannot be passed the wrong way round, and so only a file can carry a
     * backup — there is nothing to back up about a directory the runner owns.
     */
    public static function file(
        string $path,
        string $relativePath,
        InitializationOutcome $outcome,
        ?string $backupPath = null,
    ): self {
        return new self($path, $relativePath, $outcome, false, $backupPath);
    }

    public static function directory(string $path, string $relativePath, InitializationOutcome $outcome): self
    {
        return new self($path, $relativePath, $outcome, true, null);
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

    public function wasReplaced(): bool
    {
        return $this->outcome === InitializationOutcome::Replaced;
    }

    /**
     * Where the previous content of a replaced file was copied, so output can
     * tell the user how to get it back.
     */
    public function backupPath(): ?string
    {
        return $this->backupPath;
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
