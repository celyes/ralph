<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\LogSettings;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\ChecklistStore;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\Support\Secrets;

/**
 * Deletes a successful task's own log files when the project has asked for it,
 * and records that it did (PROJECT.md section K, item 11).
 *
 * {@see LogSettings} is the switch; this is the deletion it turns on. The item
 * asks for three things, and each is a rule here rather than a habit of the
 * caller:
 *
 *  - **Only that task's eligible log files.** Only files inside the
 *    generation's own log directory, only ones whose names
 *    {@see LogDirectory} composes — `attempt-NN-kind.log` — and only ordinary
 *    files. That is the attempt's agent output and the output of the checks
 *    that ran against it, which is what the setting was documented to mean. A
 *    name this package did not write is left alone whatever it is: a directory
 *    full of generated data is still somewhere a person may have put something,
 *    and a wipe that deleted by extension would be the one that found their
 *    note.
 *  - **State and completion metadata are retained.** Nothing here writes to a
 *    row except the one timestamp, and the files that are evidence rather than
 *    output — `attempt-NN-completed.md` — are not eligible and sit in the same
 *    directory untouched. The trade the setting offers is output for disk, not
 *    the account of what a task did.
 *  - **Safe to retry after an interruption.** Deleting a log is idempotent and
 *    a half-wiped directory is not ambiguous — every file left in it is still a
 *    log — so there is nothing here that needs a mark written before the
 *    act. The timestamp goes on the row only once
 *    nothing eligible is left, which makes the two readings exact: a row with
 *    one has nothing of its own to delete, and a row without one is looked at
 *    again. A wipe stopped half way therefore costs a retry, not a decision.
 *
 * **It wipes nothing a reader would want.** A pass is eligible only when it is
 * `completed` and owes no checklist write, which at the point the runner calls
 * this means its checks, its row and the write to the user's checklist are all
 * durable. Everything else — failed, blocked,
 * interrupted, still running — keeps every byte it wrote, which is section K,
 * item 12's promise and is enforced here rather than at the call site so that a
 * later caller cannot opt out of it. The `running` case is not only a matter of
 * principle: an attempt in progress owns an open handle on its log, and
 * deleting it would discard the explanation of whatever happens next.
 *
 * **A wipe never fails a task.** A file that will not go away is a reason on
 * the returned {@see LogWipe} and a line in the run's output. The one thing
 * raised is a state store that cannot record a deletion already carried out,
 * which is a real inconsistency: a wipe that happened and was not written down
 * is a `ralph logs` that reports missing files it cannot account for.
 */
final class LogWiper
{
    /**
     * What a file has to be called to be deleted.
     *
     * The shape {@see LogDirectory::logFor()} composes, pinned here rather than
     * reduced to "ends in `.log`": the directory holds files this package wrote
     * and may hold files it did not, and the only ones it is entitled to remove
     * are the ones it can name.
     */
    private const string ELIGIBLE = '/^attempt-\d+-[a-z0-9][a-z0-9-]*\.log$/';

    public function __construct(
        private readonly LogDirectory $logs,
        private readonly LogSettings $settings,
        private readonly GenerationStore $generations,
        private readonly ?ChecklistStore $checklists = null,
    ) {
    }

    /**
     * The wiper for a project, reading its `logs` section.
     */
    public static function forProject(
        Configuration $configuration,
        GenerationStore $generations,
        ?ChecklistStore $checklists = null,
        ?Secrets $secrets = null,
    ): self {
        return new self(
            LogDirectory::in($configuration->projectRoot(), $secrets),
            $configuration->logs(),
            $generations,
            $checklists,
        );
    }

    /**
     * Delete one pass's eligible logs, or say why they are being kept.
     *
     * @param GenerationRecord $record the row **as the store now holds it**:
     *        whether a pass's logs may go turns on the pass being completed,
     *        and a caller's older copy would be answering about a moment before
     *        that became true
     * @param OutputSink|null $sink where the outcome is noted, when there is
     *        one worth noting. Usually the terminal and not the attempt's log,
     *        for the obvious reason: the file a note about the deletion would
     *        go in is one of the files being deleted.
     *
     * @throws StateException when the deletion cannot be recorded
     */
    public function wipe(GenerationRecord $record, ?OutputSink $sink = null): LogWipe
    {
        $wipe = $this->carryOut($record);

        if ($wipe->removedAnything() || !$wipe->isComplete()) {
            $sink?->note($wipe->describe());
        }

        return $wipe;
    }

