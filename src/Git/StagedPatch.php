<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * The text of what a task's commit would contain, split by file.
 *
 * Read once, between verification passing and the commit being made, for the
 * two things PROJECT.md section G, item 5 asks for: a reading of whether the
 * change weakens the project's own verification, and a copy of the diff kept
 * for a person to review. Both need the patch itself —
 * {@see StagedChanges} is a summary, and "eleven lines left this test file"
 * does not say whether a case was deleted or a comment was rewrapped.
 *
 * `git diff --cached` with its interpretation pinned, for the same reasons
 * {@see StagedChanges} pins its own commands:
 *
 *  - `--find-renames`, so a repository configured with `diff.renames=false`
 *    does not turn every rename into a deletion this would then report as a
 *    deleted test file.
 *  - `--no-ext-diff` and `--no-textconv`, so the project's own diff drivers
 *    cannot decide what the package reads. A `textconv` filter is arbitrary
 *    code configured in the repository, and a worker can configure one.
 *  - `core.quotePath=false`, so a path outside ASCII arrives as itself rather
 *    than as Git's escaping of it, and matches the path
 *    {@see StagedChanges} reported for the same file.
 *  - `--no-color`, because a patch read by a parser must not depend on whether
 *    the project set `color.diff=always`.
 *
 * **Why the parsing is as shallow as it is.** The sections are split on the
 * `diff --git` line at column zero and the lines are taken from inside hunks,
 * marker stripped; nothing counts line numbers or validates a hunk header.
 * Inside a hunk every line is prefixed — a space, a `+` or a `-` — so a file
 * whose *content* is a patch cannot be mistaken for a section boundary, and
 * that one property is all the structure this needs. A deliberately thin
 * reading also cannot fail on a patch shape it has not met: an unparseable
 * section contributes no lines, which makes a finding less likely rather than
 * more, and a finding this package does not make is a commit a person reviews.
 *
 * **Two limits worth stating.** A binary file has no lines to read at all, so
 * nothing can be concluded about what changing one did. And a path containing
 * a control character is still escaped by Git in a diff header whatever
 * `core.quotePath` says, so its section is attributed to the escaped spelling;
 * its lines are still counted, and only the judgement about *what kind of file*
 * it is would be missed.
 */
final class StagedPatch
{
    /**
     * The arguments producing the patch this class parses.
     *
     * @var list<string>
     */
    public const array COMMAND = [
        '-c',
        'core.quotePath=false',
        'diff',
        '--cached',
        '--find-renames',
        '--no-color',
        '--no-ext-diff',
        '--no-textconv',
    ];

    /**
     * Where a section begins, at column zero.
     */
    private const string SECTION = 'diff --git ';

    /**
     * Where a section's lines begin.
     */
    private const string HUNK = '@@';

    /**
     * What Git prints instead of a hunk for a file it will not read as text.
     */
    private const string BINARY = 'Binary files ';

    /**
     * How a section states a rename it found no content change in, which is the
     * one shape with no `---`/`+++` pair to read a path from.
     */
    private const string RENAME_FROM = 'rename from ';

    private const string RENAME_TO = 'rename to ';

    /**
     * @param list<PatchedFile> $files in the order the patch states them
     */
    private function __construct(
        private readonly string $text,
        private readonly array $files,
    ) {
    }

