<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * Turns the configured `git.base` into the commit a run is built on.
 *
 * Asked once, at the start of a run, and the answer travels as a
 * {@see BaseCommit} (PROJECT.md section F, item 2). Resolving per task would
 * reintroduce exactly what recording the SHA is for: a `HEAD` that moved
 * between the second task and the third would silently give two tasks different
 * foundations, and the commits produced would not be comparable with each
 * other.
 *
 * {@see \Celyes\RalphPhp\Preflight\Check\BaseReferenceCheck} asks the same
 * question earlier and reports it as a failed check rather than an exception,
 * which is the right shape for a diagnostic command listing everything that is
 * wrong. This is the run's own copy, and it exists for two reasons that are not
 * duplication: preflight is optional, and the window between a preflight that
 * passed and a run that starts is long enough for a branch to be deleted. The
 * two deliberately share the way they ask — `--end-of-options`, the `^{commit}`
 * peel — because what they must not disagree about is which references count.
 *
 * The reference is passed after `--end-of-options`, so a value beginning with a
 * dash reaches Git as a reference and not as an option. Configuration
 * validation already rejects such a value; this is the second lock on the same
 * door, at the one place where user text reaches a command line.
 *
 * The `^{commit}` suffix is what makes the answer a commit rather than whatever
 * the reference pointed at. An annotated tag is a tag object, and a tree or a
 * blob can be named by SHA; peeling means `git.base: "v1.0"` yields the commit
 * the tag marks, and a reference naming a tree is refused here instead of
 * failing later inside `git worktree add`.
 */
final class BaseResolver
{
    /**
     * The command whose output {@see BaseCommit} is built from.
     *
     * `--verify` makes a reference that does not resolve a failure rather than
     * output echoing the reference back; `--quiet` keeps Git's own complaint
     * off the user's terminal, since this class phrases its own.
     *
     * @var list<string>
     */
    private const array COMMAND = ['rev-parse', '--verify', '--quiet', '--end-of-options'];

    /**
     * Git's exit status for a reference that simply is not there.
     *
     * Separated from every other nonzero status on purpose. `--quiet` prints
     * nothing in this case, so there would be nothing to show a user; anything
     * else — 128 for a directory that is not a repository, a timeout on an
     * unresponsive mount — has a reason worth quoting instead of being reported
     * as a missing branch.
     */
    private const int REFERENCE_NOT_FOUND = 1;

    private function __construct(
        private readonly Repository $git,
        private readonly string $reference,
        private readonly ?string $source,
    ) {
    }

    /**
     * The base of the repository this configuration describes.
     *
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     *
     * @throws InvalidArgumentException when the configured project root is not
     *                                 a directory that exists
     */
    public static function forProject(
        Configuration $configuration,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self(
            Repository::at($configuration->projectRoot(), $executable, $processes),
            $configuration->git()->baseReference(),
            $configuration->source() ?? ConfigurationLoader::fileIn($configuration->projectRoot()),
        );
    }

    /**
     * A resolver for a named reference in a given repository.
     *
     * @param string|null $source the configuration file to name in hints, when
     *        the reference came from one
     */
    public static function of(Repository $git, string $reference, ?string $source = null): self
    {
        return new self($git, $reference, $source);
    }

    /**
     * The reference that will be resolved, as configured.
     */
    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * Ask Git which commit the reference names.
     *
     * @throws ConfigurationException when the reference names no commit, the
     *                               repository has no commits yet, or Git could
     *                               not be asked
     */
    public function resolve(): BaseCommit
    {
        $reference = trim($this->reference);

        if ($reference === '') {
            throw new ConfigurationException(
                'No base reference is configured, so there is no commit for task branches to start from.',
                [sprintf('Set "git.base" in %s to a branch, tag or commit.', $this->source())],
            );
        }

        $result = $this->git->query(...[...self::COMMAND, $reference . '^{commit}']);

        if ($result->succeeded()) {
            return BaseCommit::of($reference, $result->trimmedOutput());
        }

        if ($result->exitCode() !== self::REFERENCE_NOT_FOUND) {
            throw new ConfigurationException(
                sprintf(
                    'the base reference "%s" could not be resolved in %s: %s',
                    $reference,
                    $this->git->workingTree(),
                    $result->failureReason(),
                ),
                [
                    'Ralph PHP will not start tasks from a commit it could not confirm.',
                    sprintf(
                        'Run "git rev-parse --verify %s" in %s to see what Git reports.',
                        $reference,
                        $this->git->workingTree(),
                    ),
                ],
            );
        }

        throw $this->unresolved($reference);
    }

    /**
     * The reference resolved to nothing.
     *
     * An empty repository is separated out, because "HEAD does not name a
     * commit" is true there but describes the default setting rather than the
     * problem, which is that there is nothing to branch from yet. The same
     * distinction is drawn by
     * {@see \Celyes\RalphPhp\Preflight\Check\BaseReferenceCheck}.
     */
    private function unresolved(string $reference): ConfigurationException
    {
        $head = $this->git->query('rev-parse', '--verify', '--quiet', 'HEAD');

        if (!$head->succeeded()) {
            return new ConfigurationException(
                sprintf('%s has no commits yet, so there is nothing for a task branch to start from.', $this->git->workingTree()),
                [
                    sprintf('git.base: "%s"', $reference),
                    'Create the first commit: a task\'s work is committed on top of the base commit.',
                ],
            );
        }

        return new ConfigurationException(
            sprintf('the base reference "%s" does not name a commit in %s.', $reference, $this->git->workingTree()),
            [sprintf('Set "git.base" in %s to a branch, tag or commit that exists.', $this->source())],
        );
    }

    private function source(): string
    {
        return $this->source ?? ConfigurationLoader::fileIn($this->git->workingTree());
    }
}
