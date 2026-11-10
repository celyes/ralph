<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;
use Celyes\RalphPhp\Support\Secrets;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use InvalidArgumentException;

/**
 * Where a task's logs live, and what they are called.
 *
 * One place decides the layout, because two places would mean `ralph logs
 * TASK-ID` looking in a directory the runner does not write to — a failure
 * whose symptom is an empty log rather than an error. The path is derived from
 * the project root and is not configurable, like the state file and the memory
 * directory: this is generated data inside the directory cleanup owns.
 *
 * **A directory per generation, not per task.** The name comes from
 * {@see TaskGeneration::slug()}, which is what a reopened task's second pass is
 * written down under everywhere else — its branch, its memory. A task reopened
 * after being completed gets `TASK-1a2b3c4d-2/`, so the earlier pass's logs are
 * still there to read, which is what section C, item 8 means by preserving its
 * history.
 *
 * **A file per attempt.** `attempt-02-agent.log` is the second attempt's worker
 * output. The attempt number leads with a zero so an `ls` of the directory
 * reads in order, and the kind is a suffix so that the verification logs of
 * section G, item 2 can sit beside the agent's as `attempt-02-check-tests.log`
 * without either having to be found by elimination. The same naming carries
 * what is not output at all: `attempt-02-staged.diff` is the patch an attempt's
 * commit would have contained, `attempt-02-satisfied.md` is why a task was
 * completed without one, and `attempt-02-completed.md` is what a ticked box
 * rests on. All of them are preserved where the attempt's own logs are, because
 * they are read together.
 *
 * **Known limit of the padding.** Past attempt 99 the names stop sorting as
 * bytes in the order they were made. The per-task attempt limit is in single
 * figures ({@see Defaults::ATTEMPTS_PER_TASK}), a run cannot reach three digits
 * without the user raising that limit deliberately, and padding wider would
 * move the edge rather than remove it. Names stay unique either way; only the
 * listing order suffers.
 */
final class LogDirectory
{
    /**
     * Suffix every log file has.
     *
     * `.log` rather than `.txt`, because an editor and a pager both already
     * know what to do with it, and because the runtime directory also holds
     * Markdown that is meant to be read as Markdown.
     */
    public const string EXTENSION = '.log';

    /**
     * Suffix on a preserved patch.
     *
     * Not `.log`, because the file is not output: it is a patch, and `.diff` is
     * what gets it syntax-highlighted in an editor and accepted by `git apply`.
     * A reviewer sent to look at a diff should not have to find out that the
     * `.log` beside the real logs is one.
     */
    public const string DIFF_EXTENSION = '.diff';

    /**
     * The kind suffix on a worker's own output.
     */
    public const string AGENT = 'agent';

    /**
     * The kind suffix on the diff an attempt's commit would have contained.
     */
    public const string STAGED = 'staged';

    /**
     * Suffix on a record meant to be read rather than paged through.
     *
     * The memory notes of section D are Markdown for the same reason: a file
     * whose headings and lists are there to be read by a person is not a log,
     * and calling it one would send a reviewer to `tail` instead of to their
     * editor.
     */
    public const string RECORD_EXTENSION = '.md';

    /**
     * The kind suffix on the evidence behind a no-change outcome.
     */
    public const string SATISFIED = 'satisfied';

    /**
     * The kind suffix on the evidence behind a ticked box.
     */
    public const string COMPLETED = 'completed';

    /**
     * Digits the attempt number is padded to.
     */
    private const int ATTEMPT_DIGITS = 2;

