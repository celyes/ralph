<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * A branch name Git will actually accept, as a value.
 *
 * Task branches are generated from configuration and from the user's own
 * wording ({@see BranchNamer}), which means the package can produce a name Git
 * refuses — and the moment that happens matters. A name checked here is refused
 * before a worktree is prepared or an agent is invoked, with a message naming
 * what is wrong with it; a name checked only by `git branch` is refused after
 * the work has been done, when the commit has nowhere to go.
 *
 * The rules are Git's, not an approximation of them, and they are pinned by a
 * test that asks the installed `git check-ref-format` and `git branch` the same
 * questions. Two of them are not in `check-ref-format` output at all and were
 * found by asking `git branch`:
 *
 *  - **`HEAD`** is a valid *reference* name and not a valid *branch* name.
 *    `git check-ref-format refs/heads/HEAD` succeeds while `git branch HEAD`
 *    refuses, because the name would shadow the pointer every command resolves.
 *    Only the whole name: `ralph/HEAD` is accepted by both.
 *  - **A leading `-`** is refused by `git branch` even after `--`, and would in
 *    any case be read as an option by anything less careful.
 *
 * Valid UTF-8 is required on top of Git's rules. Git stores a ref name as
 * bytes, but this one is written to SQLite, printed in status output and
 * serialised into the JSON events of PROJECT.md section I, none of which can
 * carry a byte that is not text.
 *
 * Length is deliberately not validated. The real limit is the filesystem's —
 * a loose ref is a file, and its name plus `.lock` must fit in a directory
 * entry — so it depends on the ref's last segment, on whether refs are packed,
 * and on the machine. Bounding the names the package *generates* is
 * {@see BranchNamer}'s job; refusing a name a repository already holds is not
 * this type's business, because the only thing to do with such a branch is
 * report it as it is spelled.
 */
final class BranchName
{
    /**
     * The namespace every branch lives in.
     *
     * Used by {@see self::reference()} for the commands that must not be given
     * a bare name — a branch called `main` and a tag called `main` are both
     * `main` to `git rev-parse`, and the fully qualified form is the only
     * spelling that cannot resolve to the wrong object.
     */
    public const string NAMESPACE = 'refs/heads/';

    /**
     * Characters Git refuses anywhere in a reference name: ASCII control
     * characters, DEL, space, and the six it reserves for revision syntax plus
     * the backslash.
     */
    private const string FORBIDDEN = '/[\x00-\x1F\x7F ~^:?*\[\\\\]/';

    private function __construct(private readonly string $value)
    {
    }

    /**
     * Read a branch name, refusing anything Git would.
     *
     * Surrounding whitespace is forgiven because a name arrives from places
     * that add it — a line of `git branch --list` output, a column read back
     * from the database, a value typed at a prompt — and no Git reference can
     * contain it, so there is nothing to lose by trimming.
     *
     * @throws InvalidArgumentException when Git would not hold a branch under
     *                                 this name
     */
    public static function fromString(string $name): self
    {
        $candidate = trim($name);
        $problem = self::problemWith($candidate);

        if ($problem !== null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a usable branch name: it %s.',
                $name,
                $problem,
            ));
        }

        return new self($candidate);
    }

    /**
     * Whether text can be used as a branch name.
     */
    public static function isValid(string $name): bool
    {
        return self::problemWith(trim($name)) === null;
    }

    /**
     * Why Git would refuse this name, phrased to follow "it …", or null when
     * Git would accept it.
     *
     * Public because the same sentence is useful without an exception: a
     * generated name is reported against the setting that produced it, and a
     * name a user typed is reported as a usage error.
     */
    public static function problemWith(string $name): ?string
    {
        if ($name === '') {
            return 'is empty';
        }

        if (!mb_check_encoding($name, 'UTF-8')) {
            return 'is not valid UTF-8, so it could not be recorded or printed';
        }

        if (preg_match(self::FORBIDDEN, $name) === 1) {
            return 'contains a space, a control character or one of ~ ^ : ? * [ \\';
        }

        if (str_contains($name, '..')) {
            return 'contains "..", which Git reads as a range';
        }

        if (str_contains($name, '@{')) {
            return 'contains "@{", which Git reads as a reflog entry';
        }

        if (str_starts_with($name, '-')) {
            return 'starts with "-", which Git reads as a command-line option';
        }

        if ($name === 'HEAD') {
            return 'is "HEAD", which names the commit that is checked out rather than a branch';
        }

        if (str_starts_with($name, '/') || str_ends_with($name, '/') || str_contains($name, '//')) {
            return 'has an empty path segment';
        }

        if (str_ends_with($name, '.')) {
            return 'ends with "."';
        }

        foreach (explode('/', $name) as $segment) {
            if (str_starts_with($segment, '.')) {
                return 'has a segment starting with "."';
            }

            if (str_ends_with($segment, '.lock')) {
                return 'has a segment ending with ".lock", which Git uses for its own lock files';
            }
        }

        return null;
    }

    /**
     * The name as Git holds it.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * The fully qualified reference, for the commands a bare name is ambiguous
     * to.
     */
    public function reference(): string
    {
        return self::NAMESPACE . $this->value;
    }

    /**
     * Whether the name was generated under a prefix.
     *
     * The weakest half of the question PROJECT.md section F, item 4 asks: a
     * branch outside the configured prefix was certainly not generated by this
     * package, while one inside it may still belong to another pass or to a
     * person who likes the same prefix. Which pass owns a branch is recorded,
     * never derived from its spelling — see {@see BranchNamer}.
     */
    public function hasPrefix(string $prefix): bool
    {
        return $prefix !== '' && str_starts_with($this->value, $prefix);
    }

    /**
     * Whether two values name the same branch.
     *
     * Byte-for-byte: Git reference names are case-sensitive, and on a
     * case-insensitive filesystem two names differing only in case are the same
     * ref, which is a property of that machine rather than of the name.
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
