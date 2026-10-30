<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * A task's internal identifier: `TASK-` and eight hexadecimal digits.
 *
 * Derived from the task's normalised body ({@see TaskBody}) and from nothing
 * else — not the checkbox, not the line, not the file — so the same task keeps
 * the same identifier when the checklist is reordered, when a task above it is
 * ticked, when the file is renamed, and when a finished task is reopened. That
 * is what makes the state store's rows, the task branch, the log directory and
 * `ralph logs TASK-ID` still refer to the same work on the next run.
 *
 * The derivation is a promise, not an implementation detail: identifiers are
 * written into Git branches, commit messages and a SQLite database that outlive
 * the process. `TASK-` + the first eight hexadecimal digits of the SHA-256 of
 * the normalised body, uppercased. Changing that recipe orphans every stored
 * task, so it is pinned by a test with literal expected values rather than by
 * this comment alone.
 *
 * Eight digits is a deliberate trade. Thirty-two bits is short enough to read
 * out, type into `ralph logs` and put in a branch name, and leaves a collision
 * chance around one in a million for a checklist of a hundred tasks. The
 * remaining case is not papered over: {@see TaskIdentifier} refuses a checklist
 * where two different bodies produce one identifier instead of quietly treating
 * them as one task.
 *
 * Identifiers are generated, never user-written, so there is no need to make
 * them pronounceable or to avoid confusable digits; what matters is that they
 * are stable, short, and obviously not prose when they appear in output.
 */
final class TaskId
{
    /**
     * What every identifier starts with.
     *
     * Present so an identifier is recognisable on its own — in a branch name, a
     * log path or a commit message — rather than looking like a truncated SHA.
     */
    private const string PREFIX = 'TASK-';

    /**
     * How many hexadecimal digits follow the prefix.
     */
    private const int DIGITS = 8;

    /**
     * A well-formed identifier, in canonical form.
     */
    private const string PATTERN = '/^TASK-[0-9A-F]{8}$/';

    private function __construct(private readonly string $value)
    {
    }

    /**
     * The identifier of a normalised task body.
     *
     * @throws InvalidArgumentException when the body is empty, which has no
     *                                  identity to compute
     */
    public static function fromBody(TaskBody $body): self
    {
        if ($body->isEmpty()) {
            throw new InvalidArgumentException('A task with no description has no identifier.');
        }

        $digest = hash('sha256', $body->normalized());

        return new self(self::PREFIX . strtoupper(substr($digest, 0, self::DIGITS)));
    }

    /**
     * The identifier of a task description, normalising it first.
     *
     * @throws InvalidArgumentException when the description is not valid UTF-8
     *                                 or says nothing
     */
    public static function fromDescription(string $description): self
    {
        return self::fromBody(TaskBody::of($description));
    }

    /**
     * Read an identifier back from stored or typed text.
     *
     * Surrounding whitespace and letter case are forgiven, because this is what
     * a user pastes into `ralph logs` after copying it out of a status table;
     * anything else is rejected rather than guessed at, so a mistyped
     * identifier reports itself instead of selecting the wrong task.
     *
     * @throws InvalidArgumentException when the text is not an identifier
     */
    public static function fromString(string $value): self
    {
        $candidate = strtoupper(trim($value));

        if (preg_match(self::PATTERN, $candidate) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a task identifier; expected a value like "TASK-19E0214A".', $value),
            );
        }

        return new self($candidate);
    }

    /**
     * Whether text can be read as an identifier.
     *
     * For callers that have to tell a user their input was wrong in their own
     * words — the CLI turns this into a usage error rather than an exception.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, strtoupper(trim($value))) === 1;
    }

    /**
     * The identifier in its canonical form.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Whether two identifiers name the same task.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