    /**
     * Read the output of {@see self::COMMAND}.
     *
     * Never raises. A section whose paths cannot be read is dropped rather than
     * reported, which is the conservative direction here: this reading decides
     * whether to *stop* a verified task, and a parser that threw on an
     * unfamiliar patch would turn a diff Git rendered oddly into a failed task.
     * The whole text is kept either way, so what was dropped is still in the
     * file a person reviews.
     */
    public static function parse(string $patch): self
    {
        $files = [];

        foreach (self::sections($patch) as $section) {
            $file = self::fileIn($section);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        return new self($patch, $files);
    }

    /**
     * A patch of an index with nothing in it.
     */
    public static function none(): self
    {
        return new self('', []);
    }

    /**
     * The patch as Git printed it, which is what is preserved for review.
     */
    public function text(): string
    {
        return $this->text;
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * Every file the patch states, in its order.
     *
     * @return list<PatchedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The section for a path, matched on either of a rename's two names, or
     * null when the patch says nothing about it.
     */
    public function forPath(string $path): ?PatchedFile
    {
        foreach ($this->files as $file) {
            if (in_array($path, $file->paths(), true)) {
                return $file;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /**
     * Bytes of patch text, for a log line about what was preserved.
     */
    public function bytes(): int
    {
        return strlen($this->text);
    }

    /**
     * The patch split into one list of lines per file.
     *
     * The `diff --git` line at column zero is the only boundary, and it cannot
     * be mistaken for content: inside a hunk every line carries a space, a `+`
     * or a `-` in front of it, so a file whose own text is a patch is indented
     * by one character before it gets here. Each section keeps that line as its
     * first, because a binary file's section has no other statement of its
     * path.
     *
     * @return list<list<string>>
     */
    private static function sections(string $patch): array
    {
        $sections = [];
        $current = null;

        foreach (self::lines($patch) as $line) {
            if (str_starts_with($line, self::SECTION)) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                // The boundary line is kept as the section's first line: it is
                // the only place a binary file's path is stated, since Git
                // writes no `---`/`+++` pair for one.
                $current = [$line];

                continue;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * One section read into a file, or null when it names no path.
     *
     * A section with no path is one Git described in a shape this does not
     * read — and a pure rename, where there is no content change to describe
     * and so no `---`/`+++` pair, is read from the `rename from`/`rename to`
     * lines instead. Returning null rather than raising is the conservative
     * direction the class note explains: a section that contributes no lines
     * can only make a finding less likely.
     *
     * @param list<string> $section
     */
    private static function fileIn(array $section): ?PatchedFile
    {
        $path = null;
        $original = null;
        $added = [];
        $removed = [];
        $binary = false;
        $inHunk = false;
        $header = null;

        foreach ($section as $line) {
            if ($header === null && str_starts_with($line, self::SECTION)) {
                $header = $line;

                continue;
            }

            if (str_starts_with($line, self::HUNK)) {
                $inHunk = true;

                continue;
            }

            if ($inHunk) {
                if (str_starts_with($line, '+')) {
                    $added[] = substr($line, 1);
                } elseif (str_starts_with($line, '-')) {
                    $removed[] = substr($line, 1);
                }

                continue;
            }

            // Still in the section's header.
            if (str_starts_with($line, '+++ ')) {
                $path = self::pathIn(substr($line, 4)) ?? $path;
            } elseif (str_starts_with($line, '--- ')) {
                $original = self::pathIn(substr($line, 4));
                $path ??= $original;
            } elseif (str_starts_with($line, self::RENAME_TO)) {
                $path = self::pathIn(substr($line, strlen(self::RENAME_TO)));
            } elseif (str_starts_with($line, self::RENAME_FROM)) {
                $original = self::pathIn(substr($line, strlen(self::RENAME_FROM)));
            } elseif (str_starts_with($line, self::BINARY)) {
                $binary = true;
            }
        }

        // Last, and only when nothing better named the file: a section with no
        // path line at all is a binary one, or a mode change.
        $path ??= $header === null ? null : self::pathInHeader($header);

        return $path === null
            ? null
            : PatchedFile::of($path, $original, $added, $removed, $binary);
    }

    /**
     * The patch's lines, with the line endings of either kind removed.
     *
     * @return list<string>
     */
    private static function lines(string $patch): array
    {
        if ($patch === '') {
            return [];
        }

        return array_map(
            static fn (string $line): string => rtrim($line, "\r"),
            explode("\n", $patch),
        );
    }

    /**
     * The path stated by a section's own `diff --git a/P b/P` line.
     *
     * Read by length rather than by splitting on ` b/`, which a filename
     * containing that text would break: for anything other than a rename both
     * halves are the same path, so the only arrangement of `a/P b/P` whose
     * halves agree is the right one. A rename, where they do not, returns null
     * and is read from the `rename from`/`rename to` lines instead; so is a
     * name Git quoted.
     */
    private static function pathInHeader(string $line): ?string
    {
        $value = substr($line, strlen(self::SECTION));

        // "a/" + P + " " + "b/" + P
        $length = intdiv(strlen($value) - 5, 2);

        if ($length < 1 || strlen($value) !== 5 + (2 * $length)) {
            return null;
        }

        $first = substr($value, 0, $length + 2);
        $second = substr($value, $length + 3);

        if (!str_starts_with($first, 'a/') || !str_starts_with($second, 'b/')) {
            return null;
        }

        return substr($first, 2) === substr($second, 2) ? substr($first, 2) : null;
    }

    /**
     * The repository-relative path in a `---` or `+++` header.
     *
     * `/dev/null` is Git saying the file is not on that side of the diff, which
     * is an absence rather than a path. Everything else carries the `a/` or
     * `b/` prefix Git adds, and only the first one is removed: a repository
     * holding a directory called `b` would otherwise have `b/b/x.php` read as
     * `x.php`.
     */
    private static function pathIn(string $header): ?string
    {
        // Git appends a tab when the name contains whitespace, to mark where
        // the name ends. Exactly one is removed, and no spaces are: a trailing
        // space is a legal part of a filename, and trimming it would attribute
        // a section to a file beside the one it is about.
        $value = str_ends_with($header, "\t") ? substr($header, 0, -1) : $header;

        // A name holding a control character is escaped and quoted whatever
        // `core.quotePath` says. The quotes are removed so the `a/` prefix can
        // be; the escapes are left as Git wrote them, which is the limit the
        // class note states.
        if (strlen($value) > 1 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        if ($value === '' || $value === '/dev/null') {
            return null;
        }

        foreach (['a/', 'b/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }
}
