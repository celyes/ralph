<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

use Celyes\RalphPhp\Exception\ConfigurationException;

/**
 * Checks that a loaded configuration's values can actually be used, before a
 * run selects a task or touches the repository.
 *
 * The loader checks *shape*: a setting has the type its value object needs.
 * That still admits settings that are well-formed and unusable — a zero
 * iteration limit, an empty command array, a checklist outside the repository.
 * Those are rejected here, up front, because the alternative is discovering
 * them halfway through a task: after a worktree exists, or after an agent has
 * been paid for its tokens.
 *
 * Every failure names the setting the user has to edit, in the file's own
 * dotted notation (`limits.iterations`, `agents.codex.executable`,
 * `checks.0.command`), and says what a usable value looks like.
 *
 * What is deliberately *not* checked here: anything requiring the filesystem,
 * the environment, or Git. Whether the task file exists, whether the agent's
 * executable is installed, whether the base reference resolves — those are
 * preflight checks, which run later and can fail for reasons the configuration
 * cannot fix.
 */
final class ConfigurationValidator
{
    /**
     * Control characters, which no configured command, reference or label may
     * contain: they corrupt log output, and a NUL byte makes the process
     * layer fail with a message about neither the setting nor the file.
     */
    private const string CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    /**
     * Agent names, and the adapter names that identify an implementation in the
     * registry. Defined with the agent entry itself, because the adapter
     * registry validates a registered name against the same pattern and the two
     * must not drift: a provider must not be able to register a name no
     * configuration file may contain.
     */
    private const string IDENTIFIER = AgentDefinition::NAME_PATTERN;

    /**
     * Environment variable names, as the shell accepts them.
     */
    private const string ENVIRONMENT_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Characters Git rejects in a reference name, plus the space.
     */
    private const string BRANCH_CHARACTERS = '/[ ~^:?*\[\\\\]/';

    /**
     * @throws ConfigurationException when a setting's value cannot be used
     */
    public function validate(Configuration $configuration): Configuration
    {
        $source = $configuration->source() ?? 'the configuration';

        $this->validatePaths($source, $configuration);
        $this->validateAgents($source, $configuration);
        $this->validateChecks($source, $configuration);
        $this->validateWorkspace($source, $configuration);
        $this->validateGit($source, $configuration->git());
        $this->validateLimits($source, $configuration->limits());

        return $configuration;
    }

