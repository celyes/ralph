<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\GitProbe;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ProcessResult;

/**
 * That the configured `git.base` names a commit.
 *
 * Every task branch starts from it, and the run records the commit it resolved
 * to so that later movement of the reference cannot change what a task was
 * built on. A base that does not resolve therefore stops everything, and it is
 * worth saying so before a task is selected rather than at the moment the first
 * worktree is created.
 *
 * The reference is resolved with `--end-of-options`, so a value beginning with
 * a dash is passed to Git as a reference and not as an option. Configuration
 * validation already rejects such a value; this is the second lock on the same
 * door, in the one place where user text reaches a command line.
 */
final class BaseReferenceCheck implements PreflightCheck
{
    /**
     * Branches named in a hint for a base that does not resolve. Enough to
     * recognise the right name, short enough not to bury the message.
     */
    private const int BRANCHES_IN_HINT = 5;

    public function __construct(private readonly GitProbe $git = new GitProbe())
    {
    }

    public function id(): CheckId
    {
        return CheckId::BaseReference;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $base = $context->configuration()->git()->baseReference();

        $resolved = $this->git->run(
            $context,
            'rev-parse',
            '--verify',
            '--quiet',
            '--end-of-options',
            $base . '^{commit}',
        );

        if (!$resolved->succeeded()) {
            return $this->unresolved($context, $base);
        }

        $commit = $resolved->trimmedOutput();

        return CheckResult::passed(
            $this->id(),
            sprintf('git.base "%s" is %s', $base, substr($commit, 0, 12)),
            [sprintf('commit: %s', $commit), ...$this->subjectOf($context, $commit)],
        );
    }

    /**
     * The reference does not name a commit.
     *
     * An empty repository is separated out: "HEAD does not name a commit" is
     * true there but describes the default setting rather than the problem,
     * which is that there is nothing to branch from yet.
     */
    private function unresolved(PreflightContext $context, string $base): CheckResult
    {
        $head = $this->git->run($context, 'rev-parse', '--verify', '--quiet', 'HEAD');

        if (!$head->succeeded()) {
            return CheckResult::failed(
                $this->id(),
                'the repository has no commits yet',
                [sprintf('git.base: "%s"', $base)],
                [
                    'Create the first commit: task branches are created from a commit, and a task\'s '
                        . 'work is committed on top of it.',
                ],
            );
        }

        $hints = [];
        $branches = $this->branches($context);

        if ($branches !== []) {
            $hints[] = sprintf('Branches in this repository: %s.', implode(', ', $branches));
        }

        $hints[] = sprintf(
            'Set "git.base" in %s to a branch, tag or commit that exists.',
            $context->configuration()->source() ?? ConfigurationLoader::fileIn($context->projectRoot()),
        );

        return CheckResult::failed(
            $this->id(),
            sprintf('git.base "%s" does not name a commit in this repository', $base),
            [],
            $hints,
        );
    }

    /**
     * The base commit's subject line, as supporting evidence that the resolved
     * commit is the one the user has in mind.
     *
     * @return list<string>
     */
    private function subjectOf(PreflightContext $context, string $commit): array
    {
        $subject = $this->git->run(
            $context,
            'show',
            '--no-patch',
            '--format=%s',
            '--end-of-options',
            $commit,
        );

        if (!$subject->succeeded() || $subject->firstOutputLine() === '') {
            return [];
        }

        return [sprintf('subject: %s', $subject->firstOutputLine())];
    }

    /**
     * Local branch names, for the hint.
     *
     * @return list<string>
     */
    private function branches(PreflightContext $context): array
    {
        $result = $this->git->run(
            $context,
            'for-each-ref',
            '--format=%(refname:short)',
            '--count=' . (self::BRANCHES_IN_HINT + 1),
            'refs/heads',
        );

        $names = self::linesOf($result);

        if (count($names) > self::BRANCHES_IN_HINT) {
            return [...array_slice($names, 0, self::BRANCHES_IN_HINT), 'and others'];
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function linesOf(ProcessResult $result): array
    {
        if (!$result->succeeded() || $result->trimmedOutput() === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $result->trimmedOutput()))),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
