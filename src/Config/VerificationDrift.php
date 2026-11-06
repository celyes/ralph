<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The difference between the checks a run froze and what the configuration
 * says now.
 *
 * The other half of PROJECT.md section G, item 3: the frozen snapshot is what
 * makes a worker's edit unable to *replace* the required checks, and this is
 * what makes it unable to do so *silently*. A run that quietly ignored an edit
 * would leave a user reading a configuration that the run in front of them is
 * not obeying.
 *
 * It is a statement about two readings and nothing more. It does not decide
 * whether the run should stop — the frozen set is used either way — and it is
 * not the weakened-verification detection of section G, item 5, which is about
 * what a *diff* does to a project's own tests rather than what an edit does to
 * this package's settings.
 *
 * **An unreadable configuration is drift too.** A worker that writes broken
 * JSON over `.ralph/config.json` has changed what the next run will read, and
 * reporting that as "no change" because the comparison could not be made would
 * be the silence this exists to break. It is not, however, a reason to end a
 * run whose checks were frozen before the file was touched.
 */
final class VerificationDrift
{
    /**
     * @param list<string> $added    checks the configuration now has and the run does not
     * @param list<string> $removed  checks the run is held to and the configuration no longer states
     * @param list<string> $changed  checks whose command or timeout was edited
     * @param bool $reordered whether the same checks are now in a different order
     * @param string|null $unreadable why the current configuration could not be read
     */
    private function __construct(
        private readonly array $added,
        private readonly array $removed,
        private readonly array $changed,
        private readonly bool $reordered,
        private readonly ?string $unreadable,
    ) {
    }

    /**
     * The configuration still states what the run froze.
     */
    public static function none(): self
    {
        return new self([], [], [], false, null);
    }

    /**
     * The configuration cannot be read at all any more.
     */
    public static function unreadable(string $reason): self
    {
        return new self([], [], [], false, $reason);
    }

    /**
     * Compare a frozen set against a later reading.
     */
    public static function between(VerificationSnapshot $frozen, VerificationSnapshot $current): self
    {
        $before = $frozen->names();
        $after = $current->names();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $changed = [];

        foreach ($before as $name) {
            $was = $frozen->find($name);
            $now = $current->find($name);

            if ($was === null || $now === null) {
                continue;
            }

            if ($was->toArray() !== $now->toArray()) {
                $changed[] = $name;
            }
        }

        // Order is only worth reporting when the sets are otherwise the same:
        // a removal already moves everything after it, and saying so twice
        // would describe one edit as two.
        $reordered = $added === [] && $removed === [] && $before !== $after;

        return new self($added, $removed, $changed, $reordered, null);
    }

    /**
     * Whether the configuration has moved away from what the run is running.
     */
    public function hasChanged(): bool
    {
        return $this->unreadable !== null
            || $this->added !== []
            || $this->removed !== []
            || $this->changed !== []
            || $this->reordered;
    }

    /**
     * @return list<string>
     */
    public function added(): array
    {
        return $this->added;
    }

    /**
     * @return list<string>
     */
    public function removed(): array
    {
        return $this->removed;
    }

    /**
     * @return list<string>
     */
    public function changed(): array
    {
        return $this->changed;
    }

    public function isReordered(): bool
    {
        return $this->reordered;
    }

    /**
     * Why the configuration could not be read, when that is what happened.
     */
    public function unreadableReason(): ?string
    {
        return $this->unreadable;
    }

    /**
     * The headline a run prints when it notices the edit.
     */
    public function describe(): string
    {
        if (!$this->hasChanged()) {
            return 'the verification configuration is unchanged';
        }

        return 'the verification configuration changed while the run was going; '
            . 'this run is held to the checks it froze at its start';
    }

    /**
     * What changed, one short line each.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->unreadable !== null) {
            return ['unreadable: ' . $this->unreadable];
        }

        $lines = [];

        if ($this->removed !== []) {
            $lines[] = 'no longer configured: ' . implode(', ', $this->removed);
        }

        if ($this->added !== []) {
            $lines[] = 'newly configured: ' . implode(', ', $this->added);
        }

        if ($this->changed !== []) {
            $lines[] = 'edited: ' . implode(', ', $this->changed);
        }

        if ($this->reordered) {
            $lines[] = 'reordered, which changes which failure is reported first';
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'changed' => $this->hasChanged(),
            'added' => $this->added,
            'removed' => $this->removed,
            'edited' => $this->changed,
            'reordered' => $this->reordered,
            'unreadable' => $this->unreadable,
        ];
    }
}
