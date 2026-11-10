<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Run\Journal;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Fixture\FixtureWorker;
use RuntimeException;

/**
 * A real project the `ralph` binary can be run in, and read back afterwards.
 *
 * What the five commands of PROJECT.md section I, item 1 need in order to be
 * tested as commands rather than as classes: a Git repository with a commit in
 * it, a checklist, a configuration naming the fixture worker as its agent, and
 * a way to invoke the binary with that directory as its working directory —
 * because the directory the user is standing in is how every one of them finds
 * the project.
 *
 * Everything under the command is the real thing: real Git, the real state
 * store, the real verification commands (`php -v`, so a check that passes is a
 * process that really ran), and the fixture worker started as an executable
 * through the Codex adapter. No agent CLI, no credentials and no network
 * (section J, item 8).
 *
 * The run is a subprocess, which is the point. A command's working directory,
 * its exit status and the stream it printed a failure on are all behaviour the
 * item asks for, and none of the three exists when the application is driven
 * in-process.
 */
final class LoopProject
{
    /**
     * Seconds to wait for a background run to print what a test is waiting for.
     */
    private const int APPEARANCE_TIMEOUT_SECONDS = 30;

    /**
     * Seconds to wait for an interrupted run to finish stopping.
     */
    private const int STOP_TIMEOUT_SECONDS = 30;

    private function __construct(
        private readonly TemporaryProject $project,
        /**
         * Where the fixture worker lives: outside the repository, so its
         * program, plan and journal cannot turn up in a diff a run stages.
         */
        private readonly string $tools,
    ) {
    }

    /**
     * An initialised repository with one commit, and nowhere for a worker yet.
     */
    public static function create(): self
    {
        $project = new self(TemporaryProject::create(), TemporaryDirectory::create('ralph-tools'));

        Git::initialise($project->root());
        Git::setIdentity($project->root());
        Git::run($project->root(), 'config', 'core.autocrlf', 'false');

        $project->project->write('.gitignore', "/vendor/\n/.ralph/runtime/\n");
        $project->project->write('src/Import.php', "<?php\n");
        Git::run($project->root(), 'add', '--all');
        Git::commit($project->root());

        return $project;
    }

    /**
     * Install the agent this project's runs will use.
     *
     * @param FixtureStep ...$steps what it does on each successive invocation,
     *        the last one repeating
     */
    public function withWorker(FixtureStep ...$steps): self
    {
        FixtureWorker::install($this->tools, array_values($steps));

        return $this;
    }

    /**
     * Write the checklist and the context file, and commit them as the user's.
     *
     * @param list<string> $pending
     * @param list<string> $checked
     */
    public function withTasks(array $pending, array $checked = []): self
    {
        $lines = ['# Tasks', ''];

        foreach ($pending as $description) {
            $lines[] = '- [ ] ' . $description;
        }

        foreach ($checked as $description) {
            $lines[] = '- [x] ' . $description;
        }

        $this->project->write('.ralph/tasks.md', implode("\n", [...$lines, '']));
        $this->project->write('.ralph/CONTEXT.md', "# Conventions\n\nKeep it small.\n");

        return $this->committing();
    }

    /**
     * Write the checklist verbatim, and commit it as the user's own file.
     *
     * For the documents {@see withTasks()} cannot produce: prose around the
     * tasks, details indented under them, a worked example in a code fence,
     * Windows line endings, a missing final newline. What a run does to such a
     * file is only assertable if the test wrote the file byte for byte.
     */
    public function withChecklist(string $markdown): self
    {
        $this->project->write('.ralph/tasks.md', $markdown);
        $this->project->write('.ralph/CONTEXT.md', "# Conventions\n\nKeep it small.\n");

        return $this->committing();
    }

    /**
     * Rewrite the checklist between two runs, as the user would.
     *
     * Left uncommitted on purpose. The checklist is a managed path, so an
     * uncommitted edit to it is not the unrelated work a run refuses to start
     * over — and a run has already left its own tick in the file by this point,
     * which is the state the next run really meets.
     */
    public function editChecklist(string $markdown): self
    {
        $this->project->write('.ralph/tasks.md', $markdown);

        return $this;
    }

    /**
     * Write `.ralph/config.json`, and commit it as the user's own file.
     *
     * @param array<string, mixed> $overrides merged into the settings every
     *        case here runs under
     */
    public function withConfiguration(array $overrides = []): self
    {
        $this->project->writeConfigArray($this->settings($overrides));

        return $this->committing();
    }

