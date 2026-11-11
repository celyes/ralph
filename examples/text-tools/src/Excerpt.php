<?php

declare(strict_types=1);

namespace RalphPhpExample\TextTools;

use InvalidArgumentException;

/**
 * Shortens an article body to a preview of a given length.
 *
 * Whitespace is collapsed to single spaces so that a preview taken from
 * Markdown or HTML-ish text reads as one line.
 *
 * The cut is made at the character limit, which can leave half a word in front
 * of the ellipsis: the second task in `.ralph/tasks.md` is to cut on a word
 * boundary instead.
 */
final class Excerpt
{
    /**
     * Appended to a text that had to be cut.
     */
    public const string ELLIPSIS = '…';

    /**
     * @param int $length the greatest number of characters the preview may
     *                    hold, not counting the ellipsis
     *
     * @throws InvalidArgumentException when the length is below 1
     */
    public function __construct(private readonly int $length = 160)
    {
        if ($length < 1) {
            throw new InvalidArgumentException(
                sprintf('An excerpt length must be at least 1 character, got %d.', $length),
            );
        }
    }

    /**
     * The preview of a text, with an ellipsis when it had to be cut.
     */
    public function from(string $text): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($collapsed, 'UTF-8') <= $this->length) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, $this->length, 'UTF-8')) . self::ELLIPSIS;
    }
}
