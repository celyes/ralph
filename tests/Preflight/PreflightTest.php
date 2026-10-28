<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Preflight;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Preflight\Check\AgentCheck;
use Celyes\RalphPhp\Preflight\Check\BaseReferenceCheck;
use Celyes\RalphPhp\Preflight\Check\ExtensionsCheck;
use Celyes\RalphPhp\Preflight\Check\GitCheck;
use Celyes\RalphPhp\Preflight\Check\IdentityCheck;
use Celyes\RalphPhp\Preflight\Check\PhpCheck;
use Celyes\RalphPhp\Preflight\Check\RepositoryCheck;
use Celyes\RalphPhp\Preflight\Check\VerificationCheck;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckOutcome;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\Preflight;
use Celyes\RalphPhp\Preflight\PreflightReport;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The preflight checks, against real repositories and real programs.
 *
 * Every test prepares an actual Git repository and actual executables: the
 * checks exist to report what this machine can do, so a double would only
 * confirm that the double was configured as the test expected. The agent and
 * the verification commands are fake programs rather than real ones, because
 * preflight resolves them and never runs them — which is itself asserted.
 */
#[CoversClass(Preflight::class)]
#[CoversClass(PhpCheck::class)]
#[CoversClass(ExtensionsCheck::class)]
#[CoversClass(GitCheck::class)]
#[CoversClass(RepositoryCheck::class)]
#[CoversClass(BaseReferenceCheck::class)]
#[CoversClass(IdentityCheck::class)]
#[CoversClass(AgentCheck::class)]
#[CoversClass(VerificationCheck::class)]
final class PreflightTest extends TestCase
{
    private TemporaryProject $project;