    /**
     * Decide, delete, then record — in that order and only in that order.
     *
     * @throws StateException when the deletion cannot be recorded
     */
    private function carryOut(GenerationRecord $record): LogWipe
    {
        $refusal = $this->refuse($record);

        if ($refusal !== null) {
            return LogWipe::kept($refusal);
        }

        // Already done, by an earlier run or by an earlier call in this one.
        // Complete rather than kept: there is nothing outstanding, which is
        // what stops every later look at this pass reading the same directory.
        if ($record->logsWereWiped()) {
            return LogWipe::wiped([]);
        }

        $wipe = $this->remove($this->logs->forGeneration($record->generation()));

        if ($wipe->isComplete()) {
            $this->generations->recordLogWipe($record);
        }

        return $wipe;
    }

    /**
     * Why this pass's logs are not a candidate, or null when they are.
     */
    private function refuse(GenerationRecord $record): ?LogRetentionReason
    {
        if ($this->settings->retainsEverything()) {
            return LogRetentionReason::Configured;
        }

        if ($record->state() !== LifecycleState::Completed) {
            return LogRetentionReason::Unsuccessful;
        }

        // A completed pass has its checklist write settled by construction,
        // since the row is not completed until the box is written. Asked rather
        // than assumed, for the reason every other condition at the end of a
        // pass is: the one irreversible thing this package does to its own
        // records should not depend on nobody ever reordering the code above
        // it. A caller with no checklist store to ask is taken at its word
        // about the state, which is the one fact the row itself carries.
        if ($this->checklists?->outstandingFor($record) !== null) {
            return LogRetentionReason::Unsettled;
        }

        return null;
    }

    /**
     * Delete what is eligible in a directory, and say what is left.
     *
     * Each deletion is confirmed by looking rather than by trusting the return
     * of `unlink()`, so a file removed from under a cached stat and a file that
     * never went are not reported the same way.
     */
    private function remove(string $directory): LogWipe
    {
        $deleted = [];
        $left = [];

        foreach ($this->eligible($directory) as $path) {
            @unlink($path);
            clearstatcache(true, $path);

            if (file_exists($path)) {
                $left[] = $path;

                continue;
            }

            $deleted[] = $path;
        }

        if ($left !== []) {
            return LogWipe::partial($deleted, $left);
        }

        return LogWipe::wiped($deleted, $this->removeIfEmpty($directory));
    }

    /**
     * The files in a generation's log directory this wiper may delete.
     *
     * Sorted, so that a part-way wipe that is retried starts where a reader of
     * the directory would expect it to, and so the reported order is the order
     * the attempts were made.
     *
     * Symbolic links are not eligible, whatever they point at. A link in here
     * is not something this package wrote, and following one would put the
     * deletion outside the runtime directory entirely.
     *
     * @return list<string> absolute paths
     */
    private function eligible(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $names = @scandir($directory);

        if ($names === false) {
            return [];
        }

        $eligible = [];

        foreach ($names as $name) {
            $path = $directory . '/' . $name;

            if (preg_match(self::ELIGIBLE, $name) !== 1 || is_link($path) || !is_file($path)) {
                continue;
            }

            $eligible[] = $path;
        }

        sort($eligible);

        return $eligible;
    }

    /**
     * Remove the generation's log directory when the wipe emptied it.
     *
     * `rmdir()` is asked rather than the directory being examined first,
     * because the question and the act are then one operation: a directory that
     * still holds the completion record, a preserved diff or anything a user
     * put there refuses, which is exactly the guarantee wanted, and no reading
     * of a listing can go stale in between.
     */
    private function removeIfEmpty(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $removed = @rmdir($directory);
        clearstatcache(true, $directory);

        return $removed && !is_dir($directory);
    }
}
