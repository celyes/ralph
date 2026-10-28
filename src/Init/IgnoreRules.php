<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;

/**
 * The Git ignore rules a Ralph PHP project needs.
 *
 * Three things the package produces must stay out of a commit: the runtime
 * directory, which holds the state database, raw worker output and task
 * worktrees; the operational task checklist, which is a local work queue rather
 * than project source (PROJECT.md section 4); and the `.bak` copies
 * `init --force` leaves behind. Everything else initialisation writes — the
 * configuration and the context file — is meant to be shared, and is not
 * ignored.
 *
 * The rules are derived from the project's own configuration, not hard-coded,
 * so a checklist configured as `docs/backlog.md` is ignored where it actually
 * is. Both configured paths are validated to resolve inside the project before
 * a configuration can be loaded, so every rule can be anchored to the project
 * root; the check is repeated here only so that a path from somewhere else
 * could never be turned into a rule about an unrelated file.
 */
final class IgnoreRules
{
    /**
     * @param list<string> $patterns gitignore patterns, anchored to the project root
     */
    private function __construct(private readonly array $patterns)
    {
    }

    /**
     * The rules for a project, read from the configuration it will run with.
     */
    public static function forProject(string $root, Configuration $configuration): self
    {
        $root = Path::normalize($root);
        $checklist = $configuration->tasksFile();

        $patterns = [self::directory($root, Path::normalize($root . '/' . Defaults::RUNTIME_DIRECTORY))];

        if (Path::isInside($root, $checklist)) {
            $patterns[] = self::file($root, $checklist);
        }

        // The backups are named after the files they copy, so their rules are
        // derived from the same paths rather than assuming `.ralph/`.
        $managed = [
            ConfigurationLoader::fileIn($root),
            $checklist,
            $configuration->contextFile(),
        ];

        foreach ($managed as $path) {
            if (Path::isInside($root, $path)) {
                $patterns[] = self::file($root, $path) . ProjectInitializer::BACKUP_SUFFIX . '*';
            }
        }

        return new self(array_values(array_unique($patterns)));
    }

    /**
     * Every rule the project needs, whether or not it is already in place.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * The rules the given ignore-file contents do not already decide.
     *
     * A rule counts as decided when the file already ignores the path, and also
     * when it explicitly *un*-ignores it with a `!` rule: a user who has chosen
     * to track their checklist must not have the rule put back on the next
     * `ralph init`.
     *
     * The comparison is textual and deliberately incomplete — it is not a
     * gitignore implementation. It recognises the same rule written with or
     * without a leading or trailing slash, a bare file name, a wildcard rule
     * that matches, and an ignored parent directory. Anything subtler is
     * treated as missing, which restates a rule Git already applies; a
     * duplicate rule changes nothing about what is ignored. It errs the other
     * way too: an existing `*.bak` is taken to decide our `.bak*` rule, so a
     * second numbered backup can stay visible in `git status`. Both costs are
     * cosmetic, and neither can un-ignore a path the user asked to be ignored.
     *
     * @return list<string>
     */
    public function missingFrom(string $contents): array
    {
        $existing = self::read($contents);

        $missing = [];
        foreach ($this->patterns as $pattern) {
            if (!self::isDecided($pattern, $existing)) {
                $missing[] = $pattern;
            }
        }

        return $missing;
    }

    /**
     * The rules of an ignore file, as comparable parts.
     *
     * @return list<array{key: string, negated: bool}>
     */
    private static function read(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);
        if ($lines === false) {
            return [];
        }

        $rules = [];
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $negated = str_starts_with($line, '!');

            $rules[] = [
                'key' => self::key($negated ? substr($line, 1) : $line),
                'negated' => $negated,
            ];
        }

        return $rules;
    }

    /**
     * Whether one needed rule is already decided by the file's own rules.
     *
     * @param list<array{key: string, negated: bool}> $existing
     */
    private static function isDecided(string $pattern, array $existing): bool
    {
        $key = self::key($pattern);

        // The path the rule stands for, with our one wildcard dropped, so a
        // file's backup rule is compared as the file name it is built from.
        $path = rtrim($key, '*');
        $name = basename($path);

        foreach ($existing as $rule) {
            $anchored = str_contains($rule['key'], '/');

            // The same rule, however it is written, and whichever way it points.
            if ($rule['key'] === $key || self::matches($rule['key'], $anchored ? $path : $name, $anchored)) {
                return true;
            }

            // Only an *ignored* parent decides a path below it: Git cannot
            // re-include a path whose parent directory is excluded, so a
            // negated parent decides nothing.
            if ($rule['negated']) {
                continue;
            }

            if ($anchored) {
                if (str_starts_with($path, $rule['key'] . '/')) {
                    return true;
                }

                continue;
            }

            // A rule without a slash matches that name at any depth, including
            // a directory — which carries everything below it with it.
            foreach (self::parentSegments($path) as $segment) {
                if (self::matches($rule['key'], $segment, false)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether an existing rule matches one path or name.
     *
     * fnmatch() is absent on some platforms; where it is, a wildcard rule is
     * treated as not matching, which can only add a rule that was already
     * there.
     */
    private static function matches(string $rule, string $subject, bool $anchored): bool
    {
        if (!self::hasWildcard($rule)) {
            return $rule === $subject;
        }

        if (!function_exists('fnmatch')) {
            return false;
        }

        return $anchored ? fnmatch($rule, $subject, FNM_PATHNAME) : fnmatch($rule, $subject);
    }

    /**
     * The directories a path sits in, relative to the project root.
     *
     * @return list<string>
     */
    private static function parentSegments(string $path): array
    {
        $segments = explode('/', $path);
        array_pop($segments);

        return array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

    private static function hasWildcard(string $rule): bool
    {
        return strpbrk($rule, '*?[') !== false;
    }

    /**
     * A rule reduced to what two spellings of the same rule have in common:
     * escapes removed, and the leading and trailing slash that only say where
     * it is anchored dropped.
     */
    private static function key(string $rule): string
    {
        $unescaped = preg_replace('/\\\\(.)/', '$1', $rule) ?? $rule;

        return trim($unescaped, '/');
    }

    /**
     * The rule for a directory inside the project: anchored to the project
     * root and marked as a directory, so it cannot match a file of that name.
     */
    private static function directory(string $root, string $path): string
    {
        return self::file($root, $path) . '/';
    }

    /**
     * The rule for one file inside the project, anchored to the project root so
     * it cannot match a same-named file elsewhere in the repository.
     */
    private static function file(string $root, string $path): string
    {
        return '/' . self::escape(substr($path, strlen(rtrim($root, '/')) + 1));
    }

    /**
     * Escape the characters Git reads as pattern syntax, so a configured path
     * is matched literally. The leading slash this is written with rules out
     * the `#` and `!` that are only special at the start of a rule.
     */
    private static function escape(string $path): string
    {
        $escaped = str_replace(['\\', '*', '?', '[', ']'], ['\\\\', '\*', '\?', '\[', '\]'], $path);

        // A trailing space is stripped by Git unless it is escaped.
        return preg_replace('/ $/', '\\ ', $escaped) ?? $escaped;
    }
}
