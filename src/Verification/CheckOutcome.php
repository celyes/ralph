<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

/**
 * How one verification command finished.
 *
 * The four values of `verification_results.outcome`, as something other than a
 * string literal. `not_run` is the one that needs saying out loud: the checks
 * are a sequence that stops at the first failure, and a check that never ran
 * must not be stored as a passing one — that is the difference between "check 3
 * failed" and "check 3 was never reached", and the whole reason the rows carry
 * a position.
 *
 * A timeout is kept apart from a failure for the same reason the state store
 * keeps them apart on an attempt: a check that was killed at its limit says
 * nothing about whether the work is correct, and the next attempt is told a
 * different thing about it.
 */
enum CheckOutcome: string
{
    /**
     * The command exited zero within its timeout.
     */
    case Passed = 'passed';

    /**
     * The command ran to completion and exited non-zero.
     */
    case Failed = 'failed';

    /**
     * The command was still running when its timeout expired, and was killed.
     */
    case TimedOut = 'timed_out';

    /**
     * The command was never started, because an earlier one in the sequence
     * failed.
     */
    case NotRun = 'not_run';

    /**
     * The stored values, in sequence order.
     *
     * What the schema's CHECK constraint is built from, so the DDL and this
     * enum cannot drift apart.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether this check is behind the work rather than against it.
     */
    public function isPassed(): bool
    {
        return $this === self::Passed;
    }

    /**
     * Whether this check decided the work does not pass.
     *
     * A timeout counts: the check did not pass, and a run that treated an
     * unfinished check as a satisfied one would commit on the strength of a
     * command that never answered.
     */
    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::TimedOut;
    }

    /**
     * Whether the command was started at all.
     */
    public function ran(): bool
    {
        return $this !== self::NotRun;
    }

    /**
     * Whether the sequence stops here.
     */
    public function stopsTheSequence(): bool
    {
        return $this->isFailure();
    }

    /**
     * One word for a status line.
     */
    public function label(): string
    {
        return match ($this) {
            self::Passed => 'passed',
            self::Failed => 'failed',
            self::TimedOut => 'timed out',
            self::NotRun => 'not run',
        };
    }

    /**
     * One phrase for a log line or a failure note.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Passed => 'passed',
            self::Failed => 'failed',
            self::TimedOut => 'was killed at its timeout',
            self::NotRun => 'was not reached, because an earlier check failed',
        };
    }
}
