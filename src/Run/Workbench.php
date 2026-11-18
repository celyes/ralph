<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Log\LogWiper;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Memory\MemoryWriter;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tasks\ChecklistReconciler;
use Celyes\RalphPhp\Tasks\ChecklistWriter;
use Celyes\RalphPhp\Tasks\GenerationSelector;

/**
 * Everything a task is carried out with, built once for a run.
 *
 * The checklist readers and writers of PROJECT.md section C, the context and
 * memory of section D, and the logs of section E. Each of these was written to
 * be usable on its own and most of them take the configuration — so they are
 * assembled here, once, rather than in the middle of a loop that would
 * otherwise spend a line per task rebuilding the same objects from the same
 * settings.
 *
 * Built from the configuration the run froze, so a worker that edits
 * `.ralph/config.json` while the run is going cannot change where this run
 * reads its tasks from or how long it keeps their logs for any task the run has
 * left.
 *
 * There is nothing here that touches Git. A task is implemented in the user's
 * own checkout, on the branch they have checked out (section K, item 1), so the
 * workspace a pass works in is the project root and needs neither making nor
 * cleaning up.
 */
final class Workbench
{
    public function __construct(
        private readonly CompletionRecorder $completions,
        private readonly LogDirectory $logs,
        private readonly LogWiper $wiper,
        private readonly MemoryWriter $memory,
        private readonly AttemptContextLoader $context,
        private readonly ChecklistReader $checklist,
        private readonly ChecklistWriter $checkboxes,
        private readonly ChecklistReconciler $reconciler,
        private readonly GenerationSelector $passes,
    ) {
    }

    /**
     * Assemble the tools for a project.
     *
     * @param Journal $journal the run's stores; the log wiper decides what it
     *        may delete from a pass's row, and it must be the same instance the
     *        loop reads back through
     */
    public static function forProject(
        Configuration $configuration,
        Journal $journal,
        ?Clock $clock = null,
        ?Secrets $secrets = null,
    ): self {
        // The logs are told the run's known secrets so that the notes they
        // write are redacted; the worker's own output is kept as it arrived
        // (section I, item 6, and {@see \Celyes\RalphPhp\Log\LogFile}).
        $logs = LogDirectory::in($configuration->projectRoot(), $secrets);

        return new self(
            new CompletionRecorder(),
            $logs,
            // The same directory the run writes into, so what a wipe is
            // entitled to delete cannot drift from what was written.
            new LogWiper(
                $logs,
                $configuration->logs(),
                $journal->generations(),
                $journal->checklists(),
            ),
            MemoryWriter::in($configuration->projectRoot(), $clock ?? new SystemClock()),
            new AttemptContextLoader(),
            new ChecklistReader(),
            new ChecklistWriter(),
            new ChecklistReconciler(),
            new GenerationSelector(),
        );
    }

    /**
     * What keeps the evidence behind a ticked box (PROJECT.md section G,
     * item 7).
     */
    public function completions(): CompletionRecorder
    {
        return $this->completions;
    }

    public function logs(): LogDirectory
    {
        return $this->logs;
    }

    /**
     * What deletes a successful task's own logs, when the project has asked for
     * that (PROJECT.md section K, item 11).
     */
    public function wiper(): LogWiper
    {
        return $this->wiper;
    }

    public function memory(): MemoryWriter
    {
        return $this->memory;
    }

    public function context(): AttemptContextLoader
    {
        return $this->context;
    }

    public function checklist(): ChecklistReader
    {
        return $this->checklist;
    }

    public function checkboxes(): ChecklistWriter
    {
        return $this->checkboxes;
    }

    public function reconciler(): ChecklistReconciler
    {
        return $this->reconciler;
    }

    /**
     * How a task's next execution generation is chosen.
     */
    public function passes(): GenerationSelector
    {
        return $this->passes;
    }
}
