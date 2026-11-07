<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\TaskBranches;
use Celyes\RalphPhp\Git\TaskCommitter;
use Celyes\RalphPhp\Git\TaskPublisher;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspaceAuditor;
use Celyes\RalphPhp\Git\WorkspacePreparer;
use Celyes\RalphPhp\Git\WorkspaceStager;
use Celyes\RalphPhp\Git\WorktreeCleaner;
use Celyes\RalphPhp\Log\LogDirectory;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\Memory\MemoryWriter;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Tasks\ChecklistReader;
use Celyes\RalphPhp\Tasks\ChecklistReconciler;
use Celyes\RalphPhp\Tasks\ChecklistWriter;
use Celyes\RalphPhp\Tasks\GenerationSelector;
use Celyes\RalphPhp\Verification\ChecksAuditor;

/**
 * Everything a task is carried out with, built once for a run.
 *
 * The Git steps of PROJECT.md section F, the checklist readers and writers of
 * section C, and the context and log of sections D and E. Each of these was
 * written to be usable on its own and each takes the configuration — so they
 * are assembled here, once, rather than in the middle of a loop that would
 * otherwise spend a line per task rebuilding the same ten objects from the same
 * settings.
 *
 * Built from the configuration the run froze, so a worker that edits
 * `.ralph/config.json` while the run is going cannot change the base reference,
 * the branch prefix, the push remote, or what counts as uncommittable for any
 * task the run has left.
 */
final class Workbench
{
    public function __construct(
        private readonly TaskWorktrees $worktrees,
        private readonly TaskBranches $branches,
        private readonly BranchNamer $namer,
        private readonly WorkspacePreparer $preparer,
        private readonly WorkspaceAuditor $auditor,
        private readonly WorkspaceStager $stager,
        private readonly ChecksAuditor $checks,
        private readonly TaskCommitter $committer,
        private readonly TaskPublisher $publisher,
        private readonly WorktreeCleaner $cleaner,
        private readonly LogDirectory $logs,
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
     * @param Journal $journal the run's stores; the Git steps that record what
     *        they did need the generation store, and they must be the same
     *        instances the loop reads back through
     */
    public static function forProject(
        Configuration $configuration,
        Journal $journal,
        ?Clock $clock = null,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        $generations = $journal->generations();

        return new self(
            TaskWorktrees::forProject($configuration, $executable, $processes),
            TaskBranches::forProject($configuration, $executable, $processes),
            BranchNamer::forProject($configuration),
            WorkspacePreparer::forProject($configuration, $processes),
            WorkspaceAuditor::forProject($configuration, $generations, $executable, $processes),
            WorkspaceStager::forProject($configuration, $executable, $processes),
            new ChecksAuditor($executable, $processes),
            TaskCommitter::forProject($configuration, $generations, $executable, $processes),
            TaskPublisher::forProject($configuration, $generations, $executable, $processes),
            WorktreeCleaner::forProject($configuration, $generations, $executable, $processes),
            LogDirectory::in($configuration->projectRoot()),
            MemoryWriter::in($configuration->projectRoot(), $clock ?? new SystemClock()),
            new AttemptContextLoader(),
            new ChecklistReader(),
            new ChecklistWriter(),
            new ChecklistReconciler(),
            new GenerationSelector(),
        );
    }

    public function worktrees(): TaskWorktrees
    {
        return $this->worktrees;
    }

    public function branches(): TaskBranches
    {
        return $this->branches;
    }

    public function namer(): BranchNamer
    {
        return $this->namer;
    }

    public function preparer(): WorkspacePreparer
    {
        return $this->preparer;
    }

    public function auditor(): WorkspaceAuditor
    {
        return $this->auditor;
    }

    public function stager(): WorkspaceStager
    {
        return $this->stager;
    }

    /**
     * What reads a staged diff for changes that weaken the project's own
     * verification (PROJECT.md section G, item 5).
     */
    public function checks(): ChecksAuditor
    {
        return $this->checks;
    }

    public function committer(): TaskCommitter
    {
        return $this->committer;
    }

    public function publisher(): TaskPublisher
    {
        return $this->publisher;
    }

    public function cleaner(): WorktreeCleaner
    {
        return $this->cleaner;
    }

    public function logs(): LogDirectory
    {
        return $this->logs;
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
