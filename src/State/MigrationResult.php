<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

/**
 * What bringing a database up to date actually did.
 *
 * Reported rather than printed, so the same answer serves `ralph status`, the
 * tests, and a run that only wants to know whether it is looking at a database
 * that already existed. The common case is {@see self::isUnchanged()}: a
 * project that has run before opens its database and nothing happens, and that
 * is worth being able to state positively.
 */
final class MigrationResult
{
    /**
     * @param list<Migration> $applied in the order they were applied
     */
    public function __construct(
        private readonly int $from,
        private readonly int $to,
        private readonly array $applied,
    ) {
    }

    /**
     * The version the database recorded before, zero for an empty one.
     */
    public function from(): int
    {
        return $this->from;
    }

    /**
     * The version it records now.
     */
    public function to(): int
    {
        return $this->to;
    }

    /**
     * @return list<Migration>
     */
    public function applied(): array
    {
        return $this->applied;
    }

    /**
     * Whether anything was applied.
     */
    public function isUnchanged(): bool
    {
        return $this->applied === [];
    }

    /**
     * Whether this built the schema from nothing.
     */
    public function isInitial(): bool
    {
        return $this->from === 0 && $this->applied !== [];
    }

    /**
     * How it reads in command output.
     */
    public function describe(): string
    {
        if ($this->isUnchanged()) {
            return sprintf('schema version %d, already up to date', $this->to);
        }

        if ($this->isInitial()) {
            return sprintf('created the schema at version %d', $this->to);
        }

        return sprintf(
            'migrated the schema from version %d to %d (%s)',
            $this->from,
            $this->to,
            implode(', ', array_map(
                static fn (Migration $migration): string => $migration->describe(),
                $this->applied,
            )),
        );
    }
}
