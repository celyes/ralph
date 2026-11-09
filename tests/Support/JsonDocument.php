<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * A decoded JSON object, read by field with its type required.
 *
 * For the two outputs this package means another program to read — the
 * `ralph status --json` document and the JSONL event stream (PROJECT.md section
 * I, item 3). A test of either is pinning a contract: that a field is there,
 * under that name, holding that type. Reading one through `$decoded['a']['b']`
 * states none of that, and a renamed field surfaces as a null somewhere
 * downstream rather than as the broken promise it is.
 *
 * So every accessor here is also an assertion. `string('project.root')` says
 * the document must have a `project` object with a `root` string in it, and
 * says so where the field is read, with the path in the failure message.
 *
 * Paths are dotted, because the alternative is a chain of calls whose
 * intermediate steps the test does not care about.
 */
final class JsonDocument
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * One JSON object.
     *
     * @throws RuntimeException when the text is not a JSON object
     */
    public static function parse(string $json): self
    {
        try {
            $decoded = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException(sprintf('Not JSON: %s', $failure->getMessage()), 0, $failure);
        }

        return new self(self::asObject($decoded, '<root>'));
    }

    /**
     * Every object of a JSONL stream, in the order the file holds them.
     *
     * Blank lines are skipped; anything else must be one object per line,
     * because that is the format's whole promise.
     *
     * @return list<self>
     *
     * @throws RuntimeException when a line is not a JSON object
     */
    public static function parseLines(string $contents): array
    {
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => trim($line) !== '',
        ));

        return array_map(static fn (string $line): self => self::parse($line), $lines);
    }

    /**
     * The whole object, for an assertion over all of it at once.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Whether the path is present at all, whatever it holds.
     */
    public function has(string $path): bool
    {
        $values = $this->values;

        foreach (explode('.', $path) as $step) {
            if (!is_array($values) || !array_key_exists($step, $values)) {
                return false;
            }

            $values = $values[$step];
        }

        return true;
    }

    /**
     * Whether the field is present and null.
     *
     * A distinction worth making, because these documents use a present null
     * deliberately: `task` is null on a run-level event, and `run` is null in a
     * project that has never run.
     *
     * @throws RuntimeException when the field is absent
     */
    public function isNull(string $path): bool
    {
        return $this->at($path) === null;
    }

    /**
     * @throws RuntimeException when the field is absent or is not a string
     */
    public function string(string $path): string
    {
        $value = $this->at($path);

        if (!is_string($value)) {
            throw $this->wrongType($path, 'a string', $value);
        }

        return $value;
    }

    /**
     * @throws RuntimeException when the field is absent or is not an integer
     */
    public function integer(string $path): int
    {
        $value = $this->at($path);

        if (!is_int($value)) {
            throw $this->wrongType($path, 'an integer', $value);
        }

        return $value;
    }

    /**
     * @throws RuntimeException when the field is absent or is not a boolean
     */
    public function boolean(string $path): bool
    {
        $value = $this->at($path);

        if (!is_bool($value)) {
            throw $this->wrongType($path, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * A nested object, as a document of its own.
     *
     * @throws RuntimeException when the field is absent or is not an object
     */
    public function object(string $path): self
    {
        return new self(self::asObject($this->at($path), $path));
    }

    /**
     * A list of objects: the document's `tasks`, and the like.
     *
     * @return list<self>
     *
     * @throws RuntimeException when the field is absent or holds anything else
     */
    public function objects(string $path): array
    {
        $value = $this->at($path);

        if (!is_array($value)) {
            throw $this->wrongType($path, 'a list of objects', $value);
        }

        $objects = [];

        foreach (array_values($value) as $position => $item) {
            $objects[] = new self(self::asObject($item, sprintf('%s[%d]', $path, $position)));
        }

        return $objects;
    }

    /**
     * A list of strings: a run's check names, and the like.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the field is absent or holds anything else
     */
    public function strings(string $path): array
    {
        $value = $this->at($path);

        if (!is_array($value)) {
            throw $this->wrongType($path, 'a list of strings', $value);
        }

        $strings = [];

        foreach (array_values($value) as $position => $item) {
            if (!is_string($item)) {
                throw $this->wrongType(sprintf('%s[%d]', $path, $position), 'a string', $item);
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * The value at a dotted path, whatever it is.
     *
     * @throws RuntimeException when any step of the path is missing
     */
    private function at(string $path): mixed
    {
        $values = $this->values;
        $walked = [];

        foreach (explode('.', $path) as $step) {
            $walked[] = $step;

            if (!is_array($values) || !array_key_exists($step, $values)) {
                throw new RuntimeException(sprintf(
                    'The document has no "%s"%s.',
                    implode('.', $walked),
                    $walked === explode('.', $path) ? '' : sprintf(' on the way to "%s"', $path),
                ));
            }

            $values = $values[$step];
        }

        return $values;
    }

    /**
     * The value as an object keyed by strings.
     *
     * Re-keyed rather than asserted over, because a decoded JSON object's keys
     * are strings and PHP's array keys are not: `{"1": true}` decodes to an
     * integer key, and a document read by name should not care.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the value is not an array
     */
    private static function asObject(mixed $value, string $what): array
    {
        if (!is_array($value)) {
            throw new RuntimeException(sprintf(
                '"%s" is %s, not a JSON object.',
                $what,
                get_debug_type($value),
            ));
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    private function wrongType(string $path, string $expected, mixed $value): RuntimeException
    {
        return new RuntimeException(sprintf(
            '"%s" is %s, and %s was required.',
            $path,
            get_debug_type($value),
            $expected,
        ));
    }
}
