<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\OutputStream;

/**
 * A sink that keeps every call made to it, in order.
 *
 * Here because the thing most often under test about streaming is not the text
 * that came out but *when* and *in how many pieces* — a sink handed one chunk
 * at the end has collected output, not streamed it, and a string is the same
 * either way. Each write is recorded separately, so a test can assert that a
 * worker's first line arrived before its second was written.
 */
final class RecordingSink implements OutputSink
{
    /**
     * @var list<array{stream: OutputStream, chunk: string}>
     */
    public array $writes = [];

    /**
     * @var list<string>
     */
    public array $notes = [];

    public int $closes = 0;

    /**
     * @param int $delayMicroseconds how long each write takes, for a test that
     *        needs a sink the worker can outrun
     */
    public function __construct(private readonly int $delayMicroseconds = 0)
    {
    }

    public function write(OutputStream $stream, string $chunk): void
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        $this->writes[] = ['stream' => $stream, 'chunk' => $chunk];
    }

    public function note(string $line): void
    {
        $this->notes[] = $line;
    }

    public function close(): void
    {
        $this->closes++;
    }

    /**
     * Everything written to one stream, joined.
     */
    public function textOf(OutputStream $stream): string
    {
        $chunks = [];

        foreach ($this->writes as $write) {
            if ($write['stream'] === $stream) {
                $chunks[] = $write['chunk'];
            }
        }

        return implode('', $chunks);
    }

    /**
     * How many bytes arrived on one stream.
     */
    public function byteCountOf(OutputStream $stream): int
    {
        return strlen($this->textOf($stream));
    }

    /**
     * How many separate chunks arrived on one stream.
     */
    public function chunkCountOf(OutputStream $stream): int
    {
        $count = 0;

        foreach ($this->writes as $write) {
            if ($write['stream'] === $stream) {
                $count++;
            }
        }

        return $count;
    }
}
