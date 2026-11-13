<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Log\StreamFilter;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * Claude Code, run once per attempt as `claude --print`.
 *
 * PROJECT.md section E, item 7. Everything that is the same for every
 * command-line agent — resolving the executable, the explicit working
 * directory, the argument array, the time budget, the streaming, the notes
 * around the invocation, turning a timeout into a result — is
 * {@see ProcessAdapter}'s, so what is here is only what is true of this CLI:
 * which flag runs it without a terminal, where its prompt goes, what it is
 * allowed to do, and what its output is read for.
 *
 * It is deliberately the same shape as {@see CodexAdapter} and deliberately
 * not the same flags. Two CLIs that both "run a prompt headlessly" differ in
 * every detail of how that is said, and an adapter that assumed otherwise is
 * what section 4 warns against.
 *
 * **Why `--print`.** Plain `claude` starts the interactive session: it draws a
 * terminal UI and waits for a person. `--print` is the non-interactive form —
 * it takes a prompt, works, prints its answer, and exits with a status. The
 * loop has nobody to answer anything (standard input is a file that ends, by
 * {@see \Celyes\RalphPhp\Process\ProcessRunner}'s design), so an adapter that
 * started the interactive command would hang until the task timeout killed it
 * and report a timeout for a setup mistake.
 *
 * **Why the briefing goes on standard input.** `claude --print` takes its
 * prompt as a positional argument *or* from standard input when no positional
 * is given, and the briefing is the task, its criteria, the project context,
 * the notes from earlier work and the previous attempt's captured output: tens
 * of kilobytes of prose a user wrote, routinely containing backticks, `$`,
 * quotes and newlines. As an argument it would be one argument —
 * {@see AgentInvocation} has no shell form, so even there nothing would parse
 * it — but argument lists have a size limit that a stream does not, and the
 * limit is the kind that is reached by the one task whose context file is
 * large. So no positional is passed at all, which is what makes the stream the
 * prompt rather than ignored input.
 *
 * **Why there is no `--cd`.** Claude Code has no such flag: its project root
 * *is* the working directory it was started in, which {@see ProcessAdapter}
 * guarantees is {@see ExecutionRequest::directory()} and which a subclass
 * cannot change. Where Codex needs its root stated, this adapter needs nothing
 * said — and `--add-dir` is pointedly not passed, because every directory it
 * added would be one more place an attempt's changes could land and never be
 * committed.
 *
 * **Why `--permission-mode bypassPermissions`, and what it does not do.** In
 * print mode there is nobody to approve a tool call, so a call that needs
 * approval is denied: under the default mode, or under `acceptEdits`, the
 * attempt could edit files but could not run the project's test suite, and an
 * agent that cannot run anything cannot check its own work before claiming a
 * candidate. This mode approves everything.
 *
 * What it does *not* do is confine the attempt. Claude Code has no equivalent
 * of Codex's `workspace-write` sandbox, so unlike {@see CodexAdapter} this
 * adapter cannot make a write outside the workspace *fail*: it can only make
 * sure the agent knows that such a write is not part of the task, is never
 * staged (section F, item 6), and is left behind in a checkout the package has
 * promised not to touch. The preamble says so for that reason, and the
 * asymmetry is worth knowing rather than discovering: a project that wants
 * confinement runs the loop inside its own container, and a project that would
 * rather give up running commands puts `--permission-mode acceptEdits` in
 * `agents.claude.arguments`, where its occurrence is the one the CLI keeps.
 *
 * **Why `--output-format stream-json`, with `--verbose` and
 * `--include-partial-messages`.** So that the session can be watched while it
 * happens (PROJECT.md section K). `--print --output-format text` prints the
 * final message and nothing before it: an attempt is routinely several minutes
 * of silence, and a terminal that shows nothing until the end cannot be told
 * apart from one whose agent has hung. The events are the only form in which
 * this CLI says what it is doing while it does it — which tool it is running,
 * what it is writing, that the API is making it wait — so `--verbose`, which
 * this format requires in print mode, and `--include-partial-messages`, which
 * is what makes the message arrive as it is written rather than a message
 * behind, are part of the same decision rather than extras.
 *
 * The cost is that the output is no longer prose, and it is paid in one place:
 * {@see ClaudeStream} renders the events for the terminal and the log as they
 * arrive, and reduces the captured session back to the agent's own words for
 * everything that reads an attempt afterwards. A project still has the last
 * word — `--output-format text` in `agents.claude.arguments` is the CLI's
 * final occurrence and wins — and that case stays readable, because output
 * with no events in it is passed through untouched.
 *
 * **Anything the project wants said differently, it says last.** The
 * configured model and extra argument array are appended after these flags, so
 * a repeated option's last occurrence — the project's — is the one the CLI
 * keeps. Nothing here is a setting in disguise: it is the minimum that makes a
 * non-interactive attempt possible, overridable by the configuration like every
 * other invocation detail (section E, item 8).
 *
 * **What its output is read for.** The {@see ResultProtocol} block the briefing
 * asks for, read by {@see ProcessAdapter::reported()} — the same reading the
 * other built-in adapter uses, which is section E, item 10 holding by
 * construction rather than by two adapters agreeing. What that reading is given
 * is the session's final message, which the stream carries as its own field:
 * the same text `--output-format text` would have printed, so changing the
 * format changed what a person sees and nothing about what the loop acts on.
 *
 * The limitation an operator should know about is the reason the preamble says
 * where the report has to go. The log now holds the whole session — every tool
 * call, every message — but the report is read from the last message only. A
 * report printed in the middle of a session is visible in the log and is not
 * what the attempt is judged by.
 *
 * **Cancellation.** The timeout is {@see ExecutionRequest::seconds()} and the
 * enforcement is {@see ProcessAdapter}'s: the process is asked to stop, killed
 * if it will not, and the attempt is recorded as a timeout with whatever was
 * printed first. Nothing of an abandoned session needs saving, because each
 * attempt is given its briefing afresh and this adapter never resumes one —
 * `--continue` and `--resume` are not passed, so a stopped session is not state
 * the next attempt reads. Stopping the child processes an agent itself spawned
 * is section H, item 4, where interruption is handled for every command the
 * package runs.
 *
 * **Which Claude Code this is written for.** The invocation was exercised
 * against 2.1.226, and {@see self::MINIMUM_VERSION} states the floor of the
 * series it belongs to, so an installation too old for these flags is refused
 * before a run starts rather than discovered from a usage message in an
 * attempt's log (section E, item 9).
 */
final class ClaudeAdapter extends ProcessAdapter implements VersionedAdapter
{
    /**
     * The flag that runs a prompt without a terminal.
     */
    public const string NON_INTERACTIVE = '--print';

    /**
     * The output format the session is streamed in: one JSON event per line,
     * read by {@see ClaudeStream}. See the class description.
     */
    public const string OUTPUT_FORMAT = 'stream-json';

    /**
     * What this CLI requires alongside {@see self::OUTPUT_FORMAT} in print
     * mode. Without it the session refuses to start, which would be a usage
     * message once per attempt rather than an attempt.
     */
    public const string VERBOSE = '--verbose';

    /**
     * The flag that makes a message arrive as it is written instead of when it
     * is finished — the difference between a stream and a feed of finished
     * messages.
     */
    public const string PARTIAL_MESSAGES = '--include-partial-messages';

    /**
     * The permission mode an unattended attempt runs under: everything
     * approved, nothing confined. See the class description.
     */
    public const string PERMISSIONS = 'bypassPermissions';

    /**
     * The CLI's name in a report a person reads.
     */
    public const string CLI = 'Claude Code';

    /**
     * The oldest Claude Code release this invocation is written for.
     *
     * The floor of the 2.x line rather than the 2.1.226 the class description
     * records as tested: a floor is what can be required without refusing an
     * operator for running something newer, and what stays true as the CLI
     * moves. The 1.x line is where it would bite — `--permission-mode` and the
     * `bypassPermissions` value this adapter depends on are not how that
     * generation spelled an unattended session, and an attempt under it is a
     * usage message repeated once per retry.
     */
    public const string MINIMUM_VERSION = '2.0.0';

    public function name(): string
    {
        return BuiltInAdapters::CLAUDE;
    }

    /**
     * `claude --version`, measured against {@see self::MINIMUM_VERSION}.
     *
     * It answers with a bare `2.1.226 (Claude Code)` and exits, without a
     * session, a model or a credential — which is what makes it something
     * preflight may run on a machine that is only being checked.
     */
    public function versionRequirement(): VersionRequirement
    {
        return VersionRequirement::of(self::CLI, self::MINIMUM_VERSION);
    }

    /**
     * `claude --print --output-format stream-json --verbose --include-partial-messages
     * --permission-mode bypassPermissions [--model M] [extra…]`
     *
     * In that order for the reasons the class description gives: the flags this
     * adapter needs first, the project's own after them so they win, and no
     * positional argument at all, because the absence of one is what sends
     * Claude Code to standard input for its prompt.
     */
    protected function invocationFor(ExecutionRequest $request): AgentInvocation
    {
        $definition = $request->agent()->definition();

        $invocation = AgentInvocation::forAgent($request->agent(), [
            self::NON_INTERACTIVE,
            '--output-format',
            self::OUTPUT_FORMAT,
            // Required by this CLI alongside that format, and what makes the
            // session arrive as it happens rather than as a final message.
            self::VERBOSE,
            self::PARTIAL_MESSAGES,
            '--permission-mode',
            self::PERMISSIONS,
        ]);

        $model = $definition->model();

        // Placed here rather than by the configuration layer because where a
        // model is named is something only this CLI knows: section E, item 8's
        // per-agent model, in the one place that can spell it.
        if ($model !== null && trim($model) !== '') {
            $invocation = $invocation->with('--model', $model);
        }

        return $invocation
            ->with(...$definition->arguments())
            ->withInput($this->prompt($request));
    }

    /**
     * Render the session for whoever is watching it.
     *
     * The live half of section K: the events are turned into a readable account
     * on their way to the terminal and the attempt's log, so a user can see
     * which tool the agent is running and what it is saying while it does.
     * {@see ClaudeStream} is the whole of the format's reading.
     */
    protected function observe(OutputSink $output): ?StreamFilter
    {
        return ClaudeStream::rendering($output);
    }

    /**
     * Hand the reading of record the agent's words rather than the envelopes.
     *
     * The other half, and the reason the format change costs nothing
     * downstream: what {@see ProcessAdapter} interprets, quotes as evidence and
     * puts in a diagnostic is the session's final message — the same text this
     * CLI would have printed under `--output-format text`.
     */
    protected function decode(ProcessResult $result): ProcessResult
    {
        return $result->withStandardOutput(ClaudeStream::transcript($result->standardOutput()));
    }

    /**
     * Read the output for the report the briefing asked for.
     *
     * Nothing Claude-specific is interpreted in front of it. Its exit status
     * says a session ended, not whether a task was done — which section 4 is
     * explicit about for every agent — and the cases a status could be read as
     * are the ones {@see ProcessAdapter::reported()} already weighs the
     * declaration against.
     */
    protected function interpret(ExecutionRequest $request, ProcessResult $result): ExecutionResult
    {
        return $this->reported($request, $result);
    }

    /**
     * The briefing, with what is true of being run this way in front of it.
     *
     * The preamble holds only facts about this invocation, and they are
     * composed from the same constants that build it, so it cannot describe a
     * permission mode the adapter did not ask for. What the task is, what
     * finishing it means and how to report it belong to the common briefing
     * ({@see ExecutionRequest::toMarkdown()}) and are deliberately not restated
     * here: two adapters wording the shared half their own way is how two
     * agents end up answering two slightly different questions.
     *
     * Each item is something an attempt would otherwise have to discover by
     * failing — that no question will be answered, that a write outside the
     * workspace is quietly thrown away rather than refused, that a report in
     * any but the last message is never read — and the last attempt at a task
     * is a bad time to discover any of them.
     */
    private function prompt(ExecutionRequest $request): string
    {
        $preamble = implode("\n", [
            '# How you are being run',
            '',
            sprintf(
                'Ralph PHP started you as `%s %s` with this briefing on standard input. Nobody is'
                    . ' watching the session: a question asked here is never answered, and standard'
                    . ' input ends after this text.',
                $request->agent()->definition()->executable(),
                self::NON_INTERACTIVE,
            ),
            '',
            sprintf(
                '- **Work only in `%s`.** It is your working directory and the whole of this task\'s'
                    . ' workspace. You are running under `--permission-mode %s`, so every tool runs'
                    . ' without approval and nothing will stop you writing outside it — but Ralph PHP'
                    . ' only ever commits this directory, so a change made anywhere else is silently'
                    . ' dropped and left behind in a checkout it has promised not to touch.',
                $request->directory(),
                self::PERMISSIONS,
            ),
            '- **Nothing is waiting on you to ask:** if the task genuinely needs a decision a person'
                . ' has to make, report it as described below rather than guessing or stopping'
                . ' silently.',
            '- **Put the result block in your final message.** The whole session is logged, but the'
                . ' report is read from your last message only; one printed earlier is not what the'
                . ' attempt is judged by, and an attempt with no report in its final message is an'
                . ' execution error whatever its exit status.',
        ]);

        return $preamble . "\n\n" . $request->toMarkdown();
    }
}
