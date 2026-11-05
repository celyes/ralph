<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\PreparationCommand;
use Celyes\RalphPhp\Config\WorkspaceSettings;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\PreparationException;
use Celyes\RalphPhp\Git\BaseCommit;
use Celyes\RalphPhp\Git\BaseResolver;
use Celyes\RalphPhp\Git\PreparationStep;
use Celyes\RalphPhp\Git\TaskWorktree;
use Celyes\RalphPhp\Git\TaskWorktrees;
use Celyes\RalphPhp\Git\WorkspacePreparation;
use Celyes\RalphPhp\Git\WorkspacePreparer;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Log\RecordingSink;
use Celyes\RalphPhp\Tests\Support\Composer;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Making a new task worktree usable, against real repositories and real
 * processes.
 *
 * The whole item exists because of what a linked worktree *is*: a checkout of
 * tracked files, with no `vendor/` and none of the local files Git never
 * carried. So these tests create the worktree with {@see TaskWorktrees} rather
 * than a fixture directory — the absence being compensated for is Git's
 * behaviour, and a directory assembled by the test would be a directory the
 * test had already prepared.
 *
 * The preparation commands are small programs this repository writes, not
 * `composer`: what is under test is that a configured command runs *in the
 * workspace* with its argument array intact, and a real install would test the
 * network. Each program records the directory it ran in, which is the assertion
 * that matters most here — a prepare command that ran in the controlling
 * checkout would install into the user's own directory.
 *
 * Two assertions recur. The controlling checkout is byte-for-byte unchanged by
 * everything, including every refusal; and a refusal happens before any command
 * runs, which is asserted from the absence of the marker those programs leave.
 */
#[CoversClass(WorkspacePreparer::class)]
#[CoversClass(WorkspacePreparation::class)]
#[CoversClass(PreparationStep::class)]
#[CoversClass(PreparationException::class)]
#[CoversClass(WorkspaceSettings::class)]
#[CoversClass(PreparationCommand::class)]
final class WorkspacePreparerTest extends TestCase
{
    /**
     * Where a preparation program records the directory it ran in, relative to
     * that directory.
     */
    private const string MARKER = 'prepared-in.txt';

    private TemporaryProject $project;

    private Environment $environment;

