<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Preflight;

use Celyes\RalphPhp\Agent\ClaudeAdapter;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Preflight\Check\AgentCheck;
use Celyes\RalphPhp\Preflight\Check\AgentVersionCheck;
use Celyes\RalphPhp\Preflight\Check\ExtensionsCheck;
use Celyes\RalphPhp\Preflight\Check\PhpCheck;
use Celyes\RalphPhp\Preflight\Check\VerificationCheck;
use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckOutcome;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\Preflight;
use Celyes\RalphPhp\Preflight\PreflightReport;
use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Agent\NamedAdapterProvider;
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
 * the verification commands are fake programs rather than real ones: a
 * verification command is resolved and never run, which is itself asserted,
 * and the agent is asked for its version and nothing else, which is asserted
 * from what the fake records about every invocation it received.
 */
#[CoversClass(Preflight::class)]
#[CoversClass(PhpCheck::class)]
#[CoversClass(ExtensionsCheck::class)]
#[CoversClass(AgentCheck::class)]
#[CoversClass(AgentVersionCheck::class)]
#[CoversClass(VerificationCheck::class)]
final class PreflightTest extends TestCase
{
    /**
     * A version the shipped adapters' minimums are certainly below, for the
     * tests that are about something other than the version.
     */
    private const string NEW_ENOUGH = '9.9.9';

    /**
     * Where the fake agent records every invocation it received, relative to
     * the project root.
     */
    private const string INVOCATIONS = 'agent-invocations.txt';

    private TemporaryProject $project;

    private Environment $environment;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the project a run works in is a repository.');
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
        Git::commit($this->project->root());

        $report = $this->report();

        self::assertTrue($report->isUsable(), $this->describe($report));
        self::assertSame([], $report->failures(), $this->describe($report));
        self::assertSame([], $report->warnings(), $this->describe($report));

        foreach (CheckId::cases() as $id) {
            self::assertSame(CheckOutcome::Passed, $this->outcome($report, $id), $this->describe($report));
        }

