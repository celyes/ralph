<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight;

use Celyes\RalphPhp\Agent\AdapterRegistrationFile;
use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Preflight\Check\AgentCheck;
use Celyes\RalphPhp\Preflight\Check\AgentVersionCheck;
use Celyes\RalphPhp\Preflight\Check\BaseReferenceCheck;
use Celyes\RalphPhp\Preflight\Check\ExtensionsCheck;
use Celyes\RalphPhp\Preflight\Check\GitCheck;
use Celyes\RalphPhp\Preflight\Check\IdentityCheck;
use Celyes\RalphPhp\Preflight\Check\PhpCheck;
use Celyes\RalphPhp\Preflight\Check\RepositoryCheck;
use Celyes\RalphPhp\Preflight\Check\VerificationCheck;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Process\ProcessRunner;

/**
 * Establishes that the environment can do the work before any of it starts.
 *
 * Configuration validation asks whether the *settings* make sense; preflight
 * asks whether the machine can satisfy them. The two are separate because
 * their answers change independently: a configuration that was valid yesterday
 * runs into an uninstalled agent, a repository with no commits, or a Git with
 * no author identity today, and none of that is something the user can fix by
 * editing a file.
 *
 * Every check runs, even after one fails, so that an unprepared environment
 * produces one list of things to fix rather than a sequence of runs that each
 * reveal the next problem. The exception is a check whose answer depends on a
 * failed one: asking Git for a commit in a directory that is not a repository
 * produces a message about the wrong thing, so those are reported as skipped.
 *
 * Nothing here writes, and no verification command and no agent *session* is
 * started: the checks resolve and interrogate, which is why preflight is safe
 * to run at any time, including on a project with an interrupted run in it.
 * Two things are executed, both of them queries that change nothing — `git`,
 * to ask about the repository, and the selected agent's version argument
 * ({@see AgentVersionCheck}), which starts no session, reads no task and costs
 * nothing.
 *
 * A project's own adapters are loaded before the agent is selected, so an
 * agent pointed at an adapter from `.ralph/adapters.php` is checked rather
 * than reported as unsupported. That executes the project's registration file,
 * with the trust {@see AdapterRegistrationFile} documents.
 */
final class Preflight
{
    /**
     * @var list<PreflightCheck>
     */
    private readonly array $checks;

    /**
     * @param AdapterRegistry|null $adapters the adapters a run could use; null
     *        reads the project's own registrations for each run, which is what
     *        a command does
     * @param list<PreflightCheck>|null $checks the checks to run, for tests;
     *        null uses the full set in {@see CheckId} order
     */
    public function __construct(
        private readonly ?AdapterRegistry $adapters = null,
        ?array $checks = null,
    ) {
        $this->checks = $checks ?? self::defaultChecks();
    }

    /**
     * Run every check against a project's configuration.
     *
     * Agent selection happens first and is *not* a check: an agent name that
     * matches no configured entry, or an entry naming an adapter the package
     * cannot execute, is a configuration error with nothing to inspect, and is
     * reported as one by {@see AgentSelector}.
     *
     * @param string|null $requestedAgent the value of `--agent`, or null for the project default
     *
     * @throws UsageException when `--agent` was given without a name
     * @throws ConfigurationException when no such agent is configured, its adapter is
     *         unavailable, or the project's registration file cannot be used
     */
    public function run(Configuration $configuration, ?string $requestedAgent = null): PreflightReport
    {
        $adapters = $this->adaptersFor($configuration);

        $context = new PreflightContext(
            $configuration,
            (new AgentSelector($adapters))->select($configuration, $requestedAgent),
            $adapters,
        );

        foreach ($this->checks as $check) {
            $context->record($this->inspect($check, $context));
        }

        return new PreflightReport(
            $configuration->projectRoot(),
            $configuration->source(),
            $context->agent(),
            $context->results(),
        );
    }

    /**
     * The adapters this project can execute: the shipped ones, plus whatever
     * `.ralph/adapters.php` registers.
     *
     * Built per run rather than held, because the registrations belong to the
     * project being checked and one process may check more than one — and
     * because a registry is what both halves of the answer need: selection,
     * to accept an agent naming a project's adapter, and
     * {@see AgentVersionCheck}, to ask that adapter about its CLI.
     *
     * @throws ConfigurationException when the registration file cannot be used
     */
    private function adaptersFor(Configuration $configuration): AdapterRegistry
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }

        $registry = AdapterRegistry::default();
        AdapterRegistrationFile::in($configuration->projectRoot())->registerInto($registry);

        return $registry;
    }

    /**
     * Run one check, unless something it depends on has already failed.
     */
    private function inspect(PreflightCheck $check, PreflightContext $context): CheckResult
    {
        foreach ($check->id()->dependsOn() as $dependency) {
            if (!$context->allowsDependents($dependency)) {
                return CheckResult::skipped($check->id(), $dependency);
            }
        }

        return $check->inspect($context);
    }

    /**
     * The full set of checks, sharing one Git probe and one executable finder.
     *
     * @return list<PreflightCheck>
     */
    private static function defaultChecks(): array
    {
        $git = new GitProbe(new ProcessRunner());
        $executables = new ExecutableFinder();

        return [
            new PhpCheck(),
            new ExtensionsCheck(),
            new GitCheck($git, $executables),
            new RepositoryCheck($git),
            new BaseReferenceCheck($git),
            new IdentityCheck($git),
            new AgentCheck($executables),
            new AgentVersionCheck(),
            new VerificationCheck($executables),
        ];
    }
}
