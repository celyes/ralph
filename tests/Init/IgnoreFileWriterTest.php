<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Init;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Init\IgnoreFileOutcome;
use Celyes\RalphPhp\Init\IgnoreFileUpdate;
use Celyes\RalphPhp\Init\IgnoreFileWriter;
use Celyes\RalphPhp\Init\IgnoreRules;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ignore rules for the paths Ralph PHP generates.
 *
 * The point of these tests is what is *not* done: the ignore file belongs to
 * the project, so every existing line has to survive, a rule the user negated
 * must not be overruled, and a file that cannot be written must not take the
 * rest of initialisation down with it.
 */
#[CoversClass(IgnoreFileWriter::class)]
#[CoversClass(IgnoreRules::class)]
#[CoversClass(IgnoreFileUpdate::class)]
#[CoversClass(IgnoreFileOutcome::class)]
final class IgnoreFileWriterTest extends TestCase
{
    /**
     * The rules a default project needs: the runtime directory, the local task
     * checklist, and the backups `init --force` leaves beside the three files
     * it writes.
     *
     * @var list<string>
     */
    private const array DEFAULT_RULES = [
        '/.ralph/runtime/',
        '/.ralph/tasks.md',
        '/.ralph/config.json.bak*',
        '/.ralph/tasks.md.bak*',
        '/.ralph/CONTEXT.md.bak*',
    ];

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
    public function it_writes_the_rules_a_project_needs_when_there_is_no_ignore_file(): void
    {
        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Created, $update->outcome());
        self::assertTrue($update->wrote());
        self::assertNull($update->reason());
        self::assertSame(IgnoreFileWriter::FILE, $update->relativePath());
        self::assertSame($this->project->path(IgnoreFileWriter::FILE), $update->path());
        self::assertSame(self::DEFAULT_RULES, $update->rules());

        $contents = $this->ignoreFile();

