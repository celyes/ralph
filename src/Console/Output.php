<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

/**
 * Writes CLI output to a pair of streams.
 *
 * Normal output goes to the first stream and diagnostics to the second, so that
 * `ralph status` output can be piped while errors stay visible. Tests construct
 * this with in-memory streams and read back what was written.
 */
final class Output
{
    /**
     * @param resource $standard stream for ordinary output
     * @param resource $diagnostic stream for errors and warnings
     */
    public function __construct(
        private readonly mixed $standard,
        private readonly mixed $diagnostic,
    ) {
    }

    /**
     * Output bound to the process's own STDOUT and STDERR.
     */
    public static function toStandardStreams(): self
    {
        return new self(STDOUT, STDERR);
    }

    /**
     * Write a line of ordinary output.
     */
    public function line(string $text = ''): void
    {
        $this->write($this->standard, $text . PHP_EOL);
    }

    /**
     * Write several lines of ordinary output.
     *
     * @param list<string> $lines
     */
    public function lines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * Write a line of diagnostic output.
     */
    public function diagnosticLine(string $text = ''): void
    {
        $this->write($this->diagnostic, $text . PHP_EOL);
    }

    /**
     * @param resource $stream
     */
    private function write(mixed $stream, string $text): void
    {
        if (!is_resource($stream)) {
            return;
        }

        fwrite($stream, $text);
    }
}
