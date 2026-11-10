<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Support\Secrets;

/**
 * Whatever runs a task's configured checks against its workspace.
 *
 * The seam between the loop and the checks. The runner decides *when* a
 * candidate implementation is verified, what the verdict means, and what is
 * persisted; an implementation of this decides only how the configured commands
 * are executed and what each one did. The command runner of PROJECT.md
 * section G, item 2 is the real one; a test of the loop supplies its own, and
 * that is the reason this is an interface rather than a class the runner
 * constructs for itself.
 *
 * **The checks are passed in, not read from configuration.** The run froze them
 * at its start precisely so that nothing an agent edits mid-run can replace
 * them (PROJECT.md section G, item 3), and an implementation that went back to
 * `.ralph/config.json` for them would quietly undo that.
 *
 * **The excerpt it keeps is a diagnostic, so it is redacted.** A check's
 * captured output is stored, shown by `ralph logs` and fed into the next
 * attempt's briefing, which makes it one of the package-generated diagnostics
 * PROJECT.md section I, item 6 covers. The run's known secrets are passed in
 * rather than resolved here, for the same reason the checks are: what a run is
 * held to was settled when it started.
 *
 * **It must not decide the task's fate.** A failing check is a returned report,
 * not an exception: the runner leaves the task unchecked, writes the evidence
 * into the next attempt, and carries on. An exception from here means the
 * checks could not be run at all — an executable that is gone, a workspace that
 * cannot be entered — which is a different thing from work that does not pass.
 */
interface Verifier
{
    /**
     * Run the checks, in order, and report what each one did.
     *
     * Stops at the first failure: the remaining checks are reported as not run
     * rather than skipped silently. The returned report always accounts for
     * every check it was given.
     *
     * @param list<VerificationCommand> $checks    the run's frozen checks, in
     *        configured order
     * @param string                    $directory the task workspace the checks
     *        run in — never the user's own checkout
     * @param OutputSink|null           $sink      where each check's output goes
     *        while it runs. Never closed here: the caller that opened the
     *        attempt's log owns it.
     * @param Secrets|null              $secrets   the run's known secrets, kept
     *        out of the excerpt each check is recorded with. What was streamed
     *        to the sink while the check ran is a verbatim copy and is not
     *        touched ({@see \Celyes\RalphPhp\Log\LogFile}).
     *
     * @throws RalphException when the checks could not be run, which is a
     *         setup failure rather than a verdict on the work
     */
    public function verify(
        array $checks,
        string $directory,
        ?OutputSink $sink = null,
        ?Secrets $secrets = null,
    ): VerificationReport;
}
