<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\AdapterRegistrationFile;
use Celyes\RalphPhp\Agent\AdapterRegistry;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a project gets its own adapter into a run: `.ralph/adapters.php`.
 *
 * The documented half of PROJECT.md section E, item 3, and these tests use real
 * files because that is what the behaviour is: a file that is absent, that
 * returns the wrong thing, that cannot be parsed, or that is a directory are
 * four different reports, and a double would only confirm that `require` was
 * called. The end-to-end test is the item's own wording — an agent for an
 * adapter this package has never heard of, selected and executed with nothing
 * in the package naming it, and nothing edited in the runner to allow it.
 */
#[CoversClass(AdapterRegistrationFile::class)]
final class AdapterRegistrationFileTest extends TestCase
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
    public function a_project_without_the_file_registers_nothing_and_is_not_an_error(): void
    {
        // Every project that uses only the adapters the package ships.
        $file = AdapterRegistrationFile::in($this->project->root());
        $registry = new AdapterRegistry();

        self::assertFalse($file->exists());
        self::assertSame([], $file->registerInto($registry));
        self::assertSame([], $registry->names());
    }

    #[Test]
    public function the_file_is_read_from_one_fixed_path_inside_the_project(): void
    {
        // Fixed rather than configurable: it is code the run executes, so no
        // setting may redirect which code that is.
        self::assertSame('.ralph/adapters.php', Defaults::ADAPTERS_FILE);
        self::assertSame(
            $this->project->path(Defaults::ADAPTERS_FILE),
            AdapterRegistrationFile::in($this->project->root())->path(),
        );
        self::assertSame('/tmp/elsewhere.php', AdapterRegistrationFile::at('/tmp/elsewhere.php')->path());
    }

    #[Test]
    public function the_providers_the_file_returns_are_registered_in_its_own_order(): void
    {
        $file = $this->writeRegistrationFile(
            "return [\n"
            . "    new {$this->providerClass()}('zeta'),\n"
            . "    new {$this->providerClass()}('acme'),\n"
            . "];",
        );
        $registry = new AdapterRegistry();

        self::assertTrue($file->exists());
        self::assertSame(['zeta', 'acme'], $file->registerInto($registry));
        self::assertSame(['zeta', 'acme'], $registry->names());
    }

    #[Test]
    public function keys_are_ignored_because_each_provider_names_its_own_adapter(): void
    {
        $file = $this->writeRegistrationFile(
            "return ['ignored' => new {$this->providerClass()}('acme')];",
        );
        $registry = new AdapterRegistry();

        self::assertSame(['acme'], $file->registerInto($registry));
        self::assertTrue($registry->has('acme'));
        self::assertFalse($registry->has('ignored'));
    }

    #[Test]
    public function an_empty_array_registers_nothing(): void
    {
        $registry = new AdapterRegistry();

        self::assertSame([], $this->writeRegistrationFile('return [];')->registerInto($registry));
        self::assertSame([], $registry->names());
    }

    #[Test]
    public function a_file_that_does_not_return_an_array_is_reported_with_what_it_did_return(): void
    {
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile("return 'Acme\\Provider';")->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('must return an array, string returned', $failure->getMessage());
        self::assertStringContainsString($this->path(), $failure->getMessage());
        self::assertSame(ExitCode::CONFIGURATION, $failure->exitCode());
        self::assertStringContainsString(AdapterProvider::class, implode("\n", $failure->hints()));
    }

    #[Test]
    public function a_file_that_returns_nothing_at_all_is_reported_the_same_way(): void
    {
        // A file with no `return` leaves `require` with its own value, 1. The
        // mistake is forgetting the return, and the report has to be readable
        // rather than a type nobody wrote.
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile('$providers = [];')->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('must return an array, int returned', $failure->getMessage());
    }

    #[Test]
    public function an_entry_that_is_not_a_provider_is_reported_with_its_position(): void
    {
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile(
                "return [new {$this->providerClass()}('acme'), new \\stdClass()];",
            )->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('returned stdClass at index 1, not ' . AdapterProvider::class, $failure->getMessage());
    }

    #[Test]
    public function a_string_keyed_entry_that_is_not_a_provider_is_named_by_its_key(): void
    {
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile("return ['acme' => 'Acme\\Provider'];")
                ->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('returned string at key "acme"', $failure->getMessage());
    }

    #[Test]
    public function an_error_raised_while_loading_is_reported_as_the_files_failure(): void
    {
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile("throw new \\RuntimeException('no credentials');")
                ->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('could not be loaded', $failure->getMessage());
        self::assertStringContainsString('RuntimeException: no credentials', implode("\n", $failure->hints()));
    }

    #[Test]
    public function a_class_the_file_cannot_load_points_at_the_projects_autoloader(): void
    {
        // The commonest way this file fails, and the only one whose cause is
        // not in the file itself: installed as a global tool, Ralph PHP runs
        // under its own autoloader and knows nothing of the project's classes.
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile('return [new \\Acme\\Ralph\\MissingProvider()];')
                ->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('could not be loaded', $failure->getMessage());

        $hints = implode("\n", $failure->hints());
        self::assertStringContainsString('Acme\\Ralph\\MissingProvider', $hints);
        self::assertStringContainsString('load the project\'s autoloader itself', $hints);
    }

    #[Test]
    public function a_parse_error_in_the_file_is_reported_rather_than_crashing_the_run(): void
    {
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile('return [ this is not php')->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('could not be loaded', $failure->getMessage());
        self::assertStringContainsString('ParseError', implode("\n", $failure->hints()));
        self::assertSame(ExitCode::CONFIGURATION, $failure->exitCode());
    }

    #[Test]
    public function a_directory_at_the_path_is_reported_instead_of_being_required(): void
    {
        // Checked before loading rather than caught after: a `require` that
        // cannot open its file is a fatal compile error no `catch` reaches.
        $path = $this->project->path(Defaults::ADAPTERS_FILE);
        self::assertTrue(mkdir($path, 0o700, true));

        $failure = $this->failure(
            fn () => AdapterRegistrationFile::in($this->project->root())->registerInto(new AdapterRegistry()),
        );

        self::assertStringContainsString('cannot be read', $failure->getMessage());
        self::assertStringContainsString('It is a directory.', implode("\n", $failure->hints()));
    }

    #[Test]
    public function an_unreadable_file_is_a_different_report_from_having_no_file(): void
    {
        $file = $this->writeRegistrationFile('return [];');
        $path = $this->path();

        if (!chmod($path, 0o000) || is_readable($path)) {
            chmod($path, 0o600);
            self::markTestSkipped('This user can read a file with no permissions.');
        }

        try {
            $failure = $this->failure(fn () => $file->registerInto(new AdapterRegistry()));
        } finally {
            chmod($path, 0o600);
        }

        self::assertStringContainsString('cannot be read', $failure->getMessage());
        self::assertStringContainsString('Check its permissions.', implode("\n", $failure->hints()));
    }

    #[Test]
    public function a_name_the_registry_already_holds_is_refused_and_names_both_providers(): void
    {
        $registry = new AdapterRegistry();
        $registry->register(new NamedAdapterProvider('acme'));

        $failure = $this->failure(
            fn () => $this->writeRegistrationFile("return [new {$this->providerClass()}('acme')];")
                ->registerInto($registry),
        );

        self::assertSame('The adapter "acme" is already registered.', $failure->getMessage());
        self::assertStringContainsString(NamedAdapterProvider::class, implode("\n", $failure->hints()));
    }

    #[Test]
    public function a_file_cannot_put_its_own_code_behind_an_adapter_the_package_ships(): void
    {
        // The name is what a run's attempts, logs and commits are attributed
        // to, so a project that could claim "codex" would produce a history
        // saying Codex did work Codex never did.
        $failure = $this->failure(
            fn () => $this->writeRegistrationFile(
                sprintf("return [new %s('%s')];", $this->providerClass(), BuiltInAdapters::CODEX),
            )->registerInto(AdapterRegistry::default()),
        );

        self::assertStringContainsString('is reserved for the adapter Ralph PHP ships', $failure->getMessage());
    }

    #[Test]
    public function the_file_is_loaded_once_per_process_however_often_it_is_registered(): void
    {
        // `require` executes a file every time, so a second load of one that
        // declares a class would be a fatal redeclaration — and `require_once`
        // cannot be used instead, because its second call returns true rather
        // than the file's value and there would be nothing left to validate.
        // This file declares a class and counts its own executions: if the
        // value were not remembered, the assertions below would never run.
        $receipt = $this->project->path('loads.txt');
        $file = $this->writeRegistrationFile(
            sprintf("file_put_contents(%s, 'x', FILE_APPEND);\n", var_export($receipt, true))
            . "\n"
            . "final class RalphDeclaredOnce\n"
            . "{\n"
            . "}\n"
            . "\n"
            . "return [new {$this->providerClass()}('acme')];",
        );

        $first = new AdapterRegistry();
        $second = new AdapterRegistry();

        self::assertSame(['acme'], $file->registerInto($first));
        self::assertSame(['acme'], $file->registerInto($second));
        self::assertSame(['acme'], AdapterRegistrationFile::in($this->project->root())->registerInto(new AdapterRegistry()));

        self::assertSame('x', file_get_contents($receipt));
        self::assertSame('acme', $second->create($this->selection())->name());
    }

    #[Test]
    public function an_agent_the_package_has_never_heard_of_runs_through_the_file_alone(): void
    {
        $this->writeRegistrationFile("return [new {$this->providerClass()}('acme')];");
        $this->project->writeConfigArray([
            'agent' => 'mine',
            'agents' => ['mine' => ['adapter' => 'acme', 'executable' => '/usr/bin/acme']],
        ]);

        $registry = AdapterRegistry::default();
        AdapterRegistrationFile::in($this->project->root())->registerInto($registry);

        $configuration = (new ConfigurationLoader())->load($this->project->root());
        $selection = (new AgentSelector($registry))->select($configuration);
        $adapter = $registry->create($selection);

        self::assertSame('acme', $selection->adapter());
        self::assertSame('acme', $adapter->name());
    }

    /**
     * The project's registration file, written with the given body.
     */
    private function writeRegistrationFile(string $body): AdapterRegistrationFile
    {
        $this->project->write(
            Defaults::ADAPTERS_FILE,
            "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n",
        );

        return AdapterRegistrationFile::in($this->project->root());
    }

    private function path(): string
    {
        return $this->project->path(Defaults::ADAPTERS_FILE);
    }

    /**
     * The provider a fixture file instantiates, as it has to be written there:
     * fully qualified, because the file has no `use` statements of its own.
     */
    private function providerClass(): string
    {
        return '\\' . NamedAdapterProvider::class;
    }

    private function selection(string $adapter = 'acme', string $agent = 'mine'): AgentSelection
    {
        return new AgentSelection(
            new AgentDefinition($agent, $adapter, 'acme-cli'),
            AgentSource::ProjectDefault,
        );
    }

    private function failure(callable $call): ConfigurationException
    {
        try {
            $call();
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('Expected a configuration failure.');
    }
}
