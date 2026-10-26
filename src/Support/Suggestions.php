<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

/**
 * "Did you mean …?" suggestions for a name the user got slightly wrong.
 *
 * Used for mistyped command names and for mistyped configuration settings, so
 * both report a typo the same way.
 */
final class Suggestions
{
    /**
     * Candidates close enough to the given name to suggest, nearest first.
     *
     * A candidate matches when it is within an edit distance scaled to its own
     * length (so short names are not matched to everything) or when the given
     * name is a substring of it, which catches truncations like "ver" for
     * "version".
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public static function for(string $name, array $candidates): array
    {
        $needle = strtolower($name);
        $matches = [];

        foreach ($candidates as $candidate) {
            $distance = levenshtein($needle, strtolower($candidate));
            $tolerance = (int) ceil(max(strlen($candidate), 1) / 3);

            if ($distance <= max($tolerance, 2) || ($needle !== '' && str_contains(strtolower($candidate), $needle))) {
                $matches[$candidate] = $distance;
            }
        }

        asort($matches);

        return array_keys($matches);
    }

    /**
     * The suggestions rendered as a sentence, or null when there are none.
     *
     * @param list<string> $candidates
     */
    public static function sentence(string $name, array $candidates): ?string
    {
        $matches = self::for($name, $candidates);

        if ($matches === []) {
            return null;
        }

        return sprintf(
            'Did you mean %s?',
            implode(' or ', array_map(static fn (string $match): string => '"' . $match . '"', $matches)),
        );
    }

    private function __construct()
    {
    }
}
