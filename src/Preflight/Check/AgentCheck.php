<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Process\ExecutableFinder;

/**
 * That the agent this run selected can actually be executed.
 *
 * Two requirements, reported together so a user setting an agent up for the
 * first time sees both at once: the executable exists and can be run, and every
 * environment variable the agent's configuration declares for authentication is
 * set.
 *
 * Only variable *names* are read from the configuration and only names are
 * printed; a value is never shown, logged, or persisted. The declared list is
 * normally empty, because Codex and Claude Code can both be authenticated by an
 * interactive login — so an unauthenticated agent that needs no variables is
 * not something this check can detect. What it does instead is make the
 * declaration useful to whoever does authenticate by variable.
 *
 * Whether the installed CLI is a *version* its adapter supports is the
 * separate question {@see AgentVersionCheck} answers, which this check is what
 * makes answerable: it resolves the executable, remembers it under
 * {@see self::EXECUTABLE}, and a failure here skips the version query rather
 * than reporting twice that an agent nobody can find also has no version.
 *
 * Neither failure is ever resolved by running something else. The hints say so
 * in as many words, because "no agent found" is the one moment a tool could
 * plausibly be expected to reach for whatever else is installed, and a run
 * whose commits were made by an agent the user did not choose is worse than no
 * run (PROJECT.md section E, item 9).
 */
final class AgentCheck implements PreflightCheck
{
    /**
     * Key the resolved executable is remembered under, for
     * {@see AgentVersionCheck}.
     *
     * Remembered rather than searched for twice so the program whose version
     * is read is certainly the one this check passed on: between two searches
     * sits a PATH that a shell profile, a version manager or a test may have
     * changed.
     */
    public const string EXECUTABLE = 'agent.executable';

    public function __construct(private readonly ExecutableFinder $executables = new ExecutableFinder())
    {
    }

    public function id(): CheckId
    {
        return CheckId::Agent;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $agent = $context->agent();
        $definition = $agent->definition();
        $executable = $this->executables->find($definition->executable(), $context->projectRoot());

        $problems = [];
        $hints = [];

        if ($executable === null) {
            $problems[] = sprintf('its executable "%s" was not found', $definition->executable());
            $hints = [...$hints, ...$this->executableHints($context, $definition)];
        }

        $missing = $this->missingVariables($definition);

        if ($missing !== []) {
            $problems[] = sprintf(
                '%s %s not set',
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            );
            $hints[] = sprintf(
                'Export %s before running ralph. Ralph PHP reads %s from the environment, so no '
                    . 'credential is written into the configuration.',
                count($missing) === 1 ? 'it' : 'them',
                count($missing) === 1 ? 'it' : 'them',
            );
        }

        $details = $this->details($definition, $executable);

        if ($problems !== []) {
            $hints[] = 'Ralph PHP does not run a different agent in its place.';

            return CheckResult::failed(
                $this->id(),
                sprintf('the agent "%s" cannot be run: %s', $agent->name(), implode('; ', $problems)),
                $details,
                $hints,
            );
        }

        // Only once both halves are satisfied, because a failed check has not
        // established an executable worth passing on. What actually keeps the
        // version query from starting an agent that cannot be run is the
        // dependency {@see CheckId::dependencies()} declares on this check,
        // which skips it outright; this is only about not leaving a half-true
        // fact behind for it.
        $context->remember(self::EXECUTABLE, (string) $executable);

        return CheckResult::passed(
            $this->id(),
            sprintf('%s (adapter "%s") at %s', $agent->name(), $agent->adapter(), (string) $executable),
            $details,
        );
    }

    /**
     * The settings this run will invoke the agent with.
     *
     * Shown whether or not the check passed: a missing executable is often a
     * configuration pointing at the wrong one, and the user cannot see that
     * from the failure alone.
     *
     * @return list<string>
     */
    private function details(AgentDefinition $definition, ?string $executable): array
    {
        $details = [sprintf('configured executable: %s', $definition->executable())];

        if ($executable !== null && $executable !== $definition->executable()) {
            $details[] = sprintf('resolved to: %s', $executable);
        }

        $model = $definition->model();
        if ($model !== null) {
            $details[] = sprintf('model: %s', $model);
        }

        if ($definition->arguments() !== []) {
            $details[] = sprintf('extra arguments: %s', implode(' ', $definition->arguments()));
        }

        $variables = $definition->environment();
        $details[] = $variables === []
            ? 'authentication: no environment variables declared'
            : sprintf('authentication variables: %s', implode(', ', $variables));

        return $details;
    }

    /**
     * Variables the definition declares that the environment does not provide.
     *
     * A variable set to the empty string counts as missing: an exported but
     * empty API key is the shape an unset variable takes in a shell profile or
     * a CI configuration, and no agent would accept it.
     *
     * @return list<string>
     */
    private function missingVariables(AgentDefinition $definition): array
    {
        $missing = [];

        foreach ($definition->environment() as $variable) {
            $value = getenv($variable);

            if (!is_string($value) || trim($value) === '') {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function executableHints(PreflightContext $context, AgentDefinition $definition): array
    {
        $configured = $definition->executable();
        $hints = [];

        if (ExecutableFinder::isSearched($configured)) {
            $hints[] = sprintf('It is in none of the directories in %s.', $this->executables->describeSearchPath());
            $hints[] = sprintf('Install the "%s" CLI, or put it on PATH.', $configured);
        } elseif (is_file($configured)) {
            $hints[] = sprintf('%s exists but is not executable.', $configured);
        } else {
            $hints[] = sprintf('Nothing exists at %s.', $configured);
        }

        $hints[] = sprintf(
            'Set "agents.%s.executable" in %s to its absolute path.',
            $definition->name(),
            $context->configuration()->source() ?? ConfigurationLoader::fileIn($context->projectRoot()),
        );

        if ($context->agent()->isOverride()) {
            $hints[] = 'This agent was selected with "--agent"; omit the option to use the project default.';
        }

        return $hints;
    }
}
