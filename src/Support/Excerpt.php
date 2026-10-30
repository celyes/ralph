<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

/**
 * A piece of the user's own text, short enough to quote inside a message.
 *
 * Errors about a checklist quote the line or the description they are about, so
 * the user can find it without counting lines. A task description can be a
 * paragraph long, though, and a wrapped error message is harder to read than a
 * truncated one — so quoting goes through here, and every message truncates at
 * the same width with the same ellipsis.
 *
 * Measured in characters rather than bytes, so a description written in a
 * non-Latin script is cut between characters instead of in the middle of one.
 */
final class Excerpt
{
    /**
     * The default width: long enough for a realistic task, short enough to sit
     * inside one line of a terminal next to the rest of a sentence. A
     * readability choice with nothing else behind it.
     */
    public const int WIDTH = 60;

    /**
     * The text, trimmed and shortened to at most the given width.
     */
    public static function of(string $text, int $width = self::WIDTH): string
    {
        $text = trim($text);

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width - 1) . '…' : $text;
    }

    private function __construct()
    {
    }
}