    /**
     * @param Secrets|null $secrets what the logs this opens keep out of their
     *        own notes — the worker's output is kept as it arrived
     *        ({@see LogFile})
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?Secrets $secrets = null,
    ) {
    }

    /**
     * Where a project's logs live.
     */
    public static function directoryIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . Defaults::LOGS_DIRECTORY);
    }

    /**
     * The logs of the project rooted at the given path.
     */
    public static function in(string $projectRoot, ?Secrets $secrets = null): self
    {
        return new self(self::directoryIn($projectRoot), $secrets);
    }

    /**
     * Absolute path to the logs directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The directory one pass at a task logs into.
     */
    public function forGeneration(TaskGeneration $generation): string
    {
        return $this->directory . '/' . $generation->slug();
    }

    /**
     * The path one attempt's worker output is written to.
     *
     * @param int $attempt 1-based attempt number within the generation
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     */
    public function agentLog(TaskGeneration $generation, int $attempt): string
    {
        return $this->logFor($generation, $attempt, self::AGENT);
    }

    /**
     * The path for one attempt's log of a given kind.
     *
     * @param string $kind what the log holds, as lowercase words joined by
     *        hyphens — a file name this package composes, so anything else is a
     *        bug in the caller rather than bad user input
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     *                                 or the kind is not a name a file can be
     *                                 called
     */
    public function logFor(TaskGeneration $generation, int $attempt, string $kind): string
    {
        return $this->fileFor($generation, $attempt, $kind, self::EXTENSION);
    }

    /**
     * The path one attempt's staged diff is preserved at.
     *
     * Beside that attempt's logs rather than in a directory of its own, because
     * a person reading why a task stopped reads the log and the patch together,
     * and the one thing a preserved diff must not be is somewhere other than
     * where `ralph logs` sends them. Named for the attempt for the same reason
     * the logs are: a second attempt's diff is a different diff.
     *
     * The patch is only written when there is something to review — see
     * {@see \Celyes\RalphPhp\Verification\ChecksAuditor} — so this is a path
     * that may well hold no file.
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     */
    public function stagedDiff(TaskGeneration $generation, int $attempt): string
    {
        return $this->fileFor($generation, $attempt, self::STAGED, self::DIFF_EXTENSION);
    }

    /**
     * The path one attempt's no-change evidence is written to.
     *
     * Beside the attempt's logs, like the preserved diff and for the same
     * reason: a task completed without a commit has no commit to read, so this
     * file is what answers "why is this ticked off", and the one place it must
     * not be is somewhere other than where `ralph logs` sends a reader.
     *
     * Written only when an attempt staged nothing — see
     * {@see \Celyes\RalphPhp\Verification\SatisfactionAuditor} — so this is
     * a path that will usually hold no file.
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     */
    public function satisfactionRecord(TaskGeneration $generation, int $attempt): string
    {
        return $this->fileFor($generation, $attempt, self::SATISFIED, self::RECORD_EXTENSION);
    }

    /**
     * The path one attempt's completion evidence is written to.
     *
     * Beside the no-change record and the preserved diff, because the three
     * answer the same question for the three ways a pass can end, and whoever
     * is asking why a box is ticked should find the answer where `ralph logs`
     * already sends them.
     *
     * Written when a pass reaches the checklist write — see
     * {@see \Celyes\RalphPhp\Run\CompletionRecorder} — so this is a path that
     * holds a file for a completed task and usually none for any other.
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     */
    public function completionRecord(TaskGeneration $generation, int $attempt): string
    {
        return $this->fileFor($generation, $attempt, self::COMPLETED, self::RECORD_EXTENSION);
    }

    /**
     * One attempt's file of a given kind.
     *
     * @param string $kind what the file holds, as lowercase words joined by
     *        hyphens — a file name this package composes, so anything else is a
     *        bug in the caller rather than bad user input
     *
     * @throws InvalidArgumentException when the attempt number is not positive
     *                                 or the kind is not a name a file can be
     *                                 called
     */
    private function fileFor(TaskGeneration $generation, int $attempt, string $kind, string $extension): string
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('An attempt number starts at 1.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $kind) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A log kind is lowercase words joined by hyphens; "%s" is not.',
                $kind,
            ));
        }

        return sprintf(
            '%s/attempt-%s-%s%s',
            $this->forGeneration($generation),
            str_pad((string) $attempt, self::ATTEMPT_DIGITS, '0', STR_PAD_LEFT),
            $kind,
            $extension,
        );
    }

    /**
     * Open one attempt's worker log for appending.
     *
     * The directory is created here, on the way to the first write, rather than
     * when the task is selected: a task whose attempt never started should not
     * leave an empty log directory behind for `ralph logs` to report on.
     *
     * @throws FilesystemException when the log cannot be opened
     * @throws InvalidArgumentException when the attempt number is not positive
     */
    public function openAgentLog(TaskGeneration $generation, int $attempt): LogFile
    {
        return LogFile::appendTo($this->agentLog($generation, $attempt), $this->secrets);
    }
}
