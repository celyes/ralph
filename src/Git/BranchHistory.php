<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * What a branch holds past one commit, and whether this project put it all
 * there (PROJECT.md section K, item 5).
 *
 * The reading a shared branch needs on resume. A per-task branch answers the
 * same question by counting: a pass makes one commit on its own branch, so one
 * commit past the base is a finished pass and anything else is a
 * reconciliation ({@see CommitReader::countBetween()}). A shared branch holds a
 * commit per task on purpose, so counting says nothing, and the question
 * becomes *whose* the commits past a given one are:
 *
 *  - **Accounted for.** Every commit in the range is one the state store
 *    records against a pass. The branch moved on because later tasks of the run
 *    committed to it, which is what the mode is for, and a pass whose commit
 *    sits under them is still intact.
 *  - **A stranger.** The first commit in the range that no row accounts for,
 *    kept with what it says about itself so a person can see it. It stops the
 *    resume: a run that committed on top of it would build on work no check of
 *    this run ran against, and every way of continuing without it — resetting
 *    the branch, forcing it, committing over it — destroys somebody's work.
 *  - **Too long to account for.** More commits than {@see self::MAX_READ},
 *    which is a refusal rather than a shorter answer: a reconciliation that
 *    read part of a range and called it accounted for would be guessing about
 *    the rest.
 *
 * Read forwards from the commit the caller asked about, which is why the
 * commits arrive oldest first ({@see CommitReader::between()}): the first
 * answer is about what was committed immediately after it, and that is the
 * answer every decision in {@see \Celyes\RalphPhp\Run\PassRecovery} turns on.
 *
 * Free of I/O and of judgement. {@see BranchHistories} reads it, and what to do
 * about a stranger is the caller's: the same reading means "stop the resume" to
 * a pass being reconciled and "this is what you are looking at" to a
 * diagnostic.
 */
final class BranchHistory
{
    /**
     * How many commits past the asked-about one are read before the range is
     * refused as unaccountable.
     *
     * Generous on purpose: a shared branch legitimately accumulates a commit
     * per task across every run that uses it, and a project with hundreds of
     * finished tasks must not have its resume refused for being productive.
     * What this is really guarding is the other shape — a branch somebody
     * merged a long-lived line of development into — where the honest answer is
     * that reconciliation cannot vouch for what is on it.
     */
    public const int MAX_READ = 500;

    /**
     * @param string           $from     the commit the range starts past, exclusive
     * @param string           $tip      the commit the range ends at, inclusive
     * @param int              $total    how many commits the range holds, however
     *        many of them were read
     * @param list<string>     $recorded the slug of the pass recording each
     *        commit read, oldest first, up to the stranger when there is one
     * @param CommitIdentity|null $stranger the first commit no pass recorded
     * @param bool             $truncated whether the range was longer than
     *        {@see self::MAX_READ}
     */
    private function __construct(
        private readonly string $from,
        private readonly string $tip,
        private readonly int $total,
        private readonly array $recorded,
        private readonly ?CommitIdentity $stranger,
        private readonly bool $truncated,
    ) {
    }

    /**
     * The branch holds nothing past the commit.
     */
    public static function none(string $from, string $tip): self
    {
        return new self($from, $tip, 0, [], null, false);
    }

    /**
     * Every commit past it is one this project recorded.
     *
     * @param list<string> $recorded the recording pass of each commit, oldest first
     */
    public static function accountedFor(string $from, string $tip, array $recorded): self
    {
        return new self($from, $tip, count($recorded), $recorded, null, false);
    }

    /**
     * A commit past it belongs to nobody the store knows.
     *
     * @param list<string> $recorded the commits accounted for *before* the
     *        stranger, which is what tells a stranger sitting directly on the
     *        asked-about commit from one sitting on later tasks' work
     */
    public static function withStranger(
        string $from,
        string $tip,
        int $total,
        array $recorded,
        CommitIdentity $stranger,
    ): self {
        return new self($from, $tip, $total, $recorded, $stranger, false);
    }

    /**
     * The range is longer than reconciliation reads.
     *
     * @param list<string> $recorded what was read before the reading stopped
     */
    public static function tooLong(string $from, string $tip, int $total, array $recorded): self
    {
        return new self($from, $tip, $total, $recorded, null, true);
    }

    /**
     * The commit the range starts past.
     */
    public function from(): string
    {
        return $this->from;
    }