        self::assertStringContainsString(
            '1 verification command can run',
            $this->summary($report, CheckId::Checks),
        );
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
    public function every_check_is_answered_even_after_one_fails(): void
    {
        $report = $this->report(['checks' => [['command' => ['no-such-program-' . bin2hex(random_bytes(4))]]]]);

        self::assertCount(count(CheckId::cases()), $report->results());
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Php));
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Checks));
        self::assertCount(1, $report->failures());
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

        // The one moment a tool could plausibly reach for whatever else is
        // installed. It says, in as many words, that it will not.
        self::assertStringContainsString('Ralph PHP does not run a different agent in its place.', $hints);
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
    public function an_agent_cli_older_than_its_adapter_requires_is_refused(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report(['agents' => ['codex' => ['executable' => $this->agentProgram('codex-cli 0.39.0')]]]);

        // The executable is there and runnable: it is the release that this
        // adapter's "exec --cd --sandbox" invocation is not written for.
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Agent), $this->describe($report));
        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::AgentVersion), $this->describe($report));
        self::assertFalse($report->isUsable());
        self::assertSame(
            sprintf('Codex CLI 0.39.0 is older than the required %s', CodexAdapter::MINIMUM_VERSION),
            $this->summary($report, CheckId::AgentVersion),
        );

        $hints = implode("\n", $this->hints($report, CheckId::AgentVersion));
        self::assertStringContainsString('Upgrade the Codex CLI', $hints);
        self::assertStringContainsString('agents.codex.executable', $hints);
        self::assertStringContainsString('Ralph PHP does not run a different agent in its place.', $hints);
    }

    #[Test]
    public function a_cli_newer_than_the_minimum_is_not_refused_for_being_ahead(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        // A minimum is a floor. Everything above it passes, including the
        // build suffixes CLIs add, which version_compare() would otherwise
        // read as pre-release markers and judge older than the release.
        $report = $this->report(['agents' => ['codex' => ['executable' => $this->agentProgram('codex-cli 0.44.0-nightly')]]]);

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::AgentVersion), $this->describe($report));
        self::assertSame('Codex CLI 0.44.0', $this->summary($report, CheckId::AgentVersion));
        self::assertStringContainsString(
            'requires Codex CLI ' . CodexAdapter::MINIMUM_VERSION . ' or newer',
            implode("\n", $this->details($report, CheckId::AgentVersion)),
        );
    }

    #[Test]
    public function a_version_that_cannot_be_established_warns_rather_than_stopping_the_run(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        // An answer with no version number in it. The agent executable may be
        // a wrapper somebody wrote, and refusing to start because it answers
        // oddly would be a worse failure than the one the check prevents.
        $unreadable = $this->report(['agents' => ['codex' => ['executable' => $this->agentProgram('Codex, the coding agent')]]]);

        self::assertSame(CheckOutcome::Warning, $this->outcome($unreadable, CheckId::AgentVersion), $this->describe($unreadable));
        self::assertTrue($unreadable->isUsable(), $this->describe($unreadable));
        self::assertStringContainsString('holds no version number', $this->summary($unreadable, CheckId::AgentVersion));
        self::assertStringContainsString(
            'reported: Codex, the coding agent',
            implode("\n", $this->details($unreadable, CheckId::AgentVersion)),
        );

        // A query that failed says so, with what the program printed.
        $failed = $this->report(['agents' => ['codex' => [
            'executable' => $this->agentProgram('codex: unknown option --version', status: 3),
        ]]]);

        self::assertSame(CheckOutcome::Warning, $this->outcome($failed, CheckId::AgentVersion), $this->describe($failed));
        self::assertTrue($failed->isUsable(), $this->describe($failed));
        self::assertStringContainsString('it exited with status 3', $this->summary($failed, CheckId::AgentVersion));
        self::assertStringContainsString(
            'Ralph PHP does not run a different agent in its place.',
            implode("\n", $this->hints($failed, CheckId::AgentVersion)),
        );
    }

    #[Test]
    public function the_version_is_not_asked_for_when_the_agent_cannot_be_run(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report(['agents' => ['codex' => ['executable' => 'ralph-no-such-agent']]]);

        self::assertSame(CheckOutcome::Failed, $this->outcome($report, CheckId::Agent));
        self::assertSame(CheckOutcome::Skipped, $this->outcome($report, CheckId::AgentVersion));
        self::assertStringContainsString(
            'not checked, because the "agent" check failed',
            $this->summary($report, CheckId::AgentVersion),
        );
    }

    #[Test]
    public function the_agent_is_asked_for_its_version_and_for_nothing_else(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $report = $this->report();

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::AgentVersion), $this->describe($report));

        // No session, no task, no prompt: one query, with the arguments the
        // adapter named and nothing added to them, in the project it is about
        // rather than wherever this process happens to be.
        self::assertSame(['--version'], $this->invocations());
        self::assertSame([$this->project->root()], $this->invocationDirectories());
    }

    #[Test]
    public function the_version_is_read_from_the_program_the_agent_check_resolved(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        // Configured as a bare name, as a user who installed the CLI normally
        // would write it: what runs is the program found on PATH, and the
        // report names it so that a machine with two installations shows which
        // one answered.
        $installed = $this->agentProgram(relative: 'tools/ralph-fake-codex');
        $this->environment->set('PATH', dirname($installed) . PATH_SEPARATOR . (string) getenv('PATH'));

        $report = $this->report(['agents' => ['codex' => ['executable' => 'ralph-fake-codex']]]);

        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::AgentVersion), $this->describe($report));
        self::assertStringContainsString(
            'asked: ' . $installed . ' --version',
            implode("\n", $this->details($report, CheckId::AgentVersion)),
        );
    }

    #[Test]
    public function the_version_required_is_the_selected_adapter_s_own(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $settings = ['agents' => [
            'codex' => ['executable' => $this->agentProgram('codex-cli ' . self::NEW_ENOUGH)],
            'claude' => ['executable' => $this->agentProgram('1.0.119 (Claude Code)', 'tools/claude')],
        ]];

        self::assertSame(CheckOutcome::Passed, $this->outcome($this->report($settings), CheckId::AgentVersion));

        // The same machine, the same repository, a different adapter: Claude
        // Code answers in its own format and is measured against its own floor.
        $claude = $this->report($settings, 'claude');

        self::assertSame(CheckOutcome::Failed, $this->outcome($claude, CheckId::AgentVersion), $this->describe($claude));
        self::assertSame(
            sprintf('Claude Code 1.0.119 is older than the required %s', ClaudeAdapter::MINIMUM_VERSION),
            $this->summary($claude, CheckId::AgentVersion),
        );
    }

    #[Test]
    public function an_adapter_the_project_registered_is_checked_rather_than_called_unsupported(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        $this->project->write(
            Defaults::ADAPTERS_FILE,
            "<?php\n\nreturn [new \\" . NamedAdapterProvider::class . "('acme')];\n",
        );

        $report = $this->report([
            'agent' => 'mine',
            'agents' => ['mine' => [
                'adapter' => 'acme',
                'executable' => $this->agentProgram(relative: 'tools/acme-cli'),
            ]],
        ]);

        self::assertTrue($report->isUsable(), $this->describe($report));
        self::assertSame('acme', $report->agent()->adapter());
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::Agent), $this->describe($report));

        // An adapter with no version to speak of — an agent reached over HTTP,
        // a wrapper a project maintains — is reported as having stated no
        // requirement, and nothing is run for it.
        self::assertSame(CheckOutcome::Passed, $this->outcome($report, CheckId::AgentVersion), $this->describe($report));
        self::assertStringContainsString(
            'the adapter "acme" states no version requirement',
            $this->summary($report, CheckId::AgentVersion),
        );
        self::assertSame([], $this->invocations());
    }

    #[Test]
    public function an_adapter_registered_nowhere_is_refused_without_a_substitute(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        try {
            $this->report([
                'agent' => 'mine',
                'agents' => ['mine' => ['adapter' => 'gemini', 'executable' => $this->agentProgram()]],
            ]);

            self::fail('An agent naming an adapter nothing implements was accepted.');
        } catch (ConfigurationException $refusal) {
            self::assertSame(
                'The agent "mine" names the unsupported adapter "gemini".',
                $refusal->getMessage(),
            );
            self::assertStringContainsString('Available adapters: "codex", "claude".', implode("\n", $refusal->hints()));
        }

        // Refused rather than quietly run under one of the adapters that does
        // exist: the agent is what a run's commits and cost are attributed to.
        self::assertSame([], $this->invocations());
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

    #[Test]
    public function a_project_with_no_checks_warns_rather_than_refusing_to_start(): void
    {
        $this->givenARepository();
        Git::commit($this->project->root());

        // The default: `checks` is empty until a project says how it is
        // verified, so this is what a freshly initialised project reaches.
        $report = $this->report(['checks' => []]);

        self::assertSame(CheckOutcome::Warning, $this->outcome($report, CheckId::Checks));
        self::assertStringContainsString(
            'no verification commands are configured',
            $this->summary($report, CheckId::Checks),
        );

        // What the run will do instead is said before it starts, not after.
        $details = implode("\n", $this->details($report, CheckId::Checks));
        self::assertStringContainsString('"checks" is empty', $details);
        self::assertStringContainsString('the agent\'s own reported result alone', $details);
        self::assertStringContainsString(
            'Add the command this project is verified with under "checks"',
            implode("\n", $this->hints($report, CheckId::Checks)),
        );

        // A warning is not a refusal: this project may start a run.
        self::assertTrue($report->isUsable(), $this->describe($report));
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
     * The settings a test gave, with an installed agent, a runnable check and a
     * runnable workspace preparation filled in: a test about the base reference
     * should not have to configure any of them, and must not fail because this
     * machine has no Codex — or, for the two that default to Composer, because
     * a temporary directory has no composer.json.
     *
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings): Configuration
    {
        $settings['agents'] ??= ['codex' => ['executable' => $this->agentProgram()]];
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
     * A stand-in for an agent CLI.
     *
     * It answers any invocation with what the test says and appends the
     * arguments it was given to one file, so a test can assert both what
     * preflight concluded about the version and that asking for it was the
     * only thing preflight did with the agent.
     */
    private function agentProgram(
        string $prints = 'codex-cli ' . self::NEW_ENOUGH,
        string $relative = 'tools/codex',
        int $status = 0,
    ): string {
        return $this->writeProgram($relative, implode("\n", [
            '#!/bin/sh',
            'printf \'%s\\t%s\\n\' "$PWD" "$*" >> ' . escapeshellarg($this->project->path(self::INVOCATIONS)),
            'printf \'%s\\n\' ' . escapeshellarg($prints),
            'exit ' . $status,
        ]) . "\n");
    }

    /**
     * The arguments of every invocation the fake agent received, in order.
     *
     * @return list<string>
     */
    private function invocations(): array
    {
        return array_map(
            static fn (array $parts): string => $parts[1] ?? '',
            $this->invocationRecords(),
        );
    }

    /**
     * The working directory of every invocation the fake agent received.
     *
     * @return list<string>
     */
    private function invocationDirectories(): array
    {
        return array_map(
            static fn (array $parts): string => $parts[0],
            $this->invocationRecords(),
        );
    }

    /**
     * @return list<list<string>>
     */
    private function invocationRecords(): array
    {
        $path = $this->project->path(self::INVOCATIONS);

        if (!is_file($path)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => explode("\t", $line),
            array_filter(explode("\n", (string) file_get_contents($path))),
        ));
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
