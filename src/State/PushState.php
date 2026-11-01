<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * Where a generation's commit stands with the remote.
 *
 * Four answers, and the distinctions between them are what makes a failed
 * publication recoverable without repeating any work. PROJECT.md section H,
 * item 7 requires that resume can retry only the push; to do that it has to be
 * able to tell "this pass was never meant to push" from "this pass has a commit
 * waiting to go out" from "the push was attempted and refused". A boolean
 * cannot, and neither can a null.
 *
 * Pushing is opt-in, so {@see self::NotRequired} is the default and the common
 * case. It is *not* the same as {@see self::Published}: a task that completed
 * without a push and a task whose branch is on the remote are different facts
 * about the repository, and only one of them means a colleague can fetch the
 * work.
 *
 * Spellings are pinned: they are written into `generations.push_state`, which
 * the database constrains with {@see Schema::pushStates()}, built from these
 * cases so the two cannot drift.
 */
enum PushState: string
{
    /**
     * Publication is off, or this task's commit was never meant to leave the
     * machine.
     *
     * The default. A task can complete in this state: PROJECT.md makes a
     * successful push a condition of ticking a box only when a push is
     * required.
     */
    case NotRequired = 'not_required';

    /**
     * A push is required and has not succeeded yet.
     *
     * The state a pass sits in between committing and publishing, and the state
     * an interrupted run leaves behind. A task here is not complete, however
     * good its verification output was.
     */
    case Pending = 'pending';

    /**
     * The commit is on the remote.
     */
    case Published = 'published';

    /**
     * The push was attempted and did not succeed.
     *
     * Kept apart from {@see self::Pending} because the two call for different
     * things to be said to the user: one is work in progress, the other is a
     * rejection with a reason — a non-fast-forward, a missing remote, a refused
     * credential — that resume should report rather than silently retry
     * forever. The local commit is intact either way; nothing about a failed
     * push touches it.
     */
    case Failed = 'failed';

    /**
     * The state a pass starts in, given whether its configuration publishes.
     */
    public static function required(bool $enabled): self
    {
        return $enabled ? self::Pending : self::NotRequired;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The stored spellings, in this order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Read a state back from a column.
     *
     * @throws InvalidArgumentException when the stored value is not one of
     *                                 these, which means the database and this
     *                                 build disagree about publication and
     *                                 should not be guessed at
     */
    public static function fromStorage(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a push state; the stored value must be one of %s.',
            $value,
            implode(', ', self::values()),
        ));
    }

    /**
     * Whether publication is still owed.
     *
     * True for both {@see self::Pending} and {@see self::Failed}: a refused push
     * is still a push that has to happen before the task can be called done.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    /**
     * Whether publication is settled, so a task is not held back by it.
     */
    public function isSatisfied(): bool
    {
        return $this === self::NotRequired || $this === self::Published;
    }

    /**
     * Whether a push was attempted and refused.
     */
    public function hasFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Whether resume should retry the push and nothing before it.
     */
    public function needsRetry(): bool
    {
        return $this->isOutstanding();
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'not pushed',
            self::Pending => 'push pending',
            self::Published => 'pushed',
            self::Failed => 'push failed',
        };
    }

    /**
     * The same thing phrased for a sentence.
     */
    public function describe(): string
    {
        return match ($this) {
            self::NotRequired => 'publication is not enabled for this task',
            self::Pending => 'the commit has not been published yet',
            self::Published => 'the commit has been published to the remote',
            self::Failed => 'the push was attempted and did not succeed',
        };
    }
}
