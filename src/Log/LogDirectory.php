<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\FilesystemException;
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
 * without either having to be found by elimination.
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
     * The kind suffix on a worker's own output.
     */
    public const string AGENT = 'agent';

    /**
     * Digits the attempt number is padded to.
     */
    private const int ATTEMPT_DIGITS = 2;

    public function __construct(private readonly string $directory)
    {
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
    public static function in(string $projectRoot): self
    {
        return new self(self::directoryIn($projectRoot));
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
            self::EXTENSION,
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
        return LogFile::appendTo($this->agentLog($generation, $attempt));
    }
}
