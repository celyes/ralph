<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * Somewhere a worker's output goes while the worker is still running.
 *
 * PROJECT.md section E, item 4 asks for two destinations at once — the
 * terminal, so a user can see an agent working, and the task's own log, so the
 * same output can be read afterwards — and the point of an interface here is
 * that {@see \Celyes\RalphPhp\Process\ProcessRunner} forwards chunks to one
 * thing and does not know how many destinations are behind it
 * ({@see Sinks}), or whether there are any at all.
 *
 * **Chunks, not lines.** A sink is handed whatever came off the pipe, which is
 * a read of up to a few kilobytes that begins and ends wherever the worker
 * happened to be. A sink that needs whole lines buffers them itself
 * ({@see LogFile} does); one that does not, writes the bytes through
 * ({@see TerminalSink} does, which is what makes output appear as it is
 * produced rather than a line behind).
 *
 * **A sink must not throw.** It is written to from inside the loop that is
 * draining a live child process's pipes, so an exception out of a write would
 * abandon that child — a running agent with nobody waiting for it, which is
 * exactly the orphan this package must not create. A sink that cannot write
 * absorbs the failure, stops trying, and reports it through its own API
 * afterwards ({@see LogFile::failure()}); losing a diagnostic copy of the
 * output is a much smaller harm than losing the process. The runner guards
 * against a sink that breaks this anyway, but it can only protect the child,
 * not the output.
 *
 * **Who closes it.** Not the runner, and not an adapter. An attempt's log spans
 * more than one command — the agent, then the verification commands of section
 * G — so the sink outlives any single invocation and is closed by whoever
 * opened it.
 */
interface OutputSink
{
    /**
     * Take a chunk of worker output, as it arrived.
     *
     * @param OutputStream $stream which descriptor it came off
     * @param string $chunk raw bytes, possibly ending mid-line and possibly
     *        not valid UTF-8 — a worker may print anything
     */
    public function write(OutputStream $stream, string $chunk): void;

    /**
     * Record a line Ralph PHP itself is saying.
     *
     * Kept distinct from {@see self::write()} for the reason the claim and the
     * evidence are kept apart everywhere else in this package: "the agent
     * exited with status 1" is the package's observation, and a log in which
     * that line is indistinguishable from a line the agent printed is a log in
     * which a model can forge one. A sink marks notes as its own.
     *
     * @param string $line one line, without a trailing newline
     */
    public function note(string $line): void;

    /**
     * Finish: flush anything buffered and release whatever was held open.
     *
     * Must be safe to call twice, since a run that failed part-way may close a
     * sink on the way out of more than one `finally`.
     */
    public function close(): void;
}
