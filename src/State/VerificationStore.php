<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Support\Clock;
use Celyes\RalphPhp\Support\SystemClock;
use Celyes\RalphPhp\Verification\CheckRun;
use Celyes\RalphPhp\Verification\VerificationReport;

/**
 * The `verification_results` rows of one attempt.
 *
 * Written by the runner, because the runner is what decides a check's verdict
 * matters (PROJECT.md section 4: the loop owns verification and completion, the
 * agent owns implementation). A {@see \Celyes\RalphPhp\Verification\Verifier}
 * runs commands and reports; nothing it returns is state until it is written
 * here.
 *
 * **These rows are what an attempt's completion stands on.**
 * {@see AttemptStore::finish()} refuses a `completed` attempt that no passing
 * check supports, and it reads exactly this table to find out. So the write has
 * to happen before the attempt is finished, and it has to be the verifier's
 * actual findings: an attempt recorded as complete with no rows behind it is
 * the one lie this schema is shaped to make impossible.
 *
 * **The sequence is stored whole, in one transaction.** A half-written sequence
 * would read as "check 2 failed, check 3 passed", which is not a thing that can
 * happen, and a reader has no way to tell it from a sequence that really did.
 */
final class VerificationStore
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Write an attempt's whole verification sequence.
     *
     * Positions are 1-based and follow the report's order, which is the order
     * the checks were reached. An attempt that already has rows is refused
     * rather than added to: a second sequence for one attempt means the checks
     * ran twice, and which of the two decided the commit would be unanswerable.
     *
     * @return list<CheckRun> the runs as written, for a caller that wants to
     *                        report what it persisted
     *
     * @throws StateException when the attempt is gone, when it already has
     *                        results, or when the rows cannot be written
     */
    public function record(AttemptRecord $attempt, VerificationReport $report): array
    {
        if ($this->countFor($attempt) > 0) {
            throw new StateException(
                sprintf(
                    'Attempt %d already has verification results recorded.',
                    $attempt->number(),
                ),
                [
                    'The checks run once per attempt. A second run is a new attempt, '
                    . 'so that which sequence stood behind the commit stays answerable.',
                ],
            );
        }

        $runs = $report->runs();

        try {
            $this->database->transaction(function () use ($attempt, $runs): void {
                $position = 0;

                foreach ($runs as $run) {
                    ++$position;
                    $columns = ['attempt_id' => $attempt->rowId(), 'position' => $position]
                        + $run->toColumns();

                    $this->database->execute(
                        sprintf(
                            'INSERT INTO verification_results (%s) VALUES (%s)',
                            implode(', ', array_keys($columns)),
                            implode(', ', array_fill(0, count($columns), '?')),
                        ),
                        array_values($columns),
                    );
                }
            });
        } catch (StateException $failure) {
            throw $this->refused($attempt, $failure);
        }

        return $runs;
    }

    /**
     * An attempt's results, in the order the checks were reached.
     *
     * @return list<CheckRun>
     *
     * @throws StateException when the rows cannot be read
     */
    public function forAttempt(AttemptRecord $attempt): array
    {
        $rows = $this->database->query(
            'SELECT * FROM verification_results WHERE attempt_id = ? ORDER BY position',
            [$attempt->rowId()],
        );

        return array_map(static fn (array $row): CheckRun => CheckRun::fromRow($row), $rows);
    }

    /**
     * An attempt's results as the report the runner acted on, or null when the
     * checks never ran.
     *
     * What `ralph logs` and `ralph status` read: the verdict, not the rows.
     *
     * @throws StateException when the rows cannot be read
     */
    public function reportFor(AttemptRecord $attempt): ?VerificationReport
    {
        $runs = $this->forAttempt($attempt);

        return $runs === [] ? null : VerificationReport::of(...$runs);
    }

    /**
     * How many checks were recorded for an attempt.
     *
     * @throws StateException when the rows cannot be read
     */
    public function countFor(AttemptRecord $attempt): int
    {
        return (int) $this->database->value(
            'SELECT COUNT(*) FROM verification_results WHERE attempt_id = ?',
            [$attempt->rowId()],
        );
    }

    /**
     * Turn a refused write into a failure that says which attempt it was for.
     *
     * The foreign key is the one worth naming: an attempt row that has gone
     * means the database was rebuilt or the attempt belongs to another store,
     * and the checks have already been paid for by the time this is found out.
     */
    private function refused(AttemptRecord $attempt, StateException $failure): StateException
    {
        return new StateException(
            sprintf(
                'The verification results for attempt %d could not be recorded: %s',
                $attempt->number(),
                $failure->getMessage(),
            ),
            [
                ...$failure->hints(),
                'The attempt must exist in this same state database for its results to '
                . 'reference it.',
                sprintf('Recorded at %s.', Timestamp::now($this->clock)->describe()),
            ],
        );
    }
}
