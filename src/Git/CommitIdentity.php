<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use InvalidArgumentException;

/**
 * What a commit says about itself, read back out of its message.
 *
 * The reading half of {@see CommitMessage}, and the reason that class puts the
 * identifiers in the commit at all. PROJECT.md section H, item 5 names the
 * recovery case this exists for: a run that crashed between making a commit and
 * recording its SHA leaves the commit in the repository and nothing in the
 * state database pointing at it. The branch tip is the only place it is still
 * named, and the only way to know whether that tip is *this pass's* commit —
 * rather than a stranger's, or an earlier pass's — is to read what the commit
 * says it is.
 *
 * **Only the last paragraph is read, and only when it is all trailers.** That
 * is Git's own rule for a trailer block, and following it is what keeps a task
 * description from forging one. A description is flattened to a single line
 * before it reaches a message ({@see CommitMessage}), so one that happens to
 * read `Ralph-Task: TASK-FAKE0001` becomes a line of the message body — and the
 * body is never the last paragraph of a message that carries trailers, because
 * the trailers are written after it. A message whose *only* paragraph looks
 * like trailers is read as having none, for the same reason: this package never
 * writes one, so anything of that shape was written by somebody else.
 *
 * A reading, not a judgement. Whether an identified commit may be adopted into
 * a pass's row is {@see \Celyes\RalphPhp\Run\PassRecovery}'s decision, and it
 * needs more than the message to make it.
 */
final class CommitIdentity
{
    /**
     * Characters of a commit id shown when one is quoted to a person.
     */
    public const int SHORT_LENGTH = BaseCommit::SHORT_LENGTH;

    /**
     * What a line of a trailer block looks like.
     *
     * `Key: value`, with the key limited to what Git will parse as one. A
     * continuation line — Git allows a trailer's value to be wrapped onto an
     * indented line below it — is matched by the second branch, so a wrapped
     * value does not make the whole block unreadable.
     */
    private const string TRAILER_LINE = '/^(?:[A-Za-z0-9][A-Za-z0-9-]*:(?:[ \t].*)?|[ \t]+\S.*)$/';

    /**
     * A trailer, as a key and the rest of the line.
     */
    private const string TRAILER = '/^([A-Za-z0-9][A-Za-z0-9-]*):[ \t]*(.*)$/';

    /**
     * @param array<string, string> $trailers the message's own trailers, keyed
     *        as they were written
     */
    private function __construct(
        private readonly string $sha,
        private readonly string $subject,
        private readonly array $trailers,
    ) {
    }

    /**
     * One commit, as its SHA and its whole message.
     *
     * @throws InvalidArgumentException when the SHA says nothing
     */
    public static function of(string $sha, string $message): self
    {
        $commit = trim($sha);

        if ($commit === '') {
            throw new InvalidArgumentException('A commit identity must say which commit it is of.');
        }

        $text = str_replace("\r\n", "\n", $message);
        $paragraphs = self::paragraphsIn($text);

        return new self(
            $commit,
            $paragraphs === [] ? '' : trim(explode("\n", $paragraphs[0])[0]),
            self::trailersIn($paragraphs),
        );
    }

    /**
     * The commit this is about.
     */
    public function sha(): string
    {
        return $this->sha;
    }

    /**
     * The commit as it is quoted to a person.
     */
    public function shortSha(): string
    {
        return substr($this->sha, 0, self::SHORT_LENGTH);
    }

    /**
     * The commit's first line.
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * Every trailer the message's trailer block holds.
     *
     * The project's own trailers are in here too, not only the `Ralph-`
     * prefixed ones: a reader deciding whether a commit is this package's wants
     * to be able to see what else it says.
     *
     * @return array<string, string>
     */
    public function trailers(): array
    {
        return $this->trailers;
    }

    /**
     * One trailer's value, or null when the message does not carry it.
     */
    public function trailer(string $key): ?string
    {
        return $this->trailers[$key] ?? null;
    }

    /**
     * The task the commit names, or null when it names none.
     */
    public function task(): ?string
    {
        return $this->trailer(CommitMessage::TASK);
    }

    /**
     * Which pass at the task the commit names, or null when it names none or
     * names something that is not a pass number.
     */
    public function generation(): ?int
    {
        return $this->number(CommitMessage::GENERATION);
    }

