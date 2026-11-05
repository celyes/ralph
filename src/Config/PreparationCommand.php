<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * One entry of the `workspace.prepare` setting: a command that makes a freshly
 * created task worktree able to build and test the project.
 *
 * Shaped like a {@see VerificationCommand} — a name, an argument array and a
 * timeout — and deliberately a separate type, because the two answer different
 * questions and fail at different moments. A verification command decides
 * whether work is done, runs after the agent, and a failure leaves the task
 * unchecked for the next attempt to improve on. A preparation command runs
 * *before* the agent in a workspace that is not usable yet, and a failure stops
 * the task before any tokens are spent, because an agent sent into a workspace
 * with no dependencies installed cannot run the project's tests and will report
 * failures that are about the workspace rather than its own work.
 *
 * The timeout is the other reason they are not one type: installing
 * dependencies is minutes of network work, while a check is expected to be the
 * project's own suite, so they want different defaults
 * ({@see Defaults::PREPARE_TIMEOUT_SECONDS} against
 * {@see Defaults::CHECK_TIMEOUT_SECONDS}).
 *
 * Like a check, the command is an argument array rather than a shell string, so
 * nothing in a path or a task description can change what is executed.
 */
final class PreparationCommand
{
    /**
     * @param string $name label shown in status output and logs
     * @param list<string> $command executable and arguments
     * @param int $timeout seconds this command may run
     */
    public function __construct(
        private readonly string $name,
        private readonly array $command,
        private readonly int $timeout = Defaults::PREPARE_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Read one preparation command. A command given as a single string is kept
     * as one element rather than split on spaces, for the reason
     * {@see RawConfiguration::stringListOrString()} gives.
     *
     * The name defaults to the executable, so a short entry needs only its
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
            $raw->nullableInt('timeout') ?? Defaults::PREPARE_TIMEOUT_SECONDS,
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
