<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

/**
 * The outcome of running the `ralph` binary as a real subprocess.
 */
final readonly class CliResult
{
    /**
     * @param list<string> $command the argument array that was executed
     */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $standardOutput,
        public string $errorOutput,
    ) {
    }

    /**
     * The ordinary output as a list of lines, with the trailing newline dropped.
     *
     * @return list<string>
     */
    public function standardOutputLines(): array
    {
        return self::toLines($this->standardOutput);
    }

    /**
     * The diagnostic output as a list of lines, with the trailing newline dropped.
     *
     * @return list<string>
     */
    public function errorOutputLines(): array
    {
        return self::toLines($this->errorOutput);
    }

    /**
     * Both streams together, in no particular order.
     *
     * For assertions about a message that a foreign program emits, where which
     * stream it chose is that program's business rather than the behaviour
     * under test.
     */
    public function allOutput(): string
    {
        return $this->standardOutput . $this->errorOutput;
    }

    /**
     * A readable rendering of the whole invocation, used as the assertion
     * message so a failure shows what ran and what it printed.
     */
    public function describe(): string
    {
        return sprintf(
            "command: %s\nexit code: %d\nstdout:\n%s\nstderr:\n%s",
            implode(' ', array_map('escapeshellarg', $this->command)),
            $this->exitCode,
            $this->standardOutput === '' ? '  (empty)' : $this->standardOutput,
            $this->errorOutput === '' ? '  (empty)' : $this->errorOutput,
        );
    }

    /**
     * @return list<string>
     */
    private static function toLines(string $output): array
    {
        if ($output === '') {
            return [];
        }

        return explode("\n", str_replace("\r\n", "\n", rtrim($output, "\n")));
    }
}
