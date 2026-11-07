<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Exception;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Process\Interruption;

/**
 * The run was asked to stop, and a phase gave way rather than carrying on.
 *
 * PROJECT.md section H, item 4, as the loop's own control flow. A Ctrl+C can
 * arrive at any moment, including between a passing verification run and the
 * commit that would follow it, and the step in hand has to be able to put its
 * work down somewhere a reader will understand. An exception is how, because
 * every phase of {@see \Celyes\RalphPhp\Run\TaskPass} already sits inside a
 * `try` that records what it found and keeps the workspace: raising this takes
 * that machinery as it is and changes only what the row ends up saying.
 *
 * **It is not a failure, and the state it leads to is not `failed`.** A pass
 * that stopped because a person stopped it is `interrupted` — resumable, its
 * attempt still counted, its workspace kept — and nothing about the work it had
 * done is held against it. That distinction is the reason this is a type of its
 * own rather than a {@see StateException} with a particular message.
 */
final class InterruptionException extends RalphException
{
    /**
     * @param list<string> $hints
     */
    private function __construct(
        private readonly Interruption $interruption,
        string $message,
        private readonly array $hints,
    ) {
        parent::__construct($message);
    }

    /**
     * The stop that cut a phase short, named.
     *
     * @param string $phase what the pass was about to do, in words a user who
     *        has just pressed Ctrl+C will recognise
     */
    public static function before(Interruption $interruption, string $phase): self
    {
        return new self(
            $interruption,
            sprintf('%s before %s', $interruption->reason(), $phase),
            [
                'Nothing was left running: the worker was stopped and its workspace is kept as it was.',
                'Run `ralph resume` to continue from the phase this stopped in.',
            ],
        );
    }

    /**
     * A stop that a step ran into rather than one a boundary noticed.
     *
     * Every command the package starts is cancelled once a stop has been asked
     * for, so the step in hand usually refuses before the next boundary is
     * reached: `git` cannot read a commit, a check reports failure, a push goes
     * nowhere. The refusal's own words are kept, because they say where the run
     * had got to — but what the pass is recorded as is this, and not a failure
     * of the work.
     *
     * @param RalphException $refusal what the step said when it gave up
     */
    public static function during(Interruption $interruption, RalphException $refusal): self
    {
        return new self(
            $interruption,
            sprintf('%s: %s', $interruption->reason(), $refusal->getMessage()),
            [
                'The step in hand was cancelled with the run, so what it reported is where the run '
                    . 'had got to rather than something wrong with the work.',
                'Run `ralph resume` to continue from the phase this stopped in.',
            ],
        );
    }

    /**
     * The interruption itself, for the row and the stop reason.
     */
    public function interruption(): Interruption
    {
        return $this->interruption;
    }

    /**
     * {@see ExitCode::CONFIGURATION} for now, as every recognised failure is
     * until PROJECT.md section I, item 5 settles the documented set — where a
     * status for an interrupted run belongs with the rest of them rather than
     * ahead of them.
     */
    public function exitCode(): int
    {
        return ExitCode::CONFIGURATION;
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