    /**
     * The commit the range ends at.
     */
    public function tip(): string
    {
        return $this->tip;
    }

    /**
     * How many commits the branch holds past the asked-about one.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Whether the branch holds nothing past it.
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * Whether this project put every commit in the range there.
     */
    public function isAccountedFor(): bool
    {
        return $this->stranger === null && !$this->truncated;
    }

    /**
     * The first commit in the range that no pass recorded, when there is one.
     */
    public function stranger(): ?CommitIdentity
    {
        return $this->stranger;
    }

    /**
     * Whether the commit nobody recorded is the first one past the asked-about
     * commit.
     *
     * The distinction a crash recovery turns on. A pass commits directly onto
     * the commit its workspace was made from, so a commit of its own that no
     * row names can only be the first one in the range; a stranger with
     * recorded commits before it arrived after later tasks had committed, and
     * is not a commit the pass could have made.
     */
    public function strangerIsFirst(): bool
    {
        return $this->stranger !== null && $this->recorded === [];
    }

    /**
     * Whether the range was too long to account for.
     */
    public function wasTooLong(): bool
    {
        return $this->truncated;
    }

    /**
     * The passes whose commits the range holds, in the order they committed and
     * without repeating a pass that committed twice.
     *
     * @return list<string>
     */
    public function passes(): array
    {
        return array_values(array_unique($this->recorded));
    }

    /**
     * One clause for a finding or a refusal, phrased to follow a branch name:
     * "ralph/work has moved two commits past a1b2c3d, recorded by …".
     *
     * The commit the range starts at is named rather than called "it", because
     * the sentences this ends up in already name a branch and a commit of their
     * own, and a reader cannot tell which one a bare "it" meant.
     */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return sprintf('holds nothing past %s', self::shorten($this->from));
        }

        if ($this->truncated) {
            return sprintf(
                'holds %s past %s, more than reconciliation reads (%d)',
                self::commits($this->total),
                self::shorten($this->from),
                self::MAX_READ,
            );
        }

        if ($this->stranger !== null) {
            return sprintf(
                'holds %s past %s, and %s is a commit no pass recorded: "%s"',
                self::commits($this->total),
                self::shorten($this->from),
                $this->stranger->shortSha(),
                $this->stranger->subject(),
            );
        }

        return sprintf(
            'has moved %s past %s, recorded by %s',
            self::commits($this->total),
            self::shorten($this->from),
            implode(', ', $this->passes()),
        );
    }

    /**
     * Why the range cannot be vouched for, phrased as a sentence, or null when
     * it can.
     */
    public function reason(): ?string
    {
        if ($this->isAccountedFor()) {
            return null;
        }

        if ($this->truncated) {
            return sprintf(
                'The branch holds %s past %s, which is more than reconciliation reads (%d), so this run '
                    . 'cannot establish that all of it is work it made.',
                self::commits($this->total),
                self::shorten($this->from),
                self::MAX_READ,
            );
        }

        $stranger = $this->stranger;

        return sprintf(
            'The branch holds %s past %s, and %s is a commit no pass of this project recorded: "%s".',
            self::commits($this->total),
            self::shorten($this->from),
            $stranger?->shortSha() ?? '',
            $stranger?->subject() ?? '',
        );
    }

    /**
     * The evidence, one item per line.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [sprintf(
            '%s..%s holds %s.',
            self::shorten($this->from),
            self::shorten($this->tip),
            self::commits($this->total),
        )];

        if ($this->passes() !== []) {
            $details[] = sprintf('Recorded by %s.', implode(', ', $this->passes()));
        }

        if ($this->stranger !== null) {
            $details[] = sprintf('Unrecorded: %s.', $this->stranger->describe());
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'tip' => $this->tip,
            'total' => $this->total,
            'accounted_for' => $this->isAccountedFor(),
            'passes' => $this->passes(),
            'stranger' => $this->stranger?->toArray(),
            'too_long' => $this->truncated,
        ];
    }

    /**
     * A number of commits, counted in words a person reads.
     */
    private static function commits(int $count): string
    {
        return sprintf('%d commit%s', $count, $count === 1 ? '' : 's');
    }

    /**
     * A SHA as it is quoted to a person, and never as it is passed to Git.
     */
    private static function shorten(string $sha): string
    {
        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1
            ? substr($sha, 0, CommitIdentity::SHORT_LENGTH)
            : $sha;
    }
}
