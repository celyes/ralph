<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Support;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\Configuration;
use SensitiveParameter;

/**
 * The secret values this project told Ralph PHP about, and how to keep them out
 * of what Ralph PHP writes.
 *
 * PROJECT.md section I, item 6 asks for known configured secrets to be redacted
 * from package-generated diagnostics. Both qualifiers are the point.
 *
 * **Known, and configured.** The only credentials this package can recognise
 * are the ones a project named: `agents.*.environment` lists the *names* of the
 * variables an agent authenticates with ({@see
 * \Celyes\RalphPhp\Config\AgentDefinition}), and the values behind those names
 * are read from the environment when a run starts. That list is the whole of
 * what redaction can act on. A token inside a Git remote URL, a key an agent
 * read out of a file, a password a failing test prints — none of those were
 * declared, so nothing here can recognise them, and no amount of pattern
 * matching would make this honest rather than lucky. The limits are documented
 * where a user meets them, in `ralph logs --help`.
 *
 * **Package-generated.** A diagnostic this package composed — a line it prints,
 * a note it writes into an attempt's log, an event payload, the excerpt of a
 * check's output it stores — goes through here. A verbatim copy of another
 * process's output does not: {@see \Celyes\RalphPhp\Log\LogFile} is a copy of
 * what the agent printed rather than a rendering of it, and a redaction that
 * rewrote those bytes would silently change the length and the content of the
 * one artefact a person reads to find out what really happened. That is the
 * split section I, item 6 draws, and the raw logs are the documented side of
 * it.
 *
 * **Values enter from one place and leave from none.** {@see self::of()} is
 * called by {@see \Celyes\RalphPhp\Agent\AgentEnvironment::secrets()}, which is
 * the one type in this package that holds a credential value, and there is no
 * accessor here that gives one back. {@see self::redact()} consumes them;
 * {@see self::names()} answers in variable names, which are safe to print and
 * are the part a reader needs. The same reasoning as `AgentEnvironment`'s: a
 * value that cannot be asked for cannot be interpolated into a message by a
 * caller who did not think about it.
 *
 * **A short value is not redacted**, and that is a deliberate refusal rather
 * than an oversight. Replacing every occurrence of a two-character string would
 * corrupt every path, SHA prefix and sentence the package writes, while telling
 * a reader nothing; and no API key or token is {@see self::MINIMUM_LENGTH}
 * characters or fewer, so a declared variable holding one is configuration
 * about something else. Those names are reported by
 * {@see self::unredactable()} so that the decision is visible rather than
 * silent.
 *
 * **One pass, longest value first.** Redaction is a single `strtr`, which
 * matches the longest candidate at each position and never looks at what it has
 * already written. So a secret that contains another secret is replaced whole,
 * and a placeholder cannot be redacted again by a value that happens to occur
 * inside it.
 */
final class Secrets
{
    /**
     * Characters a declared value needs before it is treated as a secret.
     *
     * Eight is the shortest length at which replacing every occurrence of a
     * string is more likely to hide a credential than to mangle a diagnostic.
     * Counted in bytes, because a replacement is made in bytes.
     * See the class description; the number is a judgement, and the behaviour
     * either side of it is pinned by a test.
     */
    public const int MINIMUM_LENGTH = 8;

    /**
     * @param array<string, string> $replacements value => placeholder, which is
     *        what `strtr` takes and the only form the values are kept in
     * @param list<string> $names declared variables whose values are redacted
     * @param list<string> $short declared variables set to something too short
     *        to redact
     */
    private function __construct(
        #[SensitiveParameter]
        private readonly array $replacements,
        private readonly array $names,
        private readonly array $short,
    ) {
    }

    /**
     * Nothing is secret.
     *
     * The default everywhere a {@see Secrets} is optional, and a real object
     * rather than a null the callers have to test for: a command that never
     * loaded a configuration and a project that declares no authentication
     * variables should take the same code path as one that does.
     */
    public static function none(): self
    {
        return new self([], [], []);
    }