    /**
     * The task checklist and the context file.
     *
     * Both must name a file inside the repository: the runner reads the
     * checklist, rewrites one checkbox at a time, and passes the context to the
     * agent, and none of that is safe to point at an arbitrary location on the
     * machine. Neither may live in the runtime directory, whose contents the
     * runner generates, ignores in Git and removes when cleaning up.
     */
    private function validatePaths(string $source, Configuration $configuration): void
    {
        $root = $configuration->projectRoot();
        $runtime = Path::normalize($root . '/' . Defaults::RUNTIME_DIRECTORY);

        $paths = [
            'tasks' => [$configuration->tasksPath(), $configuration->tasksFile()],
            'context' => [$configuration->contextPath(), $configuration->contextFile()],
        ];

        foreach ($paths as $setting => [$configured, $resolved]) {
            $this->requireText($source, $setting, $configured, 'a path to a file inside the project');

            if ($resolved === $root) {
                $this->reject(
                    $source,
                    $setting,
                    'must name a file, not the project directory itself.',
                    sprintf('Set it to a path such as "%s".', Defaults::TASKS_FILE),
                );
            }

            if (!Path::isInside($root, $resolved)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must be inside the project: "%s" resolves to %s.', $configured, $resolved),
                    sprintf('The project is %s.', $root),
                    'Ralph PHP only reads and writes files in the repository it is working on.',
                );
            }

            if (Path::isInside($runtime, $resolved)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must not be inside the runtime directory %s.', Defaults::RUNTIME_DIRECTORY),
                    'Ralph PHP generates that directory, ignores it in Git and removes it when cleaning up.',
                );
            }
        }

        if ($configuration->tasksFile() === $configuration->contextFile()) {
            throw new ConfigurationException(
                sprintf('%s: "tasks" and "context" are the same file, %s.', $source, $configuration->tasksFile()),
                ['The checklist is rewritten as tasks complete; the context file is yours to maintain.'],
            );
        }
    }

    /**
     * Every configured agent, not only the one a run selects: a broken entry
     * is a mistake worth reporting while the user is looking at the file.
     *
     * Whether an entry's adapter has an implementation is checked by agent
     * selection, which knows the adapter registry the run will use and reports
     * only the agent that was actually asked for.
     */
    private function validateAgents(string $source, Configuration $configuration): void
    {
        $this->requireText($source, 'agent', $configuration->defaultAgent(), 'the name of a configured agent');

        foreach ($configuration->agents() as $name => $agent) {
            $setting = 'agents.' . $name;

            if (preg_match(self::IDENTIFIER, $name) !== 1) {
                $this->reject(
                    $source,
                    $setting,
                    'is not a usable agent name.',
                    'Agent names are typed as "run --agent NAME": use letters, digits, ".", "_" or "-".',
                );
            }

            $this->requireText($source, $setting . '.adapter', $agent->adapter(), 'a registered adapter name');

            if (preg_match(self::IDENTIFIER, $agent->adapter()) !== 1) {
                $this->reject(
                    $source,
                    $setting . '.adapter',
                    sprintf('is not a usable adapter name, "%s" given.', $agent->adapter()),
                    'An adapter name identifies an adapter implementation, such as "codex" or "claude".',
                );
            }

            $this->requireText(
                $source,
                $setting . '.executable',
                $agent->executable(),
                'a command name or an absolute path',
            );

            $model = $agent->model();
            if ($model !== null) {
                $this->requireText($source, $setting . '.model', $model, 'a model name');
            }

            foreach ($agent->arguments() as $index => $argument) {
                $this->requireText(
                    $source,
                    $setting . '.arguments.' . $index,
                    $argument,
                    'one argument passed to the agent',
                );
            }

            foreach ($agent->environment() as $index => $variable) {
                if (preg_match(self::ENVIRONMENT_NAME, $variable) === 1) {
                    continue;
                }

                $this->reject(
                    $source,
                    $setting . '.environment.' . $index,
                    sprintf('is not an environment variable name, "%s" given.', self::withoutValue($variable)),
                    'List the name only: Ralph PHP reads the value from the environment when it runs the '
                        . 'agent, so no credential has to be written into the configuration.',
                );
            }
        }
    }

    /**
     * The verification commands.
     *
     * An empty list is allowed, and is the default. A project is the only thing
     * that knows how it is verified, so the package asks rather than assumes —
     * and a user who has nothing to put here should not have to invent a
     * command to get a run started.
     *
     * What an empty list means is not hidden: a run that freezes no checks has
     * only the agent's own result and the commit it produced to decide a task
     * on, which preflight warns about ({@see
     * \Celyes\RalphPhp\Preflight\Check\VerificationCheck}), `ralph run`
     * says in its opening lines, and the completion record names. It is a
     * weaker run, stated as one; it is not a configuration mistake.
     *
     * Every command that *is* listed is held to the same rules as before: a
     * non-empty argument array, a usable name, a name no other check shares,
     * and a positive timeout.
     */
    private function validateChecks(string $source, Configuration $configuration): void
    {
        $checks = $configuration->checks();
        $seen = [];

        foreach ($checks as $index => $check) {
            $setting = 'checks.' . $index;

            if ($check->command() === []) {
                $this->reject(
                    $source,
                    $setting . '.command',
                    'must not be empty.',
                    'A command is an executable followed by its arguments, such as ["composer", "test"].',
                );
            }

            foreach ($check->command() as $position => $argument) {
                $this->requireText(
                    $source,
                    $setting . '.command.' . $position,
                    $argument,
                    $position === 0 ? 'the executable to run' : 'one argument of the command',
                );
            }

            $this->requireText($source, $setting . '.name', $check->name(), 'a label for this check');

            if (preg_match('#[/\\\\]#', $check->name()) === 1) {
                $this->reject(
                    $source,
                    $setting . '.name',
                    sprintf('must not contain a path separator, "%s" given.', $check->name()),
                    'Check names label status output and log files.',
                );
            }

            if (isset($seen[$check->name()])) {
                $this->reject(
                    $source,
                    $setting . '.name',
                    sprintf('repeats the name "%s", already used by %s.', $check->name(), $seen[$check->name()]),
                    'Check names identify a check in status output and in its logs, so they must differ.',
                );
            }

            $seen[$check->name()] = 'checks.' . $index;

            if ($check->timeout() < 1) {
                $this->rejectNonpositive($source, $setting . '.timeout', $check->timeout(), 'seconds');
            }
        }
    }

    /**
     * The `workspace` section: the commands that prepare a new task worktree,
     * and the files copied into it.
     *
     * An empty `prepare` is allowed, and is the default: a project whose
     * checkout works as Git leaves it has nothing to prepare. A project that
     * does need something — a PHP project whose checks need `vendor/`, most
     * often — lists it here, because what a worktree is missing is a fact about
     * the project rather than one this package can assume.
     *
     * A copied path is held to the rule the task checklist is held to — it must
     * name a file inside the project — and to two more that only apply to a
     * copy. It must be relative, because the source and the destination are the
     * *same* path in two checkouts and an absolute path has no meaning in the
     * second. And it must not be a path the package or Git owns: copying the
     * runtime directory would carry the state database into a workspace, and
     * copying out of `.git` would overwrite the administrative file that makes
     * the workspace a linked worktree at all.
     */
    private function validateWorkspace(string $source, Configuration $configuration): void
    {
        $workspace = $configuration->workspace();
        $root = $configuration->projectRoot();
        $runtime = Path::normalize($root . '/' . Defaults::RUNTIME_DIRECTORY);

        $seen = [];

        foreach ($workspace->prepare() as $index => $command) {
            $setting = 'workspace.prepare.' . $index;

            if ($command->command() === []) {
                $this->reject(
                    $source,
                    $setting . '.command',
                    'must not be empty.',
                    'A command is an executable followed by its arguments, such as ["composer", "install"].',
                );
            }

            foreach ($command->command() as $position => $argument) {
                $this->requireText(
                    $source,
                    $setting . '.command.' . $position,
                    $argument,
                    $position === 0 ? 'the executable to run' : 'one argument of the command',
                );
            }

            $this->requireText($source, $setting . '.name', $command->name(), 'a label for this command');

            if (preg_match('#[/\\\\]#', $command->name()) === 1) {
                $this->reject(
                    $source,
                    $setting . '.name',
                    sprintf('must not contain a path separator, "%s" given.', $command->name()),
                    'Preparation names label status output and log files.',
                );
            }

            if (isset($seen[$command->name()])) {
                $this->reject(
                    $source,
                    $setting . '.name',
                    sprintf('repeats the name "%s", already used by %s.', $command->name(), $seen[$command->name()]),
                    'Preparation names identify a command in status output and in its logs, so they must differ.',
                );
            }

            $seen[$command->name()] = 'workspace.prepare.' . $index;

            if ($command->timeout() < 1) {
                $this->rejectNonpositive($source, $setting . '.timeout', $command->timeout(), 'seconds');
            }
        }

        $copied = [];

        foreach ($workspace->copy() as $index => $path) {
            $setting = 'workspace.copy.' . $index;

            $this->requireText($source, $setting, $path, 'a path to a file inside the project, relative to its root');

            if (Path::isAbsolute($path)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must be relative to the project root, "%s" given.', $path),
                    'A copied file is read at that path in your checkout and written at the same path in the '
                        . 'task workspace, so the setting names one path in two places.',
                );
            }

            $resolved = Path::normalize($root . '/' . $path);

            if ($resolved === $root || !Path::isInside($root, $resolved)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must name a file inside the project: "%s" resolves to %s.', $path, $resolved),
                    sprintf('The project is %s.', $root),
                    'Ralph PHP only reads and writes files in the repository it is working on.',
                );
            }

            if (Path::isInside($runtime, $resolved)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must not be inside the runtime directory %s.', Defaults::RUNTIME_DIRECTORY),
                    'That directory holds the state database, the logs and the task workspaces themselves.',
                );
            }

            if (Path::isInside(Path::normalize($root . '/.git'), $resolved)) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must not be inside the repository\'s own .git directory, "%s" given.', $path),
                    'A task workspace is a linked worktree: its .git is maintained by Git, and writing into '
                        . 'it would break the link to this repository.',
                );
            }

            if (isset($copied[$resolved])) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('repeats the file already listed by %s.', $copied[$resolved]),
                );
            }

            $copied[$resolved] = $setting;
        }
    }

    /**
     * The base reference, the branch prefix and the push remote.
     *
     * The prefix is checked against the reference-name rules Git applies to the
     * part of a branch name it will form. The rules are repeated here rather
     * than taken from {@see \Celyes\RalphPhp\Git\BranchName}, which judges whole
     * names: a prefix ends in a separator — `ralph/` is the default — and a
     * whole name may not, so asking that type about a prefix would reject the
     * ordinary case. {@see \Celyes\RalphPhp\Git\BranchNamer} checks the complete
     * name it assembles and reports a failure back against this setting, so a
     * rule missing here surfaces later with the same remedy rather than as a
     * Git error.
     */
    private function validateGit(string $source, GitSettings $git): void
    {
        $this->requireText($source, 'git.base', $git->baseReference(), 'a branch, tag or commit');
        $this->requireText(
            $source,
            'git.push.remote',
            $git->push()->remote(),
            'the name of a configured Git remote',
        );

        foreach ([
            'git.base' => $git->baseReference(),
            'git.push.remote' => $git->push()->remote(),
        ] as $setting => $value) {
            if (preg_match('/\s/', $value) === 1) {
                $this->reject($source, $setting, sprintf('must not contain whitespace, "%s" given.', $value));
            }

            if (str_starts_with($value, '-')) {
                $this->reject(
                    $source,
                    $setting,
                    sprintf('must not start with "-", "%s" given.', $value),
                    'Git would read it as a command-line option.',
                );
            }
        }

        $prefix = $git->branchPrefix();

        $this->requireText($source, 'git.branch_prefix', $prefix, 'a prefix for generated branch names');

        $problem = $this->branchPrefixProblem($prefix);

        if ($problem !== null) {
            $this->reject(
                $source,
                'git.branch_prefix',
                sprintf('%s, "%s" given.', $problem, $prefix),
                sprintf('Task branches are named "<prefix><task>", such as "%s".', Defaults::BRANCH_PREFIX . '3f2a1b'),
            );
        }
    }

    /**
     * Why Git would refuse a branch name starting with this prefix, or null
     * when it would not.
     */
    private function branchPrefixProblem(string $prefix): ?string
    {
        if (preg_match(self::BRANCH_CHARACTERS, $prefix) === 1) {
            return 'must not contain a space or any of ~ ^ : ? * [ \\';
        }

        if (str_contains($prefix, '..')) {
            return 'must not contain ".."';
        }

        if (str_contains($prefix, '@{')) {
            return 'must not contain "@{"';
        }

        if (str_starts_with($prefix, '/') || str_contains($prefix, '//')) {
            return 'must not contain an empty path segment';
        }

        if (str_starts_with($prefix, '-')) {
            return 'must not start with "-"';
        }

        foreach (explode('/', $prefix) as $segment) {
            if (str_starts_with($segment, '.')) {
                return 'must not contain a segment starting with "."';
            }

            if (str_ends_with($segment, '.lock')) {
                return 'must not contain a segment ending with ".lock"';
            }
        }

        return null;
    }

    /**
     * The limits, every one of which must allow at least one of whatever it
     * counts. A zero or negative limit would stop the run before it started —
     * reported here as the configuration error it is, rather than as a run that
     * mysteriously completes no work.
     */
    private function validateLimits(string $source, Limits $limits): void
    {
        foreach ([
            'limits.iterations' => [$limits->iterations(), 'tasks'],
            'limits.attempts_per_task' => [$limits->attemptsPerTask(), 'attempts'],
            'limits.task_timeout' => [$limits->taskTimeout(), 'seconds'],
            'limits.run_timeout' => [$limits->runTimeout(), 'seconds'],
        ] as $setting => [$value, $unit]) {
            if ($value < 1) {
                $this->rejectNonpositive($source, $setting, $value, $unit);
            }
        }
    }

    /**
     * Require a setting to hold usable text: present, not blank, and free of
     * control characters.
     */
    private function requireText(string $source, string $setting, string $value, string $expected): void
    {
        if (trim($value) === '') {
            $this->reject(
                $source,
                $setting,
                $value === '' ? 'must not be empty.' : 'must not be blank.',
                sprintf('It must be %s.', $expected),
            );
        }

        if (preg_match(self::CONTROL_CHARACTERS, $value) === 1) {
            $this->reject(
                $source,
                $setting,
                'must not contain control characters.',
                sprintf('It must be %s.', $expected),
            );
        }
    }

    private function rejectNonpositive(string $source, string $setting, int $value, string $unit): never
    {
        $this->reject(
            $source,
            $setting,
            sprintf('must be at least 1 %s, %d given.', rtrim($unit, 's'), $value),
            sprintf('Remove "%s" to use the default.', $setting),
        );
    }

    /**
     * @param string ...$hints
     */
    private function reject(string $source, string $setting, string $problem, string ...$hints): never
    {
        throw new ConfigurationException(
            sprintf('%s: "%s" %s', $source, $setting, $problem),
            array_values($hints),
        );
    }

    /**
     * An environment entry with anything after the first `=` elided.
     *
     * The entry this rejects is most often `NAME=secret`, written into the
     * configuration by mistake. Quoting it back in full would copy the
     * credential into an error message, a terminal and whatever captured it,
     * which is the problem rather than the report of it.
     */
    private static function withoutValue(string $variable): string
    {
        $assignment = strpos($variable, '=');

        if ($assignment === false) {
            return $variable;
        }

        return substr($variable, 0, $assignment) . '=...';
    }

}
