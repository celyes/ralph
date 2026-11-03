<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Agent\VersionedAdapter;
use Celyes\RalphPhp\Agent\VersionRequirement;
use Celyes\RalphPhp\Exception\RalphException;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;

/**
 * That the installed CLI is a release the selected adapter was written for.
 *
 * The last part of PROJECT.md section E, item 9, and the one the other checks
 * could not reach: a missing executable is visible from the filesystem and an
 * unset credential from the environment, but whether `codex` is the 0.4x the
 * adapter spells `exec --cd --sandbox` for can only be answered by the program
 * itself. Left unanswered, an incompatible CLI is a usage message and a
 * non-zero exit once per attempt, charged to the task's attempt limit, and
 * never explained — the one failure in this package that costs an entire run
 * to diagnose.
 *
 * **It builds the adapter, because only the adapter knows.** The requirement
 * is {@see VersionedAdapter::versionRequirement()}'s: its CLI's name, its
 * floor, and the arguments that make that CLI print a version. Nothing here
 * assumes `--version` means anything in particular, and an adapter that
 * declares no requirement — the shape a project's own HTTP-backed or
 * wrapper-script adapter often has — is reported as having nothing to check
 * rather than guessed at.
 *
 * **Preflight runs one command it would otherwise not.** The class description
 * of {@see \Celyes\RalphPhp\Preflight\Preflight} says that no agent is
 * executed, and this is the stated exception to it: a version query starts no
 * session, reads no task, writes nothing, spends nothing, and ends in
 * milliseconds. That is a different act from running the agent, and it is
 * bounded here — {@see self::TIMEOUT_SECONDS}, the project root as the working
 * directory, no input, and only the arguments the adapter named.
 *
 * **Only a version that was read and is too old refuses a run.** A query that
 * failed, timed out, or answered in something with no version number in it is
 * a warning: the package cannot establish the version, which
 * {@see \Celyes\RalphPhp\Preflight\CheckOutcome} is explicit must not stop a
 * run the user can see no problem with. That is deliberately weaker than
 * {@see GitCheck}, which fails on a `git --version` that does not work — Git
 * is one program this package requires, while an agent executable may be a
 * wrapper a project wrote, and refusing to start because somebody's shell
 * script answers `--version` oddly would be a worse failure than the one it
 * prevents.
 *
 * Nothing here falls back to another agent, and the hints say so: the agent is
 * the one thing in a run that a tool must never substitute, because its
 * commits and its cost are attributed to whichever agent was named.
 */
final class AgentVersionCheck implements PreflightCheck
{
    /**
     * Seconds the version query may take.
     *
     * Generous, because an agent CLI is routinely a Node program whose first
     * start on a cold cache is slow, and short enough that preflight cannot be
     * hung by one that waits on something.
     */
    private const int TIMEOUT_SECONDS = 30;

    /**
     * The sentence every failure and every warning here ends with.
     */
    private const string NO_FALLBACK = 'Ralph PHP does not run a different agent in its place.';

    public function __construct(private readonly ProcessRunner $processes = new ProcessRunner())
    {
    }

    public function id(): CheckId
    {
        return CheckId::AgentVersion;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $agent = $context->agent();

        try {
            $adapter = $context->adapters()->create($agent);
        } catch (RalphException $failure) {
            // Reachable for a provider that refuses the configured settings,
            // or an adapter name registered nowhere. Agent selection reports
            // the latter first, with the configuration file in hand; this is
            // the same refusal where it can still be one failed check among a
            // report rather than an abandoned command.
            return CheckResult::failed(
                $this->id(),
                sprintf(
                    'the adapter "%s" for the agent "%s" could not be prepared: %s',
                    $agent->adapter(),
                    $agent->name(),
                    $failure->getMessage(),
                ),
                [],
                [...$failure->hints(), self::NO_FALLBACK],
            );
        }

        if (!$adapter instanceof VersionedAdapter) {
            return CheckResult::passed(
                $this->id(),
                sprintf('the adapter "%s" states no version requirement', $agent->adapter()),
                [sprintf(
                    'an adapter declares one by implementing %s; nothing is run for an adapter that does not',
                    VersionedAdapter::class,
                )],
            );
        }

        return $this->measure($context, $adapter->versionRequirement());
    }

