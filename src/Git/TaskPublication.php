<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\State\PushState;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * Where a pass stands with its remote once {@see TaskPublisher} has had its
 * turn: the row as it now reads, what was published, and whether this call is
 * what published it.
 *
 * Deliberately *not* a success/failure pair. Publication has four ends — it was
 * never required, it had already happened, it happened here, it was refused —
 * and the runner does something different with each: the first two finalise
 * without a word, the third finalises with a remote to report, and the fourth
 * leaves the task unchecked with a reason the user has to see. A boolean would
 * flatten the first and last together, which is the mistake that makes a failed
 * push look like a completed task.
 *
 * {@see self::push()} reads through to the row rather than keeping a second
 * copy of the state. The row is what resume reads and what `ralph status`
 * reports; a value object holding its own idea of where the push got to would
 * be a second answer to a question that has one.
 *
 * {@see self::wasAttempted()} is the one fact here that the row cannot answer
 * afterwards. A pass that arrives already published and a pass published by
 * this call leave identical rows, and the difference matters to a reader of the
 * log: the first means resume found the work already out, the second means it
 * went out now.
 */
final class TaskPublication
{
    /**
     * @param string|null $sha    the commit publication concerned, when there
     *        was one; null for a pass with nothing to publish
     * @param string|null $branch the branch it is published as
     * @param bool $attempted whether a push was run during this call
     */
    private function __construct(
        private readonly GenerationRecord $recorded,
        private readonly ?string $sha,
        private readonly ?string $branch,
        private readonly bool $attempted,
    ) {
    }

    /**
     * The outcome for a pass that had nothing to publish: publication is off,
     * or the task was already satisfied so there is no commit.
     */
    public static function nothingToPublish(GenerationRecord $recorded): self
    {
        return new self($recorded, null, null, false);
    }

    /**
     * The outcome for a pass whose commit was already on the remote before this
     * call.
     */
    public static function alreadyPublished(GenerationRecord $recorded, string $sha, string $branch): self
    {
        return new self($recorded, $sha, $branch, false);
    }

    /**
     * The outcome of a push this call ran, however it went.
     */
    public static function attempted(GenerationRecord $recorded, string $sha, string $branch): self
    {
        return new self($recorded, $sha, $branch, true);
    }

    /**
     * The pass this is about.
     */
    public function generation(): TaskGeneration
    {
        return $this->recorded->generation();
    }

    /**
     * The pass's row as it stands, publication recorded on it.
     */
    public function recorded(): GenerationRecord
    {
        return $this->recorded;
    }

    /**
     * Where publication stands, as the row has it.
     */
    public function push(): PushResult
    {
        return $this->recorded->push();
    }

    /**
     * The remote this pass publishes to, when it publishes.
     */
    public function remote(): ?string
    {
        return $this->push()->remote();
    }

    /**
     * The commit publication concerned, when there was one.
     */
    public function sha(): ?string
    {
        return $this->sha;
    }

    /**
     * The branch the commit is published as, when there is one.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * Whether a push was run during this call.
     */
    public function wasAttempted(): bool
    {
        return $this->attempted;
    }

    /**
     * Whether publication no longer holds the task back, which includes the
     * pass that was never going to push.
     */
    public function isSettled(): bool
    {
        return $this->push()->isSatisfied();
    }

    /**
     * Whether the commit reached the remote, now or earlier.
     */
    public function isPublished(): bool
    {
        return $this->push()->state() === PushState::Published;
    }

    /**
     * Whether a push was attempted and refused, so the task stays unchecked.
     */
    public function hasFailed(): bool
    {
        return $this->push()->hasFailed();
    }

    /**
     * Why the remote refused it, when it did.
     */
    public function error(): ?string
    {
        return $this->push()->error();
    }

    /**
     * One line for a log header or a status table.
     */
    public function describe(): string
    {
        if ($this->sha === null) {
            return sprintf('%s %s', $this->generation()->label(), $this->push()->describe());
        }

        $short = substr($this->sha, 0, TaskCommit::SHORT_LENGTH);

        $where = match (true) {
            $this->hasFailed() => sprintf('could not publish %s as %s: %s', $short, (string) $this->branch, $this->push()->describe()),
            !$this->attempted => sprintf('found %s already on "%s" as %s', $short, (string) $this->remote(), (string) $this->branch),
            default => sprintf('published %s as %s on "%s"', $short, (string) $this->branch, (string) $this->remote()),
        };

        return sprintf('%s %s', $this->generation()->label(), $where);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation()->slug(),
            'state' => $this->push()->state()->value,
            'remote' => $this->remote(),
            'sha' => $this->sha,
            'branch' => $this->branch,
            'attempted' => $this->attempted,
            'error' => $this->error(),
        ];
    }
}
