<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use InvalidArgumentException;

/**
 * Which pass at a task the runner is about to make, and what that pass may not
 * touch.
 *
 * The answer {@see GenerationSelector} returns, with the evidence behind it: the
 * generation to work in, why it is that one, and the earlier passes that stay
 * exactly as they are. Reopening a finished task is the case the class is
 * shaped around — the old generation keeps its attempts, its verification
 * output, its branch and its commit, and the new one gets the next number and a
 * branch of its own (PROJECT.md section C, item 8).
 *
 * Nothing here changes anything, and nothing here touches Git. It says what the
 * new pass owns and which branches are already taken; creating the branch and
 * refusing one owned by unrelated work is section F.
 */
final class GenerationSelection
{
    /**
     * @param list<RecordedGeneration> $preserved earlier passes, oldest first
     */
    private function __construct(
        private readonly TaskGeneration $generation,
        private readonly GenerationStart $start,
        private readonly array $preserved,
        private readonly ?RecordedGeneration $continues,
    ) {
    }

    /**
     * A task nothing has been recorded for: generation 1.
     */
    public static function firstRun(TaskId $task): self
    {
        return new self(TaskGeneration::first($task), GenerationStart::FirstRun, [], null);
    }

    /**
     * An unfinished pass, picked up where it stopped.
     *
     * @param list<RecordedGeneration> $preserved the completed passes before
     *                                            it, which a task reopened and
     *                                            then interrupted has
     *
     * @throws InvalidArgumentException when the pass being continued is
     *                                 complete, which would have the runner
     *                                 write to a branch whose work is already
     *                                 verified
     */
    public static function continuing(RecordedGeneration $open, array $preserved = []): self
    {
        if ($open->isComplete()) {
            throw new InvalidArgumentException(sprintf(
                'Generation %d of %s is complete, so it is reopened rather than continued.',
                $open->number(),
                $open->task()->value(),
            ));
        }

        return new self($open->generation(), GenerationStart::Continued, $preserved, $open);
    }

    /**
     * A finished task, started again as a new pass.
     *
     * @param list<RecordedGeneration> $preserved every pass recorded so far,
     *                                            oldest first
     *
     * @throws InvalidArgumentException when the new generation is the first,
     *                                 which cannot be a reopening, or when it
     *                                 is not a pass at the same task
     */
    public static function reopened(TaskGeneration $generation, array $preserved): self
    {
        if (!$generation->isReopening()) {
            throw new InvalidArgumentException(
                'A first generation is a first run, not a reopening.',
            );
        }

        foreach ($preserved as $pass) {
            if (!$pass->generation()->belongsTo($generation->task())) {
                throw new InvalidArgumentException(sprintf(
                    'Generation %d of %s is not a pass at %s.',
                    $pass->number(),
                    $pass->task()->value(),
                    $generation->task()->value(),
                ));
            }
        }

        return new self($generation, GenerationStart::Reopened, $preserved, null);
    }

    /**
     * The pass to work in.
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
     * Why this generation and not another.
     */
    public function start(): GenerationStart
    {
        return $this->start;
    }

    /**
     * Whether a finished task is being run again.
     */
    public function isReopening(): bool
    {
        return $this->start->isReopening();
    }

    /**
     * Whether this pass needs a branch created for it.
     */
    public function startsNewBranch(): bool
    {
        return $this->start->startsNewBranch();
    }

    /**
     * The unfinished pass this continues, or null when it starts a new one.
     *
     * Where resume reads the branch it already owns and the attempts already
     * spent.
     */
    public function continues(): ?RecordedGeneration
    {
        return $this->continues;
    }

    /**
     * The earlier passes, oldest first, which this selection leaves untouched.
     *
     * The "preserving its history" half of the task: a reopened task's records
     * are carried forward, not replaced, so `status` and `ralph logs` can still
     * show what the completed pass did and the commit it left behind stays
     * reachable.
     *
     * @return list<RecordedGeneration>
     */
    public function preserved(): array
    {
        return $this->preserved;
    }

    /**
     * The completed passes among them, oldest first.
     *
     * @return list<RecordedGeneration>
     */
    public function completed(): array
    {
        return array_values(array_filter(
            $this->preserved,
            static fn (RecordedGeneration $pass): bool => $pass->isComplete(),
        ));
    }

    /**
     * The branches earlier passes own, oldest first.
     *
     * The new pass's branch must not be any of these, and a branch that is one
     * of them is not available to be reset or reused — it is where verified
     * work lives. Checked by whoever names the branch, which is section F.
     *
     * @return list<string>
     */
    public function reservedBranches(): array
    {
        return array_values(array_filter(array_map(
            static fn (RecordedGeneration $pass): ?string => $pass->branch(),
            $this->preserved,
        )));
    }

    /**
     * Whether a branch belongs to one of the preserved passes.
     */
    public function isReserved(string $branch): bool
    {
        return in_array($branch, $this->reservedBranches(), true);
    }

    /**
     * The commit the most recent completed pass produced, when there is one.
     *
     * What a reopened task's new pass is measured against by the person
     * reviewing it: the change that was accepted last time.
     */
    public function previousCommit(): ?string
    {
        $completed = $this->completed();

        for ($index = count($completed) - 1; $index >= 0; $index--) {
            $commit = $completed[$index]->commit();

            if ($commit !== null) {
                return $commit;
            }
        }

        return null;
    }

    /**
     * One sentence saying which pass this is and why.
     */
    public function reason(): string
    {
        $task = $this->task()->value();

        return match ($this->start) {
            GenerationStart::FirstRun => sprintf('%s has not been run before.', $task),
            GenerationStart::Continued => sprintf(
                'Generation %d of %s did not finish, so this run continues it.',
                $this->number(),
                $task,
            ),
            GenerationStart::Reopened => sprintf(
                '%s was completed and has been reopened, so this run is generation %d of it.',
                $task,
                $this->number(),
            ),
        };
    }

    /**
     * What the user should know about working in this generation.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        if (!$this->isReopening()) {
            return [];
        }

        $notes = [sprintf(
            'The %d earlier generation(s) keep their attempts, logs, branches and commits; nothing is reused or reset.',
            count($this->preserved),
        )];

        $branches = $this->reservedBranches();

        if ($branches !== []) {
            $notes[] = sprintf(
                'Previous work stays on %s; this generation gets a branch of its own.',
                implode(', ', $branches),
            );
        }

        $notes[] = sprintf(
            'This generation is written down as "%s", which is what its branch and logs are named after.',
            $this->generation->slug(),
        );

        return $notes;
    }
}
