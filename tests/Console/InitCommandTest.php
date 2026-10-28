<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\Command\InitCommand;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Init\IgnoreFileWriter;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Ralph;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ralph init` on the command line.
 *
 * Run as a real subprocess, because the directory the command initialises is
 * its own working directory: that is the behaviour under test, and changing the
 * test runner's working directory to assert it would leak into the rest of the
 * suite.
 */
#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
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
    public function it_initialises_the_working_directory_and_reports_what_it_created(): void
    {
        $result = Cli::run(['init'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString(
            'Initialising ' . Ralph::NAME . ' in ' . $this->project->root(),
            $result->standardOutput,
            $result->describe(),
        );

        foreach ([Defaults::CONFIG_FILE, Defaults::TASKS_FILE, Defaults::CONTEXT_FILE] as $file) {
            self::assertStringContainsString('created  ' . $file, $result->standardOutput, $result->describe());
            self::assertFileExists($this->project->path($file));
        }

        self::assertStringContainsString(
            'created  ' . Defaults::LOGS_DIRECTORY . '/',
            $result->standardOutput,
            $result->describe(),
        );
        self::assertDirectoryExists($this->project->path(Defaults::WORKTREES_DIRECTORY));
    }

    #[Test]
    public function the_next_steps_name_the_project_s_own_paths(): void
    {
        $this->project->writeConfigArray(['tasks' => 'docs/backlog.md', 'context' => 'docs/AGENT.md']);

        $result = Cli::run(['init'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('docs/backlog.md, one task per', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Describe the project in docs/AGENT.md', $result->standardOutput, $result->describe());
        self::assertStringNotContainsString(Defaults::TASKS_FILE, $result->standardOutput, $result->describe());
    }

    #[Test]
    public function a_second_run_keeps_the_user_s_files_and_says_so(): void
    {
        Cli::run(['init'], $this->project->root());

        $checklist = "# My tasks\n\n- [ ] Something of my own\n";
        $this->project->write(Defaults::TASKS_FILE, $checklist);

        $result = Cli::run(['init'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('kept     ' . Defaults::TASKS_FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('were left as they are', $result->standardOutput, $result->describe());
        self::assertStringEqualsFile($this->project->path(Defaults::TASKS_FILE), $checklist);
    }

    #[Test]
    public function a_second_run_without_force_does_not_mention_force_as_the_only_way_out(): void
    {
        Cli::run(['init'], $this->project->root());
        $this->project->write(Defaults::TASKS_FILE, "- [ ] Mine\n");

        $result = Cli::run(['init'], $this->project->root());

        self::assertStringContainsString('Delete a file and run "ralph init" again', $result->standardOutput, $result->describe());
        self::assertStringContainsString('"ralph init --force"', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function force_replaces_the_user_s_files_and_names_the_backups(): void
    {
        Cli::run(['init'], $this->project->root());
        $checklist = "- [ ] Mine\n";
        $this->project->write(Defaults::TASKS_FILE, $checklist);
        $this->project->write(Defaults::CONTEXT_FILE, "Mine too.\n");

        $result = Cli::run(['init', '--force'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString('replaced ' . Defaults::TASKS_FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('replaced ' . Defaults::CONTEXT_FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('2 files were replaced', $result->standardOutput, $result->describe());

        // The configuration was already the template, so there was nothing to
        // preserve and no backup to report for it.
        self::assertStringContainsString('kept     ' . Defaults::CONFIG_FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('already matches the template', $result->standardOutput, $result->describe());

        foreach ([Defaults::TASKS_FILE, Defaults::CONTEXT_FILE] as $file) {
            self::assertStringContainsString(
                $this->project->path($file) . '.bak',
                $result->standardOutput,
                $result->describe(),
            );
        }

        self::assertStringEqualsFile($this->project->path(Defaults::TASKS_FILE . '.bak'), $checklist);
        self::assertStringEqualsFile($this->project->path(Defaults::TASKS_FILE), Templates::tasks());
    }

    #[Test]
    public function the_short_force_flag_is_accepted(): void
    {
        Cli::run(['init'], $this->project->root());
        $this->project->write(Defaults::TASKS_FILE, "- [ ] Mine\n");

        $result = Cli::run(['init', '-f'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringEqualsFile($this->project->path(Defaults::TASKS_FILE), Templates::tasks());
    }

    #[Test]
    public function force_resets_a_configuration_that_is_too_broken_to_load(): void
    {
        $this->project->writeConfigArray(['limits' => ['iterations' => 0]]);

        $refused = Cli::run(['init'], $this->project->root());
        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());

        $result = Cli::run(['init', '--force'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('replaced ' . Defaults::CONFIG_FILE, $result->standardOutput, $result->describe());
        self::assertStringEqualsFile($this->project->path(Defaults::CONFIG_FILE), Templates::config());
    }

    #[Test]
    public function it_writes_the_ignore_rules_and_lists_them(): void
    {
        $result = Cli::run(['init'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertSame('', $result->errorOutput, $result->describe());

        self::assertStringContainsString('created  ' . IgnoreFileWriter::FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('  /' . Defaults::RUNTIME_DIRECTORY . '/', $result->standardOutput, $result->describe());
        self::assertStringContainsString('  /' . Defaults::TASKS_FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('negate its rule with "!"', $result->standardOutput, $result->describe());

        self::assertFileExists($this->project->path(IgnoreFileWriter::FILE));
    }

    #[Test]
    public function a_second_run_says_the_ignore_file_already_decides_every_rule(): void
    {
        Cli::run(['init'], $this->project->root());

        $result = Cli::run(['init'], $this->project->root());

        self::assertStringContainsString('kept     ' . IgnoreFileWriter::FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString('already decides every rule', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function an_ignore_file_that_cannot_be_written_is_a_warning_and_not_a_failure(): void
    {
        self::assertTrue(mkdir($this->project->path(IgnoreFileWriter::FILE)));

        $result = Cli::run(['init'], $this->project->root());

        // The rules are a convenience; the project is still initialised.
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertFileExists($this->project->path(Defaults::TASKS_FILE));

        self::assertStringContainsString('skipped  ' . IgnoreFileWriter::FILE, $result->standardOutput, $result->describe());
        self::assertStringContainsString(
            'a directory exists at that path',
            $result->errorOutput,
            $result->describe(),
        );
        self::assertStringContainsString('/' . Defaults::TASKS_FILE, $result->errorOutput, $result->describe());
    }

    #[Test]
    public function an_unknown_option_is_a_usage_error(): void
    {
        $result = Cli::run(['init', '--prefix=x'], $this->project->root());

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertStringContainsString('Unknown option "--prefix"', $result->errorOutput, $result->describe());
        self::assertDirectoryDoesNotExist($this->project->path(Ralph::PROJECT_DIRECTORY));
    }

    #[Test]
    public function a_directory_argument_is_a_usage_error_rather_than_initialising_elsewhere(): void
    {
        $result = Cli::run(['init', $this->project->root()], $this->project->root());

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertStringContainsString('takes no arguments', $result->errorOutput, $result->describe());
        self::assertDirectoryDoesNotExist($this->project->path(Ralph::PROJECT_DIRECTORY));
    }

    #[Test]
    public function the_command_is_listed_as_available_and_documents_what_it_writes(): void
    {
        $list = Cli::run();
        $help = Cli::run(['help', 'init']);

        self::assertSame(ExitCode::SUCCESS, $help->exitCode, $help->describe());
        self::assertStringNotContainsString(
            'init     Create the project configuration, task checklist, and context file. (not implemented yet)',
            $list->standardOutput,
            $list->describe(),
        );

        foreach ([Defaults::CONFIG_FILE, Defaults::TASKS_FILE, Defaults::CONTEXT_FILE] as $file) {
            self::assertStringContainsString($file, $help->standardOutput, $help->describe());
        }

        self::assertStringContainsString('-f, --force', $help->standardOutput, $help->describe());
        self::assertStringContainsString('.bak', $help->standardOutput, $help->describe());
        self::assertStringContainsString(IgnoreFileWriter::FILE, $help->standardOutput, $help->describe());
    }
}
