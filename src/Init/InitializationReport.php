<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * What one `ralph init` did, as data.
 *
 * The initializer returns this instead of printing, so the same run can be
 * asserted in tests and rendered by the command — and later by the structured
 * event output in PROJECT.md section I.
 */
final class InitializationReport
{
    /**
     * @param string $projectRoot absolute path that was initialised
     * @param list<InitializedPath> $runtimeDirectories in creation order
     * @param list<string> $unreferencedPaths paths, as shown to the user, that
     *        the replaced configuration pointed at and the written one does not
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly InitializationMode $mode,
        private readonly InitializedPath $configuration,
        private readonly InitializedPath $tasks,
        private readonly InitializedPath $context,
        private readonly array $runtimeDirectories,
        private readonly array $unreferencedPaths = [],
    ) {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Whether existing files were preserved or reset to the templates.
     */
    public function mode(): InitializationMode
    {
        return $this->mode;
    }

    /**
     * The three files are named individually because callers have to refer to
     * them: the paths an initialised project uses are the configured ones, not
     * the package defaults, so output that tells the user where to write their
     * tasks has to read them from here.
     */
    public function configuration(): InitializedPath
    {
        return $this->configuration;
    }

    public function tasks(): InitializedPath
    {
        return $this->tasks;
    }

    public function context(): InitializedPath
    {
        return $this->context;
    }

    /**
     * @return list<InitializedPath>
     */
    public function runtimeDirectories(): array
    {
        return $this->runtimeDirectories;
    }

    /**
     * Every path initialisation is responsible for, in the order handled.
     *
     * @return list<InitializedPath>
     */
    public function paths(): array
    {
        return [$this->configuration, $this->tasks, $this->context, ...$this->runtimeDirectories];
    }

    /**
     * @return list<InitializedPath>
     */
    public function created(): array
    {
        return $this->matching(static fn (InitializedPath $path): bool => $path->wasCreated());
    }

    /**
     * Paths that already existed and were left alone.
     *
     * @return list<InitializedPath>
     */
    public function kept(): array
    {
        return $this->matching(static fn (InitializedPath $path): bool => $path->wasKept());
    }

    /**
     * Files whose previous content was written over, each carrying the backup
     * it was copied to.
     *
     * @return list<InitializedPath>
     */
    public function replaced(): array
    {
        return $this->matching(static fn (InitializedPath $path): bool => $path->wasReplaced());
    }

    /**
     * Files left on disk that nothing points at any more.
     *
     * Replacing the configuration returns the checklist and context paths to
     * the defaults, so a project that had configured them elsewhere keeps those
     * files — initialisation does not delete user content — but the run that
     * follows will not read them. Saying so is the difference between a stale
     * file and a silently ignored task list.
     *
     * @return list<string>
     */
    public function unreferencedPaths(): array
    {
        return $this->unreferencedPaths;
    }

    /**
     * @param callable(InitializedPath): bool $predicate
     *
     * @return list<InitializedPath>
     */
    private function matching(callable $predicate): array
    {
        return array_values(array_filter($this->paths(), $predicate));
    }
}
