<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

/**
 * Changes environment variables for one test, and puts them back.
 *
 * Several things under test read the environment of the running process:
 * PATH decides what {@see \Celyes\RalphPhp\Process\ExecutableFinder} can find,
 * an agent's declared variables decide whether its credentials are present, and
 * Git's own variables decide which configuration and identity it sees. Those
 * tests have to set real variables, which makes putting them back a
 * requirement rather than tidiness.
 */
final class Environment
{
    /**
     * @var array<string, string|false>
     */
    private array $previous = [];

    /**
     * Set a variable, or remove it when given null.
     */
    public function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->previous)) {
            $this->previous[$name] = getenv($name);
        }

        if ($value === null) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }

    /**
     * Remove a variable for the duration of the test.
     */
    public function remove(string $name): void
    {
        $this->set($name, null);
    }

    /**
     * Restore every variable this instance changed.
     */
    public function restore(): void
    {
        foreach ($this->previous as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }

        $this->previous = [];
    }
}
