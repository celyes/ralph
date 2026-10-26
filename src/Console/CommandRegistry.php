<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

use Celyes\RalphPhp\Console\Command\CommandInterface;

/**
 * The set of commands an application exposes.
 *
 * Insertion order is preserved so that help output lists commands in the order
 * they were registered rather than alphabetically.
 */
final class CommandRegistry
{
    /**
     * @var array<string, CommandInterface>
     */
    private array $commands = [];

    /**
     * @param list<CommandInterface> $commands
     */
    public function __construct(array $commands = [])
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
    }

    /**
     * Register a command, replacing any previous command of the same name.
     */
    public function add(CommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->commands);
    }

    public function get(string $name): ?CommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * @return list<CommandInterface>
     */
    public function all(): array
    {
        return array_values($this->commands);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    /**
     * Registered names that are close enough to the given one to suggest after
     * a typo, nearest first.
     *
     * @return list<string>
     */
    public function suggestionsFor(string $name): array
    {
        $candidate = strtolower($name);
        $matches = [];

        foreach ($this->names() as $registered) {
            $distance = levenshtein($candidate, $registered);
            $tolerance = (int) ceil(max(strlen($registered), 1) / 3);

            if ($distance <= max($tolerance, 2) || str_contains($registered, $candidate)) {
                $matches[$registered] = $distance;
            }
        }

        asort($matches);

        return array_keys($matches);
    }
}
