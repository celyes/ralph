<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Preflight;

use Celyes\RalphPhp\Preflight\ComposerScripts;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a project's Composer scripts.
 *
 * The important cases are the ones where this class must decline to have an
 * opinion: a wrong "no such script" would block a run over a command that
 * works, which is worse than not checking at all.
 */
#[CoversClass(ComposerScripts::class)]
final class ComposerScriptsTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('invocations')]
    public function it_identifies_the_script_an_invocation_would_run(array $arguments, ?string $expected): void
    {
        self::assertSame($expected, ComposerScripts::scriptIn($arguments));
    }

    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function invocations(): iterable
    {
        yield 'a bare script name' => [['test'], 'test'];
        yield 'an explicit run' => [['run', 'test'], 'test'];
        yield 'the run-script spelling' => [['run-script', 'test'], 'test'];
        yield 'options before the script' => [['--no-interaction', '--quiet', 'test'], 'test'];
        yield 'arguments after the script' => [['test', '--', '--filter=Foo'], 'test'];
        yield 'a script named like nothing else' => [['ci:verify'], 'ci:verify'];

        yield 'no arguments at all' => [[], null];
        yield 'only options' => [['--version'], null];
        yield 'a built-in command' => [['install'], null];
        yield 'a built-in command with arguments' => [['exec', 'phpunit'], null];
        yield 'an abbreviation of a built-in command' => [['i'], null];

        // Composer is pointed at another project, whose scripts this class
        // cannot see.
        yield 'a relocating long option' => [['--working-dir=../other', 'test'], null];
        yield 'a relocating long option with a separate value' => [['--working-dir', '../other', 'test'], null];
        yield 'a relocating short option' => [['-d', '../other', 'test'], null];
        yield 'a relocating short option with an attached value' => [['-d../other', 'test'], null];
    }

    #[Test]
    #[DataProvider('executables')]
    public function it_recognises_composer_by_the_program_name(string $executable, bool $expected): void
    {
        self::assertSame($expected, ComposerScripts::isComposer($executable));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function executables(): iterable
    {
        yield 'a bare name' => ['composer', true];
        yield 'an absolute path' => ['/usr/local/bin/composer', true];
        yield 'the phar' => ['/opt/tools/composer.phar', true];
        yield 'a vendored copy' => ['tools/composer', true];
        yield 'a Windows path' => ['C:\\tools\\composer', true];
        yield 'something else entirely' => ['/usr/bin/make', false];
        yield 'a name that merely contains it' => ['/usr/bin/composer-helper', false];
    }

    #[Test]
    public function it_reads_the_scripts_a_project_defines(): void
    {
        $project = TemporaryProject::create();

        try {
            self::assertNull(ComposerScripts::definedIn($project->root()));
            self::assertFalse(ComposerScripts::projectHasComposerJson($project->root()));

            $project->write('composer.json', (string) json_encode(['scripts' => ['test' => 'phpunit', 'lint' => 'fixer']], JSON_THROW_ON_ERROR));

            self::assertTrue(ComposerScripts::projectHasComposerJson($project->root()));
            self::assertSame(['test', 'lint'], ComposerScripts::definedIn($project->root()));
            self::assertSame($project->path('composer.json'), ComposerScripts::fileIn($project->root()));

            $project->write('composer.json', (string) json_encode(['name' => 'vendor/package'], JSON_THROW_ON_ERROR));
            self::assertSame([], ComposerScripts::definedIn($project->root()));

            // An unreadable manifest is not evidence that a script is missing.
            $project->write('composer.json', '{ not json');
            self::assertNull(ComposerScripts::definedIn($project->root()));
        } finally {
            $project->remove();
        }
    }
}
