<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Git\PatchedFile;
use Celyes\RalphPhp\Git\Repository;
use Celyes\RalphPhp\Git\StagedPatch;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the patch a task's commit would contain.
 *
 * Two kinds of case, and both are needed. Most of them stage real files in a
 * real repository and read what Git actually prints, because the thing being
 * relied on is Git's output format and a fixture string would only prove that
 * the parser agrees with whoever wrote the fixture. The rest hand
 * {@see StagedPatch::parse()} text directly, for the shapes that are awkward or
 * impossible to produce on demand — a patch whose own content is a patch, a
 * header a future Git might phrase differently.
 */
#[CoversClass(StagedPatch::class)]
#[CoversClass(PatchedFile::class)]
final class StagedPatchTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and this reads diffs Git printed.');
        }

        $this->project = TemporaryProject::create();
        Git::initialise($this->project->root());
        Git::setIdentity($this->project->root());

        // Pinned so the lines asserted here are the lines on a checkout
        // configured for CRLF.
        Git::run($this->project->root(), 'config', 'core.autocrlf', 'false');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function a_modified_file_gives_the_lines_it_gains_and_loses(): void
    {
        $this->committed(['src/Greeting.php' => "<?php\n\nreturn 'hello';\n"]);
        $this->staged(['src/Greeting.php' => "<?php\n\nreturn 'goodbye';\n// new\n"]);

        $patch = $this->read();

        self::assertFalse($patch->isEmpty());
        self::assertSame(1, $patch->count());

        $file = $patch->forPath('src/Greeting.php');
        self::assertNotNull($file);
        self::assertSame('src/Greeting.php', $file->path());
        self::assertNull($file->originalPath());
        self::assertFalse($file->isBinary());
        self::assertSame(["return 'goodbye';", '// new'], $file->added());
        self::assertSame(["return 'hello';"], $file->removed());

        // The whole text is kept verbatim: it is what gets preserved for
        // review, so it has to be the patch Git printed rather than a
        // rendering of what was parsed out of it.
        self::assertStringContainsString('diff --git a/src/Greeting.php b/src/Greeting.php', $patch->text());
        self::assertSame(strlen($patch->text()), $patch->bytes());
    }

    #[Test]
    public function a_deleted_file_loses_every_line_and_a_new_one_gains_them(): void
    {
        $this->committed(['tests/GreetingTest.php' => "<?php\n\n// a test\n"]);
        $this->staged(['tests/GreetingTest.php' => null, 'tests/FarewellTest.php' => "<?php\n\n// another\n"]);

        $patch = $this->read();

        $deleted = $patch->forPath('tests/GreetingTest.php');
        self::assertNotNull($deleted);
        self::assertSame([], $deleted->added());
        self::assertSame(['<?php', '', '// a test'], $deleted->removed());

        $added = $patch->forPath('tests/FarewellTest.php');
        self::assertNotNull($added);
        self::assertSame(['<?php', '', '// another'], $added->added());
        self::assertSame([], $added->removed());
    }

    #[Test]
    public function a_rename_is_one_file_under_both_of_its_names(): void
    {
        // Enough content that Git pairs the two paths rather than reporting a
        // deletion and an addition.
        $body = "<?php\n\n" . str_repeat("// a line of a test\n", 20);
        $this->committed(['tests/OldTest.php' => $body]);
        $this->staged(['tests/OldTest.php' => null, 'tests/NewTest.php' => $body . "// one more\n"]);

        $patch = $this->read();

        self::assertSame(1, $patch->count());

        $file = $patch->forPath('tests/NewTest.php');
        self::assertNotNull($file);
        self::assertSame('tests/NewTest.php', $file->path());
        self::assertSame('tests/OldTest.php', $file->originalPath());
        self::assertSame(['tests/NewTest.php', 'tests/OldTest.php'], $file->paths());

        // Either name finds it, which is what lets a caller holding the staged
        // summary's paths look a rename up by the name it had before.
        self::assertSame($file->path(), $patch->forPath('tests/OldTest.php')?->path());
        self::assertSame(['// one more'], $file->added());
    }

    #[Test]
    public function a_rename_with_no_content_change_is_still_read(): void
    {
        // The one section Git writes with no `---`/`+++` pair in it at all, so
        // the paths come from the rename lines or not at all.
        $body = "<?php\n\n" . str_repeat("// unchanged\n", 10);
        $this->committed(['tests/OldTest.php' => $body]);
        $this->staged(['tests/OldTest.php' => null, 'tests/Moved/NewTest.php' => $body]);

        $file = $this->read()->forPath('tests/Moved/NewTest.php');

        self::assertNotNull($file);
        self::assertSame('tests/Moved/NewTest.php', $file->path());
        self::assertSame('tests/OldTest.php', $file->originalPath());
        self::assertSame([], $file->added());
        self::assertSame([], $file->removed());
    }

    #[Test]
    public function a_path_with_a_space_in_it_is_not_trimmed_into_another_path(): void
    {
        // Git marks where such a name ends with a trailing tab, and a parser
        // that trimmed whitespace instead of that one tab would attribute the
        // section to a neighbouring file.
        $this->committed(['src/my file.php' => "<?php\n"]);
        $this->staged(['src/my file.php' => "<?php\n\n// changed\n"]);

        $file = $this->read()->forPath('src/my file.php');

        self::assertNotNull($file);
        self::assertSame('src/my file.php', $file->path());
        self::assertSame(['', '// changed'], $file->added());
    }

    #[Test]
    public function a_repository_holding_a_directory_called_b_is_read_correctly(): void
    {
        // Only the prefix Git adds is removed. Removing every `b/` would read
        // this file as `inner/x.php` and lose the section for the real one.
        $this->committed(['b/inner/x.php' => "<?php\n"]);
        $this->staged(['b/inner/x.php' => "<?php\n\n// changed\n"]);

        self::assertNotNull($this->read()->forPath('b/inner/x.php'));
    }

    #[Test]
    public function a_binary_file_is_reported_as_one_rather_than_as_no_change(): void
    {
        $this->committed(['assets/logo.png' => "\x89PNG\r\n\x1a\n" . "\x00\x01\x02"]);
        $this->staged(['assets/logo.png' => "\x89PNG\r\n\x1a\n" . "\x03\x04\x05"]);

        $file = $this->read()->forPath('assets/logo.png');

        self::assertNotNull($file);
        self::assertTrue($file->isBinary());
        self::assertSame([], $file->added());
        self::assertSame([], $file->removed());
        self::assertStringContainsString('binary', $file->describe());
    }

    #[Test]
    public function an_empty_index_is_an_empty_patch(): void
    {
        $this->committed(['src/Greeting.php' => "<?php\n"]);

        $patch = $this->read();

        self::assertTrue($patch->isEmpty());
        self::assertSame(0, $patch->count());
        self::assertNull($patch->forPath('src/Greeting.php'));

        $none = StagedPatch::none();
        self::assertTrue($none->isEmpty());
        self::assertSame(0, $none->bytes());
    }

    #[Test]
    public function a_file_whose_contents_are_a_patch_is_one_section_and_not_four(): void
    {
        // The reason the section boundary is only ever a `diff --git` at column
        // zero: inside a hunk Git puts a marker in front of every line, so a
        // test fixture that *is* a patch arrives indented by one character.
        $patch = StagedPatch::parse(implode("\n", [
            'diff --git a/tests/Fixture/sample.diff b/tests/Fixture/sample.diff',
            'index 1111111..2222222 100644',
            '--- a/tests/Fixture/sample.diff',
            '+++ b/tests/Fixture/sample.diff',
            '@@ -1,3 +1,3 @@',
            '+diff --git a/src/Inner.php b/src/Inner.php',
            '+@@ -1 +1 @@',
            '+-gone',
            '-diff --git a/src/Other.php b/src/Other.php',
            ' context --- a/not/a/header',
            '',
        ]));

        self::assertSame(1, $patch->count());

        $file = $patch->files()[0];
        self::assertSame('tests/Fixture/sample.diff', $file->path());
        self::assertSame(
            [
                'diff --git a/src/Inner.php b/src/Inner.php',
                '@@ -1 +1 @@',
                '-gone',
            ],
            $file->added(),
        );
        self::assertSame(['diff --git a/src/Other.php b/src/Other.php'], $file->removed());
    }

    #[Test]
    public function a_section_with_no_lines_is_still_the_file_it_names(): void
    {
        // A mode change states no `---`/`+++` pair, and a check's own script
        // being made unexecutable is exactly the kind of change this reading
        // exists to notice, so the path is taken from the section's own header.
        $patch = StagedPatch::parse(implode("\n", [
            'diff --git a/bin/check b/bin/check',
            'old mode 100755',
            'new mode 100644',
            '',
        ]));

        $file = $patch->forPath('bin/check');

        self::assertNotNull($file);
        self::assertSame([], $file->added());
        self::assertSame([], $file->removed());
    }

    #[Test]
    public function a_section_naming_no_readable_path_is_dropped_rather_than_raised(): void
    {
        // A shape this cannot read must not be able to fail a verified task: it
        // contributes no lines, which can only make a finding less likely. The
        // text is still kept whole for the person reviewing it.
        $text = implode("\n", [
            'diff --git "a/odd\tname.php" "b/odd\tname.php"',
            'old mode 100644',
            'new mode 100755',
            'diff --git a/src/Real.php b/src/Real.php',
            '--- a/src/Real.php',
            '+++ b/src/Real.php',
            '@@ -1 +1 @@',
            '-before',
            '+after',
            '',
        ]);

        $patch = StagedPatch::parse($text);

        self::assertSame(1, $patch->count());
        self::assertSame('src/Real.php', $patch->files()[0]->path());
        self::assertSame($text, $patch->text());
    }

    #[Test]
    public function several_hunks_of_one_file_are_counted_together(): void
    {
        $patch = StagedPatch::parse(implode("\n", [
            'diff --git a/tests/BigTest.php b/tests/BigTest.php',
            '--- a/tests/BigTest.php',
            '+++ b/tests/BigTest.php',
            '@@ -1,2 +1,2 @@',
            '-one',
            '+ONE',
            '@@ -40,2 +40,2 @@ public function context(): void',
            '-two',
            '+TWO',
            '',
        ]));

        $file = $patch->files()[0];

        self::assertSame(['ONE', 'TWO'], $file->added());
        self::assertSame(['one', 'two'], $file->removed());
        self::assertSame('tests/BigTest.php (+2, -2)', $file->describe());
    }

    #[Test]
    public function carriage_returns_do_not_become_part_of_a_line(): void
    {
        // A repository that stores CRLF produces them in the patch, and a
        // pattern matching the end of a line would never match with one left on.
        $file = StagedPatch::parse(implode("\r\n", [
            'diff --git a/tests/CrlfTest.php b/tests/CrlfTest.php',
            '--- a/tests/CrlfTest.php',
            '+++ b/tests/CrlfTest.php',
            '@@ -1 +1 @@',
            '-    public function testOld(): void',
            '+    public function testNew(): void',
            '',
        ]))->files()[0];

        self::assertSame(['    public function testNew(): void'], $file->added());
        self::assertSame(['    public function testOld(): void'], $file->removed());
    }

    #[Test]
    public function occurrences_are_counted_rather_than_lines(): void
    {
        $file = PatchedFile::of(
            'tests/CountTest.php',
            null,
            ['self::assertSame(1, $a); self::assertSame(2, $b);'],
            ['self::assertTrue($c);'],
        );

        self::assertSame(2, $file->additions('/assert/'));
        self::assertSame(1, $file->removals('/assert/'));
        self::assertSame(1, $file->net('/assert/'));
        self::assertSame(-1, $file->net('/assertTrue/'));
    }

    #[Test]
    public function a_patched_file_must_name_a_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a path');

        PatchedFile::of('');
    }

    /**
     * Write the files and commit them, so that what follows is a diff against
     * something.
     *
     * @param array<string, string> $files
     */
    private function committed(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->project->write($relative, $contents);
        }

        Git::run($this->project->root(), 'add', '--all');
        Git::commit($this->project->root());
    }

    /**
     * Write the files and stage them, the way a task's commit is built.
     *
     * @param array<string, string|null> $files null deletes the file
     */
    private function staged(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $path = $this->project->path($relative);

            if ($contents === null) {
                unlink($path);

                continue;
            }

            $this->project->write($relative, $contents);
        }

        Git::run($this->project->root(), 'add', '--all');
    }

    /**
     * The staged patch, read with the command the package uses.
     */
    private function read(): StagedPatch
    {
        $result = Repository::at($this->project->root())->query(...StagedPatch::COMMAND);

        self::assertTrue($result->succeeded(), 'git diff --cached failed: ' . $result->failureReason());

        return StagedPatch::parse($result->standardOutput());
    }
}
