<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * What the state store remembers about one pass at a task.
 *
 * Enough to answer the two questions reopening turns on, and nothing more: is
 * this pass finished, and what did it own? The evidence itself — attempts,
 * verification output, timings, blockers — stays in the state store of
 * PROJECT.md section D; repeating it here would mean two places deciding what
 * "finished" means.
 *
 * "Complete" is used in the strict sense PROJECT.md puts on ticking a box:
 * verification passed, a commit or a justified no-change result was recorded,
 * and any required push succeeded. A pass that is anything else — failed,
 * blocked, interrupted, still running — is open, and an open pass is continued
 * rather than superseded. The distinction is the one that decides whether a new
 * branch is created, which is why it is a property of the record and not
 * something re-derived from a lifecycle state the checklist layer would have to
 * know about.
 *
 * The branch is remembered because ownership outlives the run that created it.
 * A new generation must not be handed a branch an earlier generation owns, and
 * the only way to be sure of that is to know which branches are already taken
 * ({@see GenerationSelection::reservedBranches()}).
 */
final class RecordedGeneration
{
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly bool $complete,
        private readonly ?string $branch,
        private readonly ?string $commit,
        private readonly ?string $contentHash,
    ) {
    }

    /**
     * A pass that carried the task all the way through.
     *
     * @param string|null $commit the commit it produced, or null for a
     *                            verified no-change outcome, which PROJECT.md
     *                            section G, item 6 accepts as complete without
     *                            an empty commit
     * @param string|null $contentHash what the task said at the time
     *                                 ({@see TaskSnapshot::contentHash()}), so
     *                                 a later pass can report whether the work
     *                                 being asked for has changed as well
     *
     * @throws InvalidArgumentException when a branch or commit is given as an
     *                                 empty string, which is a value the state
     *                                 store never meant to record and would
     *                                 later read as a usable ref
     */
    public static function completed(
        TaskGeneration $generation,
        ?string $branch = null,
        ?string $commit = null,
        ?string $contentHash = null,
    ): self {
        return new self(
            $generation,
            true,
            self::reference($branch, 'branch'),
            self::reference($commit, 'commit'),
            self::reference($contentHash, 'content hash'),
        );
    }

    /**
     * A pass that stopped without finishing: failed, blocked, interrupted, or
     * still running.
     *
     * @throws InvalidArgumentException when a reference is an empty string
     */
    public static function open(
        TaskGeneration $generation,
        ?string $branch = null,
        ?string $contentHash = null,
    ): self {
        return new self(
            $generation,
            false,
            self::reference($branch, 'branch'),
            null,
            self::reference($contentHash, 'content hash'),
        );
    }

    /**
     * Which pass this is.
     */
    public function generation(): TaskGeneration
    {
        return $this->generation;
    }

    /**
     * The task it is a pass at.
     */
    public function task(): TaskId
    {
        return $this->generation->task();
    }

    /**
     * The generation number.
     */
    public function number(): int
    {
        return $this->generation->number();
    }

    /**
     * Whether the pass finished, in the strict sense of the class note.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Whether the pass stopped without finishing, so it is still the task's
     * current pass.
     */
    public function isOpen(): bool
    {
        return !$this->complete;
    }

    /**
     * The branch the pass owns, when one was recorded.
     */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /**
     * The commit it produced, when there is one.
     *
     * Null covers two different things, and the difference is in
     * {@see self::isComplete()}: a pass that never got as far as committing, and
     * a complete pass whose task was already satisfied.
     */
    public function commit(): ?string
    {
        return $this->commit;
    }

    /**
     * What the task said when the pass ran, when it was recorded.
     */
    public function contentHash(): ?string
    {
        return $this->contentHash;
    }

    /**
     * Whether the task's wording and details are the same now as they were
     * then.
     *
     * Only ever informative. The task is the same task either way — identity
     * comes from the description, so a reworded task is a different task
     * entirely — and this says whether the notes under it were edited in the
     * meantime, which is worth telling a user who is reopening work they
     * finished a while ago. Unknown, when nothing was recorded, is reported as
     * "not the same", so the message under-claims rather than over-claims.
     */
    public function statesSameWork(TaskSnapshot $snapshot): bool
    {
        return $this->contentHash !== null && $this->contentHash === $snapshot->contentHash();
    }

    /**
     * Whether this pass owns the named branch.
     *
     * Compared exactly: Git refs are case-sensitive, and a near-match is
     * someone else's branch.
     */
    public function ownsBranch(string $branch): bool
    {
        return $this->branch !== null && $this->branch === $branch;
    }

    /**
     * @throws InvalidArgumentException when given an empty or blank string
     */
    private static function reference(?string $value, string $what): ?string
    {
        if ($value === null) {
            return null;
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'A recorded %s must either be absent or name something.',
                $what,
            ));
        }

        return $value;
    }
}
