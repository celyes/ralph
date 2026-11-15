<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Git\CleanupRequest;
use Celyes\RalphPhp\Git\WorkspaceRetention;
use Celyes\RalphPhp\Git\WorktreeCleaner;
use Celyes\RalphPhp\Git\WorktreeCleanup;
use Celyes\RalphPhp\Git\WorktreeLayout;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\Run\RepositoryLock;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Tasks\TaskId;

/**
 * `ralph clean`: the task workspaces this project is holding, and the explicit
 * way to be rid of one.
 *
 * The second half of PROJECT.md section K, item 7. A failed, blocked or
 * interrupted pass keeps its worktree, because that directory is the evidence —
 * the half-written implementation, the index as the worker left it, what
 * `ralph logs` sends a person to look at. Preserving them is right and it is
 * also cumulative: a project worked on for a week has a checkout per stopped
 * task under `.ralph/runtime/worktrees/`, each with its own `vendor/`. Without
 * a command the answer to that is `rm -rf`, which takes Git's registration with
 * it and leaves `git worktree add` refusing the path afterwards.
 *
 * **Asking is the default; acting has to be asked for.** `ralph clean` with no
 * arguments removes nothing at all. It lists every workspace a row names, says
 * what it would take to remove each, and exits — so the first thing a person
 * runs is the thing that shows them what they are about to do. Naming a task
 * (`ralph clean TASK-ID`) or every task (`ralph clean --all`) is what carries it
 * out. That is the opposite of most `clean` commands, deliberately: the
 * directories here can hold the only copy of something, and a listing that
 * cost somebody nothing is better than a deletion that cost them an afternoon.
 *
 * **It cannot take uncommitted work, and there is no flag that lets it.** The
 * command does exactly one thing the run's own cleanup will not
 * ({@see CleanupRequest::Explicit}): it waives "the pass has not finished", for
 * a pass that has stopped. Every other protection {@see WorktreeCleaner} makes
 * stands — modified or untracked files, a commit no row recorded, a commit its
 * branch does not hold, a worktree Git has locked, a directory this package did
 * not create. A workspace kept for one of those is reported with the reason and
 * the path, and clearing it is the user's own `git worktree remove` once they
 * have decided what in it is worth keeping. A `--force` here would be a single
 * word that deleted an unknown amount of somebody's work, which is why the
 * package does not have one.
 *
 * **It takes the repository lock.** A workspace a live runner is holding must
 * not be deleted from another terminal, and "is a runner live" has one safe
 * answer in this package ({@see RepositoryLock}). The listing form takes no
 * lock, because it only asks questions, and is safe to run during a run.
 *
 * **Nothing a pass earned is touched.** Cleanup removes a directory; the
 * branch, the commit, the push result, the attempts, the logs and the
 * verification verdicts all stay exactly where they are, which is what a
 * stopped pass is retried and reviewed from. `ralph clean` on a task is
 * therefore not a way to undo it: {@see RetryCommand} is what tries again, and
 * it prepares a fresh workspace from the recorded base when the old one has
 * gone.
 */
final class CleanCommand extends ProjectCommand
{
    public function __construct(
        private readonly StateBootstrap $state = new StateBootstrap(),
        ConfigurationLoader $loader = new ConfigurationLoader(),
    ) {
        parent::__construct($loader);
    }

    public function name(): string
    {
        return 'clean';
    }

    public function summary(): string
    {
        return 'List preserved task workspaces, and remove the ones you ask for.';
    }

    /**
     * @return list<string>
     */
    protected function options(): array
    {
        return ['all'];
    }

    protected function maximumArguments(): int
    {
        return 1;
    }

