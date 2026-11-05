<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Config;

use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\GitSettings;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Config\PushSettings;
use Celyes\RalphPhp\Config\RawConfiguration;
use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Loading `.ralph/config.json`.
 *
 * Covers the three things that can go wrong in a way the user has to be told
 * about: a file that cannot be read at all, a setting with an unusable type,
 * and a setting that was silently misspelled. The rest asserts that the
 * documented defaults and the merge behaviour are what the runner receives.
 */
#[CoversClass(ConfigurationLoader::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(RawConfiguration::class)]
#[CoversClass(AgentDefinition::class)]
#[CoversClass(VerificationCommand::class)]
#[CoversClass(GitSettings::class)]
#[CoversClass(PushSettings::class)]
#[CoversClass(Limits::class)]
#[CoversClass(Defaults::class)]
final class ConfigurationLoaderTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function an_empty_object_loads_the_documented_defaults(): void
    {
        $this->project->writeConfig('{}');

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertSame(Defaults::TASKS_FILE, $config->tasksPath());
        self::assertSame(Defaults::CONTEXT_FILE, $config->contextPath());
        self::assertSame(Defaults::AGENT, $config->defaultAgent());
        self::assertSame(['codex', 'claude'], $config->agentNames());
        self::assertSame(Defaults::BASE_REFERENCE, $config->git()->baseReference());
        self::assertSame(Defaults::BRANCH_PREFIX, $config->git()->branchPrefix());
        self::assertFalse($config->git()->push()->isEnabled());
        self::assertSame(Defaults::PUSH_REMOTE, $config->git()->push()->remote());
        self::assertSame(Defaults::ITERATIONS, $config->limits()->iterations());
        self::assertSame(Defaults::ATTEMPTS_PER_TASK, $config->limits()->attemptsPerTask());
        self::assertSame(Defaults::TASK_TIMEOUT_SECONDS, $config->limits()->taskTimeout());
        self::assertSame(Defaults::RUN_TIMEOUT_SECONDS, $config->limits()->runTimeout());

        self::assertCount(1, $config->checks());
        self::assertSame(['composer', 'test'], $config->checks()[0]->command());

        $workspace = $config->workspace();
        self::assertCount(1, $workspace->prepare());
        self::assertSame(['composer', 'install', '--no-interaction'], $workspace->prepare()[0]->command());
        self::assertSame(Defaults::PREPARE_TIMEOUT_SECONDS, $workspace->prepare()[0]->timeout());
        self::assertSame([], $workspace->copy());
        self::assertFalse($workspace->isEmpty());
    }

    #[Test]
    public function every_documented_setting_is_read_from_the_file(): void
    {
        $this->project->writeConfigArray([
            'tasks' => 'docs/work.md',
            'context' => 'docs/context.md',
            'agent' => 'claude',
            'agents' => [
                'claude' => [
                    'adapter' => 'claude',
                    'executable' => '/opt/claude/bin/claude',
                    'model' => 'claude-opus-5',
                    'arguments' => ['--permission-mode', 'acceptEdits'],
                    'environment' => ['ANTHROPIC_API_KEY'],
                ],
            ],
            'checks' => [
                ['name' => 'unit', 'command' => ['vendor/bin/phpunit', '--testsuite', 'unit'], 'timeout' => 120],
                ['command' => 'phpstan'],
            ],
            'workspace' => [
                'prepare' => [
                    ['name' => 'dependencies', 'command' => ['composer', 'install'], 'timeout' => 300],
                    ['command' => 'npm'],
                ],
                'copy' => ['phpunit.xml', 'config/local.php'],
            ],
            'git' => [
                'base' => 'origin/main',
                'branch_prefix' => 'agent/',
                'push' => ['enabled' => true, 'remote' => 'upstream'],
            ],
            'limits' => [
                'iterations' => 3,
                'attempts_per_task' => 2,
                'task_timeout' => 900,
                'run_timeout' => 3600,
            ],
        ]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertSame('docs/work.md', $config->tasksPath());
        self::assertSame('docs/context.md', $config->contextPath());
        self::assertSame('claude', $config->defaultAgent());

        $claude = $config->agent('claude');
        self::assertInstanceOf(AgentDefinition::class, $claude);
        self::assertSame('claude', $claude->adapter());
        self::assertSame('/opt/claude/bin/claude', $claude->executable());
        self::assertSame('claude-opus-5', $claude->model());
        self::assertSame(['--permission-mode', 'acceptEdits'], $claude->arguments());
        self::assertSame(['ANTHROPIC_API_KEY'], $claude->environment());

        self::assertSame('origin/main', $config->git()->baseReference());
        self::assertSame('agent/', $config->git()->branchPrefix());
        self::assertTrue($config->git()->push()->isEnabled());
        self::assertSame('upstream', $config->git()->push()->remote());

        self::assertSame(3, $config->limits()->iterations());
        self::assertSame(2, $config->limits()->attemptsPerTask());
        self::assertSame(900, $config->limits()->taskTimeout());
        self::assertSame(3600, $config->limits()->runTimeout());

        self::assertCount(2, $config->checks());
        self::assertSame('unit', $config->checks()[0]->name());
        self::assertSame(['vendor/bin/phpunit', '--testsuite', 'unit'], $config->checks()[0]->command());
        self::assertSame(120, $config->checks()[0]->timeout());

        // A one-word command may be written as a string; the name falls back to
        // the executable and the timeout to the default.
        self::assertSame(['phpstan'], $config->checks()[1]->command());
        self::assertSame('phpstan', $config->checks()[1]->name());
        self::assertSame(Defaults::CHECK_TIMEOUT_SECONDS, $config->checks()[1]->timeout());

        $workspace = $config->workspace();
        self::assertCount(2, $workspace->prepare());
        self::assertSame('dependencies', $workspace->prepare()[0]->name());
        self::assertSame(['composer', 'install'], $workspace->prepare()[0]->command());
        self::assertSame(300, $workspace->prepare()[0]->timeout());

        // A preparation command takes the same shorthand a check does.
        self::assertSame(['npm'], $workspace->prepare()[1]->command());
        self::assertSame('npm', $workspace->prepare()[1]->name());
        self::assertSame(Defaults::PREPARE_TIMEOUT_SECONDS, $workspace->prepare()[1]->timeout());

        self::assertSame(['phpunit.xml', 'config/local.php'], $workspace->copy());
    }

    #[Test]
    public function configured_paths_are_resolved_against_the_project_root(): void
    {
        // An absolute path is used as written rather than joined to the root;
        // it has to stay inside the project, which the validator enforces.
        $absoluteContext = $this->project->path('docs/CONTEXT.md');
        $this->project->writeConfigArray(['tasks' => 'docs/./work.md', 'context' => $absoluteContext]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertSame($this->project->path('docs/work.md'), $config->tasksFile());
        self::assertSame($absoluteContext, $config->contextFile());
        self::assertSame($this->project->root(), $config->projectRoot());
        self::assertSame($this->project->path('.ralph/config.json'), $config->source());
    }

    #[Test]
    public function an_agent_entry_overrides_only_the_fields_it_states(): void
    {
        $this->project->writeConfigArray(['agents' => ['codex' => ['executable' => '/usr/local/bin/codex']]]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        $codex = $config->agent('codex');
        self::assertInstanceOf(AgentDefinition::class, $codex);
        self::assertSame('/usr/local/bin/codex', $codex->executable());
        self::assertSame('codex', $codex->adapter());

        // The other built-in agent is still available and untouched.
        $claude = $config->agent('claude');
        self::assertInstanceOf(AgentDefinition::class, $claude);
        self::assertSame('claude', $claude->executable());
    }

    #[Test]
    public function an_additional_agent_is_loaded_alongside_the_built_in_ones(): void
    {
        $this->project->writeConfigArray([
            'agents' => ['local' => ['adapter' => 'process', 'executable' => './bin/worker']],
        ]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertSame(['codex', 'claude', 'local'], $config->agentNames());

        $local = $config->agent('local');
        self::assertInstanceOf(AgentDefinition::class, $local);
        self::assertSame('process', $local->adapter());
        self::assertSame('local', $local->name());
        self::assertNull($local->model());
        self::assertSame([], $local->arguments());
    }

    #[Test]
    public function an_additional_agent_must_name_its_adapter(): void
    {
        $this->project->writeConfigArray(['agents' => ['local' => ['executable' => './bin/worker']]]);

        $exception = $this->loadFailure();

        self::assertStringContainsString('"agents.local.adapter" is required.', $exception->getMessage());
    }

    #[Test]
    public function configured_checks_replace_the_defaults_rather_than_adding_to_them(): void
    {
        $this->project->writeConfigArray(['checks' => [['command' => ['make', 'ci']]]]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertCount(1, $config->checks());
        self::assertSame('make ci', $config->checks()[0]->describe());
    }

    #[Test]
    public function configured_preparation_commands_replace_the_defaults_rather_than_adding_to_them(): void
    {
        $this->project->writeConfigArray(['workspace' => ['prepare' => [['command' => ['make', 'setup']]]]]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertCount(1, $config->workspace()->prepare());
        self::assertSame('make setup', $config->workspace()->prepare()[0]->describe());
    }

    #[Test]
    public function an_empty_prepare_list_is_how_a_project_says_a_plain_checkout_is_usable(): void
    {
        // Distinct from omitting the setting, which gets the default install.
        $this->project->writeConfigArray(['workspace' => ['prepare' => []]]);

        $config = (new ConfigurationLoader())->load($this->project->root());

        self::assertSame([], $config->workspace()->prepare());
        self::assertTrue($config->workspace()->isEmpty());
    }

    #[Test]
    public function an_unknown_workspace_setting_is_reported_with_its_section(): void
    {
        $this->project->writeConfigArray(['workspace' => ['copies' => ['.env']]]);

        $exception = $this->loadFailure();

        self::assertStringContainsString('unknown setting "workspace.copies"', $exception->getMessage());
        self::assertStringContainsString(
            'Settings recognised in "workspace": prepare, copy.',
            implode("\n", $exception->hints()),
        );
    }

    #[Test]
    public function a_missing_configuration_file_points_at_the_init_command(): void
    {
        $exception = $this->loadFailure();

        self::assertStringContainsString('No configuration file at', $exception->getMessage());
        self::assertStringContainsString($this->project->path('.ralph/config.json'), $exception->getMessage());
        self::assertContains('Run "ralph init" in the project to create it.', $exception->hints());
        self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
    }

    #[Test]
    public function the_defaults_are_used_when_a_project_has_no_configuration_file(): void
    {
        $config = (new ConfigurationLoader())->loadOrDefaults($this->project->root());

        self::assertNull($config->source());
        self::assertSame(Defaults::AGENT, $config->defaultAgent());
        self::assertSame($this->project->path('.ralph/tasks.md'), $config->tasksFile());
    }

    #[Test]
    public function invalid_json_is_reported_with_the_file_path(): void
    {
        $this->project->writeConfig('{"agent": "codex",}');

        $exception = $this->loadFailure();

        self::assertStringContainsString($this->project->path('.ralph/config.json'), $exception->getMessage());
        self::assertStringContainsString('is not valid JSON', $exception->getMessage());
    }

    #[Test]
    public function an_empty_file_is_rejected(): void
    {
        $this->project->writeConfig("\n");

        self::assertStringContainsString('is empty.', $this->loadFailure()->getMessage());
    }

    #[Test]
    public function a_document_that_is_not_an_object_is_rejected(): void
    {
        $this->project->writeConfig('["codex"]');

        self::assertStringContainsString(
            'must contain a JSON object, an array given.',
            $this->loadFailure()->getMessage(),
        );
    }

    #[Test]
    public function a_setting_of_the_wrong_type_names_the_setting_and_both_types(): void
    {
        $this->project->writeConfigArray(['limits' => ['iterations' => 'ten']]);

        self::assertStringContainsString(
            '"limits.iterations" must be an integer, a string given.',
            $this->loadFailure()->getMessage(),
        );
    }

    #[Test]
    public function a_push_flag_written_as_a_string_is_not_coerced(): void
    {
        $this->project->writeConfigArray(['git' => ['push' => ['enabled' => 'true']]]);

        self::assertStringContainsString(
            '"git.push.enabled" must be true or false, a string given.',
            $this->loadFailure()->getMessage(),
        );
    }

    #[Test]
    public function a_section_written_as_a_scalar_is_rejected(): void
    {
        $this->project->writeConfigArray(['git' => 'main']);

        self::assertStringContainsString(
            '"git" must be a JSON object, a string given.',
            $this->loadFailure()->getMessage(),
        );
    }

    #[Test]
    public function a_non_string_element_in_a_list_names_its_position(): void
    {
        $this->project->writeConfigArray([
            'agents' => ['codex' => ['arguments' => ['--model', 7]]],
        ]);

        self::assertStringContainsString(
            '"agents.codex.arguments.1" must be a string, an integer given.',
            $this->loadFailure()->getMessage(),
        );
    }

    #[Test]
    public function a_misspelled_setting_is_rejected_with_a_suggestion(): void
    {
        $this->project->writeConfigArray(['agnet' => 'codex']);

        $exception = $this->loadFailure();

        self::assertStringContainsString('unknown setting "agnet".', $exception->getMessage());
        self::assertContains('Did you mean "agent"?', $exception->hints());
    }

    #[Test]
    public function a_misspelled_nested_setting_is_rejected_with_its_full_path(): void
    {
        $this->project->writeConfigArray(['limits' => ['iteration' => 4]]);

        $exception = $this->loadFailure();

        self::assertStringContainsString('unknown setting "limits.iteration".', $exception->getMessage());
        self::assertContains('Did you mean "iterations"?', $exception->hints());
        self::assertStringContainsString('recognised in "limits"', implode("\n", $exception->hints()));
    }

    #[Test]
    public function a_missing_project_directory_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new ConfigurationLoader())->load($this->project->path('nope'));
    }

    #[Test]
    public function the_effective_configuration_round_trips_through_the_loader(): void
    {
        $loader = new ConfigurationLoader();

        // Defaults::toArray() is what `ralph init` writes. Loading it must give
        // exactly the configuration an empty file gives, or an initialised
        // project would behave differently from an unconfigured one.
        $fromDefaultsFile = $loader->fromArray(Defaults::toArray(), $this->project->root());

        self::assertSame(
            $loader->defaults($this->project->root())->toArray(),
            $fromDefaultsFile->toArray(),
        );

        // And the effective configuration is itself valid input.
        $reloaded = $loader->fromArray($fromDefaultsFile->toArray(), $this->project->root());
        self::assertSame($fromDefaultsFile->toArray(), $reloaded->toArray());
    }

    #[Test]
    public function the_persisted_configuration_carries_variable_names_rather_than_values(): void
    {
        $this->project->writeConfigArray([
            'agents' => ['codex' => ['environment' => ['OPENAI_API_KEY']]],
        ]);

        $persisted = json_encode(
            (new ConfigurationLoader())->load($this->project->root())->toArray(),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringContainsString('OPENAI_API_KEY', $persisted);
        self::assertStringNotContainsString('sk-', $persisted);
    }

    /**
     * Load the project's configuration, expecting it to be rejected.
     */
    private function loadFailure(): ConfigurationException
    {
        try {
            (new ConfigurationLoader())->load($this->project->root());
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('The configuration was expected to be rejected.');
    }
}
