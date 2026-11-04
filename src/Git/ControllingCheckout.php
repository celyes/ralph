<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\CheckoutException;
use Celyes\RalphPhp\Process\ProcessRunner;
use InvalidArgumentException;

/**
 * The repository the user ran `ralph` in, and the one promise the package makes
 * about it: it is read, and left alone.
 *
 * Every task is implemented somewhere else — an isolated worktree created from
 * the configured base commit — so this checkout is not where the work happens.
 * It is where the configuration, the context and the checklist are read from,
 * and where a completed task's checkbox is written. That makes uncommitted work
 * here a problem of a particular shape: it is not visible in the worktree the
 * agent is given, so an agent implementing a task would be working from a tree
 * the user is not looking at, and the commit the run produces would not contain
 * the user's changes. The two could then conflict in a way neither the user nor
 * the run has seen.
 *
 * So a run inspects first and refuses to start (PROJECT.md section F, item 1).
 * It does not stash, it does not discard, and it does not commit. The refusal
 * is the feature: those three are the convenient things to do with someone
 * else's uncommitted work, and each of them can lose it.
 *
 * Only the project's own files count. Changes to the package's files — the
 * checklist whose checkbox the last run ticked, the configuration edited a
 * minute ago, the ignored runtime directory — are read from this checkout
 * rather than missed by the agent, so they do not stop anything.
 * {@see ManagedPaths} draws that line, and PROJECT.md section 4 requires it
 * drawn: the runner must tell its own checkbox changes from unrelated edits,
 * and must never commit the ones it finds here.
 *
 * Both commands it issues are {@see Repository::query()}, so inspecting cannot
 * take the index lock of a repository the user has open in an editor.
 *
 * Two states are deliberately *not* treated as refusals. A detached HEAD and an
 * interrupted merge or rebase both describe a checkout in an unusual position,
 * and neither affects a worktree created from a configured base commit; a
 * conflicted file in such a checkout is reported anyway, because it is an
 * uncommitted change. What the base reference resolves to is
 * {@see \Celyes\RalphPhp\Preflight\Check\BaseReferenceCheck}'s question, asked
 * before this one.
 */
final class ControllingCheckout
{
    public function __construct(
        private readonly Repository $git,
        private readonly ManagedPaths $managed,
    ) {
    }

    /**
     * The checkout a run with this configuration would use.
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
            ManagedPaths::forProject($configuration),
        );
    }

    /**
     * Read what is uncommitted, without judging it.
     *
     * For `status` and the diagnostics, which report a dirty checkout rather
     * than refusing to continue because of one.
     *
     * @throws CheckoutException when Git cannot answer
     */
    public function inspect(): CheckoutInspection
    {
        return CheckoutInspection::of($this->workingTree(), $this->status(), $this->managed);
    }

    /**
     * Read the checkout, and refuse the run if the project's own work is in it.
     *
     * Returns the same reading as {@see self::inspect()} when a run may start,
     * so a caller that wants to record what the checkout looked like — the
     * package's own uncommitted files included — has it without asking twice.
     *
     * @throws CheckoutException when the checkout holds unrelated uncommitted
     *                          changes, or cannot be read
     */
    public function requireRunnable(): CheckoutInspection
    {
        $inspection = $this->inspect();

        if (!$inspection->isRunnable()) {
            throw new CheckoutException(
                $inspection->summary() . ':' . PHP_EOL . self::indent($inspection->details()),
                $inspection->hints(),
                $inspection,
            );
        }

        return $inspection;
    }

    /**
     * Ask Git what is uncommitted.
     *
     * A command that fails is a refusal and not an empty answer. The reasons it
     * can fail — the directory is no longer a repository, the repository is
     * corrupt, a filesystem has gone away mid-run — all mean the same thing
     * here: nothing is known about what is uncommitted, and proceeding would be
     * proceeding on an assumption.
     *
     * @throws CheckoutException when Git cannot answer
     */
    private function status(): WorkingTreeStatus
    {
        $result = $this->git->query(...WorkingTreeStatus::COMMAND);

        if (!$result->succeeded()) {
            throw new CheckoutException(
                sprintf(
                    'the uncommitted changes in %s could not be read: %s',
                    $this->workingTree(),
                    $result->failureReason(),
                ),
                [
                    'Ralph PHP will not start a run it cannot tell apart from your own '
                        . 'uncommitted work.',
                    sprintf('Run "git status" in %s to see what Git reports.', $this->workingTree()),
                ],
            );
        }

        return WorkingTreeStatus::parse($result->standardOutput());
    }

    private function workingTree(): string
    {
        return $this->git->workingTree();
    }

    /**
     * @param list<string> $lines
     */
    private static function indent(array $lines): string
    {
        return implode(PHP_EOL, array_map(static fn (string $line): string => '  ' . $line, $lines));
    }
}
