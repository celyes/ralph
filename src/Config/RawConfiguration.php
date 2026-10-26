<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Support\Suggestions;
use JsonException;

/**
 * A decoded configuration object, read one setting at a time with its type
 * checked and its default applied.
 *
 * Decoded JSON is `mixed`: every setting has to be type-checked before it can
 * be handed to a typed value object, and a wrong type has to name the setting
 * the user must fix. Doing that inline in the loader would bury the structure
 * of the configuration in type juggling, so it lives here instead.
 *
 * Instances are scoped to one level of the document and carry the key path
 * leading to it, so a message about a nested setting reads
 * `"limits.iterations" must be an integer` rather than `"iterations"`.
 *
 * This class checks *shape* — the type a setting must have to be usable at all.
 * Checking that values make sense (positive limits, non-empty commands, usable
 * paths) is the configuration validator's job.
 */
final class RawConfiguration
{
    /**
     * @param array<string, mixed> $values
     * @param string $source human-readable origin, used in error messages
     * @param string $path dotted key path to this level, empty at the root
     */
    private function __construct(
        private readonly array $values,
        private readonly string $source,
        private readonly string $path = '',
    ) {
    }

    /**
     * Wrap an already-decoded associative array.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values, string $source): self
    {
        return new self($values, $source);
    }

    /**
     * Decode a JSON document that must contain an object.
     *
     * @throws ConfigurationException when the document is not valid JSON or is
     *                                not a JSON object
     */
    public static function fromJson(string $json, string $source): self
    {
        if (trim($json) === '') {
            throw new ConfigurationException(
                sprintf('%s is empty.', $source),
                ['A configuration file must contain a JSON object, at least "{}".'],
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationException(
                sprintf('%s is not valid JSON: %s', $source, $exception->getMessage()),
                ['Fix the syntax error, or delete the file and run "ralph init" to recreate it.'],
            );
        }

        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new ConfigurationException(sprintf(
                '%s must contain a JSON object, %s given.',
                $source,
                self::describe($decoded),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded, $source);
    }

    /**
     * Whether a setting is present, including when it is explicitly null.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The keys present at this level, in document order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * A string setting, or the default when it is absent.
     */
    public function string(string $key, string $default): string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_string($value)) {
            throw $this->wrongType($key, 'a string', $value);
        }

        return $value;
    }

    /**
     * A string setting that must be present unless a default is supplied.
     *
     * Used for settings with no universal default, such as the adapter of an
     * agent the package does not ship.
     */
    public function requiredString(string $key, ?string $default = null): string
    {
        if (!$this->has($key)) {
            if ($default !== null) {
                return $default;
            }

            throw $this->missing($key);
        }

        $value = $this->values[$key];
        if (!is_string($value)) {
            throw $this->wrongType($key, 'a string', $value);
        }

        return $value;
    }

