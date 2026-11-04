<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * The commit every task branch starts from, together with the reference the
 * user wrote to name it.
 *
 * The pair is the whole point. `git.base` defaults to `HEAD` and is routinely a
 * branch name, and both of those move: a user who commits to `main` while a run
 * is in progress has changed what `main` means, not what the run is building
 * on. PROJECT.md section 4 settles it — the reference is resolved to a commit
 * SHA and recorded once per run — so this type carries the answer around
 * instead of the question, and {@see self::reference()} survives only so that
 * messages and `status` can still say where the SHA came from.
 *
 * Immutable, and free of I/O: {@see BaseResolver} asks Git, this holds what Git
 * said. A value in a caller's hands can therefore be passed to every task in a
 * run without any risk that the second task resolves differently from the
 * first, which is the failure the recording exists to prevent.
 *
 * The SHA is validated as a full object name, not merely as non-empty. An
 * abbreviated SHA is ambiguous by construction — it means a different commit as
 * soon as the repository grows one that shares its prefix — and this value is
 * written into a database and compared against `git worktree list` output
 * months later.
 */
final class BaseCommit
{
    /**
     * Characters of a SHA shown when one is quoted to a person.
     *
     * Twelve rather than Git's default seven: a project with tens of thousands
     * of commits can collide at seven, and this number appears in status output
     * beside a branch name the user is expected to recognise.
     */
    public const int SHORT_LENGTH = 12;

    /**
     * A full object name, in either of the hash algorithms Git uses: 40 hex
     * characters for SHA-1 and 64 for SHA-256. The second is accepted because a
     * repository created with `--object-format=sha256` reports those, and a
     * package that refused them would refuse the whole repository for no reason
     * of its own.
     */
    private const string SHA_PATTERN = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/';

    private function __construct(
        private readonly string $reference,
        private readonly string $sha,
    ) {
    }

    /**
     * The commit a reference resolved to.
     *
     * Case is normalised because Git prints lowercase and a person may type
     * uppercase; the stored value is then the spelling every later comparison
     * uses.
     *
     * @param string $reference what the configuration named — a branch, a tag,
     *        `HEAD`, or a SHA
     * @param string $sha the full object name Git resolved it to
     *
     * @throws InvalidArgumentException when the reference says nothing, or the
     *                                 SHA is not a full object name
     */
    public static function of(string $reference, string $sha): self
    {
        $named = trim($reference);

        if ($named === '') {
            throw new InvalidArgumentException('A base commit must record the reference it was resolved from.');
        }

        $object = strtolower(trim($sha));

        if (preg_match(self::SHA_PATTERN, $object) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a full commit id; a base commit is recorded in full so that it cannot become ambiguous later.',
                $sha,
            ));
        }

        return new self($named, $object);
    }

    /**
     * The reference the user configured, as they wrote it.
     */
    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * The commit the reference resolved to, in full.
     */
    public function sha(): string
    {
        return $this->sha;
    }

    /**
     * The SHA shortened for reading, never for passing to Git.
     */
    public function shortSha(): string
    {
        return substr($this->sha, 0, self::SHORT_LENGTH);
    }

    /**
     * Whether the configured reference was the commit itself.
     *
     * True for a full SHA and for any abbreviation of one, which is what makes
     * {@see self::describe()} able to avoid printing the same value twice.
     */
    public function wasNamedDirectly(): bool
    {
        return str_starts_with($this->sha, strtolower($this->reference));
    }

    /**
     * One phrase naming both halves, for a log header or a status table.
     */
    public function describe(): string
    {
        if ($this->wasNamedDirectly()) {
            return $this->shortSha();
        }

        return sprintf('%s (%s)', $this->reference, $this->shortSha());
    }

    /**
     * Whether two values name the same commit.
     *
     * The commit only. Two runs that reached this commit through `HEAD` and
     * through `main` are building on the same thing, and that is the question
     * reconciliation asks.
     */
    public function equals(self $other): bool
    {
        return $this->sha === $other->sha;
    }

    public function __toString(): string
    {
        return $this->sha;
    }
}