    private Environment $environment;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and these checks are about a real repository.');
        }

        $this->project = TemporaryProject::create();
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
        $this->project->remove();
    }

    #[Test]
    public function a_prepared_project_passes_every_check(): void
    {
        $this->givenARepository();
        $commit = Git::commit($this->project->root());

        $report = $this->report();

        self::assertTrue($report->isUsable(), $this->describe($report));
        self::assertSame([], $report->failures(), $this->describe($report));
        self::assertSame([], $report->warnings(), $this->describe($report));

        foreach (CheckId::cases() as $id) {
            self::assertSame(CheckOutcome::Passed, $this->outcome($report, $id), $this->describe($report));
        }

        self::assertStringContainsString(substr($commit, 0, 12), $this->summary($report, CheckId::BaseReference));
        self::assertStringContainsString('Test User <test@example.com>', $this->summary($report, CheckId::Identity));
        self::assertStringContainsString('Initial commit', implode("\n", $this->details($report, CheckId::BaseReference)));
    }

    #[Test]
    public function the_report_names_what_the_checks_were_run_against(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report();

        self::assertSame($this->project->root(), $report->projectRoot());
        self::assertSame($this->project->path(Defaults::CONFIG_FILE), $report->configurationSource());
        self::assertSame('codex', $report->agent()->name());

        $data = $report->toArray();
        self::assertTrue($data['usable']);
        self::assertSame('codex', $data['agent']);
        self::assertCount(count(CheckId::cases()), $data['checks']);
        self::assertSame('php', $data['checks'][0]['check']);
        self::assertSame('pass', $data['checks'][0]['outcome']);
        self::assertSame($this->summary($report, CheckId::Php), $data['checks'][0]['summary']);
    }

    #[Test]
    public function the_checks_run_in_the_documented_order(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $ran = array_map(
            static fn (CheckResult $result): string => $result->id()->value,
            $this->report()->results(),
        );

        self::assertSame(array_map(static fn (CheckId $id): string => $id->value, CheckId::cases()), $ran);
    }

    #[Test]
    public function a_directory_that_is_not_a_repository_is_reported_without_blaming_the_configuration(): void
    {
        $report = $this->report();

        self::assertFalse($report->isUsable());
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Repository));
        self::assertStringContainsString('is not in a Git repository', $this->summary($report, CheckId::Repository));
        self::assertStringContainsString('git init', implode("\n", $this->hints($report, CheckId::Repository)));

        // The base cannot be resolved without a repository, and saying so is
        // more useful than a second failure about the same thing.
        self::assertSame(CheckOutcome::Skipped, $this->outcome($report, CheckId::BaseReference));
        self::assertStringContainsString('"repository" check failed', $this->summary($report, CheckId::BaseReference));

        // The identity does not depend on the repository, so it is still
        // answered: the user learns about both problems in one run.
        self::assertNotSame(CheckOutcome::Skipped, $this->outcome($report, CheckId::Identity));
    }

    #[Test]
    public function a_bare_repository_is_rejected(): void
    {
        Git::initialise($this->project->root(), bare: true);

        $report = $this->report();

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Repository));
        self::assertStringContainsString('is a bare repository', $this->summary($report, CheckId::Repository));
        self::assertStringContainsString('working tree', implode("\n", $this->hints($report, CheckId::Repository)));
    }

    #[Test]
    public function a_subdirectory_of_a_repository_is_rejected_with_the_directory_to_run_from(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $subdirectory = $this->project->path('packages/library');
        mkdir($subdirectory, 0o700, true);

        $report = (new Preflight())->run(
            (new ConfigurationLoader())->fromArray([], $subdirectory),
        );

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Repository));
        self::assertStringContainsString('is not the root of its repository', $this->summary($report, CheckId::Repository));
        self::assertStringContainsString(
            'Run ralph from ' . $this->project->root(),
            implode("\n", $this->hints($report, CheckId::Repository)),
        );
    }

    #[Test]
    public function an_empty_repository_is_reported_as_having_no_commits(): void
    {
        $this->givenARepository();

        $report = $this->report();

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Repository));
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::BaseReference));
        self::assertStringContainsString('no commits yet', $this->summary($report, CheckId::BaseReference));
        self::assertStringContainsString('first commit', implode("\n", $this->hints($report, CheckId::BaseReference)));
    }

    #[Test]
    public function a_base_reference_that_does_not_exist_names_the_branches_that_do(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());
        Git::run($this->project->root(), 'branch', 'release');

        $report = $this->report(['git' => ['base' => 'production']]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::BaseReference));
        self::assertStringContainsString(
            'git.base "production" does not name a commit',
            $this->summary($report, CheckId::BaseReference),
        );

        $hints = implode("\n", $this->hints($report, CheckId::BaseReference));
        self::assertStringContainsString('main', $hints);
        self::assertStringContainsString('release', $hints);
        self::assertStringContainsString($this->project->path(Defaults::CONFIG_FILE), $hints);
    }

    #[Test]
    public function a_configured_branch_is_resolved_to_its_commit(): void
    {
        $this->givenARepository();
        $commit = Git::commit($this->project->root());

        $report = $this->report(['git' => ['base' => 'main']]);

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::BaseReference));
        self::assertStringContainsString('git.base "main"', $this->summary($report, CheckId::BaseReference));
        self::assertStringContainsString($commit, implode("\n", $this->details($report, CheckId::BaseReference)));
    }

    #[Test]
    public function a_reference_that_is_not_a_commit_does_not_satisfy_the_base(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());
        // An annotated tag of a tree, which resolves as an object but is not a
        // commit to branch from.
        $tree = trim(Git::run($this->project->root(), 'rev-parse', 'HEAD^{tree}')->standardOutput);
        Git::run($this->project->root(), 'tag', 'tree-tag', $tree);

        $report = $this->report(['git' => ['base' => 'tree-tag']]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::BaseReference));
    }

    #[Test]
    public function a_missing_git_identity_is_reported_with_the_commands_that_set_it(): void
    {
        $version = Git::version();
        if ($version === null || version_compare($version, '2.32.0', '<')) {
            self::markTestSkipped('Neutralising the machine\'s own Git configuration needs Git 2.32 or newer.');
        }

        $this->givenARepository(identity: false);
        Git::commit($this->project->root());
        $this->givenNoGitConfigurationOutsideTheRepository();

        $report = $this->report();

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Identity));
        self::assertStringContainsString(
            'Git cannot determine the commit author identity',
            $this->summary($report, CheckId::Identity),
        );
        self::assertStringContainsString('git config user.email', implode("\n", $this->hints($report, CheckId::Identity)));
    }

    #[Test]
    public function the_repository_checked_is_the_project_rather_than_one_named_in_the_environment(): void
    {
        $elsewhere = TemporaryProject::create();

        try {
            Git::initialise($elsewhere->root());
            Git::setIdentity($elsewhere->root());
            Git::commit($elsewhere->root());

            // A redirection left in the shell must not make preflight answer
            // about another repository than the project it was given.
            $this->environment->set('GIT_DIR', $elsewhere->path('.git'));
            $this->environment->set('GIT_WORK_TREE', $elsewhere->root());

            $report = $this->report();

            self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Repository));
            self::assertStringContainsString(
                $this->project->root() . ' is not in a Git repository',
                $this->summary($report, CheckId::Repository),
            );
        } finally {
            $elsewhere->remove();
        }
    }

    #[Test]
    public function a_git_older_than_the_requirement_is_rejected(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());
        $this->givenAGitNamed("#!/bin/sh\necho \"git version 2.17.1\"\n");

        $report = $this->report();

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Git));
        self::assertStringContainsString(
            'is older than the required ' . Ralph::MINIMUM_GIT_VERSION,
            $this->summary($report, CheckId::Git),
        );
        self::assertSame(CheckOutcome::Skipped, $this->outcome($report, CheckId::Repository));
    }

    #[Test]
    public function a_git_version_that_cannot_be_read_is_a_warning_rather_than_a_refusal(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        // Vendors and distributions write their own text into the version
        // string. Everything but the version query is the real Git, because
        // what this test is about is that the checks after it still run.
        $real = (new ExecutableFinder())->find('git', $this->project->root());
        self::assertIsString($real);
        $this->givenAGitNamed(sprintf(
            "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo \"git version (a vendor build)\"; exit 0; fi\nexec %s \"$@\"\n",
            $real,
        ));

        $report = $this->report();

        self::assertSame(CheckOutcome::Warning, $this->outcome($report, CheckId::Git));
        self::assertStringContainsString('version could not be read', $this->summary($report, CheckId::Git));
        self::assertStringContainsString(
            'git version (a vendor build)',
            implode("\n", $this->details($report, CheckId::Git)),
        );

        // A warning is not a refusal: the run may start, and the checks that
        // depend on Git are still answered.
        self::assertTrue($report->isUsable(), $this->describe($report));
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Repository));
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::BaseReference));
    }

    #[Test]
    public function the_git_checks_are_skipped_when_git_is_not_installed(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());
        $this->environment->set('PATH', $this->project->path('empty'));

        $report = $this->report();

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Git));
        self::assertStringContainsString('was not found in PATH', $this->summary($report, CheckId::Git));

        foreach ([CheckId::Repository, CheckId::BaseReference, CheckId::Identity] as $dependent) {
            self::assertSame(CheckOutcome::Skipped, $this->outcome($report, $dependent), $this->describe($report));
            self::assertStringContainsString('"git" check failed', $this->summary($report, $dependent));
        }
    }

    #[Test]
    public function every_check_is_answered_even_after_one_fails(): void
    {
        $report = $this->report(['checks' => [['command' => ['no-such-program-' . bin2hex(random_bytes(4))]]]]);

        self::assertCount(count(CheckId::cases()), $report->results());
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Repository));
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Checks));
        self::assertGreaterThan(1, count($report->failures()));
    }

    #[Test]
    public function an_agent_whose_executable_is_missing_names_the_setting_to_change(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report(['agents' => ['codex' => ['executable' => 'ralph-no-such-agent']]]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Agent));
        self::assertStringContainsString(
            'the agent "codex" cannot be run: its executable "ralph-no-such-agent" was not found',
            $this->summary($report, CheckId::Agent),
        );

        $hints = implode("\n", $this->hints($report, CheckId::Agent));
        self::assertStringContainsString('agents.codex.executable', $hints);
        self::assertStringContainsString($this->project->path(Defaults::CONFIG_FILE), $hints);
    }

    #[Test]
    public function an_agent_path_that_exists_but_cannot_be_executed_says_so(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());
        $path = $this->writeProgram('tools/codex', executable: false);

        $report = $this->report(['agents' => ['codex' => ['executable' => $path]]]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Agent));
        self::assertStringContainsString(
            $path . ' exists but is not executable.',
            implode("\n", $this->hints($report, CheckId::Agent)),
        );
    }

    #[Test]
    public function declared_authentication_variables_must_be_present(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $settings = ['agents' => ['codex' => [
            'executable' => $this->writeProgram('tools/codex'),
            'environment' => ['RALPH_TEST_TOKEN'],
        ]]];

        $this->environment->remove('RALPH_TEST_TOKEN');
        $report = $this->report($settings);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Agent));
        self::assertStringContainsString('RALPH_TEST_TOKEN is not set', $this->summary($report, CheckId::Agent));

        // An exported but empty variable is how an unset credential looks in a
        // shell profile, and no agent would accept it.
        $this->environment->set('RALPH_TEST_TOKEN', '   ');
        self::assertSame(CheckOutcome::Failed, $this->outcome($this->report($settings), CheckId::Agent));

        $this->environment->set('RALPH_TEST_TOKEN', 'a-secret-value');
        $report = $this->report($settings);

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Agent));
        self::assertStringContainsString(
            'authentication variables: RALPH_TEST_TOKEN',
            implode("\n", $this->details($report, CheckId::Agent)),
        );
        self::assertStringNotContainsString('a-secret-value', $this->describe($report));
    }

    #[Test]
    public function the_agent_checked_is_the_one_the_run_would_use(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $settings = [
            'agent' => 'codex',
            'agents' => [
                'codex' => ['executable' => $this->writeProgram('tools/codex')],
                'claude' => ['executable' => 'ralph-no-such-agent'],
            ],
        ];

        self::assertSame(CheckOutcome::Passed, $this->outcome($this->report($settings), CheckId::Agent));

        $overridden = $this->report($settings, 'claude');

        self::assertSame('claude', $overridden->agent()->name());
        self::assertSame(CheckOutcome::Failed, $this->outcome($overridden, CheckId::Agent));
        self::assertStringContainsString(
            'selected with "--agent"',
            implode("\n", $this->hints($overridden, CheckId::Agent)),
        );
    }

    #[Test]
    public function an_unknown_agent_is_a_configuration_error_rather_than_a_failed_check(): void
    {
        $this->givenARepository();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No agent named "gemini" is configured.');

        $this->report([], 'gemini');
    }

    #[Test]
    public function a_verification_command_whose_program_is_missing_fails(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report(['checks' => [
            ['name' => 'tests', 'command' => [$this->writeProgram('tools/phpunit')]],
            ['name' => 'lint', 'command' => ['ralph-no-such-linter']],
        ]]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Checks));
        self::assertStringContainsString(
            '1 of 2 verification commands cannot run: lint',
            $this->summary($report, CheckId::Checks),
        );

        $details = implode("\n", $this->details($report, CheckId::Checks));
        self::assertStringContainsString('lint: "ralph-no-such-linter" cannot run', $details);
        self::assertStringContainsString('tests: "' . $this->project->path('tools/phpunit') . '" runs', $details);
    }

    #[Test]
    public function a_composer_check_is_measured_against_the_project_s_scripts(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $composer = $this->writeProgram('tools/composer');
        $settings = ['checks' => [['name' => 'tests', 'command' => [$composer, 'test']]]];

        // No composer.json at all: the command would resolve and then fail.
        $report = $this->report($settings);
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Checks));
        self::assertStringContainsString(
            'there is no composer.json',
            implode("\n", $this->details($report, CheckId::Checks)),
        );

        // A composer.json without the script the check names.
        $this->project->write(
            'composer.json',
            (string) json_encode(['scripts' => ['lint' => 'php-cs-fixer fix']], JSON_THROW_ON_ERROR),
        );
        $report = $this->report($settings);
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Checks));
        self::assertStringContainsString(
            'composer.json defines no "test" script',
            implode("\n", $this->details($report, CheckId::Checks)),
        );
        self::assertStringContainsString(
            'Scripts it does define: lint.',
            implode("\n", $this->hints($report, CheckId::Checks)),
        );

        // And with the script defined.
        $this->project->write(
            'composer.json',
            (string) json_encode(['scripts' => ['test' => 'phpunit']], JSON_THROW_ON_ERROR),
        );
        $report = $this->report($settings);
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Checks));
        self::assertStringContainsString(
            'composer.json defines its "test" script',
            implode("\n", $this->details($report, CheckId::Checks)),
        );
    }

    #[Test]
    public function the_verification_commands_are_resolved_and_not_run(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $marker = $this->project->path('the-check-ran');
        $program = $this->writeProgram('tools/check', "#!/bin/sh\ntouch '" . $marker . "'\n");

        $report = $this->report(['checks' => [['name' => 'tests', 'command' => [$program]]]]);

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Checks));
        self::assertFileDoesNotExist($marker, 'preflight executed a verification command');
    }

    /**
     * A repository at the project root, with the identity configured unless the
     * test is about its absence.
     */
    private function givenARepository(bool $identity = true): void
    {
        Git::initialise($this->project->root());

        if ($identity) {
            Git::setIdentity($this->project->root());
        }
    }

    /**
     * Put a program named `git` in front of the real one, and nothing else on
     * the search path.
     */
    private function givenAGitNamed(string $script): void
    {
        $shim = $this->writeProgram('tools/git-shim/git', $script);

        $this->environment->set('PATH', dirname($shim));
    }

    /**
     * Hide the machine's own global and system Git configuration, and any
     * identity in the environment, from the commands preflight runs.
     */
    private function givenNoGitConfigurationOutsideTheRepository(): void
    {
        $this->environment->set('GIT_CONFIG_GLOBAL', '/dev/null');
        $this->environment->set('GIT_CONFIG_SYSTEM', '/dev/null');

        foreach (['GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL', 'EMAIL'] as $variable) {
            $this->environment->remove($variable);
        }
    }

    /**
     * Run preflight against the project with the given settings, which are
     * loaded through the real loader so they are merged and validated exactly
     * as a file's would be.
     *
     * @param array<string, mixed> $settings
     */
    private function report(array $settings = [], ?string $agent = null): PreflightReport
    {
        return (new Preflight())->run($this->configuration($settings), $agent);
    }

    /**
     * The settings a test gave, with an installed agent and a runnable check
     * filled in: a test about the base reference should not have to configure
     * either, and must not fail because this machine has no Codex.
     *
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings): Configuration
    {
        $settings['agents'] ??= ['codex' => ['executable' => $this->writeProgram('tools/codex')]];
        $settings['checks'] ??= [['name' => 'tests', 'command' => [PHP_BINARY, '-v']]];

        return (new ConfigurationLoader())->fromArray(
            $settings,
            $this->project->root(),
            $this->project->path(Defaults::CONFIG_FILE),
        );
    }

    private function outcome(PreflightReport $report, CheckId $id): ?CheckOutcome
    {
        return $report->result($id)?->outcome();
    }

    private function summary(PreflightReport $report, CheckId $id): string
    {
        return (string) $report->result($id)?->summary();
    }

    /**
     * @return list<string>
     */
    private function details(PreflightReport $report, CheckId $id): array
    {
        return $report->result($id)?->details() ?? [];
    }

    /**
     * @return list<string>
     */
    private function hints(PreflightReport $report, CheckId $id): array
    {
        return $report->result($id)?->hints() ?? [];
    }

    /**
     * The whole report as text, used as the assertion message so a failure
     * shows what every check concluded.
     */
    private function describe(PreflightReport $report): string
    {
        $lines = [];

        foreach ($report->results() as $result) {
            $lines[] = sprintf('%s %s: %s', $result->outcome()->label(), $result->id()->label(), $result->summary());

            foreach ([...$result->details(), ...$result->hints()] as $line) {
                $lines[] = '    ' . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Write a fake program inside the project and return its absolute path.
     */
    private function writeProgram(string $relative, string $contents = "#!/bin/sh\nexit 0\n", bool $executable = true): string
    {
        $path = $this->project->write($relative, $contents);
        chmod($path, $executable ? 0o700 : 0o600);

        return $path;
    }
}
