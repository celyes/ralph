<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Config\Path;

/**
 * Reads a project's Composer scripts, so that a verification command naming one
 * can be checked against it.
 *
 * `composer test` is the commonest thing a PHP project puts under `checks`,
 * and it resolves perfectly well — Composer is installed — and then fails in
 * every run of a project that defines no `test` script. The executable existing
 * is not the whole of "a usable verification command", and this is the part of
 * the gap that can be closed without running anything.
 *
 * Deliberately conservative: it answers only for an invocation it is sure
 * about, and says "no opinion" for everything else. Being wrong here would
 * block a run over a command that works.
 */
final class ComposerScripts
{
    /**
     * Composer's own commands, as of Composer 2.
     *
     * Needed because `composer <word>` is a script invocation unless the word
     * is one of these. Listing commands rather than detecting scripts the other
     * way round keeps an unknown word — the likely typo, and the likely missing
     * script — reportable.
     *
     * @var list<string>
     */
    private const array COMMANDS = [
        'about', 'archive', 'audit', 'browse', 'bump', 'check-platform-reqs', 'clear-cache', 'clearcache',
        'completion', 'config', 'create-project', 'depends', 'diag', 'diagnose', 'dump-autoload',
        'dumpautoload', 'exec', 'fund', 'global', 'help', 'home', 'i', 'info', 'init', 'install',
        'licenses', 'list', 'outdated', 'prohibits', 'reinstall', 'remove', 'repository', 'req',
        'require', 'rm', 'search', 'self-up', 'self-update', 'show', 'status', 'suggests', 'u',
        'un', 'uninstall', 'up', 'update', 'upgrade', 'validate', 'why', 'why-not',
    ];

    /**
     * The verbs that introduce a script name explicitly.
     *
     * @var list<string>
     */
    private const array RUN_VERBS = ['run', 'run-script'];

    /**
     * Options that move Composer to another directory, after which this class
     * cannot know which `composer.json` applies.
     *
     * @var list<string>
     */
    private const array RELOCATING_OPTIONS = ['-d', '--working-dir'];

    /**
     * Whether a command's executable is Composer.
     *
     * Matched on the file name so that `/usr/local/bin/composer`,
     * `composer.phar` and a vendored `tools/composer` are all recognised.
     */
    public static function isComposer(string $executable): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $executable)));

        return $name === 'composer' || $name === 'composer.phar';
    }

    /**
     * The script a Composer invocation would run, or null when the invocation
     * does not name one or cannot be read with confidence.
     *
     * @param list<string> $arguments the command's arguments, without the executable
     */
    public static function scriptIn(array $arguments): ?string
    {
        $expectScript = false;

        foreach ($arguments as $argument) {
            if (self::relocates($argument)) {
                return null;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            if ($expectScript) {
                return $argument;
            }

            if (in_array($argument, self::RUN_VERBS, true)) {
                $expectScript = true;

                continue;
            }

            return in_array($argument, self::COMMANDS, true) ? null : $argument;
        }

        return null;
    }

    /**
     * The scripts a project defines, or null when there is no readable
     * `composer.json` to ask.
     *
     * @return list<string>|null
     */
    public static function definedIn(string $projectRoot): ?array
    {
        $file = self::fileIn($projectRoot);

        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        $scripts = $decoded['scripts'] ?? [];
        if (!is_array($scripts)) {
            return null;
        }

        return array_values(array_filter(array_keys($scripts), 'is_string'));
    }

    /**
     * Whether the project has a `composer.json` at all.
     */
    public static function projectHasComposerJson(string $projectRoot): bool
    {
        return is_file(self::fileIn($projectRoot));
    }

    public static function fileIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/composer.json');
    }

    /**
     * Whether an argument points Composer at another directory.
     */
    private static function relocates(string $argument): bool
    {
        foreach (self::RELOCATING_OPTIONS as $option) {
            if ($argument === $option || str_starts_with($argument, $option . '=')) {
                return true;
            }

            // The short form takes its value attached, as in "-dpath".
            if ($option === '-d' && str_starts_with($argument, '-d') && strlen($argument) > 2) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
