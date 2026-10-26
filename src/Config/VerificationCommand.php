<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * One entry of the `checks` setting: a command that must pass before a task's
 * work is committed and checked off.
 *
 * The command is an argument array rather than a shell string, so neither task
 * text nor a path with spaces can change what gets executed.
 */
final class VerificationCommand
{
    /**
     * @param string $name label shown in status output and logs
     * @param list<string> $command executable and arguments
     * @param int $timeout seconds this command may run
     */
    public function __construct(
        private readonly string $name,
        private readonly array $command,
        private readonly int $timeout = Defaults::CHECK_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Read one check. A command given as a single string is kept as one
     * element rather than split on spaces: splitting would quietly invent
     * argument boundaries the user did not write.
     *
     * The name defaults to the executable, so a short check needs only its
     * command.
     */
    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['name', 'command', 'timeout']);

        $command = $raw->stringListOrString('command');
        $name = $raw->string('name', $command[0] ?? '');

        return new self(
            $name,
            $command,
            $raw->nullableInt('timeout') ?? Defaults::CHECK_TIMEOUT_SECONDS,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * The command as a single display string. For logs and status only — never
     * for execution.
     */
    public function describe(): string
    {
        return implode(' ', $this->command);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'timeout' => $this->timeout,
        ];
    }
}
