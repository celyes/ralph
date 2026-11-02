<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Support\Excerpt;
use InvalidArgumentException;

/**
 * What came back when an agent's output was read for a report.
 *
 * Three answers, and PROJECT.md section E, item 5 needs all three kept apart:
 * a report was found and understood, no block was printed at all, or a block
 * was printed and could not be read. The last two both end the attempt as an
 * execution error, but they are different things to tell a user — one agent
 * never reported, the other reported something broken — and the remedies
 * differ, so the distinction survives to the evidence rather than being
 * flattened at the point it is discovered.
 *
 * **A problem is not an exception.** Unreadable output is an ordinary thing for
 * an agent to produce and the loop has bookkeeping to do about it: the attempt
 * is recorded, the evidence is kept, and the next attempt is told. So it is
 * returned as a value, exactly as {@see ExecutionAdapter::execute()} returns
 * rather than throws for anything the agent did.
 *
 * **It keeps the offending text.** A malformed reading carries the block as it
 * was printed, bounded, because "could not be read" without the thing that
 * could not be read is not evidence a user can act on — and the usual cause is
 * visible at a glance once the block is in front of them.
 */
final class ReportReading
{
    /**
     * How much of an unreadable block is kept as evidence.
     *
     * Enough for a realistic report with a sentence or two in it, and bounded
     * because the text is an agent's and may be a megabyte of anything.
     * {@see \Celyes\RalphPhp\State\FailureEvidence} bounds it again on the way
     * into a record; this bound is so that nothing large is carried around in
     * the meantime.
     */
    public const int BLOCK_CHARACTERS = 500;

    private function __construct(
        private readonly ?AgentReport $report,
        private readonly ?string $problem,
        private readonly string $block,
        private readonly ?OutputStream $stream,
        private readonly int $blocks,
    ) {
    }

    /**
     * A report was found and read.
     *
     * @param int $blocks how many blocks the output held, the last of which
     *        this is
     *
     * @throws InvalidArgumentException when fewer than one block was counted
     */
    public static function found(AgentReport $report, OutputStream $stream, int $blocks = 1): self
    {
        if ($blocks < 1) {
            throw new InvalidArgumentException('A report that was found was in at least one block.');
        }

        return new self($report, null, '', $stream, $blocks);
    }

    /**
     * The agent printed no block at all.
     */
    public static function absent(): self
    {
        return new self(null, null, '', null, 0);
    }

    /**
     * A block was printed and could not be read.
     *
     * @param string $problem what was wrong with it, in terms of the format
     * @param string $block the block's contents, as printed
     *
     * @throws InvalidArgumentException when the problem says nothing
     */
    public static function malformed(string $problem, string $block, OutputStream $stream, int $blocks = 1): self
    {
        if (trim($problem) === '') {
            throw new InvalidArgumentException('Refusing a block has to say what was wrong with it.');
        }

        return new self(
            null,
            trim($problem),
            Excerpt::of($block, self::BLOCK_CHARACTERS),
            $stream,
            max(1, $blocks),
        );
    }

    /**
     * Whether there is a report to act on.
     */
    public function hasReport(): bool
    {
        return $this->report !== null;
    }

    /**
     * The report, where one was read.
     */
    public function report(): ?AgentReport
    {
        return $this->report;
    }

    /**
     * Whether the agent printed no block.
     */
    public function isAbsent(): bool
    {
        return $this->report === null && $this->problem === null;
    }

    /**
     * Whether a block was printed that could not be read.
     */
    public function isMalformed(): bool
    {
        return $this->problem !== null;
    }

    /**
     * What was wrong with the block, where one was refused.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * The refused block as it was printed, bounded.
     */
    public function block(): string
    {
        return $this->block;
    }

    /**
     * Which stream the block was printed on, where there was one.
     *
     * Kept because it is the first thing to check when an agent reports
     * correctly and the package disagrees: a CLI that writes its transcript to
     * standard error and a wrapper that swallows it look identical from the
     * outcome alone.
     */
    public function stream(): ?OutputStream
    {
        return $this->stream;
    }

    /**
     * How many blocks the output held.
     *
     * More than one is not an error — the instructions say the last is read,
     * and an agent that quotes the format before using it has printed two —
     * but it is worth saying in a log, since it is also what an agent trying
     * two answers looks like.
     */
    public function blocks(): int
    {
        return $this->blocks;
    }

    /**
     * One line for a log note, said as an observation.
     */
    public function describe(): string
    {
        if ($this->report === null && $this->problem === null) {
            return 'printed no result block';
        }

        $aside = [];

        if ($this->stream !== null) {
            $aside[] = $this->stream->label();
        }

        if ($this->blocks > 1) {
            $aside[] = sprintf('the last of %d blocks', $this->blocks);
        }

        $line = $this->problem !== null
            ? sprintf('printed a result block that could not be read: %s', $this->problem)
            : $this->report->describe();

        return $aside === [] ? $line : sprintf('%s (%s)', $line, implode(', ', $aside));
    }
}
