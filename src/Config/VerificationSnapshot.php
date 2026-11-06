<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

use Celyes\RalphPhp\Exception\ConfigurationException;

/**
 * The verification commands as a run read them, frozen for the run's lifetime.
 *
 * PROJECT.md section G, item 3. The checks decide whether a task's work may be
 * committed and its box ticked, and the agent working on that task can write to
 * the repository — including to `.ralph/config.json`. A loop that asked the
 * configuration for its checks each time it needed them would therefore let a
 * worker choose what it is held to: replace the suite with `true`, and every
 * task after it passes. Freezing the set once, at the run's start, and running
 * *that* set for the whole run is what takes the decision away from the worker.
 *
 * **A snapshot, not a setting.** It is taken from a {@see Configuration} at the
 * run's start, written to the run's row, and read back from the row by
 * everything that runs or reports a check. An edit to the configuration file
 * changes what the *next* run requires and nothing about this one.
 *
 * **No I/O, no clock, immutable.** Comparing the frozen set against what the
 * file says now needs a reading of the file, and taking that reading is the
 * caller's business ({@see \Celyes\RalphPhp\Run\Runner}); this class only says
 * what the difference between two snapshots is.
 *
 * The checks keep their configured order, because the order is behaviour: the
 * first failure stops the sequence, so a reordering changes which failure a
 * person is shown first.
 */
final class VerificationSnapshot
{
    /**
     * How much of the digest is shown. Enough to tell two check sets apart in a
     * status line, and never used to decide anything — {@see compareTo()} is
     * what answers whether a set changed.
     */
    public const int DIGEST_LENGTH = 12;

    /**
     * @param list<VerificationCommand> $commands in configured order
     * @param string $source where the set was read from, for messages about it
     */
    private function __construct(
        private readonly array $commands,
        private readonly string $source,
    ) {
    }

    /**
     * Freeze a project's checks.
     *
     * @throws ConfigurationException when there are no checks, or two of them
     *                                share a name
     */
    public static function of(Configuration $configuration): self
    {
        return self::ofCommands(
            $configuration->checks(),
            $configuration->source() ?? 'the project configuration',
        );
    }

    /**
     * Freeze a set of checks that has already been read.
     *
     * @param list<VerificationCommand> $commands
     *
     * @throws ConfigurationException when the set cannot be the one a run is
     *                                held to
     */
    public static function ofCommands(array $commands, string $source): self
    {
        if ($commands === []) {
            throw new ConfigurationException(
                sprintf('%s: a run must freeze at least one verification command.', $source),
                [
                    'A task is checked off only after its checks pass, so a run with no checks '
                        . 'could never verify anything.',
                ],
            );
        }

        $seen = [];

        foreach ($commands as $command) {
            $name = $command->name();

            if (isset($seen[$name])) {
                throw new ConfigurationException(
                    sprintf('%s: two verification commands are both named "%s".', $source, $name),
                    ['A check is identified by its name in status output, in logs and here.'],
                );
            }

            $seen[$name] = true;
        }

        return new self($commands, $source);
    }

    /**
     * Read the set a run recorded.
     *
     * Strict on purpose. A stored check that cannot be read back is not an
     * excuse to carry on with a different set, or with none: the run would then
     * verify something other than what it froze, which is the failure this
     * whole item exists to prevent.
     *
     * @param list<array<string, mixed>> $rows as {@see toStorage()} wrote them
     *
     * @throws ConfigurationException when a stored check cannot be read back
     */
    public static function fromStorage(array $rows, string $source = 'the run\'s recorded checks'): self
    {
        $commands = [];

        foreach ($rows as $index => $row) {
            $commands[] = VerificationCommand::fromStorage($row, sprintf('%s (checks.%s)', $source, (string) $index));
        }

        return self::ofCommands($commands, $source);
    }

    /**
     * The commands, in the order they must run.
     *
     * @return list<VerificationCommand>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * The check names, which is how a check is referred to everywhere a person
     * reads about one.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (VerificationCommand $check): string => $check->name(), $this->commands);
    }

    /**
     * The named check, or null when the set does not hold one.
     */
    public function find(string $name): ?VerificationCommand
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $name) {
                return $command;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->commands);
    }

    /**
     * Where this reading came from.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * A short fingerprint of the whole set, for status lines and logs.
     *
     * Display only. It covers every name, argument and timeout in order, so two
     * sets that differ in any of them read differently — but a shortened hash
     * is not evidence, and nothing here decides anything by comparing one.
     */
    public function digest(): string
    {
        return substr(
            hash('sha256', (string) json_encode($this->toStorage())),
            0,
            self::DIGEST_LENGTH,
        );
    }

    /**
     * What changed between this frozen set and a later reading of the same
     * setting.
     */
    public function compareTo(self $current): VerificationDrift
    {
        return VerificationDrift::between($this, $current);
    }

    /**
     * The set as the run's row holds it.
     *
     * @return list<array<string, mixed>>
     */
    public function toStorage(): array
    {
        return array_map(
            static fn (VerificationCommand $check): array => $check->toArray(),
            $this->commands,
        );
    }

    /**
     * One line naming the checks a run is held to.
     */
    public function describe(): string
    {
        return sprintf('%s (frozen %s)', implode(', ', $this->names()), $this->digest());
    }
}
