<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Support\Excerpt;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskSnapshot;
use InvalidArgumentException;

/**
 * What a task's commit says about itself (PROJECT.md section F, item 7).
 *
 * A reviewer meets these commits in `git log`, months later, in a branch
 * somebody else pushed. The message is the only place the connection between
 * the commit and the run that made it survives — the state database belongs to
 * one machine and may be deleted, the worktree is cleaned up, the logs are
 * pruned, and the branch name can be anything the configured prefix made it. So
 * the identifiers go in the message, where they travel with the commit into
 * every clone of the repository.
 *
 * The shape is Git's own rather than invented: a subject line, a blank line,
 * and a trailer block. That is deliberate, because it means the identifiers are
 * *queryable* with the tools a reviewer already has — `git log
 * --grep='Ralph-Task: TASK-19E0214A'` finds every pass at one task, and
 * `git interpret-trailers --parse` reads them without a parser of this
 * package's own. Keys are `Ralph-`prefixed for the same reason: a project's own
 * trailers and these cannot collide.
 *
 *  - {@see self::TASK} is the stable identifier of the work, which survives the
 *    task being reworded only as far as {@see \Celyes\RalphPhp\Tasks\TaskId}
 *    does — it comes from the wording, so a reworded task is a different task
 *    and gets a different commit.
 *  - {@see self::GENERATION} says which pass this was. Two commits with the
 *    same task and different generations are a reopened task, which is the one
 *    case where a task legitimately has more than one commit in the history.
 *  - {@see self::RUN} and {@see self::ATTEMPT} are the run/task identifiers the
 *    item asks for: they are what ties the commit to a row in `runs` and to the
 *    log file of the invocation that produced it, so `ralph logs` has something
 *    to be asked about.
 *  - {@see self::BASE} is the commit the workspace was created from. A reviewer
 *    looking at a branch that does not merge cleanly wants to know what it was
 *    written against, and a run that resolved its base once per run
 *    ({@see BaseCommit}) is the only thing that knows.
 *  - {@see self::AGENT} names the configured agent, never its settings. The
 *    name is a project's own word for an agent — `codex`, `claude` — and the
 *    settings beside it in configuration can include a model, a path, and the
 *    names of credential variables; a commit message is published, so only the
 *    name is written.
 *
 * **Nothing the agent wrote reaches the message.** The subject comes from the
 * task as the checklist states it, not from what the worker said it did: a
 * model's summary is an unverified claim ({@see \Celyes\RalphPhp\Support\UnverifiedText}),
 * and a commit message is read as a statement of fact. The description is
 * flattened to one line before it is used, which also means a description
 * cannot forge a trailer by containing a newline and a `Ralph-Task:` of its
 * own.
 */
final class CommitMessage
{
    /**
     * The trailer naming the task.
     */
    public const string TASK = 'Ralph-Task';

    /**
     * The trailer naming which pass at the task this was.
     */
    public const string GENERATION = 'Ralph-Generation';

    /**
     * The trailer naming the run.
     */
    public const string RUN = 'Ralph-Run';

    /**
     * The trailer naming the attempt within the pass.
     */
    public const string ATTEMPT = 'Ralph-Attempt';

    /**
     * The trailer naming the commit the workspace was created from.
     */
    public const string BASE = 'Ralph-Base';

    /**
     * The trailer naming the configured agent that implemented the task.
     */
    public const string AGENT = 'Ralph-Agent';

    /**
     * Characters a subject line may have, identifier included.
     *
     * The upper end of the convention every Git tool is written around: `git
     * log --oneline` and the forges wrap or truncate past about here. The
     * identifier is counted inside it rather than added to it, because the
     * thing that has to stay readable is the line as it is displayed.
     */
    public const int SUBJECT_WIDTH = 72;

    /**
     * Characters of the task's own wording kept in the body.
     *
     * Only written when the subject could not hold the whole description, and
     * bounded because a task's description is the user's prose and can be a
     * paragraph: the commit message is not the place to reproduce a document,
     * and `.ralph/tasks.md` is where the full text lives.
     */
    public const int DESCRIPTION_WIDTH = 600;

    /**
     * @param array<string, string> $trailers in the order they are written
     */
    private function __construct(
        private readonly string $subject,
        private readonly ?string $body,
        private readonly array $trailers,
    ) {
    }