    /**
     * Rewrite the configuration between two runs, as the user would.
     *
     * Left uncommitted, for the same reason {@see editChecklist()} is:
     * `.ralph/` is a managed path, so an uncommitted change to the settings is
     * not the unrelated work a run refuses to start over. It is how a case
     * states "the user changed a setting and ran again" — including changing
     * it to something the validator will not accept.
     *
     * @param array<string, mixed> $overrides merged into the settings every
     *        case here runs under
     */
    public function editConfiguration(array $overrides = []): self
    {
        $this->project->writeConfigArray($this->settings($overrides));

        return $this;
    }

    /**
     * Run the binary in this project.
     *
     * @param list<string> $arguments
     * @param array<string, string|false> $environment variables to add to, or
     *        with `false` remove from, the environment the run inherits — which
     *        is how a case gives a declared authentication variable a value
     */
    public function ralph(array $arguments, array $environment = []): CliResult
    {
        return Cli::run($arguments, $this->root(), $environment);
    }

    /**
     * Runtime files holding the given text, as paths relative to the project.
     *
     * For the one question PROJECT.md section I, item 6 asks about a finished
     * run: where a secret ended up. Every file the run wrote under
     * `.ralph/runtime/` is read, so the answer is the whole set rather than the
     * places a test thought to look.
     *
     * @return list<string> in a stable order
     */
    public function runtimeFilesContaining(string $text): array
    {
        $root = $this->path('.ralph/runtime');

        if (!is_dir($root)) {
            return [];
        }

        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if (is_string($contents) && str_contains($contents, $text)) {
                $found[] = str_replace(
                    [$this->root() . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $file->getPathname(),
                );
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Run the binary and stop it with SIGINT once it has printed something.
     *
     * The only honest way to produce the state `ralph resume` exists for: a run
     * that was really signalled, in a process that really installed the
     * handlers, leaving the rows and the workspace a real Ctrl+C leaves. The
     * signal is aimed at a line of the run's own output rather than at a delay,
     * so the case states the moment it is interrupting instead of hoping.
     *
     * @param list<string> $arguments
     * @param string $awaited output the run must have printed before the signal
     *        is sent
     *
     * @throws RuntimeException when the run never prints it, or does not stop
     */
    public function interrupt(array $arguments, string $awaited): CliResult
    {
        $command = [PHP_BINARY, Paths::inPackage('bin/ralph'), ...$arguments];
        $standardOutput = $this->project->path('.ralph/runtime/interrupted-run.out');
        $errorOutput = $this->project->path('.ralph/runtime/interrupted-run.err');

        $directory = dirname($standardOutput);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }

        // Files rather than pipes: nothing is reading these streams while the
        // run is going on, and a run that filled a pipe's buffer would block
        // for ever waiting for a reader that only arrives after it has stopped.
        $process = proc_open(
            $command,
            [
                0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'rb'],
                1 => ['file', $standardOutput, 'wb'],
                2 => ['file', $errorOutput, 'wb'],
            ],
            $pipes,
            $this->root(),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ' . implode(' ', $command));
        }

        $this->await($process, $standardOutput, $awaited);

        proc_terminate($process, SIGINT);

        $exitCode = $this->stop($process, $command);

        return new CliResult(
            $command,
            $exitCode,
            (string) @file_get_contents($standardOutput),
            (string) @file_get_contents($errorOutput),
        );
    }

    /**
     * The state store, as the commands read it.
     *
     * @throws RuntimeException when the project has no state store
     */
    public function journal(): Journal
    {
        $configuration = (new ConfigurationLoader())->load($this->root());

        if (!is_file(StateBootstrap::fileIn($this->root()))) {
            throw new RuntimeException('This project has no state store; nothing has run in it.');
        }

        return Journal::of((new StateBootstrap())->open($configuration));
    }

    public function root(): string
    {
        return $this->project->root();
    }

    public function path(string $relative): string
    {
        return $this->project->path($relative);
    }

    /**
     * The checklist as it stands on disk.
     */
    public function checklist(): string
    {
        return (string) file_get_contents($this->path('.ralph/tasks.md'));
    }

    /**
     * The run's event stream, one document per event, in order.
     *
     * Read off disk rather than through {@see \Celyes\RalphPhp\Event\EventStream}
     * so that a test asserts what another program would actually find at the
     * path it was told to follow.
     *
     * @return list<JsonDocument>
     */
    public function events(): array
    {
        $file = $this->path('.ralph/runtime/events.jsonl');

        return is_file($file) ? JsonDocument::parseLines((string) file_get_contents($file)) : [];
    }

    /**
     * The subject lines a branch adds to the checkout's history, newest first.
     *
     * The branch's own commits and not its ancestry: a task branch starts from
     * the base commit, so its whole log is the project's history plus whatever
     * the run put on it, and "committed once" is a statement about the latter.
     *
     * @return list<string>
     */
    public function commitsOn(string $branch): array
    {
        $log = Git::tryRun($this->root(), 'log', '--format=%s', 'HEAD..' . $branch);

        return $log->exitCode === 0 ? $log->standardOutputLines() : [];
    }

    /**
     * The commit a branch points at.
     */
    public function headOf(string $branch): string
    {
        return trim(Git::run($this->root(), 'rev-parse', $branch)->standardOutput);
    }

    /**
     * Branch names this project's runs created.
     *
     * @return list<string>
     */
    public function taskBranches(): array
    {
        return array_values(array_filter(
            Git::run($this->root(), 'branch', '--format=%(refname:short)')->standardOutputLines(),
            static fn (string $branch): bool => str_starts_with($branch, 'ralph/'),
        ));
    }

    public function remove(): void
    {
        Git::tryRun($this->root(), 'worktree', 'prune');
        TemporaryDirectory::remove($this->tools);
        $this->project->remove();
    }

    /**
     * The settings every case here runs under, with a case's own merged in.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function settings(array $overrides): array
    {
        return array_replace_recursive([
            'agent' => 'fixture',
            'agents' => [
                'fixture' => [
                    'adapter' => 'codex',
                    'executable' => $this->tools . DIRECTORY_SEPARATOR . FixtureWorker::NAME,
                ],
            ],
            'checks' => [['name' => 'tests', 'command' => ['php', '-v'], 'timeout' => 30]],
            // One attempt per task unless a case asks for more: a failing case
            // left at the default of three would quietly become three cases,
            // and how many attempts a case makes is something its assertions
            // depend on and should state.
            'limits' => ['attempts_per_task' => 1],
            // Nothing to install and nothing to copy: the worker needs no
            // dependencies of its own.
            'workspace' => ['prepare' => [], 'copy' => []],
        ], $overrides);
    }

    /**
     * Commit whatever was just written, as the user's own work.
     *
     * Unrelated uncommitted changes are something a run refuses to start over
     * (section F, item 1), so a project a test has set up is a committed one.
     */
    private function committing(): self
    {
        Git::run($this->root(), 'add', '--all');
        Git::commit($this->root(), 'Set up the project');

        return $this;
    }

    /**
     * Wait until the run has printed what the test is waiting for.
     *
     * @param resource $process
     *
     * @throws RuntimeException when it never does, or stops first
     */
    private function await(mixed $process, string $log, string $awaited): void
    {
        $deadline = microtime(true) + self::APPEARANCE_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if (str_contains((string) @file_get_contents($log), $awaited)) {
                return;
            }

            if (proc_get_status($process)['running'] === false) {
                throw new RuntimeException(sprintf(
                    "The run stopped before printing %s. It printed:\n%s",
                    var_export($awaited, true),
                    (string) @file_get_contents($log),
                ));
            }

            usleep(50_000);
        }

        proc_terminate($process, SIGKILL);
        proc_close($process);

        throw new RuntimeException(sprintf(
            "The run did not print %s within %d seconds. It printed:\n%s",
            var_export($awaited, true),
            self::APPEARANCE_TIMEOUT_SECONDS,
            (string) @file_get_contents($log),
        ));
    }

    /**
     * Wait for the signalled run to stop, and return its exit status.
     *
     * @param resource $process
     * @param list<string> $command
     *
     * @throws RuntimeException when it does not stop
     */
    private function stop(mixed $process, array $command): int
    {
        $deadline = microtime(true) + self::STOP_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if ($status['running'] === false) {
                // Read from the status rather than from proc_close(), which
                // reports -1 for a process it has already reaped.
                proc_close($process);

                return $status['exitcode'];
            }

            usleep(50_000);
        }

        proc_terminate($process, SIGKILL);
        proc_close($process);

        throw new RuntimeException(sprintf(
            '%s did not stop within %d seconds of SIGINT.',
            implode(' ', $command),
            self::STOP_TIMEOUT_SECONDS,
        ));
    }
}
