<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\Exception\UsageException;

/**
 * A parsed command line.
 *
 * Grammar, deliberately small and predictable:
 *
 *  - the command name, when present, is the first token; a leading token that
 *    starts with `-` means no command was given (`ralph --version`);
 *  - `--name=value` and `--name value` set an option value, the second form
 *    only for options the command declares as value-taking, so that a bare
 *    `--force` followed by a path is not misread;
 *  - `--name` and short `-n` set a flag; clustered short flags (`-hV`) are
 *    expanded;
 *  - everything else is a positional argument, as is everything after `--`.
 *
 * Validating option *meaning* is each command's job. This class only rejects
 * input that cannot be interpreted at all.
 */
final class Input
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|bool> $options
     */
    private function __construct(
        private readonly ?string $command,
        private readonly array $arguments,
        private readonly array $options,
    ) {
    }

    /**
     * Read the command name from raw tokens without parsing options.
     *
     * The application needs this before it can know which options take values,
     * because that depends on the resolved command.
     *
     * @param list<string> $tokens argv without the script name
     */
    public static function commandName(array $tokens): ?string
    {
        $first = $tokens[0] ?? null;

        if ($first === null || $first === '' || str_starts_with($first, '-')) {
            return null;
        }

        return $first;
    }

    /**
     * Parse tokens into a command name, options, and positional arguments.
     *
     * @param list<string> $tokens argv without the script name
     * @param list<string> $valueOptions long option names that take a separate value
     *
     * @throws UsageException when a token cannot be interpreted
     */
    public static function parse(array $tokens, array $valueOptions = []): self
    {
        $command = self::commandName($tokens);
        if ($command !== null) {
            array_shift($tokens);
        }

        $arguments = [];
        $options = [];
        $endOfOptions = false;

        while ($tokens !== []) {
            $token = (string) array_shift($tokens);

            if ($endOfOptions) {
                $arguments[] = $token;
                continue;
            }

            if ($token === '--') {
                $endOfOptions = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);
                $value = null;

                $separator = strpos($name, '=');
                if ($separator !== false) {
                    $value = substr($name, $separator + 1);
                    $name = substr($name, 0, $separator);
                }

                if ($name === '') {
                    throw new UsageException(sprintf('Option "%s" has no name.', $token));
                }

                if ($value === null && in_array($name, $valueOptions, true)) {
                    $next = array_shift($tokens);
                    if ($next === null || str_starts_with($next, '-')) {
                        throw new UsageException(sprintf('Option "--%s" requires a value.', $name));
                    }

                    $value = $next;
                }

                $options[$name] = $value ?? true;
                continue;
            }

            if (str_starts_with($token, '-') && $token !== '-') {
                foreach (str_split(substr($token, 1)) as $short) {
                    $options[$short] = true;
                }

                continue;
            }

            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    /**
     * The requested command name, or null when none was given.
     */
    public function command(): ?string
    {
        return $this->command;
    }

    /**
     * Whether any of the given option names is present.
     */
    public function hasOption(string ...$names): bool
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $this->options)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of an option, or null when it is absent or used as a flag.
     */
    public function option(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A positional argument by zero-based position.
     */
    public function argument(int $position): ?string
    {
        return $this->arguments[$position] ?? null;
    }

    /**
     * All positional arguments, in the order given.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * All option names that were given, in the order they appeared.
     *
     * @return list<string>
     */
    public function optionNames(): array
    {
        return array_keys($this->options);
    }
}
