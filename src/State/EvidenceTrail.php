<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

/**
 * What the next attempt is told the ones before it ran into, held to one bound.
 *
 * PROJECT.md section G, item 4 asks for *bounded* verification-failure evidence
 * to be fed into the next attempt, and the word doing the work is "bounded". A
 * failing PHPUnit run prints megabytes; the briefing an agent is handed has to
 * stay mostly about the task. The bound is therefore stated in one place rather
 * than left to whichever caller happens to assemble a prompt:
 *
 *  - at most {@see self::ATTEMPTS} attempts contribute, newest first;
 *  - at most {@see FailureEvidence::ENTRIES} observations survive in total;
 *  - each observation keeps {@see FailureEvidence::LABEL_CHARACTERS} of its
 *    label and {@see FailureEvidence::DETAIL_CHARACTERS} over
 *    {@see FailureEvidence::DETAIL_LINES} of its detail;
 *  - everything the bound leaves out is counted and announced, by this fold and
 *    by the bounds the stored evidence was already under, so a briefing never
 *    shows three of nine observations as though they were all of them.
 *
 * **Why more than one attempt.** The obvious implementation reads the newest
 * attempt's evidence and stops, and it loses the thing this item is about: an
 * attempt that errored, timed out or was cancelled records little or nothing
 * the runner observed, and the failing check from the attempt before it is
 * still the reason the task is not done. Reading past such an attempt is what
 * keeps a verification failure in front of the agent until something fixes it.
 *
 * **Why not every attempt.** The oldest failures are the least likely to still
 * be true — they describe a workspace two attempts of editing ago — and they
 * would crowd out the ones that are. Two is what the bound on observations can
 * actually hold.
 *
 * Attempts that recorded nothing are stepped over rather than counted, up to
 * {@see self::ROWS} rows, so an attempt with only a claim to its name does not
 * spend the trail's budget on having happened.
 */
final class EvidenceTrail
{
    /**
     * Attempts that may contribute observations.
     */
    public const int ATTEMPTS = 2;

    /**
     * Attempt rows read while looking for them.
     *
     * Larger than {@see self::ATTEMPTS} because an attempt that recorded no
     * observation is skipped, and a run can produce several of those — an agent
     * that blocked, an interruption — in a row.
     */
    public const int ROWS = 6;

    /**
     * The trail for the next attempt at a pass.
     *
     * @param list<AttemptRecord> $attempts the pass's recent attempts, newest
     *        first, as {@see AttemptStore::recallFor()} returns them
     * @param int $limit how many of them may contribute
     */
    public static function of(array $attempts, int $limit = self::ATTEMPTS): FailureEvidence
    {
        if ($limit < 1) {
            return FailureEvidence::none();
        }

        $contributing = [];

        foreach ($attempts as $attempt) {
            if (count($contributing) >= $limit) {
                break;
            }

            $evidence = $attempt->evidence();

            if ($evidence === null || $evidence->isEmpty()) {
                continue;
            }

            $contributing[] = [$attempt->number(), $evidence];
        }

        return self::fold($contributing);
    }

    /**
     * Fold the attempts' observations into one bounded set.
     *
     * Newest first, because the bound drops from the end and the most recent
     * failure is the one an attempt should act on. Observations are attributed
     * to the attempt that saw them only when more than one attempt is being
     * quoted: with a single source the briefing's own heading already says
     * which attempt it was, and a prefix would spend label characters saying it
     * twice.
     *
     * @param list<array{0: int, 1: FailureEvidence}> $contributing
     */
    private static function fold(array $contributing): FailureEvidence
    {
        if ($contributing === []) {
            return FailureEvidence::none();
        }

        $attribute = count($contributing) > 1;
        $trail = FailureEvidence::none();
        $omitted = 0;

        foreach ($contributing as [$number, $evidence]) {
            foreach ($evidence->entries() as $entry) {
                $trail = $trail->with(
                    $attribute ? sprintf('attempt %d: %s', $number, $entry['label']) : $entry['label'],
                    $entry['detail'],
                );
            }

            // What the bound on the stored evidence had already left out. Added
            // after its own entries rather than at the end, so the count is
            // complete even when this fold's bound stops the loop early.
            $omitted += $evidence->omitted();
        }

        return $trail->alsoOmitted($omitted);
    }
}
