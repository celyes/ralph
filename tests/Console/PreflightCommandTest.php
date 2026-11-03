<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Command\PreflightCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph preflight` on the command line.
 *
 * Run as a real subprocess, like `ralph init`: the directory it reports on is
 * its own working directory, and that is part of the behaviour.
 */
#[CoversClass(PreflightCommand::class)]
final class PreflightCommandTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the command reports on a real repository.');
        }

        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function a_ready_project_reports_every_check_and_exits_successfully(): void
    {
        $this->givenAReadyProject();

        $result = Cli::run(['preflight'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString('Preflight checks for ' . $this->project->root(), $result->standardOutput);
        self::assertStringContainsString('agent          codex (adapter "codex"', $result->standardOutput);

        foreach (['php', 'extensions', 'git', 'repository', 'base', 'identity', 'agent', 'agent-version', 'checks'] as $check) {
            self::assertMatchesRegularExpression(
                '/^  pass  ' . $check . ' /m',
                $result->standardOutput,
                $result->describe(),
            );
        }

        self::assertStringContainsString('Run "ralph run" to start.', $result->standardOutput);
    }

    #[Test]
    public function a_failed_check_exits_with_the_configuration_status_and_explains_itself_on_stderr(): void
    {
        // A project that is not a repository, with the default agent, which is
        // not installed here either.
        $result = Cli::run(['preflight'], $this->project->root());

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());

        self::assertStringContainsString('fail  repository', $result->standardOutput, $result->describe());
        self::assertStringContainsString('skip  base', $result->standardOutput, $result->describe());

        self::assertStringContainsString('is not in a Git repository', $result->errorOutput, $result->describe());
        self::assertStringContainsString('Run "git init"', $result->errorOutput, $result->describe());
        self::assertStringContainsString('checks failed, so a run cannot start', $result->errorOutput, $result->describe());
    }

    #[Test]
    public function it_reports_on_the_default_configuration_when_the_project_has_none(): void
    {
        $this->givenAReadyProject();
        unlink($this->project->path('.ralph/config.json'));

        $result = Cli::run(['preflight'], $this->project->root());

        self::assertStringContainsString(
            'configuration  defaults (no configuration file)',
            $result->standardOutput,
            $result->describe(),
        );
        // Every check is still answered: the repository is the project's own,
        // and the agent is whichever "codex" the machine has — which is why
        // this test asserts on the report rather than on the exit status.
        self::assertMatchesRegularExpression('/^  pass  repository /m', $result->standardOutput, $result->describe());
        self::assertMatchesRegularExpression('/^  ....  agent /m', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_agent_option_chooses_which_agent_is_checked(): void
    {
        $this->givenAReadyProject();

        $result = Cli::run(['preflight', '--agent', 'claude'], $this->project->root());

        self::assertStringContainsString('agent          claude', $result->standardOutput, $result->describe());
        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('the agent "claude" cannot be run', $result->errorOutput, $result->describe());
    }

    #[Test]
    public function an_unknown_agent_is_refused_before_anything_is_checked(): void
    {
        $this->givenAReadyProject();

        $result = Cli::run(['preflight', '--agent', 'gemini'], $this->project->root());

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString('No agent named "gemini" is configured.', $result->errorOutput);
        self::assertStringNotContainsString('Preflight checks for', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function it_refuses_arguments_and_unknown_options(): void
    {
        $arguments = Cli::run(['preflight', 'somewhere'], $this->project->root());

        self::assertSame(ExitCode::USAGE, $arguments->exitCode, $arguments->describe());
        self::assertStringContainsString('takes no arguments', $arguments->errorOutput);

        $option = Cli::run(['preflight', '--fix'], $this->project->root());

        self::assertSame(ExitCode::USAGE, $option->exitCode, $option->describe());
        self::assertStringContainsString('Unknown option "--fix"', $option->errorOutput);
    }

    #[Test]
    public function its_help_describes_every_check_and_the_exit_status(): void
    {
        $result = Cli::run(['help', 'preflight'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('Usage: ralph preflight [--agent NAME]', $result->standardOutput);
        self::assertStringContainsString('--agent NAME', $result->standardOutput);
        self::assertStringContainsString('no agent session is started', $result->standardOutput);
        self::assertStringContainsString('agent-version', $result->standardOutput);
        self::assertStringContainsString('78', $result->standardOutput);
    }

    #[Test]
    public function it_is_listed_among_the_implemented_commands(): void
    {
        $result = Cli::run(['help'], $this->project->root());

        self::assertMatchesRegularExpression(
            '/^  preflight\s+Check that this machine and project can run the loop\.$/m',
            $result->standardOutput,
            $result->describe(),
        );
    }

    /**
     * A repository, an identity, a commit, an installed agent and a check that
     * can run: everything preflight asks for.
     */
    private function givenAReadyProject(): void
    {
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());
        Git::commit($this->project->root());

        // It answers "--version" with a release newer than any adapter
        // minimum, because preflight asks: an agent that could not say what it
        // is would warn, and this project is meant to be entirely ready.
        $agent = $this->project->write('tools/codex', "#!/bin/sh\nprintf 'codex-cli 9.9.9\\n'\nexit 0\n");
        chmod($agent, 0o700);

        $this->project->writeConfigArray([
            'agents' => [
                'codex' => ['executable' => $agent],
                // Named explicitly so that the test for "--agent" is about the
                // option rather than about whether this machine happens to have
                // Claude Code installed.
                'claude' => ['executable' => 'ralph-no-such-agent'],
            ],
            'checks' => [['name' => 'tests', 'command' => [PHP_BINARY, '-v']]],
        ]);
    }
}
