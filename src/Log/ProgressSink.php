<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Support\Elapsed;

/**
 * Proof that a quiet run is still running.
 *
 * The other half of PROJECT.md section K, item 2. The first half made the
 * agent's transcript optional ({@see TerminalSink}), and a run that prints a
 * task and then nothing for twenty minutes is not a quieter run, it is a run
 * that looks hung. A user watching one has no way to tell a worker that is
 * thinking from a worker that has died, which is the one failure PROJECT.md
 * section E, item 4 exists to prevent — and turning `--verbose` on to find out
 * means losing the run and starting again.
 *
 * So this is the quiet counterpart of the transcript: not the output, but the
 * fact that there is output, said once every {@see self::BEAT_SECONDS} and no
 * oftener. One line, with how long the attempt has been going and how much the
 * worker has produced so far — both of which move while the work is healthy and
 * neither of which moves when it is not:
 *
 * ```
 *   still working: 2m 10s, 184 lines of output
 * ```
 *
 * **Driven by the output, not by a clock.** A beat is considered when a chunk
 * arrives, so a beat means bytes arrived — a line printed by a timer would say
 * "still working" about a worker that had stopped saying anything, which is
 * exactly the case it is here to expose. The cost of that is a worker which
 * goes completely silent gets no beats; what it gets is the phase line
 * ({@see \Celyes\RalphPhp\Run\TaskPass}) that says the run is waiting for the
 * agent, and silence under it, which is the honest account. A verbose run has
 * nothing more to offer about such a worker either.
 *
 * **Under `--verbose` it says nothing.** The transcript is already on the
 * screen and is itself the proof; a summary interleaved with the output it
 * summarises would be a line landing in the middle of the agent's work.
 *
 * **Nothing here is evidence**, for the reason {@see TerminalSink} gives: these
 * lines are composed for a terminal that is being watched, are not written to
 * the attempt's log, and tell a reader nothing the log does not hold already.
 */
final class ProgressSink implements OutputSink
{
    /**
     * Seconds between beats.
     *
     * Long enough that a twenty-minute attempt is forty lines rather than a
     * second transcript, and short enough that a user who has just started a
     * run does not sit through a minute of nothing wondering. It is a floor on
     * the interval and not a schedule: a beat happens on the first chunk to
     * arrive after it, so a quiet worker beats more slowly and a silent one not
     * at all.
     */
    private const float BEAT_SECONDS = 30.0;

    /**
     * When the attempt this is reporting on began.
     */
    private readonly float $opened;

    /**
     * When the last beat was printed, or the opening if there has been none.
     */
    private float $beat;

    /**
     * Newlines seen, which is how much the worker has said.
     */
    private int $lines = 0;

    /**
     * Whether something better than a heartbeat is being said about this
     * attempt.
     *
     * A beat is a substitute for knowing what the worker is doing: it says
     * bytes are arriving, because for an agent whose output is a machine format
     * that is all a quiet run could tell. An adapter that reports activities
     * can say what the agent is actually doing ({@see ActivitySink}), and once
     * one has, "still working: 2m 10s, 184 lines of output" is both redundant
     * and worse — a line about the volume of output, landing in the middle of
     * an account of the work.
     *
     * Set by the first activity and never cleared: an agent that reported one
     * will go on reporting them, and a session that falls silent after one has
     * the phase line under it, exactly as a silent session always did.
     */
    private bool $narrated = false;

    /**
     * @param float $interval seconds between beats. Defaulted rather than
     *        configurable: a run does not choose this, and it is an argument at
     *        all because the alternative is a test that waits half a minute to
     *        find out what a line says. {@see Elapsed} is deliberately static —
     *        a monotonic reading is not a collaborator anything can substitute
     *        — so the interval is the only seam a test has.
     */
    public function __construct(
        private readonly Output $output,
        private readonly float $interval = self::BEAT_SECONDS,
    ) {
        $this->opened = Elapsed::reading();
        $this->beat = $this->opened;
    }

    /**
     * Count a chunk, and beat if one is due.
     *
     * Which stream it came off does not matter: an agent that reports its
     * progress on standard error is working as hard as one that does not, and
     * the beat goes to ordinary output either way, because it is a line this
     * package composed about the run rather than a copy of the worker's.
     */
    public function write(OutputStream $stream, string $chunk): void
    {
        if ($chunk === '' || $this->output->isVerbose() || $this->narrated) {
            return;
        }

        $this->lines += substr_count($chunk, "\n");

        if (Elapsed::since($this->beat) < $this->interval) {
            return;
        }

        $this->beat = Elapsed::reading();

        $style = $this->output->style();

        $this->output->line('  ' . $style->muted(sprintf(
            'still working: %s, %s',
            self::elapsed(Elapsed::since($this->opened)),
            $this->produced(),
        )));
    }

    /**
     * Notes are not beats.
     *
     * A note is the package narrating a step of its own work, and what a quiet
     * run says about its own steps is decided where those lines are composed
     * ({@see \Celyes\RalphPhp\Run\Runner}, {@see \Celyes\RalphPhp\Run\TaskPass})
     * rather than by echoing the log.
     */
    public function note(string $line): void
    {
    }

    /**
     * Stand down: this attempt has something better to say for itself.
     *
     * Nothing is printed here — showing an activity is {@see ActivitySink}'s
     * work — and what this records is that showing it is happening at all, so
     * the beats stop ({@see self::$narrated}).
     */
    public function activity(Activity $activity): void
    {
        $this->narrated = true;
    }

    /**
     * Nothing to flush and nothing held open.
     *
     * The blank line that is owed after an attempt's streamed output is
     * {@see TerminalSink::close()}'s to ask for: it is owed once, whether one
     * sink echoed the output or two did ({@see Output::separate()}).
     */
    public function close(): void
    {
    }

    /**
     * How much the worker has said.
     *
     * Counted in lines, except when there has not been one yet: a worker whose
     * output so far is one long line still being written has produced
     * something, and "0 lines of output" about it would read as a worker that
     * has produced nothing at all.
     */
    private function produced(): string
    {
        if ($this->lines === 0) {
            return 'part-way through the first line of output';
        }

        return sprintf('%d line%s of output', $this->lines, $this->lines === 1 ? '' : 's');
    }

    /**
     * A duration a person reads at a glance.
     *
     * Whole seconds under a minute and minutes above it: this is printed while
     * a user is watching, and the tenths {@see \Celyes\RalphPhp\Agent\ProcessAdapter}
     * records in the log are a precision nobody needs about a run that is still
     * going.
     */
    private static function elapsed(float $seconds): string
    {
        if ($seconds < 60.0) {
            return sprintf('%ds', (int) $seconds);
        }

        return sprintf('%dm %ds', (int) ($seconds / 60), (int) fmod($seconds, 60.0));
    }
}
