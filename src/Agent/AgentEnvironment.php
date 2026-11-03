<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\AgentDefinition;
use SensitiveParameter;

/**
 * The authentication an agent is started with, as names and never as values.
 *
 * PROJECT.md section E, item 8 asks for "environment-based authentication
 * without printing or persisting credentials", and the two halves of that
 * sentence pull in opposite directions: the values have to reach the child
 * process, and they must not reach a log file, the state store, a status table
 * or an exception message. This type is where they are allowed to be, so that
 * nowhere else has to be careful.
 *
 * **One accessor returns values, and it is named after its only caller.**
 * {@see self::forProcess()} produces the override array
 * {@see \Celyes\RalphPhp\Process\ProcessRunner::run()} hands to `proc_open`.
 * Everything else — {@see self::names()}, {@see self::missing()},
 * {@see self::describe()}, {@see self::toArray()} — answers in variable names,
 * which are the part that is safe to print and is also the part a user needs:
 * "ANTHROPIC_API_KEY is not set" is actionable, and the value never is.
 *
 * There is deliberately no `__toString()`, no `value()` and no `all()`. A
 * credential that cannot be asked for cannot be interpolated into a message by
 * a future caller who did not think about it, and this is the kind of mistake
 * that is invisible in review and permanent in a log file someone pastes into
 * an issue.
 *
 * **Why the variables are passed explicitly when the child inherits them
 * anyway.** A process started by {@see \Celyes\RalphPhp\Process\ProcessRunner}
 * inherits the runner's environment, so an agent would find its key without
 * this type existing — passing the declared variables through an override array
 * is, for a well-formed key, the same bytes arriving the same way. Two things
 * make it worth doing anyway. It lets the loop confirm authentication *before*
 * starting a twenty-minute attempt, instead of reading "invalid API key" out of
 * the transcript afterwards. And it is the one place a value can be cleaned up:
 * see below.
 *
 * **An empty value counts as absent, and a padded one is trimmed.** An exported
 * but empty variable is the shape an unset one takes in a shell profile or a CI
 * configuration, and no agent accepts it; treating it as present would turn a
 * setup mistake into an authentication failure the agent reports in prose.
 * Surrounding whitespace is the same mistake wearing a disguise and is the more
 * common one: `export ANTHROPIC_API_KEY=$(cat key.txt)` in a profile, a key
 * pasted into a CI secret with the newline the clipboard brought, a `.env` line
 * with a trailing space. The agent rejects the key, the message says the key is
 * invalid, and the key is correct — so the value the child is handed is the
 * trimmed one. This is a judgement about credentials specifically: API keys and
 * tokens are not formats in which leading or trailing whitespace carries
 * meaning, which is the same assumption the blank-is-absent rule above already
 * makes. It applies only to the variables an agent *declares*, never to the
 * rest of the inherited environment.
 *
 * The declared list is normally empty, because Codex and Claude Code can both
 * be authenticated by an interactive login instead. That case is not a problem
 * to report — it is the common one — so a definition declaring nothing produces
 * a complete environment that adds nothing to the child's.
 */
final class AgentEnvironment
{
    /**
     * @param list<string> $names declared variables, in configuration order
     * @param array<string, string> $values the ones the environment provided
     * @param list<string> $missing the ones it did not, in configuration order
     */
    private function __construct(
        private readonly array $names,
        #[SensitiveParameter]
        private readonly array $values,
        private readonly array $missing,
    ) {
    }

    /**
     * Resolve what an agent's configuration declares against the environment.
     *
     * The reader is injectable for tests that must not depend on the variables
     * the suite happens to run with; it defaults to the process environment,
     * which is the only source in production — a credential Ralph PHP read from
     * a file would be a credential Ralph PHP could write to one.
     *
     * @param (callable(string): (string|false)) $reader
     */
    public static function of(AgentDefinition $definition, ?callable $reader = null): self
    {
        $reader ??= static fn (string $name): string|false => getenv($name);

        $names = [];
        $values = [];
        $missing = [];

        foreach ($definition->environment() as $name) {
            // A list repeating a name is a configuration oddity rather than an
            // error, and resolving it twice would report it twice.
            if (in_array($name, $names, true)) {
                continue;
            }

            $names[] = $name;
            $value = $reader($name);

            if (!is_string($value) || trim($value) === '') {
                $missing[] = $name;

                continue;
            }

            // Trimmed for the reason the class description gives: a key with
            // the newline `$(cat key.txt)` left on it is a correct key the
            // agent reports as invalid.
            $values[$name] = trim($value);
        }

        return new self($names, $values, $missing);
    }

    /**
     * The names the agent declared, in configuration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * The declared names the environment did not provide.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * Whether every declared variable is set to something.
     *
     * True when nothing was declared: see the class description.
     */
    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /**
     * Whether this agent authenticates by environment variable at all.
     */
    public function isDeclared(): bool
    {
        return $this->names !== [];
    }

    /**
     * The variables to start the agent with.
     *
     * The one method that returns credential values, for the one caller that
     * needs them. Only the variables that are actually set appear: passing a
     * missing one as the empty string would hand the agent a variable it would
     * read as a malformed key rather than as an absent one. Each value is
     * trimmed, which is the one way this array differs from what the child
     * would have inherited.
     *
     * @return array<string, string>
     */
    public function forProcess(): array
    {
        return $this->values;
    }

    /**
     * One line for a log note, naming the variables and no values.
     *
     * Written before the agent starts, so that a transcript ending in an
     * authentication error says which variables were in play when it was
     * produced.
     */
    public function describe(): string
    {
        if ($this->names === []) {
            return 'no authentication variables declared';
        }

        if ($this->missing === []) {
            return sprintf(
                'authenticating with %s (%s set)',
                implode(', ', $this->names),
                count($this->names) === 1 ? 'value' : 'values',
            );
        }

        return sprintf(
            'declared %s; %s not set',
            implode(', ', $this->names),
            implode(', ', $this->missing),
        );
    }

    /**
     * The resolution as plain data, for a structured log line.
     *
     * Names and presence only, which is what makes this safe to write into an
     * attempt's log beside {@see AgentInvocation::toArray()}.
     *
     * @return array{declared: list<string>, missing: list<string>}
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->names,
            'missing' => $this->missing,
        ];
    }
}