        self::assertStringStartsWith('# Ralph PHP:', $contents);
        self::assertStringEndsWith("\n", $contents);
        self::assertSame(self::DEFAULT_RULES, $this->rulesIn($contents));
    }

    #[Test]
    public function the_configuration_and_context_file_are_not_ignored(): void
    {
        $this->update();

        foreach ([Defaults::CONFIG_FILE, Defaults::CONTEXT_FILE] as $shared) {
            self::assertNotContains('/' . $shared, $this->rulesIn($this->ignoreFile()));
        }
    }

    #[Test]
    public function the_rules_name_the_configured_checklist_rather_than_the_default_one(): void
    {
        $this->project->writeConfigArray(['tasks' => 'docs/backlog.md', 'context' => 'docs/AGENT.md']);

        $update = $this->update();

        self::assertSame(
            [
                '/.ralph/runtime/',
                '/docs/backlog.md',
                '/.ralph/config.json.bak*',
                '/docs/backlog.md.bak*',
                '/docs/AGENT.md.bak*',
            ],
            $update->rules(),
        );
    }

    #[Test]
    public function a_configured_path_is_matched_literally_rather_than_as_a_pattern(): void
    {
        $this->project->writeConfigArray(['tasks' => 'docs/sprint [1].md']);

        self::assertContains('/docs/sprint \[1\].md', $this->update()->rules());
    }

    #[Test]
    public function existing_rules_are_kept_and_the_block_is_separated_from_them(): void
    {
        $existing = "vendor/\n\n# my own rules\n*.log\n";
        $this->project->write(IgnoreFileWriter::FILE, $existing);

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Extended, $update->outcome());
        self::assertSame(self::DEFAULT_RULES, $update->rules());

        $contents = $this->ignoreFile();

        self::assertStringStartsWith($existing, $contents);
        self::assertStringContainsString("*.log\n\n# Ralph PHP:", $contents);
        self::assertSame(['vendor/', '*.log', ...self::DEFAULT_RULES], $this->rulesIn($contents));
    }

    #[Test]
    public function a_file_that_does_not_end_in_a_newline_keeps_its_last_rule_intact(): void
    {
        $this->project->write(IgnoreFileWriter::FILE, 'vendor/');

        $this->update();

        self::assertSame(['vendor/', ...self::DEFAULT_RULES], $this->rulesIn($this->ignoreFile()));
    }

    #[Test]
    public function nothing_is_written_when_every_rule_is_already_there(): void
    {
        $this->update();
        $written = $this->ignoreFile();

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Kept, $update->outcome());
        self::assertFalse($update->wrote());
        self::assertSame([], $update->rules());
        self::assertSame($written, $this->ignoreFile(), 'A second run must not touch the file.');
    }

    #[Test]
    public function a_rule_the_user_negated_is_not_overruled(): void
    {
        $this->project->write(IgnoreFileWriter::FILE, "!/.ralph/tasks.md\n");

        $update = $this->update();

        self::assertNotContains('/.ralph/tasks.md', $update->rules());
        self::assertContains('/.ralph/runtime/', $update->rules());
        self::assertStringStartsWith("!/.ralph/tasks.md\n", $this->ignoreFile());
    }

    /**
     * Rules the package must recognise as already deciding the checklist, so a
     * project that ignores it in its own words does not get a second rule.
     *
     * @return iterable<string, array{string}>
     */
    public static function equivalentChecklistRules(): iterable
    {
        yield 'the same rule' => ['/.ralph/tasks.md'];
        yield 'without the leading slash' => ['.ralph/tasks.md'];
        yield 'escaped' => ['/.ralph/tasks\.md'];
        yield 'the bare file name' => ['tasks.md'];
        yield 'the parent directory' => ['/.ralph/'];
        yield 'the parent directory by name' => ['.ralph'];
        yield 'a wildcard on the name' => ['*.md'];
        yield 'an anchored wildcard' => ['/.ralph/tasks.*'];
    }

    #[Test]
    #[DataProvider('equivalentChecklistRules')]
    public function a_rule_that_already_ignores_the_checklist_is_not_repeated(string $rule): void
    {
        $this->project->write(IgnoreFileWriter::FILE, $rule . "\n");

        self::assertNotContains('/.ralph/tasks.md', $this->update()->rules());
    }

    #[Test]
    public function an_ignored_parent_directory_decides_the_runtime_rule(): void
    {
        $this->project->write(IgnoreFileWriter::FILE, ".ralph/\n*.bak\n");

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Kept, $update->outcome());
        self::assertFileDoesNotExist($this->project->path(IgnoreFileWriter::FILE . '.bak'));
    }

    #[Test]
    public function a_negated_parent_directory_does_not_decide_the_rules_below_it(): void
    {
        // Git cannot re-include a path whose parent is excluded, so a negated
        // parent says nothing about the checklist inside it.
        $this->project->write(IgnoreFileWriter::FILE, "!.ralph/\n");

        self::assertSame(self::DEFAULT_RULES, $this->update()->rules());
    }

    #[Test]
    public function a_commented_out_rule_does_not_count_as_a_rule(): void
    {
        $this->project->write(IgnoreFileWriter::FILE, "# /.ralph/runtime/\n#/.ralph/tasks.md\n");

        self::assertSame(self::DEFAULT_RULES, $this->update()->rules());
    }

    #[Test]
    public function a_directory_at_the_ignore_path_is_reported_rather_than_removed(): void
    {
        self::assertTrue(mkdir($this->project->path(IgnoreFileWriter::FILE)));

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Skipped, $update->outcome());
        self::assertFalse($update->wrote());
        self::assertSame('a directory exists at that path', $update->reason());

        // The rules the user now has to add by hand are carried, not dropped.
        self::assertSame(self::DEFAULT_RULES, $update->rules());
        self::assertDirectoryExists($this->project->path(IgnoreFileWriter::FILE));
    }

    #[Test]
    public function a_symbolic_link_is_refused_rather_than_written_through(): void
    {
        $outside = TemporaryProject::create();
        $target = $outside->write('their.gitignore', "theirs\n");

        if (!@symlink($target, $this->project->path(IgnoreFileWriter::FILE))) {
            $outside->remove();
            self::markTestSkipped('Symbolic links are not available here.');
        }

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Skipped, $update->outcome());
        self::assertSame('it is a symbolic link', $update->reason());
        self::assertStringEqualsFile($target, "theirs\n");

        $outside->remove();
    }

    #[Test]
    public function an_unwritable_ignore_file_is_reported_without_failing_initialisation(): void
    {
        $path = $this->project->write(IgnoreFileWriter::FILE, "vendor/\n");
        self::assertTrue(chmod($path, 0o400));

        if (is_writable($path)) {
            self::markTestSkipped('This user can write a read-only file, so the refusal cannot be tested.');
        }

        $update = $this->update();

        self::assertSame(IgnoreFileOutcome::Skipped, $update->outcome());
        self::assertSame('it could not be written', $update->reason());
        self::assertStringEqualsFile($path, "vendor/\n");

        chmod($path, 0o600);
    }

    private function update(): IgnoreFileUpdate
    {
        return (new IgnoreFileWriter())->update($this->project->root(), $this->configuration());
    }

    private function configuration(): Configuration
    {
        return (new ConfigurationLoader())->loadOrDefaults($this->project->root());
    }

    private function ignoreFile(): string
    {
        return (string) file_get_contents($this->project->path(IgnoreFileWriter::FILE));
    }

    /**
     * The rules of an ignore file, in file order, without its comments.
     *
     * @return list<string>
     */
    private function rulesIn(string $contents): array
    {
        $rules = [];

        foreach (explode("\n", $contents) as $line) {
            if (trim($line) !== '' && !str_starts_with(trim($line), '#')) {
                $rules[] = trim($line);
            }
        }

        return $rules;
    }
}
