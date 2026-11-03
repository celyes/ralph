<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Process\ProcessResult;

/**
 * Codex, run once per attempt as `codex exec`.
 *
 * PROJECT.md section E, item 6. Everything that is the same for every
 * command-line agent — resolving the executable, the explicit working
 * directory, the argument array, the time budget, the streaming, the three
 * notes around the invocation, turning a timeout into a result — is
 * {@see ProcessAdapter}'s, so what is here is only what is true of this CLI:
 * which subcommand runs non-interactively, where its prompt goes, which flags
 * confine it to the task's workspace, and what its output is read for.
 *
 * **Why `exec`.** Plain `codex` is the interactive terminal UI: it draws a
 * screen, waits for a person, and asks for approval before it runs anything.
 * `codex exec` is the headless form of the same program — it takes a prompt,
 * works, prints its transcript, and exits with a status. The loop has nobody to
 * approve anything (standard input is a file that ends, by
 * {@see \Celyes\RalphPhp\Process\ProcessRunner}'s design), so an adapter that
 * started the interactive command would hang until the task timeout killed it
 * and report a timeout for a setup mistake.
 *
 * **Why the briefing goes on standard input.** `codex exec -` reads the prompt
 * from standard input, and the briefing is the task, its criteria, the project
 * context, the notes from earlier work and the previous attempt's captured
 * output: tens of kilobytes of prose a user wrote, routinely containing
 * backticks, `$`, quotes and newlines. As an argument it would be one argument
 * — {@see AgentInvocation} has no shell form, so even there nothing would parse
 * it — but argument lists have a size limit that a stream does not, and the
 * limit is the kind that is reached by the one task whose context file is
 * large. The `-` is what makes the stream the prompt rather than ignored input.
 *
 * **Why `--cd` is passed when the process already starts in the workspace.**
 * Codex decides what it may write from its own notion of a working root, and
 * `--cd` is how that root is stated rather than inferred. The two agree — the
 * process is started in {@see ExecutionRequest::directory()} either way — and
 * stating it is what makes the sandbox below mean the task's worktree and not
 * whatever directory a future change leaves the process in.
 *
 * **Why `--sandbox workspace-write`.** The task's changes have to land in the
 * workspace, and only there: the runner stages that worktree's diff after
 * verification (section F, item 6), so an edit made anywhere else is work that
 * is never committed and never reviewed, in a checkout the package has promised
 * not to touch. Under this mode Codex may read the machine, write inside its
 * working root, and run commands without asking; it may not write outside it,
 * and it has no network access unless the project turns it back on. That last
 * restriction is deliberate and worth knowing: an attempt that needs to install
 * a dependency will fail, while the verification commands of section G run
 * outside the sandbox and are unaffected.
 *
 * **Anything the project wants said differently, it says last.** The configured
 * model and extra argument array are appended after these flags and before the
 * prompt, so a project that needs a different sandbox mode, network access or
 * any other Codex setting puts it in `agents.*.arguments` and that occurrence
 * is the one the CLI keeps. Nothing here is a setting in disguise: it is the
 * minimum that makes a non-interactive attempt possible, overridable by the
 * configuration like every other invocation detail (section E, item 8).
 *
 * **What its output is read for.** Codex prints its own transcript, and the one
 * thing the loop acts on is the {@see ResultProtocol} block the briefing asks
 * for, read by {@see ProcessAdapter::reported()} — the same reading the other
 * built-in adapter uses, which is section E, item 10 holding by construction
 * rather than by two adapters agreeing. `--json` is deliberately not passed:
 * its event stream is experimental, it would wrap the agent's message in
 * another envelope to unwrap, and the block is findable in the plain transcript
 * on either stream.
 *
 * **Cancellation.** The timeout is {@see ExecutionRequest::seconds()} and the
 * enforcement is {@see ProcessAdapter}'s: the process is asked to stop, killed
 * if it will not, and the attempt is recorded as a timeout with whatever was
 * printed first. `codex exec` exits on being asked, and nothing of it needs
 * saving — a session it abandoned is not state the next attempt reads, because
 * each attempt is given its briefing afresh. Stopping the child processes an
 * agent itself spawned is section H, item 4, where interruption is handled for
 * every command the package runs.
 *
 * **Which Codex this is written for.** The invocation targets the `exec`
 * subcommand as it stands in the 0.4x releases, and {@see self::MINIMUM_VERSION}
 * states that floor so an installation older than it is refused before a run
 * starts rather than discovered from a usage message in an attempt's log
 * (section E, item 9).
 */
