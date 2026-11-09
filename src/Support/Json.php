<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

/**
 * How this package writes JSON meant for another program to read.
 *
 * Two shapes, one encoder. `ralph status --json` prints a document a person
 * will also end up reading, so it is indented; the event stream is JSONL, where
 * one event is one line and an indented object would break the format. Both go
 * through here so that the escaping, the flags and — the part that matters —
 * the handling of text this package did not write are decided once.
 *
 * **Nothing it encodes can make it throw.** Almost every string in a status
 * document or an event came, somewhere along the way, out of another process: a
 * check's output, an agent's account of what it did, a Git error. Any of them
 * can hold bytes that are not valid UTF-8, and `json_encode` refuses those
 * outright. A diagnostic that fails on the one run whose output was unusual is
 * a diagnostic that fails when it is needed, and an event stream that broke a
 * run because an agent printed a stray byte would be worse than having none. So
 * invalid sequences are dropped on the way out, and the floats JSON has no
 * spelling for become null.
 *
 * The dropped bytes are not lost: the task's log is the copy that is meant to
 * be exact ({@see \Celyes\RalphPhp\Log\LogFile}), and this is a report about it.
 *
 * **Slashes and non-ASCII are left alone.** `.ralph/runtime/logs` is a path a
 * person reads in both outputs and `\/` is noise in it; escaped Unicode is the
 * same problem for a task description written in any other language. Neither
 * escape is required by JSON, and both outputs are UTF-8.
 */
final class Json
{
    /**
     * Flags both shapes share.
     *
     * `JSON_THROW_ON_ERROR` stays on deliberately, even beside the two flags
     * that keep it from firing: a failure that got past them is a bug here, and
     * silently returning `false` from `json_encode` would turn it into an empty
     * line in an event stream.
     */
    private const int FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_IGNORE;

    private function __construct()
    {
    }

    /**
     * One JSON object on one line, for a JSONL stream.
     *
     * No line ending is added: the writer decides that, and a value that
     * carried its own would be a second record as far as a reader is concerned.
     * Line endings inside strings are escaped by the encoder, so one call is
     * always exactly one line.
     *
     * @param array<string, mixed> $value
     */
    public static function line(array $value): string
    {
        return (string) json_encode(self::encodable($value), self::FLAGS);
    }

    /**
     * An indented JSON document, for output a person may also read.
     *
     * @param array<string, mixed> $value
     */
    public static function document(array $value): string
    {
        return (string) json_encode(self::encodable($value), self::FLAGS | JSON_PRETTY_PRINT);
    }

    /**
     * The same value, with the numbers JSON cannot hold replaced.
     *
     * `NAN` and `INF` have no JSON spelling, so they become null, which says
     * the number is missing rather than inventing one. No duration or count this
     * package measures can reach either — the walk is here because the
     * alternative to a cheap guard is a report that throws instead of printing,
     * and the whole point of these two outputs is to be read when something has
     * already gone wrong.
     */
    private static function encodable(mixed $value): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        if (!is_array($value)) {
            return $value;
        }

        return array_map(static fn (mixed $item): mixed => self::encodable($item), $value);
    }
}
