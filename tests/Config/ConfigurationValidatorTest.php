<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Config;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\ConfigurationValidator;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rejecting configuration whose values cannot be used.
 *
 * Driven through the loader rather than against the validator directly,
 * because that is the only way a configuration reaches a run: the point of the
 * item is that no command can start work with unusable settings. It also means
 * every message quotes the real file a user would open.
 *
 * Each test asserts the setting named in the message, since a validation error
 * whose job is to point at one line of JSON is only useful if it points at the
 * right one. {@see self::failure()} asserts the parts every rejection must get
 * right: the file is named and the exit status is the configuration one.
 */
#[CoversClass(ConfigurationValidator::class)]
#[CoversClass(ConfigurationLoader::class)]
final class ConfigurationValidatorTest extends TestCase
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
    public function the_packages_own_defaults_are_valid(): void
    {
        $loader = new ConfigurationLoader();

        // Both the implicit defaults and the document `ralph init` writes: if
        // the validator rejected either, every project would start broken.
        $defaults = $loader->defaults($this->project->root());
        $written = $loader->fromArray(Defaults::toArray(), $this->project->root());

        self::assertSame($defaults->toArray(), $written->toArray());
        self::assertNotSame([], $defaults->checks());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function nonpositiveLimits(): array
    {
        return [
            'iterations' => ['iterations', 0],
            'attempts per task' => ['attempts_per_task', 0],
            'task timeout' => ['task_timeout', -1],
            'run timeout' => ['run_timeout', -30],
        ];
    }

    #[Test]
    #[DataProvider('nonpositiveLimits')]
    public function a_nonpositive_limit_is_rejected(string $setting, int $value): void
    {
        $exception = $this->failure(['limits' => [$setting => $value]]);

        self::assertStringContainsString(
            sprintf('"limits.%s" must be at least 1 ', $setting),
            $exception->getMessage(),
        );
        self::assertStringContainsString((string) $value . ' given.', $exception->getMessage());
        self::assertContains(sprintf('Remove "limits.%s" to use the default.', $setting), $exception->hints());
    }

    #[Test]
    public function a_configured_but_empty_check_list_is_rejected(): void
    {
        $exception = $this->failure(['checks' => []]);

        self::assertStringContainsString(
            '"checks" must list at least one verification command.',
            $exception->getMessage(),
        );
        self::assertContains('Remove the setting to use the default: composer test.', $exception->hints());
    }

    #[Test]
    public function an_empty_check_command_is_rejected(): void
    {
        $exception = $this->failure(['checks' => [['name' => 'tests', 'command' => []]]]);

        self::assertStringContainsString('"checks.0.command" must not be empty.', $exception->getMessage());
    }

    #[Test]
    public function a_blank_argument_inside_a_check_command_is_rejected(): void
    {
        $exception = $this->failure(['checks' => [['command' => ['composer', '  ']]]]);

        self::assertStringContainsString('"checks.0.command.1" must not be blank.', $exception->getMessage());
        self::assertContains('It must be one argument of the command.', $exception->hints());
    }

    #[Test]
    public function a_check_whose_executable_is_an_empty_string_is_rejected(): void
    {
        $exception = $this->failure(['checks' => [['command' => ['', '--version']]]]);

        self::assertStringContainsString('"checks.0.command.0" must not be empty.', $exception->getMessage());
        self::assertContains('It must be the executable to run.', $exception->hints());
    }

    #[Test]
    public function two_checks_cannot_share_a_name(): void
    {
        $exception = $this->failure(['checks' => [
            ['name' => 'tests', 'command' => ['composer', 'test']],
            ['name' => 'tests', 'command' => ['composer', 'test:installation']],
        ]]);

        self::assertStringContainsString(
            '"checks.1.name" repeats the name "tests", already used by checks.0.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function a_check_name_containing_a_path_separator_is_rejected(): void
    {
        $exception = $this->failure(['checks' => [['name' => 'unit/fast', 'command' => ['phpunit']]]]);

        self::assertStringContainsString('"checks.0.name" must not contain a path separator', $exception->getMessage());
        self::assertContains('Check names label status output and log files.', $exception->hints());
    }

    #[Test]
    public function a_nonpositive_check_timeout_is_rejected(): void
    {
        $exception = $this->failure(['checks' => [['command' => ['phpunit'], 'timeout' => 0]]]);

        self::assertStringContainsString('"checks.0.timeout" must be at least 1 second, 0 given.', $exception->getMessage());
    }

    #[Test]
    public function an_empty_task_path_is_rejected(): void
    {
        $exception = $this->failure(['tasks' => '']);

        self::assertStringContainsString('"tasks" must not be empty.', $exception->getMessage());
        self::assertContains('It must be a path to a file inside the project.', $exception->hints());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsOutsideTheProject(): array
    {
        return [
            'parent directory' => ['../tasks.md'],
            'escaping through a subdirectory' => ['docs/../../tasks.md'],
            'absolute elsewhere' => ['/etc/ralph/tasks.md'],
        ];
    }

    #[Test]
    #[DataProvider('pathsOutsideTheProject')]
    public function a_task_file_outside_the_project_is_rejected(string $path): void
    {
        $exception = $this->failure(['tasks' => $path]);

        self::assertStringContainsString(
            sprintf('"tasks" must be inside the project: "%s" resolves to ', $path),
            $exception->getMessage(),
        );
        self::assertContains(
            'Ralph PHP only reads and writes files in the repository it is working on.',
            $exception->hints(),
        );
    }

    #[Test]
    public function a_context_file_inside_the_runtime_directory_is_rejected(): void
    {
        $exception = $this->failure(['context' => Defaults::RUNTIME_DIRECTORY . '/memory/CONTEXT.md']);

        self::assertStringContainsString(
            sprintf('"context" must not be inside the runtime directory %s.', Defaults::RUNTIME_DIRECTORY),
            $exception->getMessage(),
        );
    }

    #[Test]
    public function a_task_path_naming_the_project_directory_is_rejected(): void
    {
        $exception = $this->failure(['tasks' => '.']);

        self::assertStringContainsString(
            '"tasks" must name a file, not the project directory itself.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function the_task_file_and_the_context_file_must_differ(): void
    {
        $exception = $this->failure(['tasks' => 'docs/ralph.md', 'context' => './docs/ralph.md']);

        self::assertStringContainsString('"tasks" and "context" are the same file,', $exception->getMessage());
        self::assertStringContainsString($this->project->path('docs/ralph.md'), $exception->getMessage());
    }

    #[Test]
    public function a_blank_default_agent_is_rejected(): void
    {
        $exception = $this->failure(['agent' => '   ']);

        self::assertStringContainsString('"agent" must not be blank.', $exception->getMessage());
        self::assertContains('It must be the name of a configured agent.', $exception->hints());
    }

    #[Test]
    public function an_agent_name_that_cannot_be_passed_to_the_option_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['my agent' => ['adapter' => 'codex', 'executable' => 'codex']]]);

        self::assertStringContainsString('"agents.my agent" is not a usable agent name.', $exception->getMessage());
        self::assertContains(
            'Agent names are typed as "run --agent NAME": use letters, digits, ".", "_" or "-".',
            $exception->hints(),
        );
    }

    #[Test]
    public function an_adapter_name_that_is_not_an_identifier_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['house' => ['adapter' => 'my adapter', 'executable' => 'house']]]);

        self::assertStringContainsString(
            '"agents.house.adapter" is not a usable adapter name, "my adapter" given.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function a_blank_agent_executable_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['codex' => ['executable' => '']]]);

        self::assertStringContainsString('"agents.codex.executable" must not be empty.', $exception->getMessage());
        self::assertContains('It must be a command name or an absolute path.', $exception->hints());
    }

    #[Test]
    public function an_executable_containing_a_control_character_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['codex' => ['executable' => "codex\n--yolo"]]]);

        self::assertStringContainsString(
            '"agents.codex.executable" must not contain control characters.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function an_agent_model_configured_as_an_empty_string_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['claude' => ['model' => '']]]);

        self::assertStringContainsString('"agents.claude.model" must not be empty.', $exception->getMessage());
    }

    #[Test]
    public function a_blank_agent_argument_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['codex' => ['arguments' => ['--full-auto', '']]]]);

        self::assertStringContainsString('"agents.codex.arguments.1" must not be empty.', $exception->getMessage());
    }

    #[Test]
    public function an_environment_entry_carrying_a_value_is_rejected(): void
    {
        // The mistake worth catching precisely: writing the credential itself
        // into a file that is meant to be committed.
        $exception = $this->failure(['agents' => ['codex' => ['environment' => ['OPENAI_API_KEY=sk-not-a-real-key']]]]);

        self::assertStringContainsString(
            '"agents.codex.environment.0" is not an environment variable name, "OPENAI_API_KEY=...',
            $exception->getMessage(),
        );

        // Quoting the entry back in full would copy the credential into the
        // error message, which is the mistake rather than the report of it.
        self::assertStringNotContainsString('sk-not-a-real-key', $exception->getMessage());
        self::assertStringNotContainsString('sk-not-a-real-key', implode("\n", $exception->hints()));
        self::assertContains(
            'List the name only: Ralph PHP reads the value from the environment when it runs the agent, '
                . 'so no credential has to be written into the configuration.',
            $exception->hints(),
        );
    }

    #[Test]
    public function an_environment_name_the_shell_would_not_accept_is_rejected(): void
    {
        $exception = $this->failure(['agents' => ['codex' => ['environment' => ['CODEX', 'api-key']]]]);

        self::assertStringContainsString(
            '"agents.codex.environment.1" is not an environment variable name, "api-key" given.',
            $exception->getMessage(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function branchPrefixesGitRejects(): array
    {
        return [
            'space' => ['ralph branches/', 'must not contain a space or any of ~ ^ : ? * [ \\'],
            'colon' => ['ralph:', 'must not contain a space or any of ~ ^ : ? * [ \\'],
            'double dot' => ['ralph../', 'must not contain ".."'],
            'reflog entry' => ['ralph@{1}/', 'must not contain "@{"'],
            'leading slash' => ['/ralph/', 'must not contain an empty path segment'],
            'empty segment' => ['ralph//task-', 'must not contain an empty path segment'],
            'leading dash' => ['-ralph/', 'must not start with "-"'],
            'dot segment' => ['ralph/.task-', 'must not contain a segment starting with "."'],
            'lock segment' => ['ralph.lock/', 'must not contain a segment ending with ".lock"'],
        ];
    }

    #[Test]
    #[DataProvider('branchPrefixesGitRejects')]
    public function a_branch_prefix_git_would_reject_is_rejected(string $prefix, string $problem): void
    {
        $exception = $this->failure(['git' => ['branch_prefix' => $prefix]]);

        self::assertStringContainsString(
            sprintf('"git.branch_prefix" %s, "%s" given.', $problem, $prefix),
            $exception->getMessage(),
        );
    }

    #[Test]
    public function an_empty_branch_prefix_is_rejected(): void
    {
        $exception = $this->failure(['git' => ['branch_prefix' => '']]);

        self::assertStringContainsString('"git.branch_prefix" must not be empty.', $exception->getMessage());
        self::assertContains('It must be a prefix for generated branch names.', $exception->hints());
    }

    #[Test]
    public function a_base_reference_containing_whitespace_is_rejected(): void
    {
        $exception = $this->failure(['git' => ['base' => 'my branch']]);

        self::assertStringContainsString(
            '"git.base" must not contain whitespace, "my branch" given.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function a_blank_base_reference_is_rejected(): void
    {
        $exception = $this->failure(['git' => ['base' => ' ']]);

        self::assertStringContainsString('"git.base" must not be blank.', $exception->getMessage());
        self::assertContains('It must be a branch, tag or commit.', $exception->hints());
    }

    #[Test]
    public function a_push_remote_git_would_read_as_an_option_is_rejected(): void
    {
        $exception = $this->failure(['git' => ['push' => ['enabled' => true, 'remote' => '--force']]]);

        self::assertStringContainsString(
            '"git.push.remote" must not start with "-", "--force" given.',
            $exception->getMessage(),
        );
        self::assertContains('Git would read it as a command-line option.', $exception->hints());
    }

    #[Test]
    public function an_empty_push_remote_is_rejected_even_when_pushing_is_disabled(): void
    {
        $exception = $this->failure(['git' => ['push' => ['remote' => '']]]);

        self::assertStringContainsString('"git.push.remote" must not be empty.', $exception->getMessage());
    }

    /**
     * Load a configuration that must be rejected, returning the failure.
     *
     * The assertions made for every case live here: the message names the file
     * the user has to edit, and the status is the one documented for a
     * configuration problem, so nothing reaches a run.
     *
     * @param array<string, mixed> $settings
     */
    private function failure(array $settings): ConfigurationException
    {
        $file = $this->project->writeConfigArray($settings);

        try {
            $configuration = (new ConfigurationLoader())->load($this->project->root());
        } catch (ConfigurationException $exception) {
            self::assertStringStartsWith($file . ': ', $exception->getMessage());
            self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());

            return $exception;
        }

        self::fail(sprintf(
            'Expected %s to be rejected, got %s.',
            json_encode($settings, JSON_THROW_ON_ERROR),
            json_encode($configuration->toArray(), JSON_THROW_ON_ERROR),
        ));
    }
}
