<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * A clock that does not move unless it is told to.
 *
 * For tests, and for the one production case that wants it: a single write that
 * sets several timestamp columns should set them to the same moment, so that a
 * row does not claim to have been updated a second after it was created when
 * both happened in one statement.
 *
 * Not immutable, deliberately — {@see self::advance()} moves this clock rather
 * than returning another one, because the point of holding one is that
 * everything reading it sees the same time.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $moment;

    public function __construct(DateTimeImmutable $moment)
    {
        $this->moment = $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A clock stopped at a point written as text.
     *
     * @throws InvalidArgumentException when the text is not a moment PHP can
     *                                 read
     */
    public static function at(string $moment): self
    {
        try {
            return new self(new DateTimeImmutable($moment, new DateTimeZone('UTC')));
        } catch (Exception $failure) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a moment a clock can be stopped at.', $moment),
                0,
                $failure,
            );
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }

    /**
     * Move the clock forward.
     *
     * @throws InvalidArgumentException when asked to move backwards, which no
     *                                 caller means and which would produce a
     *                                 history that cannot have happened
     */
    public function advance(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A clock is advanced forwards; use at() to set it back.');
        }

        $this->moment = $this->moment->modify(sprintf('+%d seconds', $seconds));

        return $this;
    }
}