    /**
     * Take in resolved variables, as names mapped to their values.
     *
     * The one way a value gets in here, and it is called by the one type that
     * holds values: {@see \Celyes\RalphPhp\Agent\AgentEnvironment::secrets()}.
     *
     * Two names set to the same value are one replacement, named after the
     * first of them — the value is what appears in a diagnostic, and there is
     * no way to tell which variable it arrived through.
     *
     * @param array<string, string> $values
     */
    public static function of(#[SensitiveParameter] array $values): self
    {
        $replacements = [];
        $names = [];
        $short = [];

        foreach ($values as $name => $value) {
            if (strlen($value) < self::MINIMUM_LENGTH) {
                $short[] = $name;

                continue;
            }

            $names[] = $name;
            $replacements[$value] ??= self::placeholderFor($name);
        }

        return new self($replacements, $names, $short);
    }

    /**
     * Every secret a project's agents declare, resolved against the
     * environment.
     *
     * All of the configured agents, not only the one a run selected: a
     * credential exported for the agent that was not chosen is still in this
     * process's environment, still reaches the agent that was, and would still
     * be a credential in a log if something printed it. Which agent is running
     * does not change what must not be written down.
     *
     * The reader is injectable for the same reason it is on
     * {@see \Celyes\RalphPhp\Agent\AgentEnvironment::of()}: a test must not
     * depend on the variables the suite happens to run with.
     *
     * @param (callable(string): (string|false))|null $reader
     */
    public static function forProject(Configuration $configuration, ?callable $reader = null): self
    {
        $secrets = self::none();

        foreach ($configuration->agents() as $definition) {
            $secrets = $secrets->merge(
                AgentEnvironment::of($definition, $reader)->secrets(),
            );
        }

        return $secrets;
    }

    /**
     * These secrets and another set's, as one.
     *
     * Where both know a value, the name this set recorded it under is kept: a
     * merge is additive, and a placeholder that changed as more agents were
     * read would make two diagnostics about one credential disagree.
     */
    public function merge(self $other): self
    {
        return new self(
            $this->replacements + $other->replacements,
            self::united($this->names, $other->names),
            self::united($this->short, $other->short),
        );
    }

    /**
     * The variables whose values are being kept out of diagnostics.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * Declared variables that are set to something too short to redact.
     *
     * Worth saying out loud where an attempt is set up: a user who declared a
     * variable expecting it to be treated as a credential should find out that
     * it is not, rather than discover it in a log. See
     * {@see self::MINIMUM_LENGTH}.
     *
     * @return list<string>
     */
    public function unredactable(): array
    {
        return $this->short;
    }

    /**
     * Whether there is nothing to redact.
     */
    public function isEmpty(): bool
    {
        return $this->replacements === [];
    }

    /**
     * How many distinct values are being redacted.
     */
    public function count(): int
    {
        return count($this->replacements);
    }

    /**
     * The text with every known secret replaced by the variable that holds it.
     *
     * Safe to call on anything, including text that has already been through:
     * a placeholder contains no secret, so a second pass changes nothing.
     */
    public function redact(string $text): string
    {
        if ($this->replacements === [] || $text === '') {
            return $text;
        }

        return strtr($text, $this->replacements);
    }

    /**
     * Several lines, each redacted.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function redactLines(array $lines): array
    {
        if ($this->replacements === []) {
            return $lines;
        }

        return array_map([$this, 'redact'], $lines);
    }

    /**
     * A structured payload, redacted wherever it holds text.
     *
     * For the JSON documents this package writes about itself — an event's
     * data, a status report — where a secret could only be in a string. Keys
     * are left alone: they are field names this package chose, and a key that
     * matched a credential would mean the package had invented a field named
     * after one.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $data
     *
     * @return array<TKey, mixed>
     */
    public function redactData(array $data): array
    {
        if ($this->replacements === []) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactData($value);
            }
        }

        return $data;
    }

    /**
     * What a redacted value is replaced by.
     *
     * Names the variable, because that is the actionable half and is already
     * printed elsewhere ({@see
     * \Celyes\RalphPhp\Agent\AgentEnvironment::describe()}), and because a
     * reader who finds this in a log should be able to tell a redaction from
     * the agent having printed the word "redacted". The form holds no quote,
     * backslash or newline, so substituting it into a JSON string or a log line
     * cannot turn a valid document into an invalid one.
     */
    public static function placeholderFor(string $name): string
    {
        return '[redacted ' . $name . ']';
    }

    /**
     * Two name lists as one, in first-seen order and without repeats.
     *
     * @param list<string> $first
     * @param list<string> $second
     *
     * @return list<string>
     */
    private static function united(array $first, array $second): array
    {
        $names = $first;

        foreach ($second as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