    /**
     * The message for the pass as its row records it.
     *
     * Everything but the wording comes from the generation's own row rather
     * than from the run's configuration, so that a commit made on a resumed run
     * says which agent did the work and which base it was written against even
     * if the project's settings have changed since — the same reason the row
     * holds them at all (PROJECT.md section D, item 4).
     *
     * The snapshot supplies the wording, and is not checked against the row's
     * content hash here. A task edited while it was being worked on is
     * reconciliation's decision (PROJECT.md section C, item 7), taken before a
     * commit is reached; this would only turn it into a second, worse-placed
     * refusal after the work was done.
     *
     * @throws InvalidArgumentException when the snapshot is of another task, or
     *                                 the description says nothing
     */
    public static function forGeneration(GenerationRecord $generation, TaskSnapshot $snapshot): self
    {
        if (!$generation->generation()->belongsTo($snapshot->id())) {
            throw new InvalidArgumentException(sprintf(
                'Pass %s is not a pass at task %s, so its commit cannot be described by it.',
                $generation->slug(),
                $snapshot->id()->value(),
            ));
        }

        return self::of(
            $generation->generation(),
            $snapshot->description(),
            $generation->baseSha(),
            $generation->runId(),
            $generation->attempts() > 0 ? $generation->attempts() : null,
            $generation->agent()->name(),
        );
    }

    /**
     * The message for a pass, from the facts that go in it.
     *
     * A run, an attempt and an agent are optional because a trailer is worse
     * than useless when it is a guess: `Ralph-Run: 0` would be read as a run
     * identifier by anything parsing it. An unknown one is left out instead, so
     * the trailers that are present are all true.
     *
     * @param int|null    $runId   the run that made the commit
     * @param int|null    $attempt which attempt at the pass produced it,
     *                             counted from one
     * @param string|null $agent   the configured agent's name, never its
     *                             settings
     *
     * @throws InvalidArgumentException when the description says nothing, or a
     *                                 run or attempt number is not a real one
     */
    public static function of(
        TaskGeneration $generation,
        string $description,
        string $baseSha,
        ?int $runId = null,
        ?int $attempt = null,
        ?string $agent = null,
    ): self {
        $wording = self::oneLine($description);

        if ($wording === '') {
            throw new InvalidArgumentException(sprintf(
                'A commit for %s needs the task\'s description to describe it.',
                $generation->slug(),
            ));
        }

        foreach (['run' => $runId, 'attempt' => $attempt] as $name => $number) {
            if ($number !== null && $number < 1) {
                throw new InvalidArgumentException(sprintf(
                    'A commit\'s %s identifier is counted from 1; %d is not one.',
                    $name,
                    $number,
                ));
            }
        }

        $identifier = $generation->task()->value();
        $room = self::SUBJECT_WIDTH - mb_strlen($identifier) - 2;
        $shortened = Excerpt::of($wording, $room);

        $trailers = [
            self::TASK => $identifier,
            self::GENERATION => (string) $generation->number(),
        ];

        if ($runId !== null) {
            $trailers[self::RUN] = (string) $runId;
        }

        if ($attempt !== null) {
            $trailers[self::ATTEMPT] = (string) $attempt;
        }

        $trailers[self::BASE] = $baseSha;

        $named = $agent === null ? '' : self::oneLine($agent);

        if ($named !== '') {
            $trailers[self::AGENT] = $named;
        }

        return new self(
            sprintf('%s: %s', $identifier, $shortened),
            $shortened === $wording ? null : Excerpt::of($wording, self::DESCRIPTION_WIDTH),
            $trailers,
        );
    }

    /**
     * The first line: the task's identifier and what the task asks for.
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * The task's full wording, when the subject could not hold it.
     */
    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * The identifiers, in the order they are written.
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
     * The whole message, as it is given to Git.
     *
     * No trailing newline: `git commit -m` adds one, and a message ending in a
     * blank line would render as one in `git log`.
     */
    public function text(): string
    {
        $paragraphs = [$this->subject];

        if ($this->body !== null) {
            $paragraphs[] = $this->body;
        }

        $trailers = [];

        foreach ($this->trailers as $key => $value) {
            $trailers[] = sprintf('%s: %s', $key, $value);
        }

        $paragraphs[] = implode("\n", $trailers);

        return implode("\n\n", $paragraphs);
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        return $this->subject;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'trailers' => $this->trailers,
        ];
    }

    public function __toString(): string
    {
        return $this->text();
    }

    /**
     * Text as a single line, with nothing in it Git would read as structure.
     *
     * A task description is ordinary Markdown prose: it can wrap across lines,
     * and {@see \Celyes\RalphPhp\Tasks\ChecklistParser} keeps the wording as
     * written. In a commit message a newline ends the subject and starts a
     * paragraph, and a line of the form `Key: value` after a blank line is a
     * trailer — so flattening is what keeps a two-line description from
     * becoming a subject and a stray paragraph, and what keeps a description
     * from writing a `Ralph-Task:` of its own.
     *
     * The control characters go first and separately, because they are not
     * whitespace and collapsing would leave them: a NUL ends an argument as far
     * as the operating system is concerned, which would hand Git half a message
     * and no indication that it was half.
     */
    private static function oneLine(string $text): string
    {
        $printable = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $printable));
    }
}
