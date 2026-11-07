<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * One file's section of a staged patch: the lines it gains and the lines it
 * loses.
 *
 * {@see StagedChanges} already says *what happened* to each path and *how many*
 * lines moved. What it cannot say is *which* lines, and that is the only thing
 * a reading of whether a diff weakens a project's own tests can be made from:
 * a test file that loses eleven lines has either had a case deleted or had its
 * formatting changed, and the difference is in the text.
 *
 * Deliberately a bag of lines rather than a model of hunks. Nothing in this
 * package needs to know where in a file a change landed, apply a patch, or
 * reverse one — {@see \Celyes\RalphPhp\Verification\ChecksAuditor} counts
 * occurrences of things in what was added against what was removed — and a
 * hunk model would be a second, worse Git.
 *
 * The lines are stored without their `+` or `-` marker, so a pattern written
 * here matches the source as the file holds it rather than as a diff renders
 * it. The context lines a patch carries are dropped: a line present both before
 * and after is not a change, and keeping it would make every count a count of
 * the diff's `-U` setting.
 */
final class PatchedFile
{
    /**
     * @param list<string> $added   lines this file gains, marker stripped
     * @param list<string> $removed lines this file loses, marker stripped
     */
    private function __construct(
        private readonly string $path,
        private readonly ?string $originalPath,
        private readonly array $added,
        private readonly array $removed,
        private readonly bool $binary,
    ) {
    }

    /**
     * One file's section.
     *
     * @param string|null $originalPath where the content came from, when the
     *        patch is of a rename or a copy
     * @param list<string> $added
     * @param list<string> $removed
     *
     * @throws InvalidArgumentException when the section names no path, which
     *                                 would make its lines unattributable
     */
    public static function of(
        string $path,
        ?string $originalPath = null,
        array $added = [],
        array $removed = [],
        bool $binary = false,
    ): self {
        if ($path === '') {
            throw new InvalidArgumentException('A patched file must name a path.');
        }

        return new self(
            $path,
            $originalPath === '' || $originalPath === $path ? null : $originalPath,
            $added,
            $removed,
            $binary,
        );
    }

    /**
     * The path as the patch names it, relative to the repository root.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Where the content was before, for a rename or a copy, and null otherwise.
     */
    public function originalPath(): ?string
    {
        return $this->originalPath;
    }

    /**
     * Every path this section is about — one, or two for a rename.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->originalPath === null ? [$this->path] : [$this->path, $this->originalPath];
    }

    /**
     * Whether Git declined to read the file as text.
     *
     * A binary section has no lines at all, and a reading of what its change
     * did to a project's tests cannot be made from it — which is a limit worth
     * carrying on the value rather than inferring from two empty arrays.
     */
    public function isBinary(): bool
    {
        return $this->binary;
    }

    /**
     * Lines the file gains, markers stripped.
     *
     * @return list<string>
     */
    public function added(): array
    {
        return $this->added;
    }

    /**
     * Lines the file loses, markers stripped.
     *
     * @return list<string>
     */
    public function removed(): array
    {
        return $this->removed;
    }

    /**
     * How many lines matching the pattern the file gains.
     *
     * Occurrences rather than lines: two assertions written on one line are two
     * assertions, and a count of lines would let a diff that joined them read
     * as a diff that removed one.
     *
     * @param non-empty-string $pattern a PCRE the caller owns
     */
    public function additions(string $pattern): int
    {
        return self::occurrences($this->added, $pattern);
    }

    /**
     * How many lines matching the pattern the file loses.
     *
     * @param non-empty-string $pattern a PCRE the caller owns
     */
    public function removals(string $pattern): int
    {
        return self::occurrences($this->removed, $pattern);
    }

    /**
     * What the file's count of the pattern did: positive for more, negative for
     * fewer.
     *
     * @param non-empty-string $pattern a PCRE the caller owns
     */
    public function net(string $pattern): int
    {
        return $this->additions($pattern) - $this->removals($pattern);
    }

    /**
     * One line for a log.
     */
    public function describe(): string
    {
        return sprintf(
            '%s%s (+%d, -%d)%s',
            $this->path,
            $this->originalPath === null ? '' : ' from ' . $this->originalPath,
            count($this->added),
            count($this->removed),
            $this->binary ? ' binary' : '',
        );
    }

    /**
     * @param list<string> $lines
     * @param non-empty-string $pattern
     */
    private static function occurrences(array $lines, string $pattern): int
    {
        $found = 0;

        foreach ($lines as $line) {
            $count = preg_match_all($pattern, $line);

            if ($count !== false) {
                $found += $count;
            }
        }

        return $found;
    }
}
