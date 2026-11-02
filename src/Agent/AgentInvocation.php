<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use InvalidArgumentException;

/**
 * One agent process, as an argument array and nothing else.
 *
 * The form PROJECT.md section E, item 2 requires: a program, its arguments one
 * element at a time, and optionally the text the process reads from standard
 * input. There is deliberately **no constructor that takes a command line**,
 * and no method that returns one for execution. That absence is the feature.
 * The text an adapter has to pass an agent is a task description a user wrote,
 * the acceptance criteria under it, the project's context file and the previous
 * attempt's captured output — arbitrary prose that routinely contains quotes,
 * `$`, backticks, semicolons and newlines. Composed into a shell string it
 * would be a command the shell reads; as an element of this array it is one
 * argument, and `execve` has no syntax for it to exploit.
 *
 * So the only way to add text is {@see self::with()}, which appends whole
 * arguments, and {@see self::withInput()}, which hands the text over out of
 * band entirely.
 *
 * **It holds no credentials.** An agent authenticates through environment
 * variables whose *names* its configuration lists (PROJECT.md section 4), and
 * the process inherits them from the runner; nothing secret has to appear in an
 * argument, which is what makes {@see self::describe()} safe to log and
 * {@see self::toArray()} safe to persist with an attempt. An adapter that put a
 * key in an argument would be putting it in the process table, where every
 * other user on the machine can read it.
 */
final class AgentInvocation
{
    /**
     * @param list<string> $arguments
     */
    private function __construct(
        private readonly string $program,
        private readonly array $arguments,
        private readonly ?string $input,
    ) {
    }

    /**
     * The invocation of a program, with its arguments in order.
     *
     * @param list<string> $arguments
     *
     * @throws InvalidArgumentException when the program is not named, or
     *                                 anything here contains a NUL byte
     */
    public static function of(string $program, array $arguments = []): self
    {
        $named = trim($program);

        if ($named === '') {
            throw new InvalidArgumentException('An invocation must name the program to run.');
        }

        self::rejectNulBytes($named, 'A program name');

        foreach ($arguments as $argument) {
            self::rejectNulBytes($argument, 'An argument');
        }

        return new self($named, $arguments, null);
    }

    /**
     * The invocation of the agent this run selected.
     *
     * The program comes from the configured `executable` and from nowhere else:
     * an adapter builds its flags, and the user's configuration decides what
     * runs. Per-agent models and extra argument arrays are section E, item 8
     * and are the adapter's to place, because where a `--model` goes in a
     * command line is something only that agent's CLI knows.
     *
     * @param list<string> $arguments
     *
     * @throws InvalidArgumentException when the configured executable is empty
     */
    public static function forAgent(AgentSelection $agent, array $arguments = []): self
    {
        return self::of($agent->definition()->executable(), $arguments);
    }

    /**
     * This invocation with more arguments appended.
     *
     * @throws InvalidArgumentException when an argument contains a NUL byte
     */
    public function with(string ...$arguments): self
    {
        foreach ($arguments as $argument) {
            self::rejectNulBytes($argument, 'An argument');
        }

        // array_values because a variadic can be called with named arguments,
        // which would arrive keyed.
        return new self($this->program, [...$this->arguments, ...array_values($arguments)], $this->input);
    }

    /**
     * This invocation with text to be read from standard input.
     *
     * The other way to hand an agent its briefing, for a CLI that reads the
     * prompt from a stream. Unbounded on purpose: a briefing is as long as the
     * task, its criteria and the context file make it, and an argument list has
     * a length limit that standard input does not.
     *
     * @throws InvalidArgumentException when the input contains a NUL byte
     */
    public function withInput(string $input): self
    {
        self::rejectNulBytes($input, 'Standard input');

        return new self($this->program, $this->arguments, $input);
    }

    /**
     * This invocation with the program replaced by the path it resolved to.
     *
     * {@see ProcessAdapter} resolves a configured name against PATH before
     * starting it, so that what was found is exactly what runs and a log line
     * says which binary it was.
     *
     * @throws InvalidArgumentException when the path is empty or holds a NUL byte
     */
    public function at(string $program): self
    {
        $resolved = self::of($program, $this->arguments);

        return $this->input === null ? $resolved : $resolved->withInput($this->input);
    }

    /**
     * The program to run, as configured or as resolved.
     */
    public function program(): string
    {
        return $this->program;
    }

    /**
     * The arguments, without the program.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * The argument array to start: the program, then its arguments.
     *
     * @return non-empty-list<string>
     */
    public function command(): array
    {
        return [$this->program, ...$this->arguments];
    }

    /**
     * The text for standard input, or null when the process is given none.
     */
    public function input(): ?string
    {
        return $this->input;
    }

    public function hasInput(): bool
    {
        return $this->input !== null;
    }

    /**
     * One line naming what will run, for a log header or the terminal.
     *
     * Quoted for reading. Arguments that contain whitespace or characters a
     * shell would act on are wrapped in single quotes so that the boundaries
     * between them are visible — a task description pasted into a log as a bare
     * argument reads as several. The briefing itself is never printed: it is
     * thousands of characters, it is in the log already, and its size is the
     * part worth seeing here.
     */
    public function describe(): string
    {
        $parts = array_map(self::quoted(...), $this->command());

        if ($this->input !== null) {
            $parts[] = sprintf('(with %d characters on standard input)', mb_strlen($this->input));
        }

        return implode(' ', $parts);
    }

    /**
     * The invocation as plain data, for a structured log line.
     *
     * The arguments are kept as the array they are rather than joined, so that
     * whatever reads the log can tell one argument from two. Standard input is
     * reported by size for the reason {@see self::describe()} gives.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'program' => $this->program,
            'arguments' => $this->arguments,
            'input_characters' => $this->input === null ? null : mb_strlen($this->input),
        ];
    }

    /**
     * An argument as it is shown, not as a shell would need it.
     */
    private static function quoted(string $argument): string
    {
        if ($argument === '') {
            return "''";
        }

        if (preg_match('/[\s"\'$`;&|<>()*?#\\\\]/', $argument) !== 1) {
            return $argument;
        }

        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /**
     * A NUL byte ends a string at the system call boundary, so an argument
     * containing one would reach the agent silently truncated — the end of a
     * task description quietly missing is worse than a refused invocation.
     *
     * @throws InvalidArgumentException when the value contains one
     */
    private static function rejectNulBytes(string $value, string $what): void
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot contain a NUL byte; it would be cut short where the byte is when the process is started.',
                $what,
            ));
        }
    }
}
