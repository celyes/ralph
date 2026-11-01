<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use InvalidArgumentException;

/**
 * The publication half of a generation's record: its state, its remote, and
 * why it failed if it did.
 *
 * Three columns that are only ever meaningful together, so they are read and
 * written as one value rather than passed around as a loose triple. The
 * combinations that cannot be true are refused here instead of being stored and
 * puzzled over later: a failure with no reason given, a reason attached to a
 * success, a remote named by a pass that was never going to push.
 *
 * The reason for a failure is kept because of what resume is supposed to do
 * with it. A non-fast-forward, a remote that does not exist and a refused
 * credential all leave the local commit intact and all need a different thing
 * done about them, and a run that reports only "push failed" leaves the user to
 * re-run the push by hand to find out which it was. It is an excerpt, not a
 * transcript: the full output belongs in the task's log.
 */
final class PushResult
{
    /**
     * How much of a Git failure is kept in the database.
     *
     * Long enough for the lines Git actually explains a rejection in, short
     * enough that a status query stays cheap. Truncation is marked, so an
     * excerpt is never mistaken for the whole message.
     */
    public const int REASON_LIMIT = 2000;

    private function __construct(
        private readonly PushState $state,
        private readonly ?string $remote,
        private readonly ?string $error,
    ) {
    }

    /**
     * Publication is off for this pass.
     */
    public static function notRequired(): self
    {
        return new self(PushState::NotRequired, null, null);
    }

    /**
     * A push is owed to a remote and has not been attempted yet.
     *
     * @throws InvalidArgumentException when the remote is not named
     */
    public static function pending(string $remote): self
    {
        return new self(PushState::Pending, self::named($remote), null);
    }

    /**
     * The commit reached the remote.
     *
     * @throws InvalidArgumentException when the remote is not named
     */
    public static function published(string $remote): self
    {
        return new self(PushState::Published, self::named($remote), null);
    }

    /**
     * The push was attempted and refused.
     *
     * @throws InvalidArgumentException when the remote is not named or no
     *                                 reason is given, since "it failed" with
     *                                 nothing after it is the one failure
     *                                 report that helps nobody
     */
    public static function failed(string $remote, string $error): self
    {
        $reason = trim($error);

        if ($reason === '') {
            throw new InvalidArgumentException('A failed push must record why it failed.');
        }

        return new self(PushState::Failed, self::named($remote), self::excerpt($reason));
    }

    /**
     * The result a configuration implies before anything has been attempted.
     */
    public static function expected(bool $enabled, string $remote): self
    {
        return $enabled ? self::pending($remote) : self::notRequired();
    }

    /**
     * Read the three columns back.
     *
     * @throws InvalidArgumentException when the row holds a combination this
     *                                 class never writes
     */
    public static function fromStorage(string $state, ?string $remote, ?string $error): self
    {
        $push = PushState::fromStorage($state);

        if ($push === PushState::NotRequired) {
            return self::notRequired();
        }

        if ($remote === null || trim($remote) === '') {
            throw new InvalidArgumentException(sprintf(
                'A stored push in state "%s" names no remote.',
                $state,
            ));
        }

        if ($push === PushState::Failed) {
            return self::failed($remote, $error ?? 'The reason was not recorded.');
        }

        return new self($push, trim($remote), null);
    }

    public function state(): PushState
    {
        return $this->state;
    }

    /**
     * The remote this pass publishes to, when it publishes.
     */
    public function remote(): ?string
    {
        return $this->remote;
    }

    /**
     * Why the push failed, when it did.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Whether publication is still owed before the task can be called done.
     */
    public function isOutstanding(): bool
    {
        return $this->state->isOutstanding();
    }

    /**
     * Whether publication no longer holds the task back.
     */
    public function isSatisfied(): bool
    {
        return $this->state->isSatisfied();
    }

    /**
     * Whether a push was attempted and refused.
     */
    public function hasFailed(): bool
    {
        return $this->state->hasFailed();
    }

    /**
     * The same pass, having published successfully.
     *
     * Returns a new value rather than mutating, so the record of the attempt
     * that failed is still readable by whatever is reporting on it.
     *
     * @throws InvalidArgumentException when publication was never required, in
     *                                 which case there is nothing that could
     *                                 have been pushed
     */
    public function succeeded(): self
    {
        if ($this->remote === null) {
            throw new InvalidArgumentException(
                'A push that was not required cannot have succeeded; record the remote first.',
            );
        }

        return self::published($this->remote);
    }

    /**
     * The same pass, having been refused.
     *
     * @throws InvalidArgumentException when publication was never required, or
     *                                 no reason is given
     */
    public function refused(string $error): self
    {
        if ($this->remote === null) {
            throw new InvalidArgumentException(
                'A push that was not required cannot have failed; record the remote first.',
            );
        }

        return self::failed($this->remote, $error);
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return $this->state->label();
    }

    /**
     * One line for a status line or a log header.
     */
    public function describe(): string
    {
        return match (true) {
            $this->state === PushState::NotRequired => $this->state->describe(),
            $this->error !== null => sprintf(
                'push to "%s" failed: %s',
                $this->remote,
                $this->firstLine($this->error),
            ),
            default => sprintf('%s "%s"', $this->state->label(), $this->remote),
        };
    }

    /**
     * The three values a row holds, ready to bind.
     *
     * @return array{state: string, remote: string|null, error: string|null}
     */
    public function toColumns(): array
    {
        return [
            'state' => $this->state->value,
            'remote' => $this->remote,
            'error' => $this->error,
        ];
    }

    /**
     * @throws InvalidArgumentException when the remote is blank
     */
    private static function named(string $remote): string
    {
        $name = trim($remote);

        if ($name === '') {
            throw new InvalidArgumentException('A push records the remote it is aimed at.');
        }

        return $name;
    }

    private static function excerpt(string $reason): string
    {
        if (mb_strlen($reason) <= self::REASON_LIMIT) {
            return $reason;
        }

        return mb_substr($reason, 0, self::REASON_LIMIT) . "\n… truncated; see the task log for the full output.";
    }

    private function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? $text : trim($line);
    }
}
