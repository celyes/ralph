<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\Log\OutputSink;

/**
 * Keeps a completion's evidence where whoever asks about a ticked box will
 * find it (PROJECT.md section G, item 7).
 *
 * The evidence itself is {@see CompletionEvidence}; this writes it down. Called
 * once per completed pass, after the box has been ticked, so the record can
 * state the write rather than predict it.
 *
 * **Why a file as well as the rows.** The state store holds every one of these
 * facts and holds them durably — that is what makes it the authority. What it
 * does not hold is the five of them as one answer to "why is this ticked off",
 * and a reviewer with a ticked checklist in front of them should not have to
 * join four tables to get it. The same reasoning put the no-change record
 * beside the attempt's logs, and this sits next to it for the same reason: it
 * is where `ralph logs` sends a reader. The path is derived from the generation
 * and the attempt and is not stored, so a row that knows only those can find
 * it.
 *
 * **A record that cannot be written does not un-complete a task.** The checks
 * passed, the commit exists and the box is ticked before this runs; a
 * filesystem that refuses the copy is a fact about the filesystem. So a failed
 * write is noted on the log and the completion stands — the same tolerance
 * {@see \Celyes\RalphPhp\Verification\SatisfactionAuditor} has, and for the
 * same reason.
 *
 * **A refused completion is recorded too.** A pass that reached the end and
 * found a condition unmet is the case most worth having written down, and the
 * caller decides whether to ask for it.
 */
final class CompletionRecorder
{
    /**
     * Write a completion's evidence, and say where it went.
     *
     * @param string|null $preserveTo where to keep it, usually a path from
     *        {@see \Celyes\RalphPhp\Log\LogDirectory::completionRecord()}; null
     *        keeps nothing and is what a caller with nowhere to write passes
     * @param OutputSink|null $sink where the record is noted as it is written;
     *        never closed here, since the caller that opened it owns it
     *
     * @return CompletionEvidence the evidence, noting the path when the write
     *         landed and unchanged when it did not
     */
    public function record(
        CompletionEvidence $evidence,
        ?string $preserveTo = null,
        ?OutputSink $sink = null,
    ): CompletionEvidence {
        $sink?->note($evidence->describe());

        foreach ($evidence->details() as $detail) {
            $sink?->note('  ' . $detail);
        }

        if ($preserveTo === null) {
            return $evidence;
        }

        return $this->preserve($evidence, $preserveTo, $sink);
    }

    /**
     * Write the record, or say why it is not there.
     */
    private function preserve(CompletionEvidence $evidence, string $path, ?OutputSink $sink): CompletionEvidence
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $sink?->note(sprintf('the completion evidence could not be kept: %s cannot be created', $directory));

            return $evidence;
        }

        // Told where it is going before it is rendered, so the document can
        // name itself if it ever needs to — the same order the no-change record
        // is written in.
        $kept = $evidence->keptAt($path);

        if (@file_put_contents($path, $kept->toMarkdown()) === false) {
            $sink?->note(sprintf('the completion evidence could not be kept at %s', $path));

            return $evidence;
        }

        $sink?->note(sprintf('completion evidence kept at %s', $path));

        return $kept;
    }
}
