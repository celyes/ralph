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
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly InitializedPath $configuration,
        private readonly InitializedPath $tasks,
        private readonly InitializedPath $context,
        private readonly array $runtimeDirectories,
    ) {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
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
        return array_values(array_filter(
            $this->paths(),
            static fn (InitializedPath $path): bool => $path->wasCreated(),
        ));
    }

    /**
     * Paths that already existed and were left alone.
     *
     * @return list<InitializedPath>
     */
    public function kept(): array
    {
        return array_values(array_filter(
            $this->paths(),
            static fn (InitializedPath $path): bool => $path->wasKept(),
        ));
    }
}