    /**
     * Ask the installed CLI what it is, and compare.
     */
    private function measure(PreflightContext $context, VersionRequirement $requirement): CheckResult
    {
        $definition = $context->agent()->definition();

        // The executable the agent check resolved and passed on, rather than a
        // second search that could answer differently.
        $executable = $context->recall(AgentCheck::EXECUTABLE) ?? $definition->executable();
        $command = $requirement->command($executable);
        $details = [
            sprintf('asked: %s', implode(' ', $command)),
            sprintf('requires %s', $requirement->describe()),
        ];

        $result = $this->processes->run(
            $command,
            $context->projectRoot(),
            self::TIMEOUT_SECONDS,
            // The agent's declared authentication, for a CLI that refuses to
            // say anything at all without it. Resolved through the same object
            // an attempt uses, so no value is read here either, and safe
            // because this check only runs when the agent check found them all
            // present.
            AgentEnvironment::of($definition)->forProcess(),
        );

        if ($result->timedOut()) {
            return $this->unknown(
                $requirement,
                $details,
                sprintf('it did not answer within %d seconds', self::TIMEOUT_SECONDS),
                'A CLI that cannot print its version in that time will not run a task either; '
                    . 'try the command above by hand.',
            );
        }

        if (!$result->succeeded()) {
            return $this->unknown(
                $requirement,
                [...$details, ...$this->reported($result)],
                sprintf('it exited with status %d', $result->exitCode() ?? -1),
                sprintf(
                    'Check that "agents.%s.executable" names the %s rather than something else; '
                        . 'the run will start, but the version was not established.',
                    $definition->name(),
                    $requirement->cli(),
                ),
            );
        }

        $version = VersionRequirement::versionIn($this->output($result));

        if ($version === null) {
            return $this->unknown(
                $requirement,
                [...$details, ...$this->reported($result)],
                'its answer holds no version number',
                'The run will start; check the version yourself if the agent then fails on its '
                    . 'first attempt.',
            );
        }

        $details[] = sprintf('reported: %s', $this->firstLine($result));

        if (!$requirement->isSatisfiedBy($version)) {
            return CheckResult::failed(
                $this->id(),
                sprintf(
                    '%s %s is older than the required %s',
                    $requirement->cli(),
                    $version,
                    $requirement->minimum(),
                ),
                $details,
                [
                    sprintf(
                        'Upgrade the %s: this adapter\'s invocation uses flags that release does not have, '
                            . 'so every attempt would fail the same way.',
                        $requirement->cli(),
                    ),
                    sprintf(
                        'Or point "agents.%s.executable" at a newer installation.',
                        $definition->name(),
                    ),
                    self::NO_FALLBACK,
                ],
            );
        }

        return CheckResult::passed(
            $this->id(),
            sprintf('%s %s', $requirement->cli(), $version),
            $details,
        );
    }

    /**
     * The version could not be established. See the class description for why
     * that is a warning rather than a refusal.
     *
     * @param list<string> $details
     */
    private function unknown(VersionRequirement $requirement, array $details, string $because, string $hint): CheckResult
    {
        return CheckResult::warning(
            $this->id(),
            sprintf('%s is required, and %s', $requirement->describe(), $because),
            $details,
            [$hint, self::NO_FALLBACK],
        );
    }

    /**
     * What the program said, as evidence for a warning.
     *
     * Both streams, trimmed to their first line: a CLI that failed to print a
     * version usually explains itself in one, and preflight's report is a
     * table rather than a log.
     *
     * @return list<string>
     */
    private function reported(ProcessResult $result): array
    {
        $said = $this->firstLine($result);

        return $said === '' ? ['it printed nothing'] : [sprintf('reported: %s', $said)];
    }

    private function output(ProcessResult $result): string
    {
        return trim($result->standardOutput()) !== '' ? $result->standardOutput() : $result->errorOutput();
    }

    private function firstLine(ProcessResult $result): string
    {
        $lines = preg_split('/\R/', trim($this->output($result))) ?: [];

        return trim((string) ($lines[0] ?? ''));
    }
}
