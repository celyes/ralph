<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * A loaded project configuration.
 *
 * Immutable and free of I/O: reading the file is the loader's job, and
 * everything downstream — agent selection, the checklist reader, Git actions,
 * verification, the limits — takes its settings from here.
 *
 * Paths are exposed both as written and resolved against the project root, so
 * messages can quote what the user configured while the runner uses an
 * absolute path.
 */
final class Configuration
{
    /**
     * @param string $projectRoot absolute path to the repository root
     * @param string|null $source absolute path of the file these settings came from, null for defaults
     * @param array<string, AgentDefinition> $agents keyed by agent name
     * @param list<VerificationCommand> $checks in configured order
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $source,
        private readonly string $tasksPath,
        private readonly string $contextPath,
        private readonly string $defaultAgent,
        private readonly array $agents,
        private readonly array $checks,
        private readonly WorkspaceSettings $workspace,
        private readonly GitSettings $git,
        private readonly Limits $limits,
    ) {
    }

    /**
     * Absolute path to the repository the configuration belongs to.
     */
    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * The file these settings were read from, or null when they are defaults.
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * The task checklist path as configured.
     */
    public function tasksPath(): string
    {
        return $this->tasksPath;
    }

    /**
     * Absolute path to the task checklist.
     */
    public function tasksFile(): string
    {
        return $this->resolve($this->tasksPath);
    }

    /**
     * The context file path as configured.
     */
    public function contextPath(): string
    {
        return $this->contextPath;
    }

    /**
     * Absolute path to the context file.
     */
    public function contextFile(): string
    {
        return $this->resolve($this->contextPath);
    }

    /**
     * The agent used when a run does not override it.
     */
    public function defaultAgent(): string
    {
        return $this->defaultAgent;
    }

    /**
     * Every configured agent, keyed by name.
     *
     * @return array<string, AgentDefinition>
     */
    public function agents(): array
    {
        return $this->agents;
    }

    /**
     * One agent's definition, or null when no agent is configured under that
     * name. Resolving which agent a run uses, and reporting an unknown name,
     * belongs to agent selection rather than here.
     */
    public function agent(string $name): ?AgentDefinition
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function agentNames(): array
    {
        return array_keys($this->agents);
    }

    /**
     * The verification commands, in the order they must run.
     *
     * @return list<VerificationCommand>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * What a newly created task worktree needs before the project can be
     * built, tested or worked on in it.
     */
    public function workspace(): WorkspaceSettings
    {
        return $this->workspace;
    }

    public function git(): GitSettings
    {
        return $this->git;
    }

    public function limits(): Limits
    {
        return $this->limits;
    }

    /**
     * The effective configuration as plain data.
     *
     * This is what gets persisted with a run and shown by `status`: it is the
     * merged result, including defaults, rather than the file's literal
     * contents, because that is what the run actually used. It contains no
     * credentials — agent definitions carry only variable names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tasks' => $this->tasksPath,
            'context' => $this->contextPath,
            'agent' => $this->defaultAgent,
            'agents' => array_map(
                static fn (AgentDefinition $agent): array => $agent->toArray(),
                $this->agents,
            ),
            'checks' => array_map(
                static fn (VerificationCommand $check): array => $check->toArray(),
                $this->checks,
            ),
            'workspace' => $this->workspace->toArray(),
            'git' => $this->git->toArray(),
            'limits' => $this->limits->toArray(),
        ];
    }

    /**
     * Resolve a configured path against the project root, leaving an absolute
     * path alone. Not realpath(): the file may legitimately not exist yet.
     */
    private function resolve(string $path): string
    {
        if (Path::isAbsolute($path)) {
            return Path::normalize($path);
        }

        return Path::normalize($this->projectRoot . '/' . $path);
    }
}