    /**
     * The run that made the commit, or null when the message does not say.
     */
    public function run(): ?int
    {
        return $this->number(CommitMessage::RUN);
    }

    /**
     * The attempt that produced the commit, or null when the message does not
     * say.
     */
    public function attempt(): ?int
    {
        return $this->number(CommitMessage::ATTEMPT);
    }

    /**
     * The commit the work was written against, or null when the message does
     * not say.
     */
    public function base(): ?string
    {
        return $this->trailer(CommitMessage::BASE);
    }

    /**
     * The configured agent that implemented the task, or null when the message
     * does not say.
     */
    public function agent(): ?string
    {
        return $this->trailer(CommitMessage::AGENT);
    }

    /**
     * Whether this is a commit Ralph PHP made.
     *
     * Both identifiers are required. One of them alone is not this package's
     * commit — it is a message with one of this package's trailer keys in it,
     * which is a different thing and must not be adopted as a pass's work.
     */
    public function isRalphCommit(): bool
    {
        $task = $this->task();

        return $task !== null && TaskId::isValid($task) && $this->generation() !== null;
    }

    /**
     * Whether the commit says it is this pass's work.
     */
    public function names(TaskGeneration $generation): bool
    {
        return $this->isRalphCommit()
            && $this->task() === $generation->task()->value()
            && $this->generation() === $generation->number();
    }

    /**
     * Whether the commit names a pass at this task, whichever pass.
     *
     * The distinction matters to a reopened task: generation 2's branch holding
     * generation 1's commit is a different problem from it holding a
     * stranger's, and the two need different words.
     */
    public function namesTask(TaskId $task): bool
    {
        return $this->isRalphCommit() && $this->task() === $task->value();
    }

    /**
     * One line for a log or a report.
     */
    public function describe(): string
    {
        if (!$this->isRalphCommit()) {
            return sprintf('%s %s', $this->shortSha(), $this->subject);
        }

        return sprintf(
            '%s %s (%s, generation %d%s)',
            $this->shortSha(),
            $this->subject,
            (string) $this->task(),
            (int) $this->generation(),
            $this->attempt() === null ? '' : sprintf(', attempt %d', $this->attempt()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sha' => $this->sha,
            'subject' => $this->subject,
            'ralph' => $this->isRalphCommit(),
            'trailers' => $this->trailers,
        ];
    }

    /**
     * A trailer read as a counted number, or null when it is absent or is not
     * one.
     *
     * A trailer holding `two` or `-1` is treated as absent rather than
     * guessed at: these numbers decide whether a commit is adopted into a
     * pass's row, and a garbled one must not become a `0` that compares equal
     * to something.
     */
    private function number(string $key): ?int
    {
        $value = $this->trailer($key);

        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The message's paragraphs, blank ones dropped.
     *
     * @return list<string>
     */
    private static function paragraphsIn(string $message): array
    {
        $paragraphs = [];

        foreach (preg_split('/\n[ \t]*\n/', trim($message)) ?: [] as $paragraph) {
            $trimmed = trim($paragraph, "\n");

            if (trim($trimmed) !== '') {
                $paragraphs[] = $trimmed;
            }
        }

        return $paragraphs;
    }

    /**
     * The trailers of the last paragraph, when the last paragraph is one.
     *
     * @param list<string> $paragraphs
     *
     * @return array<string, string>
     */
    private static function trailersIn(array $paragraphs): array
    {
        // Fewer than two paragraphs means there is no trailer block: this
        // package writes a subject and a trailer block at the least, so a
        // single paragraph of `Key: value` lines is somebody else's commit.
        if (count($paragraphs) < 2) {
            return [];
        }

        $lines = explode("\n", $paragraphs[count($paragraphs) - 1]);

        foreach ($lines as $line) {
            if (preg_match(self::TRAILER_LINE, rtrim($line)) !== 1) {
                return [];
            }
        }

        $trailers = [];

        foreach ($lines as $line) {
            if (preg_match(self::TRAILER, rtrim($line), $matches) === 1) {
                $trailers[$matches[1]] = trim($matches[2]);
            }
        }

        return $trailers;
    }
}
