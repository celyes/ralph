<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use InvalidArgumentException;

/**
 * One path in the index after staging, with what the diff says about it.
 *
 * The record a reviewer reads before looking at the commit itself, so it keeps
 * the two things a summary line needs and nothing else: what happened to the
 * path, and how much of it changed. A binary file has no line counts at all —
 * Git prints `-` for both — and that absence is carried as null rather than
 * flattened to zero, because "no lines changed" and "lines are not a
 * meaningful measure of this file" are different facts about a commit, and a
 * summary that said an added PNG changed nothing would be wrong.
 *
 * The status is as `git diff --name-status` prints it, score digits included:
 * `R100` is a pure rename and `R087` is a rename Git detected from a file that
 * also changed, and a package that dropped the score would be throwing away
 * the part a reviewer uses to decide whether to read the diff. The path is as
 * `-z` output gives it — relative to the repository root, forward slashes,
 * verbatim and unquoted.
 */
final class StagedFile
{
    /**
     * What each status letter means, as a word for a message.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'A' => 'added',
        'C' => 'copied',
        'D' => 'deleted',
        'M' => 'modified',
        'R' => 'renamed',
        'T' => 'type changed',
        'U' => 'unmerged',
    ];

    private function __construct(
        private readonly string $status,
        private readonly string $path,
        private readonly ?string $originalPath,
        private readonly ?int $insertions,
        private readonly ?int $deletions,
    ) {
    }

    /**
     * @param string $status the status field `--name-status` printed, such as
     *        `M`, `A` or `R100`
     * @param string $path the path Git named, relative to the repository root
     * @param string|null $originalPath where a renamed or copied path came from
     * @param int|null $insertions added lines, or null for a binary file
     * @param int|null $deletions removed lines, or null for a binary file
     *
     * @throws InvalidArgumentException when the status or path is empty, or a
     *                                 line count is negative
     */
    public static function of(
        string $status,
        string $path,
        ?string $originalPath = null,
        ?int $insertions = null,
        ?int $deletions = null,
    ): self {
        if ($status === '') {
            throw new InvalidArgumentException('A staged file must say what happened to it.');
        }

        if ($path === '') {
            throw new InvalidArgumentException('A staged file must name a path.');
        }

        foreach (['insertions' => $insertions, 'deletions' => $deletions] as $name => $count) {
            if ($count !== null && $count < 0) {
                throw new InvalidArgumentException(sprintf(
                    'A staged file cannot have %d %s.',
                    $count,
                    $name,
                ));
            }
        }

        return new self($status, $path, $originalPath === '' ? null : $originalPath, $insertions, $deletions);
    }

    /**
     * The status field as Git printed it, score digits included.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The path Git named, relative to the repository root.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Where a renamed or copied path came from, or null for everything else.
     */
    public function originalPath(): ?string
    {
        return $this->originalPath;
    }

    /**
     * Every path this entry is about, so that a caller checking a commit's
     * contents against an exclusion sees both halves of a rename.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->originalPath === null ? [$this->path] : [$this->path, $this->originalPath];
    }

    /**
     * Added lines, or null when the file is binary.
     */
    public function insertions(): ?int
    {
        return $this->insertions;
    }

    /**
     * Removed lines, or null when the file is binary.
     */
    public function deletions(): ?int
    {
        return $this->deletions;
    }

    /**
     * Whether Git declined to count this file's lines.
     */
    public function isBinary(): bool
    {
        return $this->insertions === null || $this->deletions === null;
    }

    /**
     * One line naming the change, the path and its size.
     */
    public function describe(): string
    {
        $line = sprintf('%s: %s', $this->label(), $this->path);

        if ($this->originalPath !== null) {
            $line = sprintf('%s (was %s)', $line, $this->originalPath);
        }

        if ($this->isBinary()) {
            return sprintf('%s (binary)', $line);
        }

        return sprintf('%s (+%d, -%d)', $line, $this->insertions, $this->deletions);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'path' => $this->path,
            'original_path' => $this->originalPath,
            'insertions' => $this->insertions,
            'deletions' => $this->deletions,
            'binary' => $this->isBinary(),
        ];
    }

    /**
     * The status as a word.
     *
     * A letter Git adds later is reported as "changed" rather than as itself:
     * an unfamiliar character in the middle of a sentence says less than the
     * honest general word, and the path beside it is what the reader needs.
     */
    private function label(): string
    {
        return self::NAMES[$this->status[0]] ?? 'changed';
    }
}
