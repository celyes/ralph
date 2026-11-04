<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * One Git working tree, and the way this package talks to it.
 *
 * Section F is a sequence of Git operations — inspect the checkout, resolve a
 * base commit, add a worktree, create a branch, stage, commit, push — and each
 * of them has the same three ways of going wrong: the command runs in whatever
 * directory the PHP process happened to be in, it runs a different `git` from
 * the one preflight approved, or it is redirected at another repository by
 * variables inherited from the user's shell. Binding those three decisions to a
 * handle, once, is what keeps them from being re-decided per call site.
 *
 *  - The working tree is absolute and must exist, so no command can resolve its
 *    directory against the runner's own.
 *  - The executable is the one given, which is how a run uses the `git`
 *    {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} found rather than
 *    whichever one the search path offers at the moment of the call.
 *  - `GIT_DIR` and `GIT_WORK_TREE` are removed from the environment. Ralph PHP
 *    locates a repository from the directory it was pointed at; inheriting a
 *    redirection would let it report about, and commit into, a repository the
 *    user never named.
 *
 * It is deliberately not a model of a repository: no caching, no inferred
 * state, nothing remembered between calls. Callers ask Git a question and read
 * the answer, so that what the package believes about a repository is only ever
 * what the repository has just said.
 */
final class Repository
{
    /**
     * Seconds a local command may take.
     *
     * Generous for a `status` or a `rev-parse`, short enough that a repository
     * on an unresponsive network mount reports a timeout rather than stopping
     * the run indefinitely. Commands that can legitimately take longer — a
     * push over the network, a worktree checkout of a large tree — pass their
     * own.
     */
    public const int TIMEOUT_SECONDS = 20;

    /**
     * The Git option that suppresses locks taken only as an optimisation.
     *
     * `git status` normally writes the refreshed index back, which means a
     * plain inspection takes `index.lock`. Ralph PHP inspects a checkout the
     * user may have open in an editor, and a run must not be able to fail
     * someone else's `git add` — nor to be blamed for touching a repository it
     * promised only to read. Supported well below
     * {@see \Celyes\RalphPhp\Ralph::MINIMUM_GIT_VERSION}.
     */
    private const string NO_OPTIONAL_LOCKS = '--no-optional-locks';

    private function __construct(
        private readonly string $workingTree,
        private readonly string $executable,
        private readonly ProcessRunner $processes,
    ) {
    }

    /**
     * A handle on the working tree at this absolute path.
     *
     * @param string $executable the `git` to run, as an absolute path or a name
     *        to be resolved on the search path
     *
     * @throws InvalidArgumentException when the path is relative or is not a
     *                                 directory that exists, or when the
     *                                 executable is empty
     */
    public static function at(
        string $workingTree,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        $directory = rtrim(trim($workingTree), '/\\');

        if ($directory === '' || !self::isAbsolute($directory)) {
            throw new InvalidArgumentException(sprintf(
                'A repository path must be absolute; "%s" would resolve against whatever directory the runner happens to be in.',
                $workingTree,
            ));
        }

        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('The repository %s is not a directory that exists.', $directory));
        }

        if (trim($executable) === '') {
            throw new InvalidArgumentException('A repository needs a git executable to run.');
        }

        return new self($directory, trim($executable), $processes);
    }

    /**
     * The absolute working tree every command runs in.
     */
    public function workingTree(): string
    {
        return $this->workingTree;
    }

    /**
     * Run a Git command in this working tree.
     *
     * Arguments are passed as an array, never as a command line, so a branch
     * name or a commit message carrying shell syntax is an argument and not an
     * instruction. A nonzero status is an ordinary answer, returned for the
     * caller to interpret; only a command that could not be started at all
     * raises {@see \Celyes\RalphPhp\Exception\ProcessException}.
     */
    public function run(string ...$arguments): ProcessResult
    {
        return $this->execute(self::listOf($arguments), self::TIMEOUT_SECONDS);
    }

    /**
     * Ask the repository a question without disturbing it.
     *
     * Identical to {@see self::run()} except for {@see self::NO_OPTIONAL_LOCKS},
     * and separate from it so that a read-only intention is visible at the call
     * site rather than asserted in a comment. Every command the checkout
     * inspection issues goes through here.
     */
    public function query(string ...$arguments): ProcessResult
    {
        return $this->execute(
            [self::NO_OPTIONAL_LOCKS, ...self::listOf($arguments)],
            self::TIMEOUT_SECONDS,
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(array $arguments, int $timeout): ProcessResult
    {
        return $this->processes->run(
            [$this->executable, ...$arguments],
            $this->workingTree,
            $timeout,
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false],
        );
    }

    /**
     * A variadic's arguments as a list.
     *
     * PHP collects them keyed by name when the caller uses named arguments, and
     * a command is a sequence of arguments in order; this is where that
     * difference stops.
     *
     * @param array<array-key, string> $arguments
     *
     * @return list<string>
     */
    private static function listOf(array $arguments): array
    {
        $list = [];

        foreach ($arguments as $argument) {
            $list[] = $argument;
        }

        return $list;
    }

    private static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path) === 1;
    }
}
