<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Ralph;

/**
 * Reads `.ralph/config.json` into a {@see Configuration}.
 *
 * Settings the file omits come from {@see Defaults}, so a project can configure
 * only what differs from the package's assumptions and an empty `{}` is a valid
 * file. The merge is written out setting by setting rather than done by a
 * generic deep merge, because the sections do not merge alike: an agent's
 * entry inherits the built-in definition of the same name field by field, while
 * `checks` is replaced wholesale — a user who lists two checks means those two,
 * not those two plus the package's default.
 *
 * The loader checks shape only. Whether the values make sense — positive
 * limits, non-empty commands, paths inside the repository, an adapter that is
 * actually registered — is the validator's responsibility, and preflight checks
 * whether the environment can satisfy them.
 */
final class ConfigurationLoader
{
    /**
     * Settings recognised at the top level of the file.
     *
     * @var list<string>
     */
    private const array KNOWN_KEYS = ['tasks', 'context', 'agent', 'agents', 'checks', 'git', 'limits'];

    /**
     * Path of the configuration file for a project.
     */
    public static function fileIn(string $projectRoot): string
    {
        return Path::normalize($projectRoot . '/' . Ralph::PROJECT_DIRECTORY . '/config.json');
    }

    /**
     * Load the configuration of the project rooted at the given directory.
     *
     * @throws ConfigurationException when the directory, the file, the JSON, or
     *                                a setting's type is unusable
     */
    public function load(string $projectRoot): Configuration
    {
        $root = $this->resolveProjectRoot($projectRoot);
        $file = self::fileIn($root);

        if (!is_file($file)) {
            throw new ConfigurationException(
                sprintf('No configuration file at %s.', $file),
                ['Run "ralph init" in the project to create it.'],
            );
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ConfigurationException(
                sprintf('Unable to read %s.', $file),
                ['Check the file\'s permissions.'],
            );
        }

        return $this->fromRaw(RawConfiguration::fromJson($contents, $file), $root, $file);
    }

    /**
     * Load the configuration if the project has one, otherwise fall back to the
     * defaults.
     *
     * For commands that should still work in a project that was never
     * initialised, such as reporting status.
     */
    public function loadOrDefaults(string $projectRoot): Configuration
    {
        $root = $this->resolveProjectRoot($projectRoot);

        if (!is_file(self::fileIn($root))) {
            return $this->defaults($root);
        }

        return $this->load($root);
    }

    /**
     * Build a configuration from already-decoded data.
     *
     * The path through which tests and `init` produce a configuration without a
     * file on disk.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data, string $projectRoot, ?string $source = null): Configuration
    {
        $root = $this->resolveProjectRoot($projectRoot);

        return $this->fromRaw(
            RawConfiguration::fromArray($data, $source ?? 'the configuration'),
            $root,
            $source,
        );
    }

    /**
     * The configuration a project with no settings of its own would get.
     */
    public function defaults(string $projectRoot): Configuration
    {
        return $this->fromArray([], $projectRoot, null);
    }

    /**
     * Merge one document over the defaults and build the value objects.
     */
    private function fromRaw(RawConfiguration $raw, string $projectRoot, ?string $source): Configuration
    {
        $raw->rejectUnknownKeys(self::KNOWN_KEYS);

        return new Configuration(
            $projectRoot,
            $source,
            $raw->string('tasks', Defaults::TASKS_FILE),
            $raw->string('context', Defaults::CONTEXT_FILE),
            $raw->string('agent', Defaults::AGENT),
            $this->agents($raw),
            $this->checks($raw),
            GitSettings::fromRaw($raw->section('git')),
            Limits::fromRaw($raw->section('limits')),
        );
    }

    /**
     * The configured agents, with the built-in ones always available.
     *
     * A project entry named after a built-in agent overrides only the fields it
     * states, so pointing Codex at a different executable does not require
     * repeating its adapter.
     *
     * @return array<string, AgentDefinition>
     */
    private function agents(RawConfiguration $raw): array
    {
        $agents = [];
        foreach (array_keys(Defaults::agents()) as $name) {
            $agents[$name] = AgentDefinition::builtIn($name);
        }

        foreach ($raw->sectionMap('agents') as $name => $definition) {
            $agents[$name] = AgentDefinition::fromRaw($name, $definition);
        }

        return $agents;
    }

    /**
     * The configured verification commands, or the defaults when the file does
     * not mention them.
     *
     * @return list<VerificationCommand>
     */
    private function checks(RawConfiguration $raw): array
    {
        $configured = $raw->sectionList('checks');

        if ($configured === null) {
            $configured = array_map(
                static fn (array $check): RawConfiguration => RawConfiguration::fromArray(
                    $check,
                    'the built-in defaults',
                ),
                Defaults::checks(),
            );
        }

        return array_map(
            static fn (RawConfiguration $check): VerificationCommand => VerificationCommand::fromRaw($check),
            $configured,
        );
    }

    /**
     * Resolve the project root to an absolute, symlink-free path.
     */
    private function resolveProjectRoot(string $projectRoot): string
    {
        if ($projectRoot === '') {
            throw new ConfigurationException('No project directory was given.');
        }

        $resolved = realpath($projectRoot);

        if ($resolved === false || !is_dir($resolved)) {
            throw new ConfigurationException(sprintf('The project directory %s does not exist.', $projectRoot));
        }

        return Path::normalize($resolved);
    }
}
