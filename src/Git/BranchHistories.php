<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\CommitException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationStore;

/**
 * Reads what a branch holds past a commit, and asks the state store whose each
 * of those commits is (PROJECT.md section K, item 5).
 *
 * The one reading that needs both halves of the evidence at once. Git knows
 * which commits a branch has gained and in what order; only the state store
 * knows which of them this package made, because a commit it made has a row
 * naming it ({@see GenerationStore::atCommit()}). Neither answer is enough on
 * its own: a shared branch that has moved on is the ordinary case of the mode
 * and the suspicious case of a crash, and what tells them apart is whether
 * every commit in between belongs to a pass.
 *
 * Why the store rather than the commit's own trailers, which
 * {@see CommitIdentity} can read: a trailer is written by whoever wrote the
 * commit, and a commit claiming to be a pass's is exactly what a reconciliation
 * must not take at face value when deciding that *other* work may be built on
 * top of it. A row is this package's own record. The trailers are read for the
 * one commit where they are the evidence — the pass's own, made a moment before
 * the crash that stopped its row being written — and that reading belongs to
 * {@see \Celyes\RalphPhp\Run\PassRecovery}, which is the only thing that knows
 * which pass is being reconciled.
 *
 * **Reads only.** One `git rev-list` and a query per commit; nothing here
 * creates, moves or deletes a reference, and a range it cannot vouch for is
 * returned as such rather than tidied up.
 */
final class BranchHistories
{
    public function __construct(
        private readonly CommitReader $commits,
        private readonly GenerationStore $generations,
    ) {
    }

    /**
     * The reader for a project's own repository and state store.
     *
     * @param GenerationStore $generations the run's own store, so that a commit
     *        adopted into a row a moment ago is already accounted for here
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     */
    public static function forProject(
        Configuration $configuration,
        GenerationStore $generations,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(
            CommitReader::forProject($configuration, $executable, $processes),
            $generations,
        );
    }

    /**
     * What the tip holds past a commit, and whether this project put it there.
     *
     * The walk stops at the first commit no row accounts for, for two reasons:
     * that commit is the answer — the later ones cannot be reasoned about until
     * somebody decides what it is — and reading past it would spend a query per
     * commit on a range already known to be unvouchable.
     *
     * An empty range is an answer rather than an absence: a tip that *is* the
     * commit asked about holds nothing past it, which is what a pass whose
     * commit is still the branch's tip finds.
     *
     * @param string $from the commit the range starts past, which the caller
     *        has established the tip contains
     *
     * @throws CommitException when Git could not be asked
     * @throws StateException  when the rows cannot be read
     */
    public function beyond(string $from, string $tip): BranchHistory
    {
        if (trim($from) === trim($tip)) {
            return BranchHistory::none($from, $tip);
        }

        $shas = $this->commits->between($from, $tip);
        $total = count($shas);

        if ($total === 0) {
            return BranchHistory::none($from, $tip);
        }

        $recorded = [];

        foreach (array_slice($shas, 0, BranchHistory::MAX_READ) as $sha) {
            $pass = $this->generations->atCommit($sha);

            if ($pass === null) {
                return BranchHistory::withStranger(
                    $from,
                    $tip,
                    $total,
                    $recorded,
                    $this->commits->identify($sha) ?? CommitIdentity::of($sha, ''),
                );
            }

            $recorded[] = $pass->slug();
        }

        return $total > BranchHistory::MAX_READ
            ? BranchHistory::tooLong($from, $tip, $total, $recorded)
            : BranchHistory::accountedFor($from, $tip, $recorded);
    }
}
