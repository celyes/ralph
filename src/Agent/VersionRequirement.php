<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use InvalidArgumentException;

/**
 * The CLI version an adapter was written against, and how to ask for it.
 *
 * PROJECT.md section E, item 9's incompatible-CLI half. An adapter's
 * invocation is a statement about one program's command line — `codex exec
 * --cd … --sandbox workspace-write`, `claude --print --permission-mode
 * bypassPermissions` — and those spellings have a history: a CLI old enough
 * not to have them does not fail with "unknown flag" in any form the loop can
 * recognise. It starts, prints its own usage, exits non-zero, and the attempt
 * is recorded as an execution error that a retry reproduces exactly. Asking
 * the installed program what it is, once, before any of that, turns a
 * confusing run into one sentence before the run starts.
 *
 * Three things belong to the adapter and are therefore stated here rather than
 * assumed by the check that reads them: what the CLI is called when a person
 * is told about it, which arguments make it print its version, and which
 * version is the oldest the adapter's invocation was written for. Section 4 is
 * explicit that two agents' CLIs have nothing in common, and a version query
 * is not an exception — `--version` is only the usual spelling, not a
 * guarantee.
 *
 * **A minimum is a floor, not the tested version.** It names the oldest
 * release whose command line the adapter is written against, so an operator
 * running something newer is never refused for being ahead, and the exact
 * version each adapter was actually exercised at is recorded in its own class
 * documentation where it cannot be mistaken for a requirement.
 *
 * Declaring one is optional: an adapter implements {@see VersionedAdapter}
 * when it has a floor worth stating, and a registered third-party adapter that
 * does not is reported as having nothing to check rather than being guessed
 * about.
 */
final class VersionRequirement
{
    /**
     * The argument almost every CLI prints its version for, used when an
     * adapter names none of its own.
     */
    public const string DEFAULT_ARGUMENT = '--version';

    /**
     * @param list<string> $arguments
     */
    private function __construct(
        private readonly string $cli,
        private readonly string $minimum,
        private readonly array $arguments,
    ) {
    }

    /**
     * @param string $cli the CLI's name as a person would say it, for messages
     * @param string $minimum the oldest version the adapter's invocation targets
     * @param string ...$arguments what makes it print its version; `--version` by default
     *
     * @throws InvalidArgumentException when an adapter declares a requirement
     *         that cannot be used — a programmer error in the adapter, not a
     *         user's mistake, and so raised rather than reported
     */
    public static function of(string $cli, string $minimum, string ...$arguments): self
    {
        if (trim($cli) === '') {
            throw new InvalidArgumentException('A version requirement must name the CLI it is about.');
        }

        if (self::versionIn($minimum) === null) {
            throw new InvalidArgumentException(sprintf(
                'The minimum version "%s" declared for %s is not a version number.',
                $minimum,
                $cli,
            ));
        }

        $arguments = $arguments === [] ? [self::DEFAULT_ARGUMENT] : $arguments;

        foreach ($arguments as $argument) {
            if (trim($argument) === '') {
                throw new InvalidArgumentException(sprintf(
                    'The version arguments declared for %s contain an empty one.',
                    $cli,
                ));
            }
        }

        return new self(trim($cli), trim($minimum), array_values($arguments));
    }

    /**
     * The CLI's name, for a report a person reads.
     */
    public function cli(): string
    {
        return $this->cli;
    }

    /**
     * The oldest version the adapter's invocation targets.
     */
    public function minimum(): string
    {
        return $this->minimum;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * The whole query, as an argument array for an already-resolved executable.
     *
     * An array rather than a string for the reason every command in this
     * package is one: a configured executable may sit at a path with a space
     * in it, and nothing here is ever handed to a shell.
     *
     * @return list<string>
     */
    public function command(string $executable): array
    {
        return [$executable, ...$this->arguments];
    }

    public function isSatisfiedBy(string $version): bool
    {
        return version_compare($version, $this->minimum, '>=');
    }

    /**
     * One phrase naming the requirement, as the object of a sentence.
     */
    public function describe(): string
    {
        return sprintf('%s %s or newer', $this->cli, $this->minimum);
    }

    /**
     * The version number in a CLI's own output, or null when there is none to
     * read.
     *
     * Deliberately forgiving about everything but the number. Agent CLIs print
     * `2.1.226 (Claude Code)`, `codex-cli 0.44.0`, a name and a build hash, or
     * all of that under a Node deprecation warning, and an adapter should not
     * have to own a parser for each. Only the first `X.Y` or `X.Y.Z` in the
     * first line that has one is taken, and the rest of the line is ignored:
     * `version_compare()` reads a trailing `-nightly` or `.windows.1` as a
     * pre-release marker, which would make a released CLI look older than the
     * minimum and refuse a run that would have worked.
     *
     * The reverse mistake — a line whose first number belongs to something
     * else, as in a banner naming the Node version — is why a version read
     * here is only ever *reported*: {@see \Celyes\RalphPhp\Preflight\Check\AgentVersionCheck}
     * refuses a run on a version it could read and that is too old, and warns
     * about everything it could not establish.
     */
    public static function versionIn(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $line, $matches) === 1) {
                return sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
            }
        }

        return null;
    }
}
