<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Support\Excerpt;
use InvalidArgumentException;
use JsonException;

/**
 * Why a pass stopped and needs a person, and what that person should do.
 *
 * The `blocker` column on a generation and on an attempt. PROJECT.md section H,
 * item 9 asks for two things whenever the loop stops short — a readable reason
 * and a documented next action — and this stores both, because a reason on its
 * own reliably becomes a user staring at "blocked: ambiguous branch ownership"
 * with no idea what is expected of them.
 *
 * `reportedBy` is the third part, and it is the one that stops a blocker being
 * misread. An agent asking for human input and the runner refusing to touch a
 * branch it does not own are both blockers, but only one of them is the
 * package's own judgement; attributing the agent's words to Ralph PHP — or the
 * reverse — is the kind of confusion that makes a tool untrustworthy.
 *
 * Stored as JSON in a single text column, following the schema's convention for
 * structured values: it is written and read whole and never queried into.
 * {@see self::fromStorage()} also reads a bare string, because a blocker
 * written before this type existed, or by a user editing a row, is still worth
 * showing rather than refusing.
 */
final class Blocker
{
    /**
     * Who the runner itself is, as a reporter.
     */
    public const string RUNNER = 'runner';

    /**
     * How much of a reason or an action is kept.
     *
     * A blocker is read in a status table and in a terminal, so what is stored
     * is what can be read there. An agent that writes an essay has it kept in
     * the task log, where length costs nothing.
     */
    public const int LIMIT = 2000;

    private function __construct(
        private readonly string $reason,
        private readonly string $nextAction,
        private readonly string $reportedBy,
    ) {
    }

    /**
     * A blocker the runner raised.
     *
     * @throws InvalidArgumentException when either half says nothing
     */
    public static function raised(string $reason, string $nextAction): self
    {
        return self::from(self::RUNNER, $reason, $nextAction);
    }

    /**
     * A blocker attributed to whoever reported it.
     *
     * @param string $reportedBy {@see self::RUNNER}, or the configured agent
     *                           name when the agent asked for human input
     *
     * @throws InvalidArgumentException when any part says nothing
     */
    public static function from(string $reportedBy, string $reason, string $nextAction): self
    {
        return new self(
            self::text($reason, 'reason'),
            self::text($nextAction, 'next action'),
            self::text($reportedBy, 'reporter'),
        );
    }

    /**
     * Read the column back.
     *
     * @throws InvalidArgumentException when a present value has no reason in it
     */
    public static function recorded(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::fromStorage($value);
    }

    /**
     * Read a stored blocker, in either of the forms a column can hold.
     *
     * @throws InvalidArgumentException when there is no reason to show
     */
    public static function fromStorage(string $value): self
    {
        $text = trim($value);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not JSON: a plain reason, with nothing recorded about what to do
            // about it. Said so, rather than invented.
            return new self(self::text($text, 'reason'), self::unknownAction(), self::RUNNER);
        }

        // A list is JSON, and is not one of these: `array_is_list()` is what
        // separates the object this writes from `["it broke"]`, which `is_array`
        // alone would send into the branch below to be refused for having no
        // `reason` key. Read as prose instead, like any other JSON that is not
        // an object.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return new self(self::text($text, 'reason'), self::unknownAction(), self::RUNNER);
        }

        $reason = isset($decoded['reason']) && is_string($decoded['reason']) ? $decoded['reason'] : '';
        $action = isset($decoded['next_action']) && is_string($decoded['next_action'])
            ? $decoded['next_action']
            : self::unknownAction();
        $reporter = isset($decoded['reported_by']) && is_string($decoded['reported_by'])
            ? $decoded['reported_by']
            : self::RUNNER;

        return new self(
            self::text($reason, 'reason'),
            self::text($action, 'next action'),
            self::text($reporter, 'reporter'),
        );
    }

    /**
     * Why the pass stopped.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * What the user is expected to do about it.
     */
    public function nextAction(): string
    {
        return $this->nextAction;
    }

    /**
     * Who raised it: {@see self::RUNNER} or the agent's configured name.
     */
    public function reportedBy(): string
    {
        return $this->reportedBy;
    }

    /**
     * Whether this is the package's own judgement rather than the agent's
     * request.
     */
    public function isFromRunner(): bool
    {
        return $this->reportedBy === self::RUNNER;
    }

    /**
     * A single short line for a status table.
     *
     * The line breaks are collapsed before the reason is shortened, because an
     * agent's request for human input arrives as prose and a status table that
     * printed it verbatim would have one row spill over several lines. A reason
     * long enough to be worth reading whole is read with
     * {@see self::describe()} or out of the task log.
     */
    public function label(): string
    {
        return Excerpt::of((string) preg_replace('/\s+/u', ' ', $this->reason));
    }

    /**
     * The whole thing, for the end of a run.
     */
    public function describe(): string
    {
        return sprintf('%s (reported by %s) Next: %s', $this->reason, $this->reportedBy, $this->nextAction);
    }

    /**
     * What the column holds.
     */
    public function toStorage(): string
    {
        return json_encode([
            'reason' => $this->reason,
            'next_action' => $this->nextAction,
            'reported_by' => $this->reportedBy,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function equals(self $other): bool
    {
        return $this->reason === $other->reason
            && $this->nextAction === $other->nextAction
            && $this->reportedBy === $other->reportedBy;
    }

    /**
     * What is said when a stored blocker has no next action recorded.
     *
     * Under-claims on purpose: inventing an action for a blocker nobody
     * described is how a user ends up doing the wrong thing confidently.
     */
    private static function unknownAction(): string
    {
        return 'No next action was recorded; read the task log for what the pass was doing.';
    }

    /**
     * @throws InvalidArgumentException when the text says nothing
     */
    private static function text(string $value, string $what): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('A blocker must state its %s.', $what));
        }

        return mb_strlen($trimmed) <= self::LIMIT ? $trimmed : mb_substr($trimmed, 0, self::LIMIT) . '…';
    }
}
