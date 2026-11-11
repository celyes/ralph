<?php

declare(strict_types=1);

namespace RalphPhpExample\TextTools;

use InvalidArgumentException;

/**
 * Turns an article title into a slug usable in a URL.
 *
 * Letters and digits are kept, everything else becomes the separator, and the
 * result is lowercase with no separator at either end.
 *
 * There is deliberately no length limit: the first task in `.ralph/tasks.md`
 * adds one.
 */
final class Slugger
{
    /**
     * The separators a slug may be built from.
     *
     * A fixed set rather than any string, because the separator is used as a
     * replacement in a regular expression and as a trim character, and neither
     * is safe for arbitrary input.
     *
     * @var list<string>
     */
    private const array SEPARATORS = ['-', '_'];

    /**
     * @throws InvalidArgumentException when the separator is not `-` or `_`
     */
    public function __construct(private readonly string $separator = '-')
    {
        if (!in_array($separator, self::SEPARATORS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The slug separator must be one of "%s", got "%s".',
                    implode('", "', self::SEPARATORS),
                    $separator,
                ),
            );
        }
    }

    /**
     * The slug of a title, or an empty string when it holds nothing sluggable.
     */
    public function slugify(string $title): string
    {
        $lowercase = mb_strtolower($title, 'UTF-8');
        $separated = preg_replace('/[^\p{L}\p{N}]+/u', $this->separator, $lowercase) ?? '';

        return trim($separated, $this->separator);
    }
}
