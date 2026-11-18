<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * Why a pass's logs are still on disk.
 *
 * {@see LogWiper} deletes a successful task's own output when the project asks
 * it to (PROJECT.md section K, item 11), and every other outcome is a refusal
 * with one of these behind it. They are kept as an enum rather than as free
 * text for the reason the Git refusals are
 * (the workspace retention this package used to have): the next item makes
 * `ralph logs TASK-ID` explain an absent file, and the explanation a reader
 * gets should be the same sentence the run wrote, not a second phrasing of it.
 *
 * The order is the order they are decided in, and it matters: a project that
 * keeps every log is never asked whether a pass succeeded, and a pass that did
 * not succeed is never read off disk at all.
 */
enum LogRetentionReason: string
{
    /**
     * The project keeps every log, which is the default.
     *
     * Not a failure and not an exception: `logs.wipe_on_success` is off, so
     * nothing about this pass was ever a candidate for deletion.
     */
    case Configured = 'configured';

    /**
     * The pass did not succeed.
     *
     * A failed, blocked or interrupted pass keeps everything it wrote whatever
     * the setting says — those are the logs somebody is going to read, and
     * section K, item 12 is where that promise is reported on. A pass still
     * being worked on is here too: an attempt that is running owns an open file
     * handle, and deleting a log out from under the process writing it would
     * lose the explanation of whatever happens next.
     */
    case Unsuccessful = 'unsuccessful';

    /**
     * The completion is not durable yet.
     *
     * The row says completed and a planned checklist write is still
     * outstanding, so the pass is one a resume still has work to do on. Wiping
     * here would be wiping the logs of a task that is not finished, which is
     * the one condition the item states as a precondition rather than as a
     * preference.
     */
    case Unsettled = 'unsettled';

    /**
     * A log file could not be deleted.
     *
     * The wipe ran, removed what it could, and something was left: a permission
     * the user changed, a file another process holds, a directory where a log
     * should be. Nothing is recorded as wiped, so the next pass over this
     * generation tries again — which is what makes a half-finished deletion a
     * retry rather than a loss.
     */
    case Undeletable = 'undeletable';

    /**
     * The sentence this reason contributes to a log line.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Configured => 'the project keeps every log',
            self::Unsuccessful => 'the pass did not succeed, and its logs are the ones worth reading',
            self::Unsettled => 'the completion is not durable yet',
            self::Undeletable => 'a log file could not be deleted',
        };
    }

    /**
     * A short label for a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Configured => 'logs kept',
            self::Unsuccessful => 'logs preserved',
            self::Unsettled => 'logs kept for now',
            self::Undeletable => 'logs partly wiped',
        };
    }
}