    /**
     * An optional string setting: null when absent or explicitly null.
     */
    public function nullableString(string $key, ?string $default = null): ?string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->wrongType($key, 'a string or null', $value);
        }

        return $value;
    }

    /**
     * A boolean setting, or the default when it is absent.
     *
     * Strings such as "true" are rejected rather than coerced: a push setting
     * that silently reads as enabled would publish work the user did not ask to
     * publish.
     */
    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_bool($value)) {
            throw $this->wrongType($key, 'true or false', $value);
        }

        return $value;
    }

    /**
     * An integer setting, or the default when it is absent.
     */
    public function int(string $key, int $default): int
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_int($value)) {
            throw $this->wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    /**
     * An optional integer setting: null when absent or explicitly null.
     */
    public function nullableInt(string $key): ?int
    {
        if (!$this->has($key)) {
            return null;
        }

        $value = $this->values[$key];
        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw $this->wrongType($key, 'an integer or null', $value);
        }

        return $value;
    }

    /**
     * A JSON array of strings, or the default when it is absent.
     *
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function stringList(string $key, array $default = []): array
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($key, 'an array of strings', $value);
        }

        $strings = [];
        foreach ($value as $index => $element) {
            if (!is_string($element)) {
                throw $this->wrongType($key . '.' . $index, 'a string', $element);
            }

            $strings[] = $element;
        }

        return $strings;
    }

    /**
     * A required list of strings that may also be written as a single string.
     *
     * `"command": "phpunit"` is the obvious thing to write for a one-word
     * command, and accepting it costs nothing. The single string becomes one
     * argument: it is never split on whitespace, because that would invent
     * argument boundaries the user did not write.
     *
     * @return list<string>
     */
    public function stringListOrString(string $key): array
    {
        if (!$this->has($key)) {
            throw $this->missing($key);
        }

        $value = $this->values[$key];
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($key, 'a string or an array of strings', $value);
        }

        return $this->stringList($key);
    }

    /**
     * A nested object, empty when the setting is absent.
     */
    public function section(string $key): self
    {
        if (!$this->has($key)) {
            return new self([], $this->source, $this->pathTo($key));
        }

        return $this->sectionFrom($this->values[$key], $this->pathTo($key));
    }

    /**
     * A nested object of objects, keyed by name; empty when absent.
     *
     * @return array<string, self>
     */
    public function sectionMap(string $key): array
    {
        $section = $this->section($key);

        $sections = [];
        foreach ($section->keys() as $name) {
            if ($name === '') {
                throw new ConfigurationException(sprintf(
                    '%s: "%s" contains an entry with an empty name.',
                    $this->source,
                    $this->pathTo($key),
                ));
            }

            $sections[$name] = $section->sectionFrom(
                $section->values[$name],
                $section->pathTo($name),
            );
        }

        return $sections;
    }

    /**
     * A JSON array of objects, or null when the setting is absent.
     *
     * Returning null rather than an empty list distinguishes "not configured",
     * where a default applies, from "configured as empty", which the validator
     * rejects for settings that must not be empty.
     *
     * @return list<self>|null
     */
    public function sectionList(string $key): ?array
    {
        if (!$this->has($key)) {
            return null;
        }

        $value = $this->values[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($key, 'an array of objects', $value);
        }

        $sections = [];
        foreach ($value as $index => $element) {
            $sections[] = $this->sectionFrom($element, $this->pathTo($key) . '.' . $index);
        }

        return $sections;
    }

    /**
     * Reject settings that are not recognised at this level.
     *
     * A misspelled setting would otherwise be ignored in silence, and the user
     * would watch the runner use a default while the file says otherwise.
     *
     * @param list<string> $known
     */
    public function rejectUnknownKeys(array $known): self
    {
        foreach ($this->keys() as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            $hints = [];
            $suggestion = Suggestions::sentence($key, $known);
            if ($suggestion !== null) {
                $hints[] = $suggestion;
            }

            $hints[] = sprintf(
                'Settings recognised %s: %s.',
                $this->path === '' ? 'at the top level' : 'in "' . $this->path . '"',
                implode(', ', $known),
            );

            throw new ConfigurationException(
                sprintf('%s: unknown setting "%s".', $this->source, $this->pathTo($key)),
                $hints,
            );
        }

        return $this;
    }

    /**
     * Wrap a value that must be a JSON object.
     */
    private function sectionFrom(mixed $value, string $path): self
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new ConfigurationException(sprintf(
                '%s: "%s" must be a JSON object, %s given.',
                $this->source,
                $path,
                self::describe($value),
            ));
        }

        /** @var array<string, mixed> $value */
        return new self($value, $this->source, $path);
    }

    private function pathTo(string|int $key): string
    {
        return $this->path === '' ? (string) $key : $this->path . '.' . $key;
    }

    private function missing(string $key): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            '%s: "%s" is required.',
            $this->source,
            $this->pathTo($key),
        ));
    }

    private function wrongType(string $key, string $expected, mixed $actual): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            '%s: "%s" must be %s, %s given.',
            $this->source,
            $this->pathTo($key),
            $expected,
            self::describe($actual),
        ));
    }

    /**
     * Name a value's type the way a user reading JSON would recognise it.
     */
    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'an array' : 'an object';
        }

        return match (get_debug_type($value)) {
            'null' => 'null',
            'bool' => $value === true ? 'true' : 'false',
            'int' => 'an integer',
            'float' => 'a number',
            'string' => 'a string',
            default => get_debug_type($value),
        };
    }
}
