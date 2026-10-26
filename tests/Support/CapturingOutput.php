<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Console\Output;
use RuntimeException;

/**
 * An {@see Output} over in-memory streams, plus access to what was written.
 *
 * Lets a test drive the application in-process and assert on both streams
 * separately, which is the cheap counterpart to running the real binary: no
 * process start-up cost, and a failure points straight at the code under test.
 */
final class CapturingOutput
{
    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $diagnostic;

    private readonly Output $output;

    public function __construct()
    {
        $this->standard = self::openMemoryStream();
        $this->diagnostic = self::openMemoryStream();
        $this->output = new Output($this->standard, $this->diagnostic);
    }

    /**
     * The {@see Output} to hand to the application or a command.
     */
    public function output(): Output
    {
        return $this->output;
    }

    /**
     * Everything written to the ordinary output stream so far.
     */
    public function standardOutput(): string
    {
        return $this->contents($this->standard);
    }

    /**
     * Everything written to the diagnostic stream so far.
     */
    public function errorOutput(): string
    {
        return $this->contents($this->diagnostic);
    }

    /**
     * @return resource
     */
    private static function openMemoryStream(): mixed
    {
        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory stream for test output.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Unable to read back captured test output.');
        }

        // Leave the position at the end so later writes append rather than
        // overwrite what has already been read.
        fseek($stream, 0, SEEK_END);

        return $contents;
    }
}