final class CodexAdapter extends ProcessAdapter implements VersionedAdapter
{
    /**
     * The non-interactive subcommand.
     */
    public const string COMMAND = 'exec';

    /**
     * The prompt placeholder that makes Codex read it from standard input.
     */
    public const string PROMPT_FROM_INPUT = '-';

    /**
     * The sandbox the attempt runs under: readable machine, writable workspace.
     */
    public const string SANDBOX = 'workspace-write';

    /**
     * The CLI's name in a report a person reads.
     */
    public const string CLI = 'Codex CLI';

    /**
     * The oldest Codex release this invocation is written for.
     *
     * The floor of the 0.4x series the class description names, rather than
     * the exact version it was exercised at: an operator running something
     * newer must never be refused for being ahead, and a floor is the only
     * form of that statement which stays true as the CLI moves. It is a floor
     * with content — `exec` taking its prompt from standard input, `--cd` and
     * `--sandbox workspace-write` are this series' spellings — so a 0.3x
     * installation is refused rather than left to fail per attempt.
     */
    public const string MINIMUM_VERSION = '0.40.0';

    public function name(): string
    {
        return BuiltInAdapters::CODEX;
    }

    /**
     * `codex --version`, measured against {@see self::MINIMUM_VERSION}.
     *
     * The bare executable rather than `exec --version`: the subcommand is
     * exactly what an installation too old to be supported does not have, so
     * asking through it would answer with a usage message instead of a
     * version.
     */
    public function versionRequirement(): VersionRequirement
    {
        return VersionRequirement::of(self::CLI, self::MINIMUM_VERSION);
    }

    /**
     * `codex exec --cd WORKSPACE --sandbox workspace-write [--model M] [extra…] -`
     *
     * In that order for the reasons the class description gives: the flags this
     * adapter needs first, the project's own after them so they win, and the
     * prompt placeholder last because it is the subcommand's positional
     * argument.
     */
    protected function invocationFor(ExecutionRequest $request): AgentInvocation
    {
        $definition = $request->agent()->definition();

        $invocation = AgentInvocation::forAgent($request->agent(), [
            self::COMMAND,
            '--cd',
            $request->directory(),
            '--sandbox',
            self::SANDBOX,
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
            ->with(self::PROMPT_FROM_INPUT)
            ->withInput($this->prompt($request));
    }

    /**
     * Read the transcript for the report the briefing asked for.
     *
     * Nothing Codex-specific is interpreted in front of it. Its exit status
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
     * sandbox the adapter did not ask for. What the task is, what finishing it
     * means and how to report it belong to the common briefing
     * ({@see ExecutionRequest::toMarkdown()}) and are deliberately not restated
     * here: two adapters wording the shared half their own way is how two
     * agents end up answering two slightly different questions.
     *
     * Each item is something an attempt would otherwise have to discover by
     * failing — that no question will be answered, that a write outside the
     * workspace is refused, that the network is off — and the last attempt at a
     * task is a bad time to discover any of them.
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
                self::COMMAND,
            ),
            '',
            sprintf(
                '- **Sandbox:** `%s`, rooted at `%s`. You may run commands and edit files inside it'
                    . ' without approval. Writes outside it are refused, and there is no network'
                    . ' access unless this project enabled it.',
                self::SANDBOX,
                $request->directory(),
            ),
            '- **Nothing is waiting on you to ask:** if the task genuinely needs a decision a person'
                . ' has to make, report it as described below rather than guessing or stopping'
                . ' silently.',
        ]);

        return $preamble . "\n\n" . $request->toMarkdown();
    }
}
