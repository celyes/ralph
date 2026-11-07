<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\StagedChanges;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\WorkspaceState;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Support\UnverifiedText;

/**
 * Makes the case for a task that changed nothing, and writes it down
 * (PROJECT.md section G, item 6).
 *
 * Called once per attempt, at the one moment the question exists: after the
 * checks have passed and after staging has reported an empty index, before
 * anything is completed. The reading itself is {@see Satisfaction} — this
 * collects what it is made of, keeps a copy for a person, and says on the log
 * what it concluded.
 *
 * **Why the record is a file.** The verification rows already hold every check
 * that ran, and the attempt's row holds the agent's claim; what neither holds
 * is the two of them together as the reason a box was ticked without a commit.
 * A commit is its own evidence and a reviewer can read it with `git show`; a
 * no-change outcome has no such artefact, so one is written — beside the
 * attempt's logs, because that is where `ralph logs` sends whoever is asking.
 * The path is not stored in the database for the same reason the preserved
 * diff's is not: it is derived from the generation and the attempt, so it can
 * be found from a row that knows only those.
 *
 * **A record that could not be written does not stop a verified task.** The
 * evidence in the state store is the durable half, and losing a convenience
 * copy of it must not turn a passing task into a failing one — so a failed
 * write is noted on the log and the outcome stands. A contradicted outcome,
 * conversely, stops the pass whether or not the file was written.
 */
final class SatisfactionAuditor
{
    /**
     * Collect the case for an attempt that staged nothing.
     *
     * @param WorkspaceState $state the workspace as Git reported it now — the
     *        reading taken before the agent ran cannot say whether the agent
     *        or a check has committed something since
     * @param string|null $preserveTo where to keep the record, usually a path
     *        from {@see \Celyes\RalphPhp\Log\LogDirectory::satisfactionRecord()}
     * @param OutputSink|null $sink where the reading is noted as it is made;
     *        never closed here, since the caller that opened it owns it
     */
    public function audit(
        TaskWorktree $worktree,
        int $attempt,
        BaseCommit $base,
        WorkspaceState $state,
        StagedChanges $staged,
        VerificationReport $verification,
        UnverifiedText $claim,
        ?string $preserveTo = null,
        ?OutputSink $sink = null,
    ): Satisfaction {
        $satisfaction = Satisfaction::of(
            $worktree->generation(),
            $attempt,
            $worktree->path(),
            $base,
            $state,
            $staged,
            $verification,
            $claim,
        );

        $satisfaction = $preserveTo === null
            ? $satisfaction
            : $this->preserve($satisfaction, $preserveTo, $sink);

        $sink?->note($satisfaction->summary());

        foreach ($satisfaction->details() as $detail) {
            $sink?->note('  ' . $detail);
        }

        return $satisfaction;
    }

    /**
     * Write the record, or say why it is not there.
     *
     * As tolerant as {@see ChecksAuditor} is when it keeps a patch, and for
     * the same reason: this is a copy made for a reader, and a directory that
     * cannot be created is a fact about the filesystem rather than about the
     * task.
     */
    private function preserve(Satisfaction $satisfaction, string $path, ?OutputSink $sink): Satisfaction
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $sink?->note(sprintf('the no-change evidence could not be kept: %s cannot be created', $directory));

            return $satisfaction;
        }

        // Told where it is going before it is rendered, so the file can name
        // itself: the hints a refusal prints say where the evidence is, and a
        // copy written first would be the one document that could not.
        $kept = $satisfaction->keptAt($path);

        if (@file_put_contents($path, $kept->toMarkdown()) === false) {
            $sink?->note(sprintf('the no-change evidence could not be kept at %s', $path));

            return $satisfaction;
        }

        $sink?->note(sprintf('no-change evidence kept at %s', $path));

        return $kept;
    }
}
