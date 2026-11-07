<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * What stopping a worker actually achieved.
 *
 * The confirmation half of PROJECT.md section H, item 4: ownership is released
 * "only after termination is confirmed", so something has to be able to say
 * that it was not. Three answers, and they are genuinely different:
 *
 * - **Confirmed.** Every process signalled is gone. The worktree is nobody's,
 *   the repository lock can be released, and a resume may start.
 * - **Survivors.** Something is still running after a kill. Whatever it is
 *   holding, it is holding now, and the lock must stay claimed.
 * - **Unknowable.** This system offers no way to look a process up, so the
 *   honest answer is that termination was requested and not that it happened.
 *   Treated as unconfirmed, because the cost of being wrong in that direction
 *   is a lock a user clears by hand, and in the other it is two agents in one
 *   worktree.
 */
final class Termination
{
    /**
     * @param list<int> $signalled every process the stop was sent to
     * @param list<int> $survivors those still running when it was given up on
     */
    private function __construct(
        private readonly array $signalled,
        private readonly array $survivors,
        private readonly bool $knowable,
        private readonly bool $killed,
    ) {
    }

    /**
     * Nothing was running, so nothing had to be stopped.
     *
     * Confirmed, and the commonest case by a long way: a command that has
     * already exited when the interruption is noticed.
     */
    public static function nothingToStop(): self
    {
        return new self([], [], true, false);
    }

    /**
     * Every process signalled has gone.
     *
     * @param list<int> $signalled
     * @param bool      $killed    whether the grace period ran out and the
     *        tree had to be killed rather than asked
     */
    public static function confirmed(array $signalled, bool $killed = false): self
    {
        return new self($signalled, [], true, $killed);
    }

    /**
     * Something signalled is still running.
     *
     * @param list<int> $signalled
     * @param list<int> $survivors
     */
    public static function survived(array $signalled, array $survivors): self
    {
        return new self($signalled, $survivors, true, true);
    }

    /**
     * The stop was sent and this system cannot say whether it worked.
     *
     * @param list<int> $signalled
     */
    public static function unconfirmable(array $signalled, bool $killed = false): self
    {
        return new self($signalled, [], false, $killed);
    }

    /**
     * Whether everything that was asked to stop has stopped.
     */
    public function isConfirmed(): bool
    {
        return $this->knowable && $this->survivors === [];
    }

    /**
     * @return list<int>
     */
    public function signalled(): array
    {
        return $this->signalled;
    }

    /**
     * Processes still running, which is what a lock record has to name.
     *
     * Empty for an unconfirmable termination, which is why
     * {@see self::isConfirmed()} and an empty survivor list are not the same
     * question.
     *
     * @return list<int>
     */
    public function survivors(): array
    {
        return $this->survivors;
    }

    /**
     * Whether a termination signal was not enough and the tree was killed.
     *
     * Worth recording separately: an agent that has to be killed had no chance
     * to finish writing a file, so a half-written file in the workspace is
     * expected rather than suspicious.
     */
    public function wasKilled(): bool
    {
        return $this->killed;
    }

    public function isKnowable(): bool
    {
        return $this->knowable;
    }

    /**
     * One line for a log note or a terminal.
     */
    public function describe(): string
    {
        if ($this->signalled === []) {
            return 'nothing was running to stop';
        }

        $what = sprintf(
            '%d process%s %s',
            count($this->signalled),
            count($this->signalled) === 1 ? '' : 'es',
            $this->killed ? 'killed' : 'stopped',
        );

        if ($this->survivors !== []) {
            return sprintf(
                '%s, and %s still running (%s)',
                $what,
                count($this->survivors) === 1 ? 'one is' : count($this->survivors) . ' are',
                implode(', ', $this->survivors),
            );
        }

        return $this->knowable
            ? $what . ', confirmed stopped'
            : $what . '; whether they stopped cannot be confirmed on this system';
    }
}
