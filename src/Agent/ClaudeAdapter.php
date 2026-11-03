<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

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
 * **Why `--output-format text` is stated although it is the default.** The
 * reading below is of a plain transcript, so the format the agent's words
 * arrive in is something this adapter depends on rather than merely accepts.
 * Stating it puts the dependency in the command line and in the log, and
 * leaves a project the usual last word over it — with the usual consequence
 * that a project which asks for another format is asking for output
 * {@see ReportReader} will not find a report in.
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
 * construction rather than by two adapters agreeing. `--output-format
 * stream-json` is deliberately not asked for: it would wrap every one of the
 * agent's words in an envelope to unwrap, and the block is findable in the
 * plain output.
 *
 * That plain output has one limitation an operator should know about, and it is
 * the reason the preamble says where the report has to go. In print mode Claude
 * Code prints its *final message*, not a running account of the tool calls it
 * made along the way, so the attempt's log holds the result and whatever the
 * agent chose to say about it rather than a transcript of the session. A
 * report printed anywhere but the last message is a report nothing captured.
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
 * The invocation was tested against Claude Code 2.1.226. Refusing a CLI too old
 * for these flags is section E, item 9.
 */
final class ClaudeAdapter extends ProcessAdapter
{
    /**
     * The flag that runs a prompt without a terminal.
     */
    public const string NON_INTERACTIVE = '--print';

    /**
     * The output format the reading below expects: the agent's own words.
     */
    public const string OUTPUT_FORMAT = 'text';

    /**
     * The permission mode an unattended attempt runs under: everything
     * approved, nothing confined. See the class description.
     */
    public const string PERMISSIONS = 'bypassPermissions';

    public function name(): string
    {
        return BuiltInAdapters::CLAUDE;
    }

    /**
     * `claude --print --output-format text --permission-mode bypassPermissions [--model M] [extra…]`
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
            '- **Put the result block in your final message.** Run in this mode, only your last'
                . ' message is captured; a report printed before it is lost, and an attempt with no'
                . ' report is an execution error whatever its exit status.',
        ]);

        return $preamble . "\n\n" . $request->toMarkdown();
    }
}
