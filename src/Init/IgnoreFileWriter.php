<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Path;

/**
 * Puts the rules of {@see IgnoreRules} into the project's `.gitignore`.
 *
 * The ignore file is the one path `ralph init` writes that it does not own: it
 * usually exists before Ralph PHP does, and everything in it was put there by
 * the user or by another tool. So this only ever *appends*, and only the rules
 * the file does not already decide. Nothing is reordered, rewritten or removed,
 * `init --force` does not change that, and a rule the user has negated with `!`
 * is left negated — tracking the checklist is a documented choice (PROJECT.md
 * section 4), and a command that overruled it on every run would take it away.
 * A line that is merely deleted is added again, because nothing distinguishes
 * it from a project that never had the rule; the block written says so.
 *
 * Failing to write ignore rules does not fail initialisation. The rules are a
 * convenience for a repository the user still controls, and losing the
 * configuration, checklist and context file over an unwritable `.gitignore`
 * would be a worse outcome than reporting it.
 */
final class IgnoreFileWriter
{
    /**
     * Name of the ignore file, at the project root.
     *
     * One file at the root rather than a `.gitignore` per directory: the
     * checklist is a configured path that can live anywhere in the project, and
     * the root file is where a user looks for rules they did not write.
     */
    public const string FILE = '.gitignore';

    /**
     * Introduces the appended block, so the rules can be recognised as this
     * package's and the user knows removing one is allowed.
     *
     * @var list<string>
     */
    private const array HEADER = [
        '# Ralph PHP: generated runtime data, and the local task checklist.',
        '# To track one of these yourself, negate its rule with "!" — a negated rule is',
        '# left alone, whereas a line simply deleted is added again by "ralph init".',
    ];

    /**
     * Ensure the project's ignore file decides every rule the project needs.
     */
    public function update(string $root, Configuration $configuration): IgnoreFileUpdate
    {
        $root = Path::normalize($root);
        $path = $root . '/' . self::FILE;
        $rules = IgnoreRules::forProject($root, $configuration);

        ['contents' => $contents, 'reason' => $reason] = $this->read($path);

        if ($reason !== null) {
            return $this->skipped($path, $rules, $reason);
        }

        $missing = $rules->missingFrom($contents ?? '');

        if ($missing === []) {
            return new IgnoreFileUpdate($path, self::FILE, IgnoreFileOutcome::Kept);
        }

        $created = $contents === null;

        if (!$this->put($path, $this->block($contents ?? '', $missing))) {
            return $this->skipped($path, $rules, 'it could not be written');
        }

        return new IgnoreFileUpdate(
            $path,
            self::FILE,
            $created ? IgnoreFileOutcome::Created : IgnoreFileOutcome::Extended,
            $missing,
        );
    }

    /**
     * The whole file, with the missing rules appended.
     *
     * Built as one string and written in a single call rather than appended to
     * an open handle, so the previous contents are in hand: the block is
     * separated from whatever the file already ends with, and a file that does
     * not end in a newline does not get a rule glued onto its last line.
     *
     * @param list<string> $missing
     */
    private function block(string $contents, array $missing): string
    {
        $block = implode("\n", [...self::HEADER, ...$missing]) . "\n";

        if ($contents === '') {
            return $block;
        }

        // A file that does not end in a newline needs one of its own before the
        // blank line that separates the block from the rules already there.
        if (!str_ends_with($contents, "\n")) {
            return $contents . "\n\n" . $block;
        }

        return str_ends_with($contents, "\n\n") ? $contents . $block : $contents . "\n" . $block;
    }

    /**
     * The current contents of the ignore file, or the reason there are none to
     * work from.
     *
     * A missing file is not a reason: it is the ordinary case of a project with
     * no rules yet, and is reported as null contents.
     *
     * @return array{contents: string|null, reason: string|null}
     */
    private function read(string $path): array
    {
        // Checked before file_exists() so a link to a file outside the project
        // is refused rather than appended to: Git would not read it as this
        // repository's ignore file, and writing it edits someone else's file.
        if (is_link($path)) {
            return self::unusable('it is a symbolic link');
        }

        if (is_dir($path)) {
            return self::unusable('a directory exists at that path');
        }

        if (!file_exists($path)) {
            return ['contents' => null, 'reason' => null];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return self::unusable('it could not be read');
        }

        return ['contents' => $contents, 'reason' => null];
    }

    /**
     * @return array{contents: null, reason: string}
     */
    private static function unusable(string $reason): array
    {
        return ['contents' => null, 'reason' => $reason];
    }

    private function put(string $path, string $contents): bool
    {
        return @file_put_contents($path, $contents) !== false;
    }

    /**
     * Nothing was written, and the rules the project still needs are carried so
     * the user can add them by hand.
     */
    private function skipped(string $path, IgnoreRules $rules, string $reason): IgnoreFileUpdate
    {
        return new IgnoreFileUpdate(
            $path,
            self::FILE,
            IgnoreFileOutcome::Skipped,
            $rules->patterns(),
            $reason,
        );
    }
}