    /**
     * The throwaway Composer home, when a test needed one.
     */
    private ?string $composerHome = null;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this prepares real linked worktrees.');
        }

        $this->project = TemporaryProject::create();
        $this->environment = new Environment();
        $this->givenACommittedProject();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();

        if ($this->composerHome !== null) {
            TemporaryDirectory::remove($this->composerHome);
            $this->composerHome = null;
        }

        Git::tryRun($this->project->root(), 'worktree', 'prune');
        $this->project->remove();
    }

    #[Test]
    public function the_default_preparation_installs_what_a_new_worktree_does_not_have(): void
    {
        if (Composer::binary() === null) {
            self::markTestSkipped('Composer is not installed, and this is about the default "composer install".');
        }

        // A package with no dependencies: the install is real, and it needs no
        // network, so this asserts the default command rather than Packagist.
        $this->project->write('composer.json', json_encode([
            'name' => 'example/project',
            'autoload' => ['psr-4' => ['Example\\' => 'src/']],
            'scripts' => ['test' => 'phpunit'],
        ], JSON_THROW_ON_ERROR));
        $this->project->write('src/Greeting.php', "<?php\n\nnamespace Example;\n\nfinal class Greeting\n{\n}\n");
        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root(), 'Add the example project');

        $this->givenAComposerHomeOfItsOwn();

        $workspace = $this->givenAWorkspace();

        // The point of the whole item: Git checked out the tracked files, so
        // the autoloader the project's tests bootstrap is not there yet.
        self::assertFileExists($workspace->path() . '/composer.json');
        self::assertFileDoesNotExist($workspace->path() . '/vendor/autoload.php');

        $preparation = WorkspacePreparer::forProject($this->configuration())->prepare($workspace);

        self::assertFileExists($workspace->path() . '/vendor/autoload.php');
        self::assertCount(1, $preparation->steps());
        self::assertSame('dependencies', $preparation->steps()[0]->name());
        self::assertSame(['composer', 'install', '--no-interaction'], $preparation->steps()[0]->command());

        // Installed in the workspace, and only there.
        self::assertFileDoesNotExist($this->project->path('vendor/autoload.php'));
    }

    #[Test]
    public function a_preparation_command_runs_inside_the_task_workspace(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparation = $this->preparer([
            'prepare' => [['name' => 'dependencies', 'command' => [$this->recordingProgram()]]],
        ])->prepare($workspace);

        // The program wrote its own working directory, which must be the
        // workspace rather than the checkout the user is sitting in.
        self::assertFileExists($workspace->path() . '/' . self::MARKER);
        self::assertSame(
            realpath($workspace->path()),
            trim((string) file_get_contents($workspace->path() . '/' . self::MARKER)),
        );
        self::assertFileDoesNotExist($this->project->path(self::MARKER));

        self::assertCount(1, $preparation->steps());
        self::assertSame('dependencies', $preparation->steps()[0]->name());
        self::assertSame([$this->recordingProgram()], $preparation->steps()[0]->command());
        self::assertSame(0, $preparation->steps()[0]->result()->exitCode());
        self::assertTrue($preparation->steps()[0]->succeeded());
        self::assertFalse($preparation->isEmpty());
        self::assertSame($workspace->path(), $preparation->path());
    }

    #[Test]
    public function the_configured_commands_run_in_order(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparation = $this->preparer([
            'prepare' => [
                ['name' => 'first', 'command' => [$this->appendingProgram(), 'one']],
                ['name' => 'second', 'command' => [$this->appendingProgram(), 'two']],
            ],
        ])->prepare($workspace);

        self::assertSame(['first', 'second'], array_map(
            static fn (PreparationStep $step): string => $step->name(),
            $preparation->steps(),
        ));
        self::assertSame("one\ntwo\n", file_get_contents($workspace->path() . '/order.txt'));
    }

    #[Test]
    public function a_configured_file_is_copied_in_at_the_same_relative_path(): void
    {
        // The two things a PHP project needs that a worktree never has: a local
        // test configuration beside the committed .dist, and a credential.
        $this->project->write('phpunit.xml', "<phpunit bootstrap=\"vendor/autoload.php\"/>\n");
        chmod($this->project->write('config/local.env', "TOKEN=shhh\n"), 0o600);

        $workspace = $this->givenAWorkspace();

        $preparation = $this->preparer([
            'prepare' => [],
            'copy' => ['phpunit.xml', 'config/local.env'],
        ])->prepare($workspace);

        self::assertSame(['phpunit.xml', 'config/local.env'], $preparation->copied());
        self::assertSame([], $preparation->alreadyPresent());

        self::assertSame(
            "<phpunit bootstrap=\"vendor/autoload.php\"/>\n",
            file_get_contents($workspace->path() . '/phpunit.xml'),
        );

        // The directory a copied file needs is created, and a 0600 credential
        // arrives as a 0600 credential: preparing a workspace must not be what
        // widens access to a secret.
        self::assertSame("TOKEN=shhh\n", file_get_contents($workspace->path() . '/config/local.env'));
        self::assertSame('0600', $this->modeOf($workspace->path() . '/config/local.env'));
    }

    #[Test]
    public function a_file_the_workspace_already_has_is_left_exactly_as_it_is(): void
    {
        // An adopted workspace — an interrupted run's, which resume continues
        // in — may hold a copied file the agent has since edited.
        $this->project->write('.env', "TOKEN=from-the-checkout\n");

        $workspace = $this->givenAWorkspace();
        file_put_contents($workspace->path() . '/.env', "TOKEN=edited-in-the-workspace\n");

        $preparation = $this->preparer(['prepare' => [], 'copy' => ['.env']])->prepare($workspace);

        self::assertSame([], $preparation->copied());
        self::assertSame(['.env'], $preparation->alreadyPresent());
        self::assertSame(
            "TOKEN=edited-in-the-workspace\n",
            file_get_contents($workspace->path() . '/.env'),
        );
    }

    #[Test]
    public function copies_happen_before_the_commands_that_may_need_them(): void
    {
        // An install can need the credential or the environment file to work at
        // all, so a command sees the copied files already in place.
        $this->project->write('.env', "TOKEN=shhh\n");

        $workspace = $this->givenAWorkspace();

        $this->preparer([
            'copy' => ['.env'],
            'prepare' => [['name' => 'dependencies', 'command' => [$this->copyingProgram(), '.env', 'seen.env']]],
        ])->prepare($workspace);

        self::assertSame("TOKEN=shhh\n", file_get_contents($workspace->path() . '/seen.env'));
    }

    #[Test]
    public function a_project_that_configures_nothing_has_nothing_prepared(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparation = $this->preparer(['prepare' => [], 'copy' => []])->prepare($workspace);

        self::assertTrue($preparation->isEmpty());
        self::assertSame([], $preparation->steps());
        self::assertStringContainsString('needed no preparation', $preparation->describe());
    }

    #[Test]
    public function a_failing_command_stops_the_task_before_the_agent_and_names_the_setting(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparer = $this->preparer([
            'prepare' => [
                ['name' => 'dependencies', 'command' => [$this->failingProgram()]],
                ['name' => 'assets', 'command' => [$this->recordingProgram()]],
            ],
        ]);

        try {
            $preparer->prepare($workspace);
            self::fail('Expected a failing preparation command to be reported.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('could not be prepared', $exception->getMessage());
            self::assertStringContainsString($workspace->path(), $exception->getMessage());
            self::assertStringContainsString('it broke', $exception->getMessage());

            $hints = implode("\n", $exception->hints());
            self::assertStringContainsString('"workspace.prepare" entry "dependencies"', $hints);
            self::assertStringContainsString('exited with status 3', $hints);
            self::assertStringContainsString('no agent was started', $hints);
            self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        }

        // It stopped at the first failure: the second command never ran.
        self::assertFileDoesNotExist($workspace->path() . '/' . self::MARKER);
    }

    #[Test]
    public function a_command_that_outlives_its_timeout_is_reported_as_such(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparer = $this->preparer([
            'prepare' => [['name' => 'dependencies', 'command' => [$this->sleepingProgram()], 'timeout' => 1]],
        ]);

        try {
            $preparer->prepare($workspace);
            self::fail('Expected a preparation command that never finishes to be stopped.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('did not finish within', $exception->getMessage());
            self::assertStringContainsString('stopped after 1 seconds', implode("\n", $exception->hints()));
        }
    }

    #[Test]
    public function a_configured_file_that_is_not_on_this_machine_is_refused_before_anything_runs(): void
    {
        $workspace = $this->givenAWorkspace();

        $preparer = $this->preparer([
            'copy' => ['.env'],
            'prepare' => [['name' => 'dependencies', 'command' => [$this->recordingProgram()]]],
        ]);

        try {
            $preparer->prepare($workspace);
            self::fail('Expected a missing file to be reported.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('the configured file ".env" could not be copied', $exception->getMessage());
            self::assertStringContainsString('there is no .env in ' . $this->project->root(), $exception->getMessage());
            self::assertStringContainsString('remove it from "workspace.copy"', implode("\n", $exception->hints()));
        }

        self::assertFileDoesNotExist($workspace->path() . '/' . self::MARKER);
    }

    #[Test]
    public function a_configured_directory_is_refused_and_points_at_a_prepare_command(): void
    {
        $this->project->write('vendor/autoload.php', "<?php\n");

        $workspace = $this->givenAWorkspace();

        try {
            $this->preparer(['prepare' => [], 'copy' => ['vendor']])->prepare($workspace);
            self::fail('Expected a directory to be refused.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('is a directory', $exception->getMessage());
            self::assertStringContainsString('"workspace.prepare"', implode("\n", $exception->hints()));
            self::assertStringContainsString('"vendor"', implode("\n", $exception->hints()));
        }

        self::assertFileDoesNotExist($workspace->path() . '/vendor');
    }

    #[Test]
    public function a_configured_symbolic_link_is_refused_rather_than_resolved(): void
    {
        $outside = $this->project->write('real.env', "TOKEN=shhh\n");

        if (!@symlink($outside, $this->project->path('.env'))) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }

        $workspace = $this->givenAWorkspace();

        try {
            $this->preparer(['prepare' => [], 'copy' => ['.env']])->prepare($workspace);
            self::fail('Expected a symbolic link to be refused.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('is a symbolic link', $exception->getMessage());
            self::assertStringContainsString('regular files only', implode("\n", $exception->hints()));
        }

        self::assertFileDoesNotExist($workspace->path() . '/.env');
    }

    #[Test]
    public function the_project_directory_itself_is_never_prepared(): void
    {
        // Defence in depth: TaskWorktrees cannot produce this, and the cost of
        // being wrong is an install in the user's own checkout.
        $root = TaskWorktree::adopted($this->generation(), $this->project->root(), $this->base());

        $preparer = $this->preparer([
            'prepare' => [['name' => 'dependencies', 'command' => [$this->recordingProgram()]]],
        ]);

        try {
            $preparer->prepare($root);
            self::fail('Expected the project directory to be refused as a workspace.');
        } catch (PreparationException $exception) {
            self::assertStringContainsString('cannot be the project directory itself', $exception->getMessage());
            self::assertStringContainsString('linked worktree', implode("\n", $exception->hints()));
        }

        self::assertFileDoesNotExist($this->project->path(self::MARKER));
    }

    #[Test]
    public function a_workspace_that_has_gone_is_reported_rather_than_created(): void
    {
        $workspace = $this->givenAWorkspace();
        Git::run($this->project->root(), 'worktree', 'remove', '--force', $workspace->path());

        $this->expectException(PreparationException::class);
        $this->expectExceptionMessage('does not exist, so it cannot be prepared');

        $this->preparer([
            'prepare' => [['name' => 'dependencies', 'command' => [$this->recordingProgram()]]],
        ])->prepare($workspace);
    }

    #[Test]
    public function preparation_output_is_streamed_as_it_arrives(): void
    {
        $this->project->write('.env', "TOKEN=shhh\n");

        $workspace = $this->givenAWorkspace();
        $sink = new RecordingSink();

        $this->preparer([
            'copy' => ['.env'],
            'prepare' => [['name' => 'dependencies', 'command' => [$this->talkingProgram()]]],
        ])->prepare($workspace, $sink);

        self::assertStringContainsString('installing', $sink->textOf(OutputStream::Standard));
        self::assertStringContainsString('a warning', $sink->textOf(OutputStream::Error));

        $notes = implode("\n", $sink->notes);
        self::assertStringContainsString('workspace: copied .env', $notes);
        self::assertStringContainsString('workspace: dependencies — ', $notes);

        // The sink belongs to whoever opened the attempt's log, and preparation
        // is one of several things written to it.
        self::assertSame(0, $sink->closes);
    }

    #[Test]
    public function the_users_checkout_is_untouched_by_preparing_a_workspace(): void
    {
        $this->project->write('.env', "TOKEN=shhh\n");

        // Snapshotted after the workspace and the test's own programs exist, so
        // that what is being compared is the effect of preparing.
        $workspace = $this->givenAWorkspace();
        $preparer = $this->preparer([
            'copy' => ['.env'],
            'prepare' => [['name' => 'dependencies', 'command' => [$this->recordingProgram()]]],
        ]);

        $before = $this->snapshotOfTheCheckout();

        $preparation = $preparer->prepare($workspace);

        self::assertSame($before, $this->snapshotOfTheCheckout());
        self::assertStringContainsString('prepared', $preparation->describe());
        self::assertGreaterThanOrEqual(0.0, $preparation->seconds());
        self::assertSame(['.env'], $preparation->toArray()['copied']);
    }

    /**
     * Point Composer at a throwaway home and cache, so the real preparation
     * command cannot read or write the developer's own Composer setup.
     */
    private function givenAComposerHomeOfItsOwn(): void
    {
        $home = TemporaryDirectory::create('ralph-composer-home');
        $this->composerHome = $home;

        $this->environment->set('COMPOSER_HOME', $home);
        $this->environment->set('COMPOSER_CACHE_DIR', $home . '/cache');
        $this->environment->set('COMPOSER_NO_INTERACTION', '1');
    }

    /**
     * A repository with one commit, which is what a task workspace is created
     * from.
     */
    private function givenACommittedProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        $this->project->write('phpunit.xml.dist', "<phpunit/>\n");
        Git::commit($this->project->root());
    }

    /**
     * A real linked worktree for one task, created the way a run creates it.
     */
    private function givenAWorkspace(): TaskWorktree
    {
        return TaskWorktrees::forProject($this->configuration())->prepare($this->generation(), $this->base());
    }

    /**
     * @param array<string, mixed> $workspace the `workspace` settings
     */
    private function preparer(array $workspace): WorkspacePreparer
    {
        return WorkspacePreparer::forProject($this->configuration($workspace));
    }

    /**
     * @param array<string, mixed>|null $workspace
     */
    private function configuration(?array $workspace = null): Configuration
    {
        $settings = $workspace === null ? [] : ['workspace' => $workspace];

        return (new ConfigurationLoader())->fromArray($settings, $this->project->root());
    }

    private function base(): BaseCommit
    {
        return BaseResolver::forProject($this->configuration())->resolve();
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first(TaskId::fromDescription('Add a greeting command'));
    }

    /**
     * A program that records the directory it ran in.
     */
    private function recordingProgram(): string
    {
        return $this->program('tools/record', sprintf("#!/bin/sh\npwd > %s\n", self::MARKER));
    }

    /**
     * A program that appends its argument to a file, for asserting order.
     */
    private function appendingProgram(): string
    {
        return $this->program('tools/append', "#!/bin/sh\nprintf '%s\\n' \"$1\" >> order.txt\n");
    }

    /**
     * A program that copies one file in the workspace to another, which only
     * works if the copied files are already in place when it runs.
     */
    private function copyingProgram(): string
    {
        return $this->program('tools/copy', "#!/bin/sh\ncat \"$1\" > \"$2\"\n");
    }

    private function failingProgram(): string
    {
        return $this->program('tools/fail', "#!/bin/sh\nprintf 'it broke\\n' >&2\nexit 3\n");
    }

    private function sleepingProgram(): string
    {
        return $this->program('tools/sleep', "#!/bin/sh\nsleep 30\n");
    }

    /**
     * A program that writes to both streams, for the streaming assertions.
     */
    private function talkingProgram(): string
    {
        return $this->program('tools/talk', "#!/bin/sh\nprintf 'installing\\n'\nprintf 'a warning\\n' >&2\n");
    }

    /**
     * An executable program in the project, outside the commit: these are the
     * test's own tools, and committing them would put them in every workspace.
     */
    private function program(string $relative, string $contents): string
    {
        $path = $this->project->write($relative, $contents);
        chmod($path, 0o700);

        return $path;
    }

    private function modeOf(string $path): string
    {
        clearstatcache(true, $path);

        return substr(sprintf('%o', (int) fileperms($path)), -4);
    }

    /**
     * Everything about the controlling checkout a run must not change: its
     * HEAD, its working tree and the files in its root.
     *
     * @return array<string, string>
     */
    private function snapshotOfTheCheckout(): array
    {
        $entries = scandir($this->project->root());

        if ($entries === false) {
            self::fail('The project root could not be read.');
        }

        sort($entries);

        return [
            'head' => Git::run($this->project->root(), 'rev-parse', 'HEAD')->standardOutput,
            'status' => Git::run($this->project->root(), 'status', '--porcelain')->standardOutput,
            'entries' => implode(',', $entries),
        ];
    }
}