    /**
     * @return list<string>
     */
    public function help(): array
    {
        return [
            'Usage: ralph clean [TASK-ID] [--all]',
            '',
            'Reports the task workspaces this project is holding under',
            '.ralph/runtime/worktrees/, and removes the ones you name.',
            '',
            'With no arguments it removes nothing: it lists every recorded workspace, says',
            'whether it could be removed, and gives the reason for each one that could not.',
            'Run it that way first — it is the only form that is safe during a run.',
            '',
            '  ralph clean            list the workspaces and what it would take to remove',
            '                         them; changes nothing',
            '  ralph clean TASK-ID    remove the workspaces of that task\'s stopped passes',
            '  ralph clean --all      the same for every stopped pass in the project',
            '',
            'Why a workspace is there in the first place:',
            '',
            '  A pass that failed, was blocked or was interrupted keeps its worktree, so',
            '  that you can read what the agent actually did and so that "ralph retry',
            '  TASK-ID" can carry on in it. A pass that completed has its worktree removed',
            '  as the last step of finishing, so a tidy project has none.',
            '',
            'What it will not do, and what to do instead:',
            '',
            '  Uncommitted changes are never deleted. A workspace holding modified, staged',
            '  or untracked files is kept and reported, whichever form you used, and there',
            '  is no option that overrides that. If you have decided those changes are not',
            '  wanted, discard them yourself and run the command again:',
            '',
            '      git -C WORKSPACE reset --hard && git -C WORKSPACE clean -fd',
            '      ralph clean TASK-ID',
            '',
            '  A commit that only this workspace refers to is kept for the same reason:',
            '  point a branch of your own at the SHA reported first, then try again.',
            '',
            '  A workspace Ralph PHP did not create, one Git has locked, and one Git has no',
            '  record of are all left alone. The report names them and what Git said; they',
            '  are yours to remove.',
            '',
            '  A pass a runner is working on now is never removed, and the acting forms',
            '  refuse to start at all while another Ralph PHP holds this repository.',
            '',
            'What is kept whatever happens: the task\'s branch and commit, its attempts, its',
            'logs and its verification results. Cleaning a workspace deletes a directory,',
            'not the record of the work — "ralph retry TASK-ID" prepares a fresh one.',
            '',
            'Exits 0 when the listing printed, or when everything you asked about is gone.',
            'Exits 75 when a workspace you asked about was kept, since clearing it is then',
            'waiting on you, and 78 when the project has nothing recorded or another runner',
            'holds it.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $task = $input->argument(0) === null ? null : $this->taskArgument($input);
        $all = $input->hasOption('all');

        if ($task !== null && $all) {
            throw new UsageException(
                '"ralph clean" takes either a task identifier or "--all", not both.',
                [
                    'Usage: ralph clean [TASK-ID] [--all]',
                    'Run "ralph clean" with neither to see what is there first.',
                ],
            );
        }

        $configuration = $this->configuration();

        // A retention reason quotes Git, a workspace path and a blocker's
        // words, so the project's declared secrets are kept out of all of it
        // before a line is printed (PROJECT.md section I, item 6).
        $output->redact(Secrets::forProject($configuration));

        $journal = $this->journal($configuration);
        $cleaner = WorktreeCleaner::forProject($configuration, $journal->generations());

        $passes = $task === null
            ? $journal->generations()->all()
            : $this->passesOf($task, $journal);

        if ($task === null && !$all) {
            return $this->report($configuration, $passes, $cleaner, $output);
        }

        return $this->removeWhatItMay($configuration, $passes, $cleaner, $output);
    }

    /**
     * Say what is there, and change nothing.
     *
     * Every question {@see WorktreeCleaner::assess()} asks is a `git` query, so
     * this cannot take the index lock of a repository somebody else is working
     * in — which is what makes it the form that needs no run lock and is safe
     * during a run.
     *
     * The decisions are read with {@see CleanupRequest::Explicit}, because the
     * question being answered is "what would `ralph clean` do", and a listing
     * that reported every stopped pass as unremovable would answer a different
     * one.
     *
     * @param list<GenerationRecord> $passes
     */
    private function report(
        Configuration $configuration,
        array $passes,
        WorktreeCleaner $cleaner,
        Output $output,
    ): int {
        $style = $output->style();

        $this->reportProject($configuration, $output);

        $workspaces = 0;
        $removable = 0;

        foreach ($passes as $pass) {
            $retention = $cleaner->assess($pass, CleanupRequest::Explicit);

            if ($retention->path() === null) {
                continue;
            }

            ++$workspaces;

            if ($retention->isRemovable()) {
                ++$removable;
            }

            $output->line();
            $this->reportWorkspace($pass, $retention, $output);
        }

        $output->line();

        if ($workspaces === 0) {
            $output->line($style->muted('No task workspace is recorded; there is nothing to clean up.'));

            return ExitCode::SUCCESS;
        }

        $output->line(sprintf(
            '%d workspace%s recorded, %d of which can be removed.',
            $workspaces,
            $workspaces === 1 ? '' : 's',
            $removable,
        ));
        $output->line($style->muted(
            'Nothing was removed. Run "ralph clean TASK-ID" for one, or "ralph clean --all" for all of them.',
        ));

        return ExitCode::SUCCESS;
    }

    /**
     * Carry it out: remove every workspace the rules allow, report every one
     * they do not.
     *
     * The lock is taken for the whole sweep rather than per workspace, so a run
     * cannot start between two removals and find one of its own directories
     * being deleted. It is released however this ends, including on the way out
     * of a raised failure.
     *
     * @param list<GenerationRecord> $passes
     *
     * @throws \Celyes\RalphPhp\Exception\LockException when another runner holds
     *         the repository
     */
    private function removeWhatItMay(
        Configuration $configuration,
        array $passes,
        WorktreeCleaner $cleaner,
        Output $output,
    ): int {
        $style = $output->style();
        $lock = RepositoryLock::forProject($configuration)->acquire($this->name());

        try {
            $this->reportProject($configuration, $output);

            $removed = 0;
            $kept = [];
            $touched = 0;

            foreach ($passes as $pass) {
                if ($cleaner->assess($pass, CleanupRequest::Explicit)->path() === null) {
                    continue;
                }

                ++$touched;
                $cleanup = $cleaner->clean($pass, null, CleanupRequest::Explicit);

                $output->line();
                $this->reportWorkspace($pass, $cleanup->retention(), $output, $cleanup);

                if ($cleanup->wasRemoved()) {
                    ++$removed;

                    continue;
                }

                $kept[] = $cleanup;
            }

            $output->line();

            if ($touched === 0) {
                $output->line($style->muted('No task workspace is recorded; there was nothing to clean up.'));

                return ExitCode::SUCCESS;
            }

            $output->line(sprintf(
                '%d workspace%s removed, %d kept.',
                $removed,
                $removed === 1 ? '' : 's',
                count($kept),
            ));

            if ($kept === []) {
                return ExitCode::SUCCESS;
            }

            // Not a failure of the command — it did everything it is allowed to
            // do — but something is waiting for a person, and a caller that
            // chains onto this must be able to tell that without reading prose.
            $output->line($style->muted(
                'What was kept is listed above with the reason. Each one is yours to clear up; '
                    . 'nothing of the work was touched.',
            ));

            return ExitCode::BLOCKED;
        } finally {
            $lock->release();
        }
    }

    /**
     * The project and where its workspaces live.
     */
    private function reportProject(Configuration $configuration, Output $output): void
    {
        $style = $output->style();

        $output->line(sprintf(
            '%s %s',
            $style->heading('Ralph PHP task workspaces in'),
            $style->identifier($configuration->projectRoot()),
        ));
        $output->line(sprintf(
            '  %s  %s',
            $style->label('worktrees'),
            $style->identifier(WorktreeLayout::forProject($configuration)->root()),
        ));
    }

    /**
     * One workspace: whose it is, where it is, what happened or would happen,
     * and why.
     *
     * The reasons and the hints are {@see WorkspaceRetention}'s own words
     * rather than this command's. They are written once, beside the check that
     * produces each one, and a command that paraphrased them would be a second
     * account of a protection the user is being asked to trust.
     */
    private function reportWorkspace(
        GenerationRecord $pass,
        WorkspaceRetention $retention,
        Output $output,
        ?WorktreeCleanup $cleanup = null,
    ): void {
        $style = $output->style();

        $output->line(sprintf(
            '%s  %s  %s',
            $style->identifier($pass->task()->value()),
            $style->muted('pass ' . $pass->number()),
            $style->state($pass->state(), $pass->state()->value),
        ));
        $output->line(sprintf(
            '  %s  %s',
            $style->label('workspace'),
            $style->identifier((string) $retention->path()),
        ));
        $output->line(sprintf(
            '  %s  %s',
            $style->label('outcome  '),
            $this->verdictOf($retention, $cleanup, $style),
        ));

        foreach ($retention->details() as $detail) {
            $output->line('  ' . $style->muted('· ' . $detail));
        }

        foreach ($retention->hints() as $hint) {
            $output->line('  ' . $style->muted('→ ' . $hint));
        }
    }

    /**
     * What to print on the outcome line: what was done when something was, and
     * what the decision was when nothing has been attempted yet.
     */
    private function verdictOf(
        WorkspaceRetention $retention,
        ?WorktreeCleanup $cleanup,
        Style $style,
    ): string {
        if ($cleanup !== null && $cleanup->clearedRegistrationOnly()) {
            return $style->pass('the record of a workspace that had already gone was cleared');
        }

        if ($cleanup !== null && $cleanup->wasRemoved()) {
            return $style->pass('removed');
        }

        if ($cleanup !== null) {
            return $style->warn('kept');
        }

        return $retention->isRemovable()
            ? $style->pass('would be removed')
            : $style->warn('would be kept');
    }

    /**
     * The passes of one task, which it must have had.
     *
     * @return list<GenerationRecord>
     *
     * @throws StateException when nothing has been recorded for the task
     */
    private function passesOf(TaskId $task, Journal $journal): array
    {
        $passes = $journal->generations()->forTask($task);

        if ($passes === []) {
            throw new StateException(
                sprintf('Nothing has been recorded for %s, so it has no workspace.', $task->value()),
                [
                    'Only a task a run has taken on has a workspace; one that is still pending has none.',
                    'Run "ralph clean" with no arguments to list the workspaces this project is holding.',
                ],
            );
        }

        return $passes;
    }

    /**
     * The state store, which this command cannot do without.
     *
     * Opened rather than created. A project with no state store has no recorded
     * workspace by definition, and writing a database to say so would be a
     * command that changed what it was asked about.
     *
     * @throws StateException when the project has no state store
     */
    private function journal(Configuration $configuration): Journal
    {
        $file = StateBootstrap::fileIn($configuration->projectRoot());

        if (!is_file($file)) {
            throw new StateException(
                sprintf('This project has no state store at %s, so no workspace is recorded.', $file),
                [
                    'Ralph PHP creates a workspace when a run takes a task on; nothing here has run yet.',
                    sprintf(
                        'If %s holds directories, they were not created by this project and are yours to remove.',
                        WorktreeLayout::forProject($configuration)->root(),
                    ),
                ],
            );
        }

        return Journal::of($this->state->open($configuration));
    }
}
