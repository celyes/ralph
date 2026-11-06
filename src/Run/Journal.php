<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\State\AttemptStore;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\RunStore;
use Celyes\RalphPhp\State\StateStore;
use Celyes\RalphPhp\State\TaskStore;
use Celyes\RalphPhp\State\VerificationStore;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;

/**
 * The five stores the loop writes through, over one database.
 *
 * Nothing but a grouping. It exists because the loop and the pass each need all
 * of them, they must all share one connection and one clock — a run whose
 * attempt rows were timed by a different clock than its generations would be
 * unreadable — and because a constructor listing five stores separately invites
 * exactly that mistake.
 */
final class Journal
{
    public function __construct(
        private readonly Database $database,
        private readonly RunStore $runs,
        private readonly TaskStore $tasks,
        private readonly GenerationStore $generations,
        private readonly AttemptStore $attempts,
        private readonly VerificationStore $verifications,
    ) {
    }

    /**
     * The stores over an open database.
     */
    public static function over(Database $database, ?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return new self(
            $database,
            new RunStore($database, $clock),
            new TaskStore($database, $clock),
            new GenerationStore($database, $clock),
            new AttemptStore($database, $clock),
            new VerificationStore($database, $clock),
        );
    }

    /**
     * The stores over a state store the bootstrap has opened and migrated.
     */
    public static function of(StateStore $state, ?Clock $clock = null): self
    {
        return self::over($state->database(), $clock);
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function runs(): RunStore
    {
        return $this->runs;
    }

    public function tasks(): TaskStore
    {
        return $this->tasks;
    }

    public function generations(): GenerationStore
    {
        return $this->generations;
    }

    public function attempts(): AttemptStore
    {
        return $this->attempts;
    }

    public function verifications(): VerificationStore
    {
        return $this->verifications;
    }
}
