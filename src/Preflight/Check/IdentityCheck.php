<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\GitProbe;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;

/**
 * That Git knows who is making the commits.
 *
 * Without an identity, `git commit` refuses, which would be discovered after an
 * agent has implemented a task and its verification commands have passed — the
 * most expensive possible moment to learn about a two-line configuration
 * problem.
 *
 * Asked as `git var GIT_AUTHOR_IDENT` rather than by reading `user.name` and
 * `user.email`, because that is the same strict resolution `git commit`
 * performs: it accounts for the `GIT_AUTHOR_*` and `GIT_COMMITTER_*` variables,
 * for repository-local configuration, and for an identity Git could only guess
 * at — which it reports as unknown rather than using. Author and committer are
 * asked for separately, since the environment can supply one without the other.
 */
final class IdentityCheck implements PreflightCheck
{
    public function __construct(private readonly GitProbe $git = new GitProbe())
    {
    }

    public function id(): CheckId
    {
        return CheckId::Identity;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $identities = [];

        foreach (['author' => 'GIT_AUTHOR_IDENT', 'committer' => 'GIT_COMMITTER_IDENT'] as $role => $variable) {
            $result = $this->git->run($context, 'var', $variable);

            if (!$result->succeeded()) {
                return CheckResult::failed(
                    $this->id(),
                    sprintf('Git cannot determine the commit %s identity', $role),
                    [sprintf('git reported: %s', $result->failureReason())],
                    [
                        'Set it for this repository:',
                        '  git config user.name "Your Name"',
                        '  git config user.email "you@example.com"',
                        'Add --global to set it for every repository.',
                        'Ralph PHP creates the task commits, so Git has to know who is making them.',
                    ],
                );
            }

            $identities[$role] = self::withoutTimestamp($result->firstOutputLine());
        }

        $details = $identities['author'] === $identities['committer']
            ? []
            : [sprintf('committer: %s', $identities['committer'])];

        return CheckResult::passed(
            $this->id(),
            sprintf('commits will be authored by %s', $identities['author']),
            $details,
        );
    }

    /**
     * Drop the time and timezone Git appends to an identity, leaving
     * `Name <email>`: the moment preflight ran is not part of the identity.
     */
    private static function withoutTimestamp(string $identity): string
    {
        return trim((string) preg_replace('/\s+\d+\s+[+-]\d{4}$/', '', $identity));
    }
}
