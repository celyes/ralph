<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * What cleanup decided about one task workspace, and why.
 *
 * A reading, not an action: {@see WorktreeCleaner::assess()} produces one
 * without touching anything, which is what lets `ralph status` report where
 * every preserved workspace is and why, and lets a user see what a cleanup
 * would do before it does it.
 *
 * The reasons are the point. PROJECT.md section F, item 10 asks for cleanup
 * behaviour that *protects* uncommitted work and completed branches, and a
 * protection a user cannot see the workings of is indistinguishable from luck:
 * "4 files you have not committed" and "the commit is not on any branch" are
 * the difference between trusting the directory can go and finding out
 * afterwards. So a preserved verdict carries one line per reason, each with the
 * specific observation behind it — the file count, the two SHAs, Git's own
 * refusal — and {@see self::hints()} carries what to do about each.
 *
 * Reasons keep the order they were found in, which is the order
 * {@see WorktreeCleaner} asks: whose directory it is, then what is in it, then
 * what Git says. The first line of a message is therefore the most fundamental
 * objection rather than the most recently noticed one.
 */
final class WorkspaceRetention
{
    /**
     * @param array<string, string> $reasons {@see RetentionReason} value to the
     *        observation behind it, in the order they were found
     */
    private function __construct(
        private readonly RetentionVerdict $verdict,
        private readonly string $label,
        private readonly ?string $path,
        private readonly array $reasons,
    ) {
    }

    /**
     * No workspace to decide about.
     *
     * @param string|null $path the directory the row names, when it names one
     *        that is simply not there any more
     */
    public static function absent(string $label, ?string $path = null): self
    {
        return new self(RetentionVerdict::Absent, self::named($label), self::location($path), []);
    }

    /**
     * A workspace that may be removed.
     *
     * Only built once every reason has been asked about and none applied, which
     * is why it takes no reasons: there is no way to express "removable
     * although".
     */
    public static function removable(string $label, string $path): self
    {
        return new self(RetentionVerdict::Removable, self::named($label), self::required($path), []);
    }

    /**
     * A workspace that stays, with the reasons it stays.
     *
     * @param array<string, string> $reasons {@see RetentionReason} value to the
     *        observation behind it
     *
     * @throws InvalidArgumentException when no reason is given, or a key is not
     *                                 a reason this version knows
     */
    public static function preserved(string $label, string $path, array $reasons): self
    {
        if ($reasons === []) {
            throw new InvalidArgumentException(
                'A preserved workspace must say why it is being kept.',
            );
        }

        foreach (array_keys($reasons) as $reason) {
            if (RetentionReason::tryFrom($reason) === null) {
                throw new InvalidArgumentException(sprintf(
                    'A workspace cannot be kept for the unknown reason "%s".',
                    $reason,
                ));
            }
        }

        return new self(RetentionVerdict::Preserved, self::named($label), self::required($path), $reasons);
    }

    /**
     * The same decision with one more reason on it.
     *
     * The one thing that happens *after* a decision is made: a removal this
     * judged safe that Git then declined. Rather than a second outcome type for
     * that, the attempt adds its reason and the verdict becomes preserved —
     * so a caller reporting a cleanup reads exactly the same shape whether the
     * refusal was predicted or discovered.
     *
     * @throws InvalidArgumentException when there is no workspace to keep
     */
    public function withReason(RetentionReason $reason, string $observation): self
    {
        if ($this->path === null) {
            throw new InvalidArgumentException(sprintf(
                'There is no workspace for %s to keep, so it cannot be kept because %s.',
                $this->label,
                $reason->value,
            ));
        }

        return new self(
            RetentionVerdict::Preserved,
            $this->label,
            $this->path,
            [...$this->reasons, $reason->value => trim($observation)],
        );
    }

    public function verdict(): RetentionVerdict
    {
        return $this->verdict;
    }

    /**
     * The pass whose workspace this is, as {@see \Celyes\RalphPhp\Tasks\TaskGeneration::label()}
     * spells it.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The workspace directory, or null when nothing was ever recorded.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    public function isRemovable(): bool
    {
        return $this->verdict === RetentionVerdict::Removable;
    }

    public function isPreserved(): bool
    {
        return $this->verdict === RetentionVerdict::Preserved;
    }

    public function isAbsent(): bool
    {
        return $this->verdict === RetentionVerdict::Absent;
    }

    /**
     * Whether the workspace is being kept for a person to look at, rather than
     * because cleanup was not entitled to touch it.
     */
    public function isForInspection(): bool
    {
        foreach ($this->reasons() as $reason) {
            if ($reason->isInspection()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every reason this workspace is kept, in the order they were found.
     *
     * @return list<RetentionReason>
     */
    public function reasons(): array
    {
        return array_map(
            static fn (string $reason): RetentionReason => RetentionReason::from($reason),
            array_keys($this->reasons),
        );
    }

    public function hasReason(RetentionReason $reason): bool
    {
        return array_key_exists($reason->value, $this->reasons);
    }

    /**
     * The observation behind one reason, or null when it is not one of this
     * decision's.
     */
    public function observationFor(RetentionReason $reason): ?string
    {
        return $this->reasons[$reason->value] ?? null;
    }

    /**
     * One line for a log or a status table.
     */
    public function describe(): string
    {
        if ($this->path === null) {
            return sprintf('%s: no workspace prepared', $this->label);
        }

        if ($this->verdict === RetentionVerdict::Absent) {
            return sprintf('%s: %s is not there', $this->label, $this->path);
        }

        if ($this->verdict === RetentionVerdict::Removable) {
            return sprintf('%s: %s can be removed', $this->label, $this->path);
        }

        return sprintf(
            '%s: %s kept, %s',
            $this->label,
            $this->path,
            implode('; ', array_map(
                fn (RetentionReason $reason): string => $this->reasons[$reason->value] === ''
                    ? $reason->describe()
                    : $this->reasons[$reason->value],
                $this->reasons(),
            )),
        );
    }

    /**
     * A line per reason, each pairing what was decided with what was seen.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];

        foreach ($this->reasons() as $reason) {
            $observation = $this->reasons[$reason->value];

            $details[] = $observation === ''
                ? ucfirst($reason->describe()) . '.'
                : sprintf('%s: %s', ucfirst($reason->describe()), $observation);
        }

        return $details;
    }

    /**
     * What to do about each reason, without repeating a hint two reasons share.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        $hints = [];

        foreach ($this->reasons() as $reason) {
            $hints[] = $reason->hint();
        }

        return array_values(array_unique($hints));
    }

    /**
     * The decision as `status --json` reports it (PROJECT.md section I,
     * item 3).
     *
     * @return array{
     *     generation: string,
     *     path: string|null,
     *     verdict: string,
     *     for_inspection: bool,
     *     reasons: array<string, string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->label,
            'path' => $this->path,
            'verdict' => $this->verdict->value,
            'for_inspection' => $this->isForInspection(),
            'reasons' => $this->reasons,
        ];
    }

    /**
     * @throws InvalidArgumentException when the pass is not named
     */
    private static function named(string $label): string
    {
        $trimmed = trim($label);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A workspace decision must name the pass it is about.');
        }

        return $trimmed;
    }

    private static function location(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return trim($path);
    }

    /**
     * @throws InvalidArgumentException when the path names nothing
     */
    private static function required(string $path): string
    {
        $trimmed = self::location($path);

        if ($trimmed === null) {
            throw new InvalidArgumentException('A workspace decision must name a directory.');
        }

        return $trimmed;
    }
}
